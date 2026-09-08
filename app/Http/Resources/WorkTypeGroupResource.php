<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkTypeGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sequenceNumber' => (int) $this->sequence_number,
            'buildOrder' => (int) $this->build_order,
            'workTypeCount' => $this->when(
                isset($this->work_types_count),
                fn () => (int) $this->work_types_count
            ),
        ];
    }
}
