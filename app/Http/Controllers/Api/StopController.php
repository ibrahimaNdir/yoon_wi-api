<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StopResource;
use App\Models\Stop;
use App\Services\GeospatialService;
use Illuminate\Http\Request;

class StopController extends Controller
{
    private GeospatialService $geospatialService;

    /**
     * Créer une nouvelle instance du contrôleur.
     */
    public function __construct(GeospatialService $geospatialService)
    {
        $this->geospatialService = $geospatialService;
    }

    /**
     * Afficher la liste de tous les arrêts.
     */
    public function index()
    {
        $stops = Stop::orderBy('name')->get();

        return StopResource::collection($stops);
    }

    /**
     * Trouver les arrêts proches d'une position géographique.
     */
    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['sometimes', 'numeric', 'min:1', 'max:5000'],
        ]);

        $results = $this->geospatialService->findNearbyStops(
            (float) $validated['lat'],
            (float) $validated['lng'],
            (int) $validated['radius'] ?? 500
        );

        return response()->json($results);
    }

    /**
     * Afficher le détail d'un arrêt et ses lignes.
     */
    public function show(Stop $stop)
    {
        $stop->load('routes');

        return new StopResource($stop);
    }
}
