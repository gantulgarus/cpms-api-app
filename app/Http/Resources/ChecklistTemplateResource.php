<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChecklistTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'workTypeId' => $this->work_type_id,
            'workTypeName' => $this->whenLoaded('workType', fn () => $this->workType?->name),
            'groupId' => $this->work_type_group_id,
            'groupName' => $this->whenLoaded('group', fn () => $this->group?->name),
            'isActive' => (bool) $this->is_active,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'sequenceNumber' => (int) $i->sequence_number,
                'text' => $i->text,
                'guidance' => $i->guidance,
                'isRequired' => (bool) $i->is_required,
            ])->values()),
        ];
    }
}
