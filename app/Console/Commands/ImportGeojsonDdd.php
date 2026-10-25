<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Stop;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('import:geojson-ddd {--dry-run : Simule sans écrire en base de données} {--fresh : Supprime les RouteStops existants du DDD avant l\'import}')]
#[Description('Importer/mettre à jour les coordonnées GPS des arrêts DDD depuis un GeoJSON.')]
class ImportGeojsonDdd extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = storage_path('app/data/query.json');

        if (! file_exists($path)) {
            $this->error("Fichier introuvable : {$path}");

            return self::FAILURE;
        }

        $json = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! isset($json['features'])) {
            $this->error('GeoJSON invalide ou sans features.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $fresh = $this->option('fresh');
        $features = $json['features'];

        $stats = [
            'total' => count($features),
            'created' => 0,
            'updated' => 0,
            'ignored' => 0,
            'route_stops' => 0,
        ];

        // --- Agence DDD ---
        $agency = Agency::whereRaw('lower(name) = ?', ['ddd'])->first();
        if ($agency === null && ! $dryRun) {
            $this->error("Agence DDD introuvable. Importez d'abord le réseau DDD avec import:reseau ddd.");

            return self::FAILURE;
        }

        // --- Purge des RouteStops existants du DDD si --fresh ---
        if ($fresh && $agency !== null && ! $dryRun) {
            DB::table('route_stops')
                ->join('routes', 'route_stops.route_id', '=', 'routes.id')
                ->where('routes.agency_id', $agency->id)
                ->delete();
            $this->info("RouteStops du réseau DDD purgés.");
        }

        $this->info("Import GeoJSON DDD : {$stats['total']} features trouvées.");
        $this->line('');

        $seq = 0;

        foreach ($features as $feature) {
            // --- Ignorer si geometry null ou sans coordonnées ---
            $geometry = $feature['geometry'] ?? null;
            if ($geometry === null || ! isset($geometry['coordinates'])) {
                $stats['ignored']++;
                continue;
            }

            $properties = $feature['properties'] ?? [];
            $nomArret = trim((string) ($properties['Nom_Arret'] ?? ''));

            // --- Ignorer si Nom_Arret vide ---
            if ($nomArret === '') {
                $stats['ignored']++;
                continue;
            }

            // --- Extraire coordonnées (GeoJSON : [longitude, latitude]) ---
            $coordinates = $geometry['coordinates'];
            if (! is_array($coordinates) || count($coordinates) < 2) {
                $stats['ignored']++;
                continue;
            }

            $longitude = (float) $coordinates[0];
            $latitude = (float) $coordinates[1];

            // --- Chercher un arrêt existant avec ce nom ET des coordonnées proches (< 10 m) ---
            $stop = Stop::where('name', $nomArret)
                ->whereRaw(
                    'ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 10)',
                    [$longitude, $latitude]
                )
                ->first();

            if ($stop !== null) {
                // Mettre à jour les coordonnées
                if (! $dryRun) {
                    $stop->update(['geom' => Point::makeGeodetic($latitude, $longitude)]);
                }
                $stats['updated']++;
                $this->line("  [MAJ] {$nomArret} ({$latitude}, {$longitude})");
            } else {
                // Créer un nouvel arrêt
                if (! $dryRun) {
                    $stop = Stop::create([
                        'name' => $nomArret,
                        'geom' => Point::makeGeodetic($latitude, $longitude),
                        'is_station' => false,
                        'verified_at' => null,
                    ]);
                }
                $stats['created']++;
                $this->line("  [CREATION] {$nomArret} ({$latitude}, {$longitude})");
            }

            // --- Si l'arrêt est null (pas trouvé ni créé), on saute la séquence ---
            if ($stop === null) {
                $stats['ignored']++;
                $this->warn("  Arrêt introuvable, on saute la séquence : {$nomArret}");
                continue;
            }

            // --- Lien avec la ligne de bus (route) ---
            $nomLigne = trim((string) ($properties['Nom_Ligne'] ?? ''));
            if ($nomLigne !== '' && $agency !== null && $stop !== null) {
                // Nettoyage : enlever le préfixe "Ligne_" / "LIgne_" → garder ex: "4"
                $shortName = preg_replace('/^(ligne_)/i', '', $nomLigne);
                $shortName = trim($shortName);

                $route = Route::where('agency_id', $agency->id)
                    ->where('short_name', $shortName)
                    ->first();

                if ($route !== null) {
                    $seq++;
                    if (! $dryRun) {
                        RouteStop::updateOrCreate(
                            ['route_id' => $route->id, 'direction' => 0, 'seq' => $seq],
                            [
                                'stop_id' => $stop->id,
                                'seconds_from_prev' => null,
                                'seconds_from_prev_peak' => null,
                                'source_id' => null,
                            ]
                        );
                    }
                    $stats['route_stops']++;
                    $this->line("    → Route {$shortName} (seq {$seq})");
                } else {
                    $this->warn("    Route introuvable : {$nomLigne} (nettoyé: {$shortName})");
                }
            }
        }

        $this->line('');
        $this->info('Rapport import GeoJSON DDD :');
        $this->line("  Features totales   : {$stats['total']}");
        $this->line("  Arrêts créés       : {$stats['created']}");
        $this->line("  Arrêts mis à jour  : {$stats['updated']}");
        $this->line("  Arrêts ignorés     : {$stats['ignored']}");
        $this->line("  RouteStops créés   : {$stats['route_stops']}");

        return self::SUCCESS;
    }
}
