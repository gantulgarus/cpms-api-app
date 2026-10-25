<?php

namespace App\Services;

use App\Models\Agreement;
use App\Models\Block;
use App\Models\ConcreteOrder;
use App\Models\Issue;
use App\Models\MaterialBudgetItem;
use App\Models\MaterialOrder;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/**
 * Блокийн ЕРӨНХИЙ тайлан — барилгын бүх үзүүлэлт нэг хуудсанд.
 *
 * АКТААС ЮУГААРАА ЯЛГААТАЙ ВЭ: акт нь нэг ХУГАЦААНЫ, нэг гүйцэтгэгчийн
 * төлбөрийн баримт. Энэ нь барилгын ЭХЛЭЛЭЭС ӨНӨӨДРИЙГ хүртэлх бүтэн
 * зураг — гүйцэтгэл, мөнгө, хугацаа, чанар, материал тав.
 *
 * ТАВАН ХЭСГИЙГ ЯАГААД САЛГАСАН БЭ: «барилга хэр явж байна» гэсэн
 * асуултад нэг тоо хариулдаггүй. 95% дууссан барилга төсвөө 40%
 * хэтрүүлсэн, 60 хоног хоцорсон байж болно. Гурвуулаа харагдаж байж
 * шийдвэр гарна.
 *
 * ХЭЗЭЭ Ч ГАРНА. Зөвхөн дууссан блокт хязгаарлавал хамгийн хэрэгтэй үе
 * нь — явцын дунд — ашиглагдахгүй.
 */
class BlockReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(Block $block): array
    {
        $block->loadMissing('project.company');

        return [
            'block' => [
                'id' => $block->id,
                'name' => $block->name,
                'buildingNo' => $block->building_no,
                'purpose' => $block->purpose,
                'floors' => $block->floors,
                'unitsPerFloor' => $block->units_per_floor,
                'startDate' => $block->start_date?->toDateString(),
                'projectName' => $block->project?->name,
                'companyName' => $block->project?->company?->name,
            ],
            'generatedAt' => now()->toIso8601String(),
            'progress' => $this->progress($block),
            'byGroup' => $this->byGroup($block),
            'money' => $this->money($block),
            'schedule' => $this->schedule($block),
            'quality' => $this->quality($block),
            'materials' => $this->materials($block),
        ];
    }

    // ───────────────────────── гүйцэтгэл ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function progress(Block $block): array
    {
        $row = DB::table('work_items as wi')
            ->where('wi.block_id', $block->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when wi.status = 'completed' then 1 else 0 end) as completed")
            ->selectRaw('sum(case when wi.planned_qty <= 0 then 1 else 0 end) as unmeasured')
            ->selectRaw('sum('.WorkItem::pendingSql('wi').') as pending')
            ->selectRaw('sum('.WorkItem::returnedSql('wi').') as returned')
            ->selectRaw('sum(wi.planned_qty) as planned_qty')
            ->selectRaw('sum(wi.accepted_qty) as accepted_qty')
            /*
             * Хувь нь МӨР тутмын дунджаар, нийт тоо хэмжээгээр БИШ.
             *
             * м² ба м³-ийг нийлүүлж хуваах нь утгагүй: 3,000 м³ газар
             * шороо 60 м² шалыг дарж, барилга «бараг дууссан» гэж
             * харагдана.
             */
            ->selectRaw('avg('.WorkItem::progressSql('wi').') as ratio')
            ->first();

        $total = (int) ($row->total ?? 0);

        return [
            'workItems' => $total,
            'completed' => (int) ($row->completed ?? 0),
            'unmeasured' => (int) ($row->unmeasured ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'returned' => (int) ($row->returned ?? 0),
            'percentage' => round(((float) ($row->ratio ?? 0)) * 100, 1),
        ];
    }

    /**
     * Ажлын БҮЛГЭЭР задаргаа — «хаана гацсан» гэдгийг харуулна.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byGroup(Block $block): array
    {
        return DB::table('work_items as wi')
            ->join('work_types as wt', 'wt.id', '=', 'wi.work_type_id')
            ->leftJoin('work_type_groups as g', 'g.id', '=', 'wt.work_type_group_id')
            ->where('wi.block_id', $block->id)
            ->groupBy('g.name', 'g.build_order')
            ->orderBy('g.build_order')
            ->selectRaw("coalesce(g.name, 'Бусад') as name")
            ->selectRaw('count(*) as items')
            ->selectRaw("sum(case when wi.status = 'completed' then 1 else 0 end) as completed")
            ->selectRaw('avg('.WorkItem::progressSql('wi').') as ratio')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'items' => (int) $r->items,
                'completed' => (int) $r->completed,
                'percentage' => round(((float) $r->ratio) * 100, 1),
            ])
            ->all();
    }

    // ───────────────────────── мөнгө ─────────────────────────

    /**
     * ГУРВАН ҮНЭ: төсөв → гэрээ → акт.
     *
     * Эдгээрийг зэрэгцүүлэх нь энэ тайлангийн гол утга. Төсөв нь жишиг,
     * гэрээ нь амлалт, акт нь бодит төлбөр. Гурвын хооронд зөрүү гарах
     * нь ХЭВИЙН — харагдахгүй байх нь л асуудал.
     *
     * @return array<string, mixed>
     */
    private function money(Block $block): array
    {
        /*
         * Төсвийн дүн — ТӨЛӨВЛӨСӨН тоо хэмжээ × төсвийн нэгж үнэ.
         *
         * Үнэгүй мөр (төсөвт үнэ тавигдаагүй) нь 0 гэж тооцогдоно —
         * таамаглахгүй. Хэдэн мөр үнэгүй болохыг тусад нь хэлнэ.
         */
        $budget = DB::table('work_items')
            ->where('block_id', $block->id)
            ->selectRaw('sum(coalesce(budget_unit_price, 0) * planned_qty) as total')
            ->selectRaw('sum(case when budget_unit_price is null then 1 else 0 end) as without_price')
            ->first();

        /*
         * `amount` нь БАГАНА БИШ, бодогддог шинж (`quantity × unit_price`).
         *
         * Тиймээс `withSum('lines', 'amount')` SQL дээр унана. Дүнг
         * SQL-ээр бодно: мөрүүдийг PHP рүү татах нь нэг блокт хэдэн
         * зуун мөр болох бөгөөд шаардлагагүй.
         */
        $agreementIds = Agreement::where('block_id', $block->id)
            ->whereIn('status', ['active', 'completed'])
            ->pluck('id');

        $agreed = round((float) DB::table('agreement_lines')
            ->whereIn('agreement_id', $agreementIds)
            ->sum(DB::raw('quantity * unit_price')), 2);

        /*
         * Актын дүн — БАТАЛГААЖСАН хэмжээ × ТОХИРОЛЦООНЫ нэгж үнэ.
         *
         * Төсвийн үнээр бодвол бодит төлбөр биш, жишиг гарна: гэрээний
         * үнэ төсвөөс зөрж болно. Тохиролцоогүй ажил 0-оор тооцогдоно.
         */
        $accepted = DB::table('work_items as wi')
            ->leftJoin('agreement_lines as al', function ($join) use ($block) {
                $join->on('al.work_type_id', '=', 'wi.work_type_id')
                    ->whereIn(
                        'al.agreement_id',
                        Agreement::where('block_id', $block->id)
                            ->whereIn('status', ['active', 'completed'])
                            ->select('id'),
                    );
            })
            ->where('wi.block_id', $block->id)
            ->selectRaw('sum(coalesce(al.unit_price, 0) * wi.accepted_qty) as total')
            ->first();

        $accepted = round((float) ($accepted->total ?? 0), 2);

        return [
            'budgetAmount' => round((float) ($budget->total ?? 0), 2),
            'withoutBudgetPrice' => (int) ($budget->without_price ?? 0),
            'agreementCount' => $agreementIds->count(),
            'agreedAmount' => $agreed,
            'acceptedAmount' => $accepted,

            /*
             * Зөрүүг ХУВИАР: «16.2 сая» гэдэг тоо дангаараа их үү, бага
             * уу гэдгийг хэлэхгүй. Төсөв 0 бол хувь утгагүй тул `null`.
             */
            'agreedVariancePercent' => $this->variance($budget->total ?? 0, $agreed),
            'acceptedVariancePercent' => $this->variance($agreed, $accepted),
        ];
    }

    private function variance(mixed $base, float $value): ?float
    {
        $base = (float) $base;

        return $base > 0 ? round(($value / $base - 1) * 100, 2) : null;
    }

    // ───────────────────────── хугацаа ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function schedule(Block $block): array
    {
        $row = DB::table('work_items')
            ->where('block_id', $block->id)
            ->selectRaw('min(planned_start_date) as first_start')
            ->selectRaw('max(planned_end_date) as last_end')
            ->selectRaw('max(baseline_end_date) as baseline_end')
            /*
             * СУНГАСАН МӨРИЙН ТОО, нийт хоног БИШ.
             *
             * Мөр бүрийн сунгалтыг нэмэх нь утгагүй тоо гаргана: 3,711
             * мөр тус бүр 30 хоногоор сунгавал «111,330 хоног» гэсэн
             * хариу гарна. Блокийн жинхэнэ гулсалт нь `slippageDays` —
             * хамгийн сүүлийн огноонуудын зөрүү.
             *
             * `extended_days` нь мөн БАГАНА БИШ, загварт бодогддог шинж
             * тул SQL-д шууд дуудах боломжгүй. Нөхцөлийг нь энд
             * давтав: төлөвлөсөн огноо үндсэн амлалтаас хойш бол
             * тэр мөр сунгагдсан.
             */
            ->selectRaw(
                'sum(case when baseline_end_date is not null'
                .' and planned_end_date > baseline_end_date then 1 else 0 end) as extended_items'
            )
            ->selectRaw(
                "sum(case when status <> 'completed' and planned_end_date < ? then 1 else 0 end) as overdue",
                [now()->toDateString()],
            )
            ->first();

        return [
            'plannedStart' => $row->first_start,
            'plannedEnd' => $row->last_end,

            /*
             * ҮНДСЭН огноо — анхны амлалт. Сунгалтын дараа `planned_end`
             * хөдөлдөг тул хоёрыг зэрэгцүүлж байж жинхэнэ гулсалт
             * харагдана.
             */
            'baselineEnd' => $row->baseline_end,
            'slippageDays' => $this->days($row->baseline_end, $row->last_end),
            'extendedItems' => (int) ($row->extended_items ?? 0),
            'overdueItems' => (int) ($row->overdue ?? 0),
        ];
    }

    private function days(?string $from, ?string $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        return (int) \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to), false);
    }

    // ───────────────────────── чанар ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function quality(Block $block): array
    {
        $itemIds = WorkItem::where('block_id', $block->id)->select('id');

        $inspections = DB::table('inspections')
            ->whereIn('work_item_id', $itemIds)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when result = 'rejected' then 1 else 0 end) as rejected")
            ->selectRaw("sum(case when result = 'partial' then 1 else 0 end) as partial")
            ->selectRaw('sum(rejected_qty) as rejected_qty')
            ->first();

        $total = (int) ($inspections->total ?? 0);
        $rejected = (int) ($inspections->rejected ?? 0);

        return [
            'inspections' => $total,
            'rejected' => $rejected,
            'partial' => (int) ($inspections->partial ?? 0),
            /*
             * Татгалзлын ХУВЬ — чанарын цорын гагц харьцуулж болох тоо.
             * «14 татгалзал» нь 20 шалгалтад их, 2,000-д цөөн.
             */
            'rejectionRate' => $total > 0 ? round($rejected / $total * 100, 1) : null,
            'openIssues' => Issue::whereIn('work_item_id', $itemIds)->where('status', 'open')->count(),
            'resolvedIssues' => Issue::whereIn('work_item_id', $itemIds)
                ->where('status', 'resolved')
                ->count(),
        ];
    }

    // ───────────────────────── материал ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function materials(Block $block): array
    {
        $budget = MaterialBudgetItem::where('block_id', $block->id)
            ->selectRaw('count(*) as items')
            ->selectRaw('sum(coalesce(actual_unit_price, 0) * total_qty) as actual')
            ->selectRaw('sum(coalesce(market_unit_price, 0) * total_qty) as market')
            ->first();

        return [
            'budgetedItems' => (int) ($budget->items ?? 0),
            'actualAmount' => round((float) ($budget->actual ?? 0), 2),
            'marketAmount' => round((float) ($budget->market ?? 0), 2),
            'marketVariancePercent' => $this->variance(
                $budget->actual ?? 0,
                round((float) ($budget->market ?? 0), 2),
            ),
            'concreteOrders' => ConcreteOrder::where('block_id', $block->id)->committed()->count(),
            'materialOrders' => MaterialOrder::where('block_id', $block->id)->committed()->count(),
            'concreteDelivered' => round((float) ConcreteOrder::where('block_id', $block->id)
                ->where('status', 'delivered')
                ->sum('delivered_qty'), 3),
        ];
    }
}
