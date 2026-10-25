<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Stop;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('fix:aftu-coords {--dry-run : Simule sans écrire en base de données}')]
#[Description('Corrige les coordonnées par défaut des arrêts AFTU via une recherche floue de nom.')]
class FixAftuCoords extends Command
{
    private const DEFAULT_LAT = 14.712019;

    private const DEFAULT_LNG = -17.457171;

    private const SIMILARITY_THRESHOLD = 0.5;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // --- 1. Agence AFTU ---
        $agency = Agency::whereRaw('lower(name) = ?', ['aftu'])->first();
        if ($agency === null) {
            $this->error('Agence AFTU introuvable.');

            return self::FAILURE;
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
        $defaultPointSql = 'ST_SetSRID(ST_MakePoint(' . self::DEFAULT_LNG . ', ' . self::DEFAULT_LAT . '), 4326)';

        $aftuStops = DB::select("
            SELECT DISTINCT s.id, s.name
            FROM stops s
            JOIN route_stops rs ON rs.stop_id = s.id
            JOIN routes r ON r.id = rs.route_id
            WHERE r.agency_id = :agencyId
              AND ST_DWithin(s.geom::geography, {$defaultPointSql}::geography, 0.1)
        ", ['agencyId' => $agency->id]);

        $this->info("Arrêts AFTU avec coordonnée par défaut : " . count($aftuStops));
        $this->line('');

        $fixed = 0;
        $unchanged = 0;

        // --- 3. Chercher par similaire de nom (pg_trgm / similarity) ---
        foreach ($aftuStops as $stop) {
            $cleanName = $this->cleanStopName($stop->name);

            if ($cleanName === '' || strlen($cleanName) < 5) {
                $unchanged++;
                $this->warn("  [INCHANGÉ] {$stop->name} (id={$stop->id}) - nom trop court après nettoyage ('{$cleanName}')");

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
                $score = $reference !== null ? ' (sim=' . round((float) $reference->sim, 2) . ')' : '';
                $this->warn("  [INCHANGÉ] {$stop->name} (id={$stop->id}){$score} - aucun arrêt suffisamment similaire trouvé");

                continue;
            }

            // --- 4. Copier les coordonnées de l'arrêt de référence ---
            $referenceLat = (float) $reference->lat;
            $referenceLng = (float) $reference->lng;

            if ($referenceLat === 0.0 && $referenceLng === 0.0) {
                $unchanged++;
                $this->warn("  [INCHANGÉ] {$stop->name} (id={$stop->id}) - référence sans coordonnées valides");

                continue;
            }

            if (! $dryRun) {
                Stop::where('id', $stop->id)
                    ->update(['geom' => Point::makeGeodetic($referenceLat, $referenceLng)]);
            }

            $fixed++;
            $this->line("  [CORRIGÉ] {$stop->name} → {$reference->name} (sim=" . round((float) $reference->sim, 2) . ") ({$referenceLat}, {$referenceLng})");
        }

        // --- 5. Rapport ---
        $this->line('');
        $this->info('Rapport fix:aftu-coords :');
        $this->line("  Arrêts AFTU avec coord. par défaut : " . count($aftuStops));
        $this->line("  Arrêts corrigés (nom similaire)    : {$fixed}");
        $this->line("  Arrêts inchangés (nom introuvable) : {$unchanged}");

        return self::SUCCESS;
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
