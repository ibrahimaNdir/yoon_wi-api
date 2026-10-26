<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Stop;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

class GeospatialService
{
    private const DEFAULT_LAT = 14.712019;

    private const DEFAULT_LNG = -17.457171;

    private const SIMILARITY_THRESHOLD = 0.5;

    /**
     * Génère les correspondances piétonnes entre arrêts proches.
     *
     * @return int Nombre de liaisons créées dans les deux sens
     */
    public function generateWalkLinks(int $maxDistance = 300, float $walkSpeed = 1.2): int
    {
        // --- 1. Vider la table pour repartir de zéro (idempotence) ---
        DB::table('walk_links')->truncate();

        // --- 2. Trouver les paires d'arrêts à moins de $maxDistance mètres ---
        $pairs = DB::select('
            SELECT s1.id AS from_stop_id,
                   s2.id AS to_stop_id,
                   ST_Distance(s1.geom::geography, s2.geom::geography) AS distance_m
            FROM stops s1
            JOIN stops s2 ON s1.id < s2.id
            WHERE ST_DWithin(s1.geom::geography, s2.geom::geography, :maxDistance)
              AND NOT EXISTS (
                  SELECT 1
                  FROM route_stops rs1
                  JOIN route_stops rs2 ON rs1.route_id = rs2.route_id
                  WHERE rs1.stop_id = s1.id
                    AND rs2.stop_id = s2.id
              )
            ORDER BY s1.id, s2.id
        ', ['maxDistance' => $maxDistance]);

        // --- 3. Préparer les insertions (les deux sens pour un graphe bidirectionnel) ---
        $batch = [];

        foreach ($pairs as $pair) {
            $distanceM = (int) round((float) $pair->distance_m);
            $walkSeconds = (int) ceil((float) $pair->distance_m / $walkSpeed);

            // Sens A → B
            $batch[] = [
                'from_stop_id' => $pair->from_stop_id,
                'to_stop_id' => $pair->to_stop_id,
                'distance_m' => $distanceM,
                'walk_seconds' => $walkSeconds,
            ];

            // Sens B → A
            $batch[] = [
                'from_stop_id' => $pair->to_stop_id,
                'to_stop_id' => $pair->from_stop_id,
                'distance_m' => $distanceM,
                'walk_seconds' => $walkSeconds,
            ];
        }

        // --- 4. Insérer les liaisons piétonnes ---
        if (! empty($batch)) {
            DB::table('walk_links')->insert($batch);
        }

        return count($batch);
    }

    /**
     * Corrige les coordonnées par défaut des arrêts AFTU via une recherche floue de nom.
     *
     * @return array{total: int, fixed: int, unchanged: int}
     */
    public function fixAftuCoords(bool $dryRun = false): array
    {
        // --- 1. Agence AFTU ---
        $agency = Agency::whereRaw('lower(name) = ?', ['aftu'])->first();
        if ($agency === null) {
            return ['total' => 0, 'fixed' => 0, 'unchanged' => 0];
        }

        // --- 1bis. Activer pg_trgm si nécessaire (extension déjà installée) ---
        if (! $dryRun) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }

        // --- 1ter. Réinitialisation IDÉMPOTENTE : remettre tous les arrêts AFTU au point par défaut ---
        if (! $dryRun) {
            DB::table('stops as s')
                ->join('route_stops as rs', 'rs.stop_id', '=', 's.id')
                ->join('routes as r', 'r.id', '=', 'rs.route_id')
                ->where('r.agency_id', $agency->id)
                ->whereRaw(
                    'NOT ST_DWithin(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 0.1)',
                    [self::DEFAULT_LNG, self::DEFAULT_LAT]
                )
                ->distinct()
                ->update(['geom' => Point::makeGeodetic(self::DEFAULT_LAT, self::DEFAULT_LNG)]);
        }

        // --- 2. Arrêts AFTU ayant exactement la coordonnée par défaut ---
        $defaultPointSql = 'ST_SetSRID(ST_MakePoint('.self::DEFAULT_LNG.', '.self::DEFAULT_LAT.'), 4326)';

        $aftuStops = DB::select("
            SELECT DISTINCT s.id, s.name
            FROM stops s
            JOIN route_stops rs ON rs.stop_id = s.id
            JOIN routes r ON r.id = rs.route_id
            WHERE r.agency_id = :agencyId
              AND ST_DWithin(s.geom::geography, {$defaultPointSql}::geography, 0.1)
        ", ['agencyId' => $agency->id]);

        $total = count($aftuStops);
        $fixed = 0;
        $unchanged = 0;

        // --- 3. Chercher par similarité de nom (pg_trgm / similarity) ---
        foreach ($aftuStops as $stop) {
            $cleanName = $this->cleanStopName($stop->name);

            if ($cleanName === '' || strlen($cleanName) < 5) {
                $unchanged++;

                continue;
            }

            $reference = DB::select("
                SELECT s2.id, s2.name,
                    similarity(upper(s2.name), upper(:cleanName)) AS sim,
                    ST_X(s2.geom::geometry) AS lng,
                    ST_Y(s2.geom::geometry) AS lat
                FROM stops s2
                WHERE s2.id != :stopId
                  AND NOT ST_DWithin(s2.geom::geography, ST_SetSRID(ST_MakePoint(:defaultLng, :defaultLat), 4326)::geography, 0.1)
                  AND NOT (ST_Y(s2.geom::geometry) = :defaultLat AND ST_X(s2.geom::geometry) = :defaultLng)
                  AND upper(s2.name) % upper(:cleanName)
                  AND char_length(upper(s2.name)) >= 5
                  AND upper(s2.name) != 'ROND POINT'
                ORDER BY sim DESC
                LIMIT 1
            ", [
                'stopId' => $stop->id,
                'cleanName' => $cleanName,
                'defaultLng' => self::DEFAULT_LNG,
                'defaultLat' => self::DEFAULT_LAT,
            ]);

            $reference = $reference[0] ?? null;

            if ($reference === null || (float) $reference->sim < self::SIMILARITY_THRESHOLD) {
                $unchanged++;

                continue;
            }

            // --- 4. Copier les coordonnées de l'arrêt de référence ---
            $referenceLat = (float) $reference->lat;
            $referenceLng = (float) $reference->lng;

            if ($referenceLat === 0.0 && $referenceLng === 0.0) {
                $unchanged++;

                continue;
            }

            if (! $dryRun) {
                Stop::where('id', $stop->id)
                    ->update(['geom' => Point::makeGeodetic($referenceLat, $referenceLng)]);
            }

            $fixed++;
        }

        return ['total' => $total, 'fixed' => $fixed, 'unchanged' => $unchanged];
    }

