<?php

namespace App\Http\Resources;

use App\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workItemId' => $this->work_item_id,
            'workItemName' => $this->whenLoaded('workItem', fn () => $this->workItem?->name),
            'locationPath' => $this->whenLoaded(
                'workItem',
                fn () => $this->workItem?->relationLoaded('location') ? $this->workItem->location?->path : null
            ),
            // Байршлын зам нь "3 давхар / 3А" гэж эхэлдэг — аль БАРИЛГА
            // гэдгийг агуулдаггүй. 75 барилгын жагсаалтад тэр нь хангалтгүй.
            'blockName' => $this->whenLoaded(
                'workItem',
                fn () => $this->workItem?->relationLoaded('block') ? $this->workItem->block?->name : null
            ),
            'category' => $this->category,
            'categoryLabel' => Issue::CATEGORY_LABELS[$this->category] ?? $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'description' => $this->description,
            'reportedBy' => $this->whenLoaded('reportedBy', fn () => $this->reportedBy?->name),
            'createdAt' => $this->created_at?->toIso8601String(),
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
