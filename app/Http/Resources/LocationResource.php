<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blockId' => $this->block_id,
            'parentId' => $this->parent_id,
            'level' => $this->level,
            'name' => $this->name,
            'path' => $this->path,
            'sequenceNumber' => $this->sequence_number,
        ];
    }
}
