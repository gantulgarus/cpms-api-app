<?php

namespace App\Services;

use App\Models\Block;
use App\Models\MaterialBudgetItem;
use Illuminate\Support\Facades\DB;

/**
 * Блокт ОРСОН төсвийг харах.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: төсөв импортлогдсоны дараа хүн түүнийг бүхэлд
 * нь харах газар байхгүй байв. Ажлын үнэ зөвхөн тохиролцоо бичих
 * үед, материал зөвхөн үлдэгдэл хэлбэрээр харагддаг. «Би юу
 * оруулсан бэ» гэдэг нь системийн суурь асуулт.
 *
 * АЖЛЫН ТӨРЛӨӨР НЭГТГЭНЭ, ажил тус бүрээр биш: А1-д 1,991 ажил бий.
 * Тэднийг жагсаах нь төсөв биш, жагсаалт болно. Төсөв нь «шавардлага
 * нийт 4,320 м², 12,000₮, 51.8 сая» гэсэн түвшинд уншигддаг.
 */
class BlockBudgetView
{
    /**
     * @return array<string, mixed>
     */
    public function build(Block $block): array
    {
        return [
            'work' => $this->work($block),
            'materials' => $this->materials($block),
        ];
    }

    /**
     * Ажлын хөлсний төсөв — ажлын ТӨРЛӨӨР.
     *
     * @return array<int, array<string, mixed>>
     */
    private function work(Block $block): array
    {
        $rows = DB::table('work_items as wi')
            ->join('work_types as wt', 'wt.id', '=', 'wi.work_type_id')
            ->leftJoin('work_type_groups as g', 'g.id', '=', 'wt.work_type_group_id')
            ->where('wi.block_id', $block->id)
            ->groupBy('wt.id', 'wt.code', 'wt.name', 'wt.level', 'wi.unit', 'g.name', 'g.build_order')
            ->orderBy('g.build_order')
            ->orderBy('wt.code')
            ->select([
                'wt.id as work_type_id',
                'wt.code',
                'wt.name',
                'wt.level',
                'wi.unit',
            ])
            ->selectRaw("coalesce(g.name, 'Бусад') as group_name")
            ->selectRaw('count(*) as works')
            ->selectRaw('sum(wi.planned_qty) as planned_qty')
            ->selectRaw('sum(wi.accepted_qty) as accepted_qty')
            /*
             * Нэгж үнэ нь ажлын төрөл бүрт НЭГ байх ёстой — төсөв
             * ингэж бичигддэг. `max` нь зөвхөн хамгаалалт: хэрэв
             * ямар нэг шалтгаанаар зөрсөн бол доорх `priceConflict`
             * түүнийг хэлнэ.
             */
            ->selectRaw('max(wi.budget_unit_price) as unit_price')
            ->selectRaw('min(wi.budget_unit_price) as min_price')
            ->selectRaw('sum(case when wi.budget_unit_price is null then 1 else 0 end) as without_price')
            ->selectRaw('sum(coalesce(wi.budget_unit_price, 0) * wi.planned_qty) as amount')
            ->get();

        return $rows->map(fn ($r) => [
            'workTypeId' => $r->work_type_id,
            'code' => $r->code,
            'name' => $r->name,
            'groupName' => $r->group_name,
            'level' => $r->level,
            'unit' => $r->unit,
            'works' => (int) $r->works,
            'plannedQty' => round((float) $r->planned_qty, 3),
            'acceptedQty' => round((float) $r->accepted_qty, 3),
            'unitPrice' => $r->unit_price !== null ? round((float) $r->unit_price, 2) : null,
            'amount' => round((float) $r->amount, 2),

            /*
             * НЭГ ТӨРӨЛД ХОЁР ӨӨР ҮНЭ байвал хэлнэ.
             *
             * Төсөв дээр ийм зүйл гарах ёсгүй. Гарсан бол аль нь зөв
             * болохыг хүн шийдэх хэрэгтэй — дүн нь хамгийн өндрөөр
             * бодогдож байгааг мэдэх ёстой.
             */
            'priceConflict' => $r->unit_price !== null
                && $r->min_price !== null
                && (float) $r->unit_price !== (float) $r->min_price,

            /** Үнэгүй ажлууд — тэдний дүн 0-оор тооцогдсон. */
            'worksWithoutPrice' => (int) $r->without_price,
        ])->all();
    }

    /**
     * Материалын төсөв — байршлын задаргаатай.
     *
     * @return array<int, array<string, mixed>>
     */
    private function materials(Block $block): array
    {
        $items = MaterialBudgetItem::with(['material', 'quantities.location', 'workType'])
            ->where('block_id', $block->id)
            ->get()
            ->sortBy(fn ($i) => $i->material?->code ?? '', SORT_NATURAL)
            ->values();

        return $items->map(fn (MaterialBudgetItem $item) => [
            'id' => $item->id,
            'code' => $item->material?->code,
            'name' => $item->material?->name,
            'grade' => $item->material?->grade,
            'unit' => $item->material?->unit,
            'workLabel' => $item->work_label,
            'workTypeName' => $item->workType?->name,
            'totalQty' => (float) $item->total_qty,
            'actualUnitPrice' => $item->actual_unit_price,
            'marketUnitPrice' => $item->market_unit_price,
            'actualAmount' => $item->actualAmount(),
            'marketAmount' => $item->marketAmount(),
            'variancePercent' => $item->variancePercent(),

            /*
             * БАЙРШЛЫН ЗАДАРГАА — энэ нь материалын төсвийн онцлог.
             *
             * Инженер аль хэдийн давхраар бодсон байдаг. Нийт дүнг л
             * харуулбал тэр ажил алдагдана: «5-р давхарт хэдэн шоо
             * бетон вэ» гэдэг нь захиалга бичихэд хэрэгтэй тоо.
             */
            'locations' => $item->quantities
                ->sortBy(fn ($q) => $q->location?->sequence_number ?? 0)
                ->map(fn ($q) => [
                    'name' => $q->location?->name ?? '—',
                    'qty' => (float) $q->quantity,
                ])
                ->values()
                ->all(),
        ])->all();
    }
}
