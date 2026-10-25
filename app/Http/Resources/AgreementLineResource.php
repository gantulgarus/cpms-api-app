<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgreementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workTypeId' => $this->work_type_id,
            'label' => $this->label ?? $this->whenLoaded('workType', fn () => $this->workType->name),
            'unit' => $this->unit,
            'quantity' => (float) $this->quantity,
            'unitPrice' => (float) $this->unit_price,
            'budgetUnitPrice' => $this->budget_unit_price !== null
                ? (float) $this->budget_unit_price
                : null,
            // Дүнг СЕРВЕР бодно — дэлгэц дахин бодвол нийлбэр зөрнө.
            'amount' => $this->amount,
            'budgetAmount' => $this->budget_amount,
            'isExtra' => (bool) $this->is_extra,
        ];
    }
}
