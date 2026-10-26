<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class MapboxService
{
    private array $geocodeCache = [];

    private int $geocodeCount = 0;

    private string $cachePath;

    /**
     * Créer une nouvelle instance du service.
     *
     * @param  string|null  $cachePath  Chemin du fichier de cache (défaut: storage/app/data/geocode_cache.json)
     */
    public function __construct(?string $cachePath = null)
    {
        $this->cachePath = $cachePath ?? storage_path('app/data/geocode_cache.json');
        $this->loadGeocodeCache();
    }

    /**
     * Géocoder un nom d'arrêt via l'API Mapbox (avec cache local).
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $stopName): ?array
    {
        // --- Vérifier le cache de géocodage local ---
        if (isset($this->geocodeCache[$stopName])) {
            $coords = $this->geocodeCache[$stopName];
            $lat = $coords['lat'] ?? $coords[1] ?? null;
            $lng = $coords['lng'] ?? $coords[0] ?? null;

            if ($lat !== null && $lng !== null) {
                return ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }

        // --- Appel API Mapbox ---
        $geocoded = $this->callMapbox($stopName);
        if ($geocoded === null) {
            return null;
        }

        $this->geocodeCache[$stopName] = $geocoded;
        $this->geocodeCount++;

        // Rate limiting : 200 ms entre chaque appel pour respecter le quota Mapbox gratuit
        usleep(200000);

        return $geocoded;
    }

    /**
     * Sauvegarder le cache de géocodage sur disque.
     */
    public function saveCache(): void
    {
        file_put_contents($this->cachePath, json_encode($this->geocodeCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Récupérer le nombre d'appels Mapbox effectués.
     */
    public function getGeocodeCount(): int
    {
        return $this->geocodeCount;
    }

    /**
     * Appeler l API Mapbox Geocoding pour un nom d'arrêt.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function callMapbox(string $stopName): ?array
    {
        $token = config('services.mapbox.token', env('MAPBOX_TOKEN'));
        if (empty($token)) {
            return null;
        }

        // 1. Dictionnaire de traduction pour les cas particuliers
        $translations = [
            'PNR' => 'Passage à Niveau de Rufisque',
            'AIBD' => 'Aéroport International Blaise Diagne',
            'Baux Maraîchers' => 'Gare des Baux Maraîchers',
        ];

        $searchName = $translations[$stopName] ?? $stopName;

        // 2. On ajoute "Dakar, Sénégal" pour contextualiser les noms de rues génériques
        if (! str_contains(strtolower($searchName), 'dakar') && ! str_contains(strtolower($searchName), 'aéroport')) {
            $searchName .= ', Dakar, Sénégal';
        }

        $encoded = rawurlencode($searchName);
        $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$encoded}.json?access_token={$token}&limit=1&country=SN&language=fr";

        try {
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $features = Arr::get($data, 'features', []);

            if (empty($features)) {
                return null;
            }

            $center = Arr::get($features[0], 'center', []);
            if (count($center) < 2) {
                return null;
            }

            // Mapbox retourne [longitude, latitude]
            $lng = (float) $center[0];
            $lat = (float) $center[1];

            return ['lat' => $lat, 'lng' => $lng];
        } catch (\Throwable $e) {
            return null;
        }
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
}
