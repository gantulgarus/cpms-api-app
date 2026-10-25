<?php

namespace App\Services;

use App\Models\Block;
use App\Models\ConcreteOrder;
use App\Models\Location;
use App\Models\Material;
use App\Models\MaterialBudgetQuantity;

/**
 * «Энэ давхарт төсвөөр хэд байсан, хэд захиалсан, хэд үлдсэн бэ».
 *
 * ЭНЭ НЬ ЗАХИАЛГЫН ХУУДСЫГ ЦААСНААС ЯЛГАХ ЦОРЫН ГАГЦ ЗҮЙЛ. Цаасан
 * хуудас үлдэгдлийг хэлж чаддаггүй тул хэтрүүлэн захиалах нь хэвийн
 * үзэгдэл болдог — илүү ирсэн бетоныг буцаах аргагүй, хатна.
 *
 * ТӨСӨВ НЬ БАЙРШИЛ × МАТЕРИАЛААР тавигддаг: «1-16 дугаар давхар, М250,
 * 3,837 м³» гэсэн мөр 16 байршилд задарсан байна. Үлдэгдлийг тэр
 * задаргааны ЯГ тэр байршил дээр бодно — давхруудыг нийлүүлбэл 5-р
 * давхрын хэтрэлт 6-р давхрын нөөцөөр нуугдана.
 */
class ConcreteBudgetBalance
{
    /**
     * Нэг байршил + нэг материалын үлдэгдэл.
     *
     * @param  string|null  $ignoreOrderId  өөрийгөө тооцохгүй (засварлах үед)
     * @return array{budget: float, ordered: float, remaining: float, overBy: float}
     */
    public function forLocation(
        string $locationId,
        string $materialId,
        ?string $ignoreOrderId = null,
    ): array {
        $budget = (float) MaterialBudgetQuantity::query()
            ->where('location_id', $locationId)
            ->whereHas('item', fn ($q) => $q->where('material_id', $materialId))
            ->sum('quantity');

        $ordered = $this->orderedQty($locationId, $materialId, $ignoreOrderId);
        $remaining = round($budget - $ordered, 3);

        return [
            'budget' => round($budget, 3),
            'ordered' => round($ordered, 3),
            'remaining' => $remaining,
            'overBy' => $remaining < 0 ? abs($remaining) : 0.0,
        ];
    }

    /**
     * Захиалагдсан хэмжээ.
     *
     * `consumedQty()`-тэй ИЖИЛ дүрэм: нийлүүлэгдсэн бол бодит хэмжээ.
     * SQL-ээр бодох тул дүрмийг энд давтах шаардлагатай — PHP рүү 300
     * мөр татаж нэгтгэх нь давхар бүрийн дэлгэц ачаалахад удаан.
     */
    private function orderedQty(string $locationId, string $materialId, ?string $ignore): float
    {
        return (float) ConcreteOrder::query()
            ->committed()
            ->where('location_id', $locationId)
            ->where('material_id', $materialId)
            ->when($ignore, fn ($q, $id) => $q->whereKeyNot($id))
            // `coalesce` нь SQLite ба MySQL хоёуланд ижил ажиллана.
            ->selectRaw('coalesce(sum(coalesce(delivered_qty, quantity)), 0) as total')
            ->value('total');
    }

    /**
     * Блокийн БҮХ бетоны үлдэгдэл — байршил × материалаар.
     *
     * Захиалга бичих дэлгэц үүнийг нэг дуудлагаар авна: инженер давхраа
     * сонгоход ямар марк хэр үлдсэнийг ТЭР ДОР НЬ харна.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBlock(Block $block): array
    {
        $materials = Material::concrete()
            ->where('project_id', $block->project_id)
            ->get()
            ->keyBy('id');

        if ($materials->isEmpty()) {
            return [];
        }

        $locations = Location::where('block_id', $block->id)
            ->orderBy('sequence_number')
            ->get(['id', 'name', 'sequence_number'])
            ->keyBy('id');

        /*
         * Төсвийн задаргаа — зөвхөн энэ блокийн бетоны мөрүүд.
         *
         * `with('item')` биш `join`: 20 байршил × 6 марк = 120 мөр,
         * тус бүрд нэг асуулга явуулах нь утгагүй.
         */
        $budgets = MaterialBudgetQuantity::query()
            ->join(
                'material_budget_items',
                'material_budget_items.id',
                '=',
                'material_budget_quantities.material_budget_item_id',
            )
            ->where('material_budget_items.block_id', $block->id)
            ->whereIn('material_budget_items.material_id', $materials->keys())
            ->get([
                'material_budget_quantities.location_id',
                'material_budget_items.material_id',
                'material_budget_quantities.quantity',
            ]);

        $ordered = ConcreteOrder::query()
            ->committed()
            ->where('block_id', $block->id)
            ->get(['location_id', 'material_id', 'quantity', 'delivered_qty', 'status']);

        $rows = [];

        foreach ($budgets as $budget) {
            $key = $budget->location_id.'|'.$budget->material_id;

            $rows[$key] ??= [
                'locationId' => $budget->location_id,
                'locationName' => $locations[$budget->location_id]->name ?? '—',
                'sequence' => $locations[$budget->location_id]->sequence_number ?? 0,
                'materialId' => $budget->material_id,
                'materialName' => $materials[$budget->material_id]->label(),
                'grade' => $materials[$budget->material_id]->grade,
                'unit' => $materials[$budget->material_id]->unit,
                'budget' => 0.0,
                'ordered' => 0.0,
            ];

            $rows[$key]['budget'] += (float) $budget->quantity;
        }

        foreach ($ordered as $order) {
            $key = $order->location_id.'|'.$order->material_id;

            /*
             * ТӨСӨВГҮЙ БАЙРШИЛД захиалга байж болно: төсөв дахин
             * импортлогдоод тэр мөр нь хасагдсан байж мэднэ. Мөрийг
             * нуухгүй — 0 төсөвтэй, бүхэлдээ хэтэрсэн гэж харуулна.
             */
            $rows[$key] ??= [
                'locationId' => $order->location_id,
                'locationName' => $locations[$order->location_id]->name ?? '—',
                'sequence' => $locations[$order->location_id]->sequence_number ?? 0,
                'materialId' => $order->material_id,
                'materialName' => $materials[$order->material_id]?->label() ?? '—',
                'grade' => $materials[$order->material_id]?->grade,
                'unit' => $materials[$order->material_id]?->unit ?? 'м3',
                'budget' => 0.0,
                'ordered' => 0.0,
            ];

            $rows[$key]['ordered'] += $order->consumedQty();
        }

        $rows = array_map(function (array $row) {
            $row['budget'] = round($row['budget'], 3);
            $row['ordered'] = round($row['ordered'], 3);
            $row['remaining'] = round($row['budget'] - $row['ordered'], 3);
            $row['overBy'] = $row['remaining'] < 0 ? abs($row['remaining']) : 0.0;

            return $row;
        }, $rows);

        usort(
            $rows,
            fn ($a, $b) => [$a['sequence'], $a['materialName']] <=> [$b['sequence'], $b['materialName']],
        );

        return array_values($rows);
    }
}
