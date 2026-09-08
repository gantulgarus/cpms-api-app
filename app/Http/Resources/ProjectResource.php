<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->company_id,
            'companyName' => $this->whenLoaded('company', fn () => $this->company?->name),
            'name' => $this->name,
            'code' => $this->code,
            'location' => $this->location,
            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'status' => $this->status,
        ];
    }
}
