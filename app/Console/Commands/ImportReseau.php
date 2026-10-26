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
use App\Services\MapboxService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
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

    private MapboxService $mapboxService;

    /**
     * Créer une nouvelle instance de la commande.
     */
    public function __construct(MapboxService $mapboxService)
    {
        parent::__construct();
        $this->mapboxService = $mapboxService;
    }

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

        $json = json_decode(file_get_contents($filePath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('JSON invalide : '.json_last_error_msg());

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
        $this->info('Lignes à traiter : '.count($lines));
        $this->info('Géocodage : '.($skipGeocode ? 'désactivé' : "activé (limite : {$limit})"));
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
            $this->mapboxService->saveCache();
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
            $numero = 'Sans numéro';
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
                        ->whereHas('routes', fn ($q) => $q->where('agency_id', $agency->id))
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
     * Créer un Stop avec géocodage via MapboxService.
     */
    private function createStop(string $name, bool $dryRun, int $limit, bool $skipGeocode, array &$stats, string $reseau): ?Stop
    {
        // --- Mode dry-run : simuler la création sans appel Mapbox ---
        if ($dryRun) {
            return new Stop(['name' => $name]);
        }

        // --- Dictionnaire en dur pour le réseau TER (priorité absolue, pas d'appel Mapbox) ---
        $currentNetwork = strtolower($reseau);
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

        if ($currentNetwork === 'ter' && isset($hardcodedTerCoords[$name])) {
            $coords = $hardcodedTerCoords[$name];

            return Stop::create([
                'name' => $name,
                'geom' => Point::makeGeodetic((float) $coords[0], (float) $coords[1]),
                'is_station' => false,
                'verified_at' => null,
            ]);
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

        // --- Vérifier la limite de géocodage avant d'appeler Mapbox ---
        if ($stats['geocoded'] >= $limit) {
            return null;
        }

        // --- Géocodage via MapboxService ---
        $geocoded = $this->mapboxService->geocode($name);

        // Vérifier la limite de géocodage (le service retourne null si limite atteinte ou échec)
        if ($geocoded === null) {
            // On ne sait pas si c'est un échec ou la limite, on compte comme échec pour la stat
            $stats['geocode_failed']++;

            return null;
        }

        $lat = $geocoded['lat'];
        $lng = $geocoded['lng'];
        $stats['geocoded']++;

        return Stop::create([
            'name' => $name,
            'geom' => Point::makeGeodetic((float) $lat, (float) $lng),
            'is_station' => false,
            'verified_at' => null,
        ]);
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
                'description' => 'Import depuis fichier JSON : '.Str::limit($source, 100),
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
