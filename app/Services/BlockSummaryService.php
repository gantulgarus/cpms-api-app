<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Contractor;
use App\Models\Location;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Блокийн явцыг сервер талд нэгтгэнэ.
 *
 * Энэ бол системийн гүйцэтгэлийн гол цэг. Нэг блокт ~3,290 ажлын нэгж байдаг
 * тул нэгтгэлийг Eloquent давталтаар хийвэл шууд унана. Бүх бүлэг НЭГ
 * `GROUP BY` query-гээр гарна, дараа нь шошгыг нэг дуудлагаар авна — нийт 2
 * query, бүлгийн тооноос үл хамаарна.
 *
 * Бүлэг бүр өөрийгөө сэргээх `filter`-ыг хамт буцаана. Client тал `key`-ээс
 * шүүлтүүр таамаглах ёсгүй: блокийн үндэс зангилаа бүх зүйлийн эцэг тул
 * түүн дээр `includeDescendants` хэрэглэвэл 11 ажлын оронд 3,290 гарч ирдэг.
 */
class BlockSummaryService
{
    public const GROUPINGS = ['floor', 'workType', 'workTypeGroup', 'contractor'];

    /**
     * @param  callable(Builder): void|null  $applyFilters  Нэмэлт шүүлтүүр
     * @return array{block: array, totals: array, groups: array}
     */
    public function summarize(Block $block, string $groupBy = 'floor', ?callable $applyFilters = null): array
    {
        $rows = $this->aggregate($block, $groupBy, $applyFilters);
        $groups = $this->decorate($rows, $groupBy);

        return [
            'block' => ['id' => $block->id, 'name' => $block->name],
            'totals' => $this->totals($groups),
            'groups' => $groups,
        ];
    }

    /** Ганц GROUP BY query — бүлгийн түлхүүр ба тоон дүнг гаргана. */
    private function aggregate(Block $block, string $groupBy, ?callable $applyFilters): array
    {
        $keyExpression = match ($groupBy) {
            'workType' => 'wi.work_type_id',
            'workTypeGroup' => 'wt.work_type_group_id',
            // PostgreSQL-д uuid-г текст рүү хөрвүүлэхгүйгээр coalesce хийхгүй.
            // Тестийг sqlite дээр гүйлгэх боломжтой байлгахын тулд драйвераар салгав.
            'contractor' => DB::getDriverName() === 'pgsql'
                ? "coalesce(wi.contractor_id::text, 'none')"
                : "coalesce(wi.contractor_id, 'none')",
            // Айлын ажлыг эцэг давхарт нь хамааруулна.
            default => "case when l.level = 'unit' then l.parent_id else l.id end",
        };

        $query = DB::table('work_items as wi')
            ->join('locations as l', 'l.id', '=', 'wi.location_id')
            ->where('wi.block_id', $block->id)
            ->selectRaw("{$keyExpression} as group_key")
            ->selectRaw('count(*) as work_items')
            ->selectRaw('sum(wi.planned_qty) as planned_qty')
            ->selectRaw('sum(wi.reported_qty) as reported_qty')
            ->selectRaw('sum(wi.accepted_qty) as accepted_qty')
            ->selectRaw("sum(case when wi.review_state = 'pending' then 1 else 0 end) as pending_inspections")
            // Энэ бүлэг хэзээ дуусах ёстой вэ — хамгийн сүүлийн товлосон огноо.
            ->selectRaw('max(wi.planned_end_date) as planned_end_date')
            ->selectRaw(
                "sum(case when wi.status <> 'completed' and wi.planned_end_date < ? then 1 else 0 end) as overdue",
                [now()->toDateString()]
            )
            ->groupBy(DB::raw($keyExpression));

        if ($groupBy === 'workTypeGroup') {
            $query->join('work_types as wt', 'wt.id', '=', 'wi.work_type_id');
        }

        $applyFilters?->__invoke($query);

        return $query->get()->all();
    }