    /**
     * Trouver les arrêts proches d'une position, triés par distance croissante.
     *
     * @return array<int, array{id: int, name: string, lat: float, lng: float, distance_m: float}>
     */
    public function findNearbyStops(float $latitude, float $longitude, int $radius = 500): array
    {
        $results = DB::select('
            SELECT s.id,
                   s.name,
                   ST_Y(s.geom::geometry) AS lat,
                   ST_X(s.geom::geometry) AS lng,
                   ST_Distance(s.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) AS distance_m
            FROM stops s
            WHERE ST_DWithin(s.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
            ORDER BY distance_m ASC
        ', [
            'lat' => $latitude,
            'lng' => $longitude,
            'radius' => $radius,
        ]);

        return array_map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'lat' => (float) $row->lat,
            'lng' => (float) $row->lng,
            'distance_m' => (float) $row->distance_m,
        ], $results);
    }

    /**
     * Nettoie un nom d'arrêt en retirant les préfixes inutiles.
     */
    private function cleanStopName(string $name): string
    {
        $cleaned = trim($name);

        $cleaned = preg_replace('/^(GARE|ARRET|TERMINUS)\s+/i', '', $cleaned);
        $cleaned = str_replace(['É', 'É'], 'E', $cleaned);

        return trim($cleaned);
    }
}
