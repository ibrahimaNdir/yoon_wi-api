<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
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
            'short_name' => $this->short_name,
            'long_name' => $this->long_name,
            'mode' => $this->mode?->value,
            'agency' => $this->whenLoaded('agency', fn () => [
                'id' => $this->agency->id,
                'name' => $this->agency->name,
            ]),
        ];
    }
}
