<?php

namespace App\Console\Commands;

use App\Enums\NetworkStatus;
use App\Enums\TransportMode;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\Fare;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Stop;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportReseau extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:reseau
        {network : Nom du réseau à importer (ter, sunu_brt, ddd, aftu)}
        {--dry-run : Simule l import sans écrire en base de données}
        {--geocode-limit=50 : Nombre maximal de géocodages Mapbox par exécution}
        {--skip-geocode : Désactive l appel à l API Mapbox}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importer un réseau de transport depuis un fichier JSON (agence, lignes, arrêts, tarifs).';

    private array $stopCache = [];

    private int $geocodeCount = 0;

    private array $geocodeCache = [];

    private string $cachePath;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $network = $this->argument('network');
        $filePath = storage_path("app/data/{$network}.json");

        if (! file_exists($filePath)) {
            $this->error("Fichier introuvable : {$filePath}");

            return self::FAILURE;
        }

        $this->cachePath = storage_path('app/data/geocode_cache.json');
        $this->loadGeocodeCache();

        $json = json_decode(file_get_contents($filePath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('JSON invalide : ' . json_last_error_msg());

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('geocode-limit');
        $skipGeocode = $this->option('skip-geocode');

        $source = Arr::get($json, 'source', '');
        $dateExtraction = Arr::get($json, 'date_extraction', now()->toDateString());
        $lines = Arr::get($json, 'lignes', []);

        if (empty($lines)) {
            $this->warn('Aucune ligne trouvée dans le fichier.');

            return self::SUCCESS;
        }

        $dataSource = null;
        if (! $dryRun) {
            $dataSource = $this->ensureDataSource($source, $dateExtraction);
        }

        $stats = [
            'lines' => 0,
            'agencies' => 0,
            'routes' => 0,
            'stops_created' => 0,
            'stops_reused' => 0,
            'stops_skipped' => 0,
            'geocoded' => 0,
            'geocode_failed' => 0,
            'route_stops' => 0,
            'fares' => 0,
        ];

        $this->info("Import du réseau : {$network}");
        $this->info('Lignes à traiter : ' . count($lines));
        $this->info('Géocodage : ' . ($skipGeocode ? 'désactivé' : "activé (limite : {$limit})"));
        $this->line('');

        foreach ($lines as $lineData) {
            $result = $this->importLine($lineData, $dataSource, $dryRun, $limit, $skipGeocode, $dateExtraction, $stats);

            if ($result === 'stop') {
                $this->warn("Limite de géocodage atteinte ({$limit}). Arrêt de l'import.");

                break;
            }

            $stats['lines']++;
        }

        if (! $dryRun) {
            file_put_contents($this->cachePath, json_encode($this->geocodeCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $this->line('');
        $this->displayReport($stats);

        return self::SUCCESS;
    }

    /**
     * Importer une seule ligne de transport.
     *
     * @return 'continue'|'stop'
     */
    private function importLine(
        array $lineData,
        ?DataSource $dataSource,
        bool $dryRun,
        int $limit,
        bool $skipGeocode,
        string $dateExtraction,
        array &$stats
    ): string {
        $reseau = Arr::get($lineData, 'reseau', 'Inconnu');
        $numero = Arr::get($lineData, 'numero');
        if (empty($numero)) {
            $numero = 'Sans numéro'; // Ou 'N/A' ou $nomLigne si tu préfères
        }
        $nomLigne = Arr::get($lineData, 'libelle_officiel', $numero);
        $tarif = Arr::get($lineData, 'tarif_standard_fcfa');

        if ($dryRun) {
            $this->line("  [DRY RUN] {$reseau} {$numero}: {$nomLigne}");
        }

        // --- Agence (simulée en dry-run) ---
        $agency = null;
        if ($dryRun) {
            $stats['agencies']++;
            $agency = new Agency(['name' => $reseau]);
        } else {
            $agency = Agency::firstOrCreate(
                ['name' => $reseau],
                [
                    'status' => $this->mapNetworkStatus($reseau),
                    'url' => null,
                ]
            );
            if ($agency->wasRecentlyCreated) {
                $stats['agencies']++;
            }
        }

        // --- Route (simulée en dry-run) ---
        $route = null;
        if ($dryRun) {
            $stats['routes']++;
            $route = new Route([
                'agency_id' => 0,
                'short_name' => $numero,
                'long_name' => $nomLigne,
            ]);
        } elseif ($agency !== null) {
            $route = Route::firstOrCreate(
                ['agency_id' => $agency->id, 'short_name' => $numero],
                [
                    'long_name' => $nomLigne,
                    'mode' => $this->mapTransportMode($reseau),
                    'headway_peak_min' => null,
                    'headway_offpeak_min' => null,
                    'valid_from' => $dateExtraction,
                    'valid_to' => null,
                    'verification' => 'to_verify',
                    'verified_at' => null,
                ]
            );
            if ($route->wasRecentlyCreated) {
                $stats['routes']++;
            }
        }

        // --- Arrêts ---
        $stopsKey = $this->detectStopsKey($lineData);
        $stops = Arr::get($lineData, $stopsKey, []);

        if (empty($stops)) {
            $this->warn("  Aucun arrêt trouvé pour {$reseau} {$numero} (clé {$stopsKey} absente ou vide).");

            return 'continue';
        }

        $direction = 0;
        $seq = 1;

        foreach ($stops as $rawStopName) {
            $stopName = trim((string) $rawStopName);
            if ($stopName === '') {
                continue;
            }

            // Gestion des doublons : réutiliser le Stop existant uniquement s'il appartient au même réseau (même agence).
            // Un arrêt du même nom sur un autre réseau doit obtenir son propre Stop (coordonnées différentes).
            if (isset($this->stopCache[$reseau][$stopName])) {
                $stop = $this->stopCache[$reseau][$stopName];
                $stats['stops_reused']++;
            } else {
                $stop = null;

                if (! $dryRun && $agency !== null) {
                    $stop = Stop::where('name', $stopName)
                        ->whereHas('routes', fn($q) => $q->where('agency_id', $agency->id))
                        ->first();
                }

                if ($stop !== null) {
                    $this->stopCache[$reseau][$stopName] = $stop;
                    $stats['stops_reused']++;
                } else {
                    $stop = $this->createStop($stopName, $dryRun, $limit, $skipGeocode, $stats, $reseau);
                    if ($stop === null) {
                        $stats['stops_skipped']++;

                        continue;
                    }
                    $this->stopCache[$reseau][$stopName] = $stop;
                    $stats['stops_created']++;
                }
            }

            // --- RouteStop (simulé en dry-run) ---
            if ($route !== null) {
                if (! $dryRun) {
                    RouteStop::firstOrCreate(
                        ['route_id' => $route->id, 'direction' => $direction, 'seq' => $seq],
                        [
                            'stop_id' => $stop->id,
                            'seconds_from_prev' => null,
                            'seconds_from_prev_peak' => null,
                            'source_id' => $dataSource?->id,
                        ]
                    );
                }
                $stats['route_stops']++;
            }

            $seq++;
        }

        // --- Tarif (simulé en dry-run) ---
        if ($tarif !== null && $tarif !== '' && $route !== null) {
            if (! $dryRun && $dataSource !== null) {
                Fare::firstOrCreate(
                    ['route_id' => $route->id, 'kind' => 'fixed', 'from_stop_id' => null, 'to_stop_id' => null],
                    [
                        'min_stops' => null,
                        'max_stops' => null,
                        'amount_fcfa' => (int) $tarif,
                        'source_id' => $dataSource->id,
                        'verified_at' => null,
                        'valid_from' => $dateExtraction,
                        'valid_to' => null,
                    ]
                );
            }
            $stats['fares']++;
        }

        return 'continue';
    }

    /**
     * Créer un Stop avec géocodage Mapbox.
     */
    private function createStop(string $name, bool $dryRun, int $limit, bool $skipGeocode, array &$stats, string $reseau): ?Stop
    {
        // --- Mode dry-run : simuler la création sans appel Mapbox ---
        if ($dryRun) {
            return new Stop(['name' => $name]);
        }

        // --- 1. DICTIONNAIRES EN DUR (Priorité absolue) ---
        $currentNetwork = strtolower($reseau ?: $this->argument('network') ?? '');
        $cleanStopName = trim($name);

        // Dictionnaire TER
        $hardcodedTerCoords = [
            'Dakar' => [14.676460, -17.433661],
            'Colobane' => [14.700332, -17.441652],
            'Hann' => [14.722095, -17.432081],
            'Dalifort' => [14.734231, -17.418998],
            'Baux Maraîchers' => [14.739698, -17.403599],
            'Pikine' => [14.749849, -17.391687],
            'Thiaroye' => [14.758761, -17.380291],
            'Yeumbeul' => [14.764894, -17.356507],
            'Mbao' => [14.744070, -17.313940],
            'PNR' => [14.723070, -17.283919],
            'Rufisque' => [14.715810, -17.270060],
            'Bargny' => [14.698110, -17.229220],
            'Diamniadio' => [14.715990, -17.198450],
            'Sébikotane' => [14.740700, -17.139100],
            'AIBD' => [14.672110, -17.064510],
        ];

        // Dictionnaire BRT
        $hardcodedBrtCoords = [
            'Préfecture de Guédiawaye' => [14.7719791, -17.3868591],
            'Golf Nord' => [14.7763179, -17.3984054],
            'Dalal Jam' => [14.7719783, -17.4082010],
            'Golf Sud' => [14.7675735, -17.4134425],
            'Ndingala' => [14.7646271, -17.4196781],
            'Parcelles' => [14.7626996, -17.4242946],
            'Croisement 22' => [14.7539779, -17.4332535],
            'Police Parcelles Assainies' => [14.7510760, -17.4387907],
            'Grand Médine' => [14.7481903, -17.4444326],
            'Cardinal Hyacinthe Thiandoum' => [14.7415853, -17.4513360],
            'Scat Urbam' => [14.7369859, -17.4552396],
            'Liberté 6' => [14.7263088, -17.4591976],
            'Liberté 5' => [14.7210415, -17.4640450],
            'Liberté 1' => [14.7099321, -17.4624955],
            'Grand Dakar' => [14.7049934, -17.4583342],
            'Dial Diop' => [14.6993790, -17.4535498],
            'Grande Mosquée' => [14.6824846, -17.4443248],
            'Sacré-Cœur' => [14.7169687, -17.4665407],
            'Place de la Nation' => [14.6960909, -17.4506369],
            'Papa Gueye Fall (Petersen)' => [14.6766438, -17.4406354],
            'Gueule Tapée' => [14.7756271, -17.3921489],
            'Khar Yalla' => [14.7320392, -17.4564326],
            'Fith Mith' => [14.7753280, -17.4055188],
        ];

        // Application du dictionnaire TER
        if ($currentNetwork === 'ter' && isset($hardcodedTerCoords[$cleanStopName])) {
            $coords = $hardcodedTerCoords[$cleanStopName];
            return Stop::create([
                'name' => $name,
                'geom' => Point::makeGeodetic($coords[0], $coords[1]),
                'is_station' => false,
                'verified_at' => null,
            ]);
        }

        // Application du dictionnaire BRT (le réseau s'appelle 'sunubrt' dans le JSON)
        if (str_contains($currentNetwork, 'brt') && isset($hardcodedBrtCoords[$cleanStopName])) {
            $coords = $hardcodedBrtCoords[$cleanStopName];
            return Stop::create([
                'name' => $name,
                'geom' => Point::makeGeodetic($coords[0], $coords[1]),
                'is_station' => true, // C'est une station BRT
                'verified_at' => null,
            ]);
        }

        // --- 2bis. Recherche dans la base par nom (insensible à la casse) avant Mapbox ---
        // Réutilise un arrêt existant (même créé par un autre réseau : DDD, TER, etc.)
        // pour éviter les doublons et économiser l'API Mapbox.
        if (! $dryRun) {
            $existingStop = Stop::whereRaw('lower(name) = ?', [strtolower($name)])->first();
            if ($existingStop !== null) {
                $stats['stops_reused']++;
                $this->line("  [REUSE DB] {$name} (id={$existingStop->id})");

                return $existingStop;
            }
        }

        // --- Mode sans géocodage : point neutre (Dakar) ---
        if ($skipGeocode) {
            return Stop::create([
                'name' => $name,
                'geom' => Point::makeGeodetic(14.7167, -17.4677),
                'is_station' => false,
                'verified_at' => null,
            ]);
        }

        // --- 2. Vérifier le cache de géocodage local (sécurisé par réseau) ---
        $cacheKey = $currentNetwork . '_' . $cleanStopName; // AJOUT DE LA SÉCURITÉ ICI

        if (isset($this->geocodeCache[$cacheKey])) {
            $coords = $this->geocodeCache[$cacheKey];
            $lat = $coords['lat'] ?? $coords[1] ?? null;
            $lng = $coords['lng'] ?? $coords[0] ?? null;

            if ($lat !== null && $lng !== null) {
                $stats['geocoded']++;
                return Stop::create([
                    'name' => $name,
                    'geom' => Point::makeGeodetic((float) $lat, (float) $lng),
                    'is_station' => false,
                    'verified_at' => null,
                ]);
            }
        }

        // --- 3. Vérifier la limite de géocodage ---
        if ($this->geocodeCount >= $limit) {
            return null;
        }

        // --- 4. Appel API Mapbox ---
        $geocoded = $this->callMapbox($name);
        if ($geocoded === null) {
            $stats['geocode_failed']++;
            return null;
        }

        $lat = $geocoded['lat'];
        $lng = $geocoded['lng'];
        $this->geocodeCache[$cacheKey] = ['lat' => $lat, 'lng' => $lng];
        $this->geocodeCount++;
        $stats['geocoded']++;

        usleep(200000);

        return Stop::create([
            'name' => $name,
            'geom' => Point::makeGeodetic((float) $lat, (float) $lng),
            'is_station' => false,
            'verified_at' => null,
        ]);
    }
    /**
     * Appeler l API Mapbox Geocoding pour un nom d arrêt.
     *
     * @return array{lat: float, lng: float}|null
     */
    /**
     * Appeler l API Mapbox Geocoding pour un nom d arrêt.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function callMapbox(string $stopName): ?array
    {
        $token = config('services.mapbox.token', env('MAPBOX_TOKEN'));
        if (empty($token)) {
            $this->warn('MAPBOX_TOKEN non configuré dans .env');

            return null;
        }

        // 1. Dictionnaire de traduction pour les cas particuliers
        $translations = [
            'PNR' => 'Passage à Niveau de Rufisque',
            'AIBD' => 'Aéroport International Blaise Diagne',
            'Baux Maraîchers' => 'Gare des Baux Maraîchers',
        ];

        $searchName = $translations[$stopName] ?? $stopName;

        // 2. On ajoute "Dakar, Sénégal" pour contextualiser les noms de rues génériques (ex: Rue 10)
        if (! str_contains(strtolower($searchName), 'dakar') && ! str_contains(strtolower($searchName), 'aéroport')) {
            $searchName .= ', Dakar, Sénégal';
        }

        $encoded = rawurlencode($searchName);
        $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$encoded}.json?access_token={$token}&limit=1&country=SN&language=fr";

        try {
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                $this->warn("Mapbox échec (HTTP {$response->status()}) pour : {$stopName}");

                return null;
            }

            $data = $response->json();
            $features = Arr::get($data, 'features', []);

            if (empty($features)) {
                $this->warn("Mapbox : aucun résultat pour : {$stopName}");

                return null;
            }

            $center = Arr::get($features[0], 'center', []);
            if (count($center) < 2) {
                $this->warn("Mapbox : coordonnées incomplètes pour : {$stopName}");

                return null;
            }

            // Mapbox retourne [longitude, latitude]
            $lng = (float) $center[0];
            $lat = (float) $center[1];

            return ['lat' => $lat, 'lng' => $lng];
        } catch (\Throwable $e) {
            $this->warn("Mapbox exception pour {$stopName} : " . $e->getMessage());

            return null;
        }
    }
    /**
     * Créer ou récupérer la DataSource liée au fichier JSON.
     */
    private function ensureDataSource(string $source, string $dateExtraction): DataSource
    {
        return DataSource::firstOrCreate(
            ['url' => $source],
            [
                'kind' => 'json_import',
                'description' => 'Import depuis fichier JSON : ' . Str::limit($source, 100),
                'collected_at' => $dateExtraction,
            ]
        );
    }

    /**
     * Détecter la clé contenant la liste des arrêts dans une ligne JSON.
     */
    private function detectStopsKey(array $lineData): string
    {
        foreach (['etapes_officielles', 'liste_stations_connues', 'liste_stations'] as $key) {
            if (isset($lineData[$key]) && is_array($lineData[$key]) && count($lineData[$key]) > 0) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Charger le cache de géocodage depuis le disque.
     */
    private function loadGeocodeCache(): void
    {
        if (! file_exists($this->cachePath)) {
            file_put_contents($this->cachePath, '{}');
        }

        $content = file_get_contents($this->cachePath);
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            $this->geocodeCache = $decoded;
        }
    }

    /**
     * Afficher le rapport final d import.
     */
    private function displayReport(array $stats): void
    {
        $this->info('Rapport d import :');
        $this->line("  Lignes traitées      : {$stats['lines']}");
        $this->line("  Agences créées       : {$stats['agencies']}");
        $this->line("  Routes créées        : {$stats['routes']}");
        $this->line("  Stops créés          : {$stats['stops_created']}");
        $this->line("  Stops réutilisés     : {$stats['stops_reused']}");
        $this->line("  Stops ignorés        : {$stats['stops_skipped']}");
        $this->line("  Géocodages Mapbox    : {$stats['geocoded']}");
        $this->line("  Échecs géocodage     : {$stats['geocode_failed']}");
        $this->line("  RouteStops créés     : {$stats['route_stops']}");
        $this->line("  Fares créés          : {$stats['fares']}");
    }

    /**
     * Mapper le nom du réseau vers un NetworkStatus.
     */
    private function mapNetworkStatus(string $reseau): string
    {
        return match (strtolower($reseau)) {
            'ter', 'sunu_brt', 'ddd' => NetworkStatus::OFFICIAL->value,
            'aftu' => NetworkStatus::TRADITIONAL->value,
            default => NetworkStatus::OFFICIAL->value,
        };
    }

    /**
     * Mapper le nom du réseau vers un TransportMode.
     */
    private function mapTransportMode(string $reseau): string
    {
        return match (strtolower($reseau)) {
            'ter' => TransportMode::RAIL->value,
            'sunu_brt' => TransportMode::BRT->value,
            'ddd', 'aftu' => TransportMode::BUS->value,
            default => TransportMode::BUS->value,
        };
    }
}
