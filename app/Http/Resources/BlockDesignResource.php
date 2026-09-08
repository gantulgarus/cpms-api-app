<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockDesignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'floors' => $this->floors,
            'unitsPerFloor' => $this->units_per_floor,
            'workTypeCount' => $this->items->count(),
            'estimatedItems' => $this->estimatedItems(),
            // Тоо хэмжээ нь тодорхойгүй ажлын төрөл — үлдэгдэл бодогдохгүй.
            'missingQuantities' => $this->items->whereNull('qty_per_location')->count(),
        ];
    }
}
