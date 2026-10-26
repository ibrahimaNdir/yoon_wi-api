<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StopResource extends JsonResource
{
    /**
     * Transformer la ressource en un tableau.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_station' => $this->is_station,
            'verified_at' => $this->verified_at?->toISOString(),
            'routes' => RouteResource::collection($this->whenLoaded('routes')),
        ];
    }
}
