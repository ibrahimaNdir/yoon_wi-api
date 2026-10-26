<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RouteResource;
use App\Http\Resources\StopResource;
use App\Models\Route;

class RouteController extends Controller
{
    /**
     * Afficher la liste de toutes les lignes de transport.
     */
    public function index()
    {
        $routes = Route::with('agency')->orderBy('short_name')->get();

        return RouteResource::collection($routes);
    }

    /**
     * Afficher les arrêts d'une ligne, triés par ordre de passage.
     */
    public function stops(Route $id)
    {
        $stops = $id->stops()->get();

        return StopResource::collection($stops);
    }
}
