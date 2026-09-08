<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'projectId' => $this->project_id,
            'designId' => $this->block_design_id,
            'name' => $this->name,
            'buildingNo' => $this->building_no,
            'purpose' => $this->purpose,
            'floors' => $this->floors,
            'unitsPerFloor' => $this->units_per_floor,
            'unitCount' => $this->unit_count,
            'startDate' => $this->start_date?->toDateString(),
            'taktDays' => $this->takt_days,
            'status' => $this->status,
        ];
    }
}
