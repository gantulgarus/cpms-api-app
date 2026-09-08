<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'groupId' => $this->work_type_group_id,
            'groupName' => $this->whenLoaded('group', fn () => $this->group?->name),
            'name' => $this->name,
            'code' => $this->code,
            'unit' => $this->unit,
            'level' => $this->level,
            'sequenceNumber' => (int) $this->sequence_number,
            // Ашиглагдаж эхэлсэн эсэх — дэлгэц "устгах" товчийг унтраахад
            // хэрэглэнэ. Сервер ямар ч байсан 409 буцаана.
            'inUse' => $this->when(isset($this->work_items_count), fn () => $this->work_items_count > 0),
        ];
    }
}
