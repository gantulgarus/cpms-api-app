<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\WorkItem;
use Illuminate\Validation\Validator;

/**
 * Checklist-ийн хаалт.
 *
 * Захиалагчийн дүрэм: "Зөвхөн зураг дээр үндэслэн ажил батлахгүй."
 * Одоог хүртэл манай систем зураг + тоо хэмжээгээр батлуулж байсан — энэ
 * класс тэр нүхийг хаана.
 *
 * ХОЁР ШИЙДВЭР ЭНД ТАЙЛБАРЛАГДАВ:
 *
 * 1. Checklist нь ЗӨВХӨН загвар тохируулсан ажлын төрөлд заавал болно.
 *    Бүх 47 төрөлд шууд заавал болговол загвар бичигдээгүй байхад БҮХ
 *    баталгаажуулалт зогсоно — өнөөдөр ажиллаж байгаа талбайг гацаана.
 *    Тиймээс ерөнхий инженер загвар бичсэн газартаа хаалт үүснэ.
 *
 * 2. Заавал зүйл "тэнцээгүй" бол БАТЛАХ боломжгүй, харин ТАТГАЛЗАХ боломжтой.
 *    Ингэснээр checklist нь зүгээр нэг маягт биш, шийдвэрт нөлөөлнө.
 */
class ChecklistGate
{
    /**
     * Шалгалт хийхийн өмнө checklist-ийн хариултыг шалгана.
     *
     * @param  array<int, array{itemId?: string, result?: string, note?: string}>  $answers
     */
    public function validate(Validator $validator, WorkItem $workItem, string $result, array $answers): void
    {
        $template = ChecklistTemplate::resolveFor($workItem);

        if (! $template) {
            return; // Загвар тохируулаагүй төрөл — хаалт үүсэхгүй.
        }

        $items = $template->items;
        $byId = $items->keyBy('id');
        $given = collect($answers)->keyBy(fn ($a) => $a['itemId'] ?? '');

        // 1. Танигдахгүй зүйл илгээсэн эсэх — өөр загварын хариулт орж ирвэл
        //    хариулт нь буруу ажилд хадгалагдана.
        foreach ($given->keys() as $id) {
            if ($id === '' || ! $byId->has($id)) {
                $validator->errors()->add('checklist', 'Чанарын хуудсанд байхгүй зүйл илгээсэн байна.');

                return;
            }
        }

        // 2. Заавал зүйл бүр хариултай байх ёстой.
        $missing = $items
            ->where('is_required', true)
            ->reject(fn (ChecklistItem $item) => $given->has($item->id))
            ->pluck('text');

        if ($missing->isNotEmpty()) {
            $validator->errors()->add(
                'checklist',
                'Чанарын хуудас дутуу: '.$missing->implode(', ')
            );

            return;
        }

        // 3. Батлахын тулд заавал зүйл бүр тэнцсэн байх ёстой.
        if ($result !== 'accepted') {
            return;
        }

        $failed = $items
            ->where('is_required', true)
            ->filter(fn (ChecklistItem $item) => ($given[$item->id]['result'] ?? null) === 'fail')
            ->pluck('text');

        if ($failed->isNotEmpty()) {
            $validator->errors()->add(
                'checklist',
                'Тэнцээгүй зүйл байхад батлах боломжгүй: '.$failed->implode(', ')
                .'. Татгалзах эсвэл хэсэгчлэн батлана уу.'
            );
        }
    }

    /** Энэ ажилд checklist шаардлагатай юу — дэлгэц урьдчилж мэдэхэд. */
    public function templateFor(WorkItem $workItem): ?ChecklistTemplate
    {
        return ChecklistTemplate::resolveFor($workItem);
    }
}
