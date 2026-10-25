<?php

namespace App\Http\Resources;

use App\Models\Agreement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Бүх ДҮН нь серверээс ирнэ.
 *
 * Дэлгэц тоо хэмжээ × үнэ гэж дахин бодвол бөөрөнхийлөл зөрч, гэрээний нийт
 * дүн нь мөрүүдийн нийлбэртэй таарахаа болино — төлбөрийн баримтад тэр нь
 * хүлээн зөвшөөрөгдөхгүй.
 */
class AgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'blockId' => $this->block_id,
            'blockName' => $this->whenLoaded('block', fn () => $this->block->name),
            'contractorId' => $this->contractor_id,
            'contractorName' => $this->whenLoaded('contractor', fn () => $this->contractor->name),
            'contractorRegistrationNo' => $this->whenLoaded(
                'contractor',
                fn () => $this->contractor->registration_no,
            ),
            'scope' => $this->scope,

            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),

            'retentionPercent' => (float) $this->retention_percent,
            'barterPercent' => (float) $this->barter_percent,
            'cashPercent' => (float) $this->cash_percent,
            'barterAmount' => $this->barter_amount !== null ? (float) $this->barter_amount : null,
            'barterNote' => $this->barter_note,

            'warrantyYears' => (int) $this->warranty_years,
            'maintenanceMonths' => (int) $this->maintenance_months,
            'workDaysPerWeek' => $this->work_days_per_week,
            'restDay' => $this->rest_day,
            'headcount' => $this->headcount,

            'status' => $this->status,
            'statusLabel' => Agreement::STATUSES[$this->status] ?? $this->status,
            'isEditable' => $this->isEditable(),

            'totalAmount' => $this->whenLoaded('lines', fn () => $this->total_amount),
            'budgetAmount' => $this->whenLoaded('lines', fn () => $this->budget_amount),
            /*
             * Төсвөөс хэдэн хувиар зөрсөн — эерэг нь ДЭЭШ гэрээлсэн.
             *
             * Үүнийг харуулахгүй бол 77 саяын ажил 90 саяар гэрээлэгдээд
             * хэн ч мэдэхгүй.
             */
            'variancePercent' => $this->whenLoaded('lines', fn () => $this->variance_percent),
            'retentionAmount' => $this->whenLoaded('lines', fn () => $this->retention_amount),

            'createdBy' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'approvedBy' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,

            'lines' => AgreementLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
