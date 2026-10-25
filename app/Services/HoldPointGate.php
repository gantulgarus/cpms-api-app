<?php

namespace App\Services;

use App\Models\Location;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/**
 * Шалгалтын цэг — батлагдаагүй далд ажил дараагийнхаа замыг хаана.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: хоёр шаттай баталгаажуулалт байгаа ч дараагийн ажил
 * зогсдоггүй бол тэр нь хоосон хэлбэр. Гүйцэтгэгч арматураа шалгуулалгүй
 * бетоноо цутгачихвал хяналтын инженер дараа нь юу ч шалгаж чадахгүй —
 * ажил нь бетон дотор. Шалгалт нь ХОЖИМ биш, ТЭР ҮЕД нь хийгдэх ёстой.
 *
 * ДҮРЭМ: ажлын төрөл `is_hold_point` гэж тэмдэглэгдсэн бөгөөд
 *
 *   · тухайн байршилд ЭСВЭЛ түүний ДЭЭД байршилд байгаа
 *     (давхрын цахилгааны шугам нь тэр давхрын айл бүрийн шавардлагыг хаана),
 *   · бүлгийн `build_order` нь шинэ ажлынхаас БАГА (өмнө хийгдэх ёстой),
 *   · тоо хэмжээтэй (`planned_qty > 0`),
 *   · бөгөөд БҮРЭН ДУУСААГҮЙ (`status <> 'completed'`)
 *
 * бол гүйцэтгэл мэдээлэхийг хориглоно.
 *
 * ЯАГААД `review_state = 'approved'` БИШ ВЭ: тэр төлөв нь «хүлээж байгаа
 * юм алга» гэсэн утгатай, «ажил дууссан» гэсэн утгатай БИШ. 100 м шугамын
 * 60-ыг мэдээлээд бүгдийг батлуулахад төлөв нь `approved` болно — гэвч
 * үлдсэн 40 м нь тавигдаагүй. Тэр хэсгийг шавардлагаар дарвал яг тэр
 * хориг хамгаалах гэж байсан зүйл болно.
 *
 * ХЯЗГААРЛАЛТ: энэ нь жинхэнэ хамаарлын сүлжээ (R1-18) БИШ. Бүлгийн
 * дарааллыг ойролцоо дараалал болгон ашиглаж байгаа тул нэг бүлэг доторх
 * алхмуудын хооронд дараалал тогтоохгүй. Тэр нь «ажлын бүрдэл» түвшин
 * нэмэгдсэний дараа хийгдэнэ.
 */
class HoldPointGate
{
    /**
     * Энэ ажлыг хааж буй батлагдаагүй шалгалтын цэгүүд.
     *
     * @return Collection<int, WorkItem>
     */
    public function blockers(WorkItem $workItem): Collection
    {
        $order = $workItem->workType?->group?->build_order;

        if ($order === null) {
            return collect();
        }

        $location = $workItem->location ?? Location::find($workItem->location_id);

        if (! $location) {
            return collect();
        }

        /*
         * Тухайн байршил БА түүний бүх дээд байршил.
         *
         * `path_key` нь `/блок/давхар/айл/` хэлбэртэй materialized path тул
         * id-уудыг нь салгаад шууд авна — рекурс query хэрэггүй.
         */
        $ancestors = array_values(array_filter(explode('/', $location->path_key)));

        return WorkItem::query()
            ->with(['workType.group', 'location'])
            ->where('block_id', $workItem->block_id)
            ->whereIn('location_id', $ancestors)
            ->where('id', '!=', $workItem->id)
            ->where('planned_qty', '>', 0)
            ->where('status', '!=', 'completed')
            ->whereHas('workType', fn ($q) => $q->where('is_hold_point', true)
                ->whereHas('group', fn ($g) => $g->where('build_order', '<', $order)))
            ->get();
    }

    /**
     * Хориглох шалтгаан — хэрэглэгчид ЯМАР ажил хаасныг нэрлэнэ.
     *
     * «Хориотой» гэж хэлээд орхивол хүн юу хийхээ мэдэхгүй. Хаасан ажлын
     * нэр, байршил, одоогийн төлөвийг нь хэлж байж засах зам гарна.
     */
    public function reason(WorkItem $workItem): ?string
    {
        $blockers = $this->blockers($workItem);

        if ($blockers->isEmpty()) {
            return null;
        }

        /*
         * Хэдэн нэгж үлдснийг хэлнэ.
         *
         * «Батлагдаагүй» гэж орхивол гүйцэтгэгч аль хэдийн батлуулсан гэж
         * бодоод маргана. Үлдэгдлийг нэрлэвэл хэн юу хийх нь тодорхой.
         */
        $names = $blockers
            ->take(3)
            ->map(fn (WorkItem $w) => sprintf(
                '%s (%s) — %s %s үлдсэн',
                $w->name,
                $w->location?->name,
                rtrim(rtrim(number_format($w->remaining_qty, 3, '.', ''), '0'), '.'),
                $w->unit,
            ))
            ->implode(', ');

        $more = $blockers->count() > 3 ? ' ба бусад '.($blockers->count() - 3) : '';

        return "Шалгалтын цэг батлагдаагүй байна: {$names}{$more}. "
            .'Далд хийгдэх ажлыг батлуулсны дараа үргэлжлүүлнэ.';
    }
}
