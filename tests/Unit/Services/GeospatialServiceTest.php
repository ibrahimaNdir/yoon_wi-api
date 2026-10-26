<?php

namespace Tests\Unit\Services;

use App\Services\GeospatialService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class GeospatialServiceTest extends TestCase
{
    private GeospatialService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GeospatialService;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_find_nearby_stops_retourne_liste_triee_par_distance(): void
    {
        $row1 = (object) [
            'id' => 1,
            'name' => 'Arret A',
            'lat' => 14.7167,
            'lng' => -17.4677,
            'distance_m' => 50.0,
        ];
        $row2 = (object) [
            'id' => 2,
            'name' => 'Arret B',
            'lat' => 14.7200,
            'lng' => -17.4600,
            'distance_m' => 200.0,
        ];

        DB::shouldReceive('select')->once()->andReturn([$row1, $row2]);

        $result = $this->service->findNearbyStops(14.7167, -17.4677, 500);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('Arret A', $result[0]['name']);
        $this->assertSame(50.0, $result[0]['distance_m']);
        $this->assertSame(2, $result[1]['id']);
        $this->assertSame(200.0, $result[1]['distance_m']);
    }

    public function test_find_nearby_stops_retourne_tableau_vide_sans_resultats(): void
    {
        DB::shouldReceive('select')->once()->andReturn([]);

        $result = $this->service->findNearbyStops(14.7167, -17.4677, 500);

        $this->assertSame([], $result);
    }

    public function test_clean_stop_name_enleve_prefixe_gare(): void
    {
        $method = new \ReflectionMethod(GeospatialService::class, 'cleanStopName');
        $method->setAccessible(true);

        $result = $method->invoke(new GeospatialService, 'GARE LAT DIOR');

        $this->assertSame('LAT DIOR', $result);
    }

    public function test_clean_stop_name_enleve_prefixe_arret(): void
    {
        $method = new \ReflectionMethod(GeospatialService::class, 'cleanStopName');
        $method->setAccessible(true);

        $result = $method->invoke(new GeospatialService, 'ARRET Avenue Bourguiba');

        $this->assertSame('Avenue Bourguiba', $result);
    }

    public function test_clean_stop_name_enleve_prefixe_terminus(): void
    {
        $method = new \ReflectionMethod(GeospatialService::class, 'cleanStopName');
        $method->setAccessible(true);

        $result = $method->invoke(new GeospatialService, 'TERMINUS liberte 5');

        $this->assertSame('liberte 5', $result);
    }

    public function test_fix_aftu_coords_retourne_tableau_vide_sans_agence_aftu(): void
    {
        DB::statement('CREATE TABLE agencies (id INTEGER PRIMARY KEY, name TEXT)');

        $result = $this->service->fixAftuCoords();

        $this->assertSame(['total' => 0, 'fixed' => 0, 'unchanged' => 0], $result);
    }

    public function test_generate_walk_links_insere_liaisons_dans_les_deux_sens(): void
    {
        $pair = (object) [
            'from_stop_id' => 1,
            'to_stop_id' => 2,
            'distance_m' => 100.0,
        ];

        $queryBuilder = Mockery::mock();
        $queryBuilder->shouldReceive('truncate')->once()->andReturnNull();
        $queryBuilder->shouldReceive('insert')->once()->withArgs(function ($batch) {
            $this->assertCount(2, $batch); // A->B et B->A
            $this->assertSame(1, $batch[0]['from_stop_id']);
            $this->assertSame(2, $batch[0]['to_stop_id']);
            $this->assertSame(2, $batch[1]['from_stop_id']);
            $this->assertSame(1, $batch[1]['to_stop_id']);

            return true;
        });

        DB::shouldReceive('table')->with('walk_links')->andReturn($queryBuilder);
        DB::shouldReceive('select')->once()->andReturn([$pair]);

        $count = $this->service->generateWalkLinks();

        $this->assertSame(2, $count);
    }

    public function test_generate_walk_links_retourne_zero_sans_paires_proches(): void
    {
        $queryBuilder = Mockery::mock();
        $queryBuilder->shouldReceive('truncate')->once()->andReturnNull();
        $queryBuilder->shouldNotReceive('insert');

        DB::shouldReceive('table')->with('walk_links')->andReturn($queryBuilder);
        DB::shouldReceive('select')->once()->andReturn([]);

        $count = $this->service->generateWalkLinks();

        $this->assertSame(0, $count);
    }
}
