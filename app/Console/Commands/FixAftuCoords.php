<?php

namespace App\Console\Commands;

use App\Services\GeospatialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fix:aftu-coords {--dry-run : Simule sans écrire en base de données}')]
#[Description('Corrige les coordonnées par défaut des arrêts AFTU via une recherche floue de nom.')]
class FixAftuCoords extends Command
{
    private GeospatialService $geospatialService;

    /**
     * Créer une nouvelle instance de la commande.
     */
    public function __construct(GeospatialService $geospatialService)
    {
        parent::__construct();
        $this->geospatialService = $geospatialService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $result = $this->geospatialService->fixAftuCoords($dryRun);

        $this->line('');
        $this->info('Rapport fix:aftu-coords :');
        $this->line("  Arrêts AFTU avec coord. par défaut : {$result['total']}");
        $this->line("  Arrêts corrigés (nom similaire)    : {$result['fixed']}");
        $this->line("  Arrêts inchangés (nom introuvable) : {$result['unchanged']}");

        return self::SUCCESS;
    }
}
