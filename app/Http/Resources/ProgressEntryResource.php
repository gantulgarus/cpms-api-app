<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workItemId' => $this->work_item_id,
            'completedQty' => (float) $this->completed_qty,
            'workersCount' => $this->workers_count,
            'remarks' => $this->remarks,
            'recordedAt' => $this->recorded_at?->toIso8601String(),
            // Тухайн мэдээлэлд хавсаргасан зураг — түүхэн жагсаалт дээр
            // гүйцэтгэл бүрийн ДООР нь харагдана.
            'photos' => PhotoResource::collection($this->whenLoaded('photos')),
            'photoCount' => $this->whenCounted('photos'),
            'reportedBy' => $this->whenLoaded('reportedBy', fn () => $this->reportedBy ? [
                'id' => $this->reportedBy->id,
                'name' => $this->reportedBy->name,
                'role' => $this->reportedBy->role,
            ] : null),
        ];
    }
}
