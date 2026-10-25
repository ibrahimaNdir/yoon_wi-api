<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('generate:walk-links')]
#[Description('Génère les correspondances piétonnes entre arrêts proches (≤ 300 m).')]
class GenerateWalkLinks extends Command
{
    private const MAX_DISTANCE_M = 300;

    private const WALK_SPEED_MPS = 1.2;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // --- 1. Vider la table pour repartir de zéro (idempotence) ---
        DB::table('walk_links')->truncate();

        // --- 2. Trouver les paires d'arrêts à moins de 300 m ---
        $pairs = DB::select("
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
        ", ['maxDistance' => self::MAX_DISTANCE_M]);

        // --- 3. Préparer les insertions (les deux sens pour un graphe bidirectionnel) ---
        $batch = [];

        foreach ($pairs as $pair) {
            $distanceM = (int) round((float) $pair->distance_m);
            $walkSeconds = (int) ceil((float) $pair->distance_m / self::WALK_SPEED_MPS);

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

        $this->info('Liaisons piétonnes créées : ' . count($batch));

        return self::SUCCESS;
    }
}
