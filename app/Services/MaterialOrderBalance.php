<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\MaterialOrder;
use App\Models\MaterialOrderLine;

/**
 * «Энэ блокт төсвөөр хэд байсан, хэд захиалсан, хэд үлдсэн бэ».
 *
 * БЕТОНООС ЯЛГАРАХ НЬ: үлдэгдэл БЛОКООР бодогдоно, байршлаар биш.
 * Пум, хадаас, брусс нь тодорхой давхарт очдоггүй — агуулахад ирээд
 * тарагддаг. Байршлаар бодох нь байхгүй нарийвчлал зохиож, тоог
 * худал болгоно.
 *
 * БЕТОН ЭНД ОРОХГҮЙ: түүнийг `ConcreteOrder` дамжуулдаг бөгөөд
 * үлдэгдэл нь давхраар бодогддог. Хоёр урсгал нэг материалыг хуваалцвал
 * аль нэг нь нөгөөгийнхөө захиалгыг тоолохгүй үлдэж, үлдэгдэл хоёр
 * газар хоёр өөр гарна.
 */
class MaterialOrderBalance
{
    /**
     * Блокийн бүх материалын үлдэгдэл (бетоноос бусад).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBlock(Block $block): array
    {
        $items = MaterialBudgetItem::query()
            ->with('material')
            ->where('block_id', $block->id)
            ->get()
            // Бетоныг хасна — тэр нь бетон захиалгын урсгалд харьяалагдана.
            ->reject(fn ($item) => $item->material?->isConcrete() ?? false);

        if ($items->isEmpty()) {
            return [];
        }

        $ordered = $this->orderedByMaterial($block);

        $rows = $items->map(function (MaterialBudgetItem $item) use ($ordered) {
            $budget = round((float) $item->total_qty, 3);
            $used = round($ordered[$item->material_id] ?? 0.0, 3);

            return [
                'materialId' => $item->material_id,
                'code' => $item->material?->code,
                'name' => $item->material?->label() ?? '—',
                'unit' => $item->material?->unit ?? '',
                'budget' => $budget,
                'ordered' => $used,
                'remaining' => round($budget - $used, 3),
                'overBy' => $used > $budget ? round($used - $budget, 3) : 0.0,
            ];
        })->values()->all();

        usort($rows, fn ($a, $b) => strnatcasecmp((string) $a['code'], (string) $b['code']));

        return $rows;
    }

    /**
     * Нэг материалын үлдэгдэл.
     *
     * @param  string|null  $ignoreOrderId  өөрийгөө тооцохгүй (засварлах үед)
     * @return array{budget: float, ordered: float, remaining: float, overBy: float}
     */
    public function forMaterial(Block $block, string $materialId, ?string $ignoreOrderId = null): array
    {
        $budget = round((float) MaterialBudgetItem::query()
            ->where('block_id', $block->id)
            ->where('material_id', $materialId)
            ->sum('total_qty'), 3);

        $ordered = round($this->orderedByMaterial($block, $ignoreOrderId)[$materialId] ?? 0.0, 3);

        return [
            'budget' => $budget,
            'ordered' => $ordered,
            'remaining' => round($budget - $ordered, 3),
            'overBy' => $ordered > $budget ? round($ordered - $budget, 3) : 0.0,
        ];
    }

    /**
     * Материал бүрээр захиалагдсан хэмжээ.
     *
     * `consumedQty()`-тэй ИЖИЛ дараалал: ирсэн → зөвшөөрсөн → захиалсан.
     * SQL-д бичвэл дүрэм хоёр газар байх тул мөрүүдийг татаад PHP-д
     * нэгтгэнэ — нэг блокийн захиалга хэдэн зуугаас хэтрэхгүй.
     *
     * @return array<string, float>
     */
    private function orderedByMaterial(Block $block, ?string $ignoreOrderId = null): array
    {
        $lines = MaterialOrderLine::query()
            ->with('order:id,status')
            ->whereNotNull('material_id')
            ->whereHas(
                'order',
                fn ($q) => $q->where('block_id', $block->id)
                    ->whereIn('status', MaterialOrder::COMMITTED_STATES)
                    ->when($ignoreOrderId, fn ($qq, $id) => $qq->whereKeyNot($id)),
            )
            ->get();

        $totals = [];

        foreach ($lines as $line) {
            $totals[$line->material_id] = ($totals[$line->material_id] ?? 0.0) + $line->consumedQty();
        }

        return $totals;
    }

    /**
     * Захиалж болох материалууд — ЖАГСААЛТ бэлтгэх дэлгэцэд.
     *
     * Төсөвт байгаа бүх материал (бетоноос бусад). Бланкны урьдчилан
     * хэвлэгдсэн мөрүүдээс өөр: төсөвт байхгүй зүйлийг гараар нэмж
     * болно, харин тэр үед үлдэгдэл харагдахгүй.
     *
     * @return \Illuminate\Support\Collection<int, Material>
     */
    public function orderableMaterials(Block $block)
    {
        return Material::query()
            ->whereIn(
                'id',
                MaterialBudgetItem::query()
                    ->where('block_id', $block->id)
                    ->select('material_id'),
            )
            ->get()
            ->reject(fn (Material $m) => $m->isConcrete())
            ->values();
    }
}
