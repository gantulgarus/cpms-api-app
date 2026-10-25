<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExtensionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workItemId' => $this->work_item_id,
            'workItemName' => $this->whenLoaded('workItem', fn () => $this->workItem->name),
            'locationPath' => $this->whenLoaded(
                'workItem',
                fn () => $this->workItem->location?->path,
            ),

            'currentEndDate' => $this->current_end_date?->toDateString(),
            'requestedEndDate' => $this->requested_end_date->toDateString(),
            // Хоногийг СЕРВЕР бодно — дэлгэц хоёр огноог хасахгүй.
            'requestedDays' => $this->requested_days,

            'category' => $this->category,
            'reason' => $this->reason,
            'status' => $this->status,

            'requestedBy' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null),
            'decidedBy' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ] : null),
            'decidedAt' => $this->decided_at?->toIso8601String(),
            'decisionNote' => $this->decision_note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
