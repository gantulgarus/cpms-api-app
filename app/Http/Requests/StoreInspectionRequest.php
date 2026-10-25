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

        if (! $user) {
            return false;
        }

        /*
         * Эрхийг ШАТААР шалгана.
         *
         * 1-р шат (талбайн инженер шалгах) нь `canInspect()`-ээс өргөн:
         * талбайн инженер өөрөө хийнэ, тэр нь батлах эрхтэй хүн БИШ. Урьд
         * нь `canInspect()`-ээр хааж байсан тул хоёр шаттай урсгалд 1-р
         * шатыг хийх хүн олдохгүй, ажил гацах байсан.
         */
        $stage = (string) $this->input('stage');

        if (! in_array($stage, Inspection::STAGES, true)) {
            return true;   // `rules()` нь энэ тохиолдлыг 422-оор барина.
        }

        if (! in_array($user->role, Inspection::STAGE_ROLES[$stage], true)) {
            $this->denial = Inspection::STAGE_LABELS[$stage].' гэсэн шатыг хийх эрх танд байхгүй.';

            return false;
        }

        $workItem = $this->route('workItem');

        // Хянагдаагүй явцын дундаас өөрийнх нь мэдээлсэн бичлэг байвал хориглоно.
        $ownReport = $workItem->progressEntries()
            ->where('reported_by_id', $user->id)
            ->where('recorded_at', '>=', $workItem->inspections()->max('inspected_at') ?? '1970-01-01')
            ->exists();

        if ($ownReport) {
            $this->denial = 'Өөрийн мэдээлсэн ажлыг өөрөө батлах боломжгүй.';

            return false;
        }

        /*
         * Нэг хүн ХОЁР ШАТЫГ ЧЪ хийж болохгүй.
         *
         * Эс бөгөөс каскад нь зүгээр л хоёр товч болно: ерөнхий инженер
         * шалгаад тэр дороо батлахад хоёр шат нэг хүний нэг шийдвэр болж,
         * «мэдээлэх ба батлах эрхийг салгасан» зарчим утгаа алдана.
         */
        if ($stage === Inspection::STAGE_FINAL) {
            $checkedByMe = $workItem->inspections()
                ->where('stage', Inspection::STAGE_SITE)
                ->where('inspector_id', $user->id)
                ->where('accepted_qty', '>', 0)
                ->exists();

            if ($checkedByMe) {
                $this->denial = 'Та энэ ажлыг 1-р шатад шалгасан тул 2-р шатад батлах боломжгүй. '
                    .'Өөр хяналтын инженер батлах шаардлагатай.';

                return false;
            }
        }

        return true;
    }

    /** Татгалзлын тодорхой шалтгаан — «Forbidden» гэдэг нь юу ч хэлэхгүй. */
    private ?string $denial = null;

    protected function failedAuthorization(): void
    {
        abort(403, $this->denial ?? 'Та энэ үйлдлийг хийх эрхгүй.');
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
                $stage = (string) $this->input('stage');

                if (! in_array($stage, Inspection::STAGES, true)) {
                    return;
                }

                /*
                 * ШАТ БҮР ӨӨРИЙН САНГААС ИДНЭ.
                 *
                 *     1-р шат: мэдээлсэн − шалгасан
                 *     2-р шат: ШАЛГАСАН − баталсан
                 *
                 * Урьд нь хоёулаа `мэдээлсэн − баталсан`-аас иддэг байсан тул
                 * талбайн инженер 100 м² шалгахад хяналтын инженерт батлах юу
                 * ч үлдэхгүй, актад орох хэмжээ 0 болдог байв. Шатыг хасах
                 * болсон шалтгаан — үндэс нь ЭНЭ мөр.
                 */
                $pool = $workItem->stagePool($stage);
                $accepted = (float) $this->input('acceptedQty');

                if ($accepted > $pool + 0.001) {
                    $what = $stage === Inspection::STAGE_SITE ? 'шалгах' : 'батлах';

                    $validator->errors()->add(
                        'acceptedQty',
                        $stage === Inspection::STAGE_FINAL && $pool <= 0
                            ? 'Батлах ажил алга: талбайн инженер хараахан шалгаагүй байна.'
                            : "{$what} ажил {$pool} {$workItem->unit} — түүнээс их {$what} боломжгүй."
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
