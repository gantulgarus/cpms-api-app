<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workItemId' => $this->work_item_id,
            'stage' => $this->stage,
            'result' => $this->result,
            'acceptedQty' => (float) $this->accepted_qty,
            'rejectedQty' => (float) $this->rejected_qty,
            'reason' => $this->reason,
            'inspectedAt' => $this->inspected_at?->toIso8601String(),
            'inspector' => $this->whenLoaded('inspector', fn () => $this->inspector ? [
                'id' => $this->inspector->id,
                'name' => $this->inspector->name,
            ] : null),

            // Чанарын хуудасны хариулт нь баримт — түүхэнд харагдана.
            'checklist' => $this->whenLoaded(
                'checklistAnswers',
                fn () => $this->checklistAnswers->map(fn ($a) => [
                    'itemId' => $a->checklist_item_id,
                    'text' => $a->item?->text,
                    'result' => $a->result,
                    'note' => $a->note,
                ])->values()
            ),
        ];
    }
}
