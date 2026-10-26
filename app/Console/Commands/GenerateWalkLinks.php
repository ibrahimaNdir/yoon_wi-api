<?php

namespace App\Console\Commands;

use App\Services\GeospatialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('generate:walk-links')]
#[Description('Génère les correspondances piétonnes entre arrêts proches (≤ 300 m).')]
class GenerateWalkLinks extends Command
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
        $count = $this->geospatialService->generateWalkLinks();

        $this->info('Liaisons piétonnes créées : '.$count);

        return self::SUCCESS;
    }
}
