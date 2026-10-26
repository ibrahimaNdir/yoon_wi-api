<?php

namespace Tests\Unit\Services;

use App\Services\MapboxService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapboxServiceTest extends TestCase
{
    private MapboxService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mapbox.token' => 'fake-mapbox-token']);

        $this->service = new MapboxService($this->tempCachePath());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_geocode_retourne_coordonnees_quand_mapbox_repond_avec_succes(): void
    {
        Http::fake([
            'api.mapbox.com*' => Http::response([
                'features' => [
                    [
                        'center' => [-17.433661, 14.676460], // [lng, lat]
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->geocode('Dakar');

        $this->assertIsArray($result);
        $this->assertSame(14.676460, $result['lat']);
        $this->assertSame(-17.433661, $result['lng']);
    }

    public function test_geocode_retourne_null_quand_mapbox_echec(): void
    {
        Http::fake([
            'api.mapbox.com*' => Http::response([], 500),
        ]);

        $result = $this->service->geocode('Dakar');

        $this->assertNull($result);
    }

    public function test_geocode_retourne_null_quand_mapbox_sans_features(): void
    {
        Http::fake([
            'api.mapbox.com*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $result = $this->service->geocode('Inconnu');

        $this->assertNull($result);
    }

    public function test_geocode_utilise_cache_local_pour_une_meme_gare(): void
    {
        Http::fake([
            'api.mapbox.com*' => Http::response([
                'features' => [
                    [
                        'center' => [-17.433661, 14.676460],
                    ],
                ],
            ], 200),
        ]);

        // Premier appel : fait une requête HTTP
        $result1 = $this->service->geocode('Dakar');
        $this->assertSame(14.676460, $result1['lat']);

        // Deuxième appel : utilise le cache, pas de nouvelle requête HTTP
        $result2 = $this->service->geocode('Dakar');
        $this->assertSame(14.676460, $result2['lat']);
    }

    public function test_geocode_applique_dictionnaire_traduction_pnr(): void
    {
        Http::fake([
            'api.mapbox.com*' => function ($request) {
                // Vérifier que l'URL contient la traduction de PNR, pas "PNR" brut
                $url = (string) $request->url();
                $this->assertStringContainsString('Passage%20%C3%A0%20Niveau%20de%20Rufisque', $url);
                $this->assertStringNotContainsString('PNR', $url);

                return Http::response([
                    'features' => [
                        ['center' => [-17.283919, 14.723070]],
                    ],
                ], 200);
            },
        ]);

        $result = $this->service->geocode('PNR');

        $this->assertSame(14.723070, $result['lat']);
        $this->assertSame(-17.283919, $result['lng']);
    }

    public function test_geocode_ajoute_dakar_senegal_pour_nom_generique(): void
    {
        Http::fake([
            'api.mapbox.com*' => function ($request) {
                $url = (string) $request->url();
                $this->assertStringContainsString('Rue%2010%2C%20Dakar%2C%20S%C3%A9n%C3%A9gal', $url);

                return Http::response([
                    'features' => [
                        ['center' => [-17.441652, 14.700332]],
                    ],
                ], 200);
            },
        ]);

        $result = $this->service->geocode('Rue 10');

        $this->assertSame(14.700332, $result['lat']);
        $this->assertSame(-17.441652, $result['lng']);
    }

    public function test_geocode_retourne_null_quand_token_manquant(): void
    {
        // Service sans token configuré : callMapbox retourne null avant tout appel HTTP.
        $service = new MapboxService($this->tempCachePath());

        $this->assertNull($service->geocode('Dakar'));
    }

    private function tempCachePath(): string
    {
        $path = storage_path('app/data/test_geocode_cache_'.uniqid('', true).'.json');

        if (file_exists($path)) {
            unlink($path);
        }

        return $path;
    }
}
