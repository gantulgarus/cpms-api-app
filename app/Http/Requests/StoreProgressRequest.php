<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Явц бүртгэх валидаци.
 *
 * v1-д 0–100 хязгаар шалгагддаггүй байсан тул -50 эсвэл 500 хувь илгээгдэж
 * болдог байв. Энд тоо хэмжээ үлдэгдлээс хэтрэхийг серверт таслана.
 */
class StoreProgressRequest extends FormRequest
{
    /**
     * Зөвхөн талбайн инженер, туслан гүйцэтгэгч, ТМ гүйцэтгэл мэдээлнэ.
     *
     * Гүйцэтгэгч зөвхөн ӨӨРИЙН ажлыг бүртгэнэ. Үүнийг middleware мөн шалгадаг
     * ч энд давхарлав: эрхийн шалгалт нь бүртгэл хийгдэх яг тэр цэгт байх
     * ёстой — маршрутын тохиргоо санамсаргүй өөрчлөгдвөл нүх үүсэхгүй.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->canReportProgress()) {
            return false;
        }

        $workItem = $this->route('workItem');

        if ($user->isContractorRep() && $workItem !== null) {
            return $workItem->contractor_id === $user->contractor_id;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'completedQty' => ['required', 'numeric', 'gt:0'],
            'workersCount' => ['nullable', 'integer', 'min:0', 'max:999'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'recordedAt' => ['nullable', 'date', 'before_or_equal:now'],
            'photoIds' => ['nullable', 'array'],
            'photoIds.*' => ['uuid'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $workItem = $this->route('workItem');
                $qty = (float) $this->input('completedQty');

                /*
                 * `reported_qty` нь ТАТГАЛЗСАН хэмжээг хассан цэвэр дүн
                 * (`WorkItem::recalculate()`). Тиймээс бүхэлд нь татгалзсан
                 * ажил дээр үлдэгдэл нь бүтэн болж, гүйцэтгэгч засвараа
                 * дахин мэдээлэх боломжтой болно.
                 */
                $remaining = round(
                    (float) $workItem->planned_qty - (float) $workItem->reported_qty,
                    3
                );

                if ($qty > $remaining + 0.001) {
                    $validator->errors()->add(
                        'completedQty',
                        "Үлдэгдэл {$remaining} {$workItem->unit} — түүнээс их байж болохгүй."
                    );
                }
            },
        ];
    }
}
