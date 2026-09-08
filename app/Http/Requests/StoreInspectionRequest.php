<?php

namespace App\Http\Requests;

use App\Models\ChecklistItem;
use App\Models\Inspection;
use App\Services\ChecklistGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Шалгалтын валидаци.
 *
 * Татгалзах эсвэл хэсэгчлэн батлахад шалтгаан заавал — захиалагч "баримт нь
 * байх хэрэгтэй" гэж тусгайлан шаардсан.
 */
class StoreInspectionRequest extends FormRequest
{
    /**
     * Зөвхөн хяналтын эрхтэй хүн, БӨГӨӨД өөрийн мэдээлсэн ажлыг биш.
     *
     * Мэдээлэгч ба батлагч нэг хүн байвал баталгаажуулалт утгагүй болно —
     * захиалагч яг үүнээс болж "баримт нь байх хэрэгтэй" гэж шаардсан.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->canInspect()) {
            return false;
        }

        $workItem = $this->route('workItem');

        // Хянагдаагүй явцын дундаас өөрийнх нь мэдээлсэн бичлэг байвал хориглоно.
        return ! $workItem->progressEntries()
            ->where('reported_by_id', $user->id)
            ->where('recorded_at', '>=', $workItem->inspections()->max('inspected_at') ?? '1970-01-01')
            ->exists();
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Өөрийн мэдээлсэн ажлыг өөрөө батлах боломжгүй.');
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(Inspection::STAGES)],
            'result' => ['required', Rule::in(Inspection::RESULTS)],
            'acceptedQty' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf(
                fn () => in_array($this->input('result'), ['rejected', 'partial'], true)
            )],

            // Чанарын хуудас. Бүтцийг энд, ДҮРМИЙГ `ChecklistGate`-д шалгана.
            'checklist' => ['nullable', 'array'],
            'checklist.*.itemId' => ['required', 'uuid'],
            'checklist.*.result' => ['required', Rule::in(ChecklistItem::RESULTS)],
            'checklist.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $workItem = $this->route('workItem');
                $pending = (float) $workItem->reported_qty - (float) $workItem->accepted_qty;
                $accepted = (float) $this->input('acceptedQty');

                if ($accepted > $pending + 0.001) {
                    $validator->errors()->add(
                        'acceptedQty',
                        "Шалгах ажил {$pending} {$workItem->unit} — түүнээс их батлах боломжгүй."
                    );
                }
            },

            // "Зөвхөн зураг дээр үндэслэн ажил батлахгүй" — чанарын хуудас
            // тохируулсан ажлын төрөлд заавал бөглөгдөнө.
            function (Validator $validator) {
                app(ChecklistGate::class)->validate(
                    $validator,
                    $this->route('workItem'),
                    (string) $this->input('result'),
                    (array) $this->input('checklist', []),
                );
            },
        ];
    }
}