    /** Түлхүүрүүдэд шошго ба сэргээх шүүлтүүр нэмнэ (нэг нэмэлт query). */
    private function decorate(array $rows, string $groupBy): array
    {
        $keys = array_map(fn ($r) => (string) $r->group_key, $rows);
        [$labels, $order] = $this->labelsFor($groupBy, $keys);

        $groups = array_map(function ($row) use ($groupBy, $labels, $order) {
            $key = (string) $row->group_key;
            $planned = (float) $row->planned_qty;
            $accepted = (float) $row->accepted_qty;

            return [
                'key' => $key,
                'label' => $labels[$key] ?? '—',
                'filter' => $this->filterFor($groupBy, $key, $labels),
                'workItems' => (int) $row->work_items,
                'plannedQty' => round($planned, 3),
                'reportedQty' => round((float) $row->reported_qty, 3),
                'acceptedQty' => round($accepted, 3),
                'percentage' => $planned > 0 ? (int) round($accepted / $planned * 100) : 0,
                'pendingInspections' => (int) $row->pending_inspections,
                'overdue' => (int) $row->overdue,
                'plannedEndDate' => $row->planned_end_date
                    ? substr((string) $row->planned_end_date, 0, 10)
                    : null,
                '_order' => $order[$key] ?? PHP_INT_MAX,
            ];
        }, $rows);

        usort($groups, fn ($a, $b) => [$a['_order'], -$a['workItems']] <=> [$b['_order'], -$b['workItems']]);

        return array_map(function ($g) {
            unset($g['_order']);

            return $g;
        }, $groups);
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,int>} шошго, эрэмбэ
     */
    private function labelsFor(string $groupBy, array $keys): array
    {
        return match ($groupBy) {
            'workType' => [
                WorkType::whereIn('id', $keys)->pluck('name', 'id')->all(),
                [],
            ],
            'workTypeGroup' => [
                WorkTypeGroup::whereIn('id', $keys)->pluck('name', 'id')->all(),
                WorkTypeGroup::whereIn('id', $keys)->pluck('build_order', 'id')->all(),
            ],
            'contractor' => [
                Contractor::whereIn('id', $keys)->pluck('name', 'id')->all() + ['none' => 'Хариуцагчгүй'],
                [],
            ],
            default => $this->floorLabels($keys),
        };
    }

    /** Давхрын шошго ба эрэмбэ. Блокийн үндэс зангилаа хамгийн түрүүнд. */
    private function floorLabels(array $keys): array
    {
        $locations = Location::whereIn('id', $keys)->get();
        $labels = [];
        $order = [];

        foreach ($locations as $location) {
            $isRoot = $location->level === 'block';
            $labels[$location->id] = $isRoot ? 'Барилга бүхэлдээ' : $location->name;
            $order[$location->id] = $isRoot ? -1 : $location->sequence_number;
        }

        return [$labels, $order];
    }

    /**
     * Бүлгийг яг сэргээх шүүлтүүр.
     *
     * Блокийн үндэс зангилаанд `includeDescendants` ХЭРЭГЛЭХГҮЙ — эс бөгөөс
     * "барилга бүхэлдээ" бүлэг дарахад блокийн бүх ажил гарч ирнэ.
     */
    private function filterFor(string $groupBy, string $key, array $labels): array
    {
        return match ($groupBy) {
            'workType' => ['workTypeId' => $key],
            'workTypeGroup' => ['workTypeGroupId' => $key],
            'contractor' => ['contractorId' => $key],
            default => [
                'locationId' => $key,
                'includeDescendants' => ($labels[$key] ?? '') !== 'Барилга бүхэлдээ',
            ],
        };
    }

    private function totals(array $groups): array
    {
        $planned = array_sum(array_column($groups, 'plannedQty'));
        $accepted = array_sum(array_column($groups, 'acceptedQty'));
        $dates = array_filter(array_column($groups, 'plannedEndDate'));

        return [
            'workItems' => (int) array_sum(array_column($groups, 'workItems')),
            'plannedQty' => round($planned, 3),
            'reportedQty' => round(array_sum(array_column($groups, 'reportedQty')), 3),
            'acceptedQty' => round($accepted, 3),
            'percentage' => $planned > 0 ? (int) round($accepted / $planned * 100) : 0,
            'pendingInspections' => (int) array_sum(array_column($groups, 'pendingInspections')),
            'overdue' => (int) array_sum(array_column($groups, 'overdue')),
            // Блок бүхэлдээ хэзээ дуусах ёстой — бүлгүүдийн хамгийн сүүлийнх.
            'plannedEndDate' => $dates ? max($dates) : null,
        ];
    }
}
