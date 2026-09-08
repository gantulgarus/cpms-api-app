<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Тооцоолсон утга бүр СЕРВЕРЭЭС ирнэ — `remainingQty`, `percentage`,
 * `overdueDays` зэргийг client дахин бодохгүй.
 */
class WorkItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blockId' => $this->block_id,
            'locationId' => $this->location_id,
            'workTypeId' => $this->work_type_id,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,

            'plannedQty' => (float) $this->planned_qty,
            'reportedQty' => (float) $this->reported_qty,
            'acceptedQty' => (float) $this->accepted_qty,
            'remainingQty' => $this->remaining_qty,
            'percentage' => $this->percentage,

            'plannedStartDate' => $this->planned_start_date?->toDateString(),
            'plannedEndDate' => $this->planned_end_date?->toDateString(),
            'status' => $this->status,
            'reviewState' => $this->review_state,
            'overdueDays' => $this->overdue_days,

            // Дахин хийсэн нийт хэмжээ — татгалзсан бүх шалгалтын нийлбэр.
            // Зөвхөн дэлгэрэнгүй хуудсанд ачаалагдана (жагсаалтад N+1 болно).
            'rejectedTotal' => $this->when(
                $this->inspections_sum_rejected_qty !== null,
                fn () => (float) $this->inspections_sum_rejected_qty,
            ),

            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'path' => $this->location->path,
                'level' => $this->location->level,
            ]),
            'workType' => $this->whenLoaded('workType', fn () => [
                'id' => $this->workType->id,
                'name' => $this->workType->name,
                'groupName' => $this->workType->group?->name,
            ]),
            'contractor' => $this->whenLoaded('contractor', fn () => $this->contractor ? [
                'id' => $this->contractor->id,
                'name' => $this->contractor->name,
            ] : null),
        ];
    }
}
