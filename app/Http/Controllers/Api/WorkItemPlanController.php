<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkItemResource;
use App\Models\Block;
use App\Models\DesignItem;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\TaktSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Төлөвлөгөөт тоо хэмжээ ба хугацаа засах.
 *
 * ЯАГААД ХЭРЭГТЭЙ: захиалагчийн Хавсралт-2-т ~30 ажлын төрлийн тоо хэмжээ
 * хоосон байсан. Тэдгээр нь `planned_qty = 0` болж үүсдэг бөгөөд үлдэгдэл нь
 * 0 тул гүйцэтгэл ОГТ оруулах боломжгүй болдог — ажил хийгдэж байхад програм
 * дээр бүртгэх арга байхгүй гэсэн үг.
 *
 * Захиалагч "тоо хэмжээг дараа өгнө" гэсэн тул дараа нь оруулах зам ЗААВАЛ
 * байх ёстой байсан.
 */
class WorkItemPlanController extends Controller
{
    /**
     * PATCH /work-items/{workItem}
     *
     * Нэг ажлын төлөвлөгөө засна.
     */
    public function update(Request $request, WorkItem $workItem): JsonResponse
    {
        $this->authorizeEdit($request);

        $validated = $request->validate([
            'plannedQty' => ['sometimes', 'numeric', 'min:0'],
            'plannedStartDate' => ['nullable', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
        ]);

        if (isset($validated['plannedQty'])) {
            $this->guardNotBelowAccepted($workItem, (float) $validated['plannedQty']);
        }

        $workItem->update(array_filter([
            'planned_qty' => $validated['plannedQty'] ?? null,
            'planned_start_date' => $validated['plannedStartDate'] ?? null,
            'planned_end_date' => $validated['plannedEndDate'] ?? null,
        ], fn ($v) => $v !== null));

        // Тоо хэмжээ өөрчлөгдвөл төлөв, хувь бүгд өөр болно.
        $workItem->recalculate();

        return response()->json([
            'data' => new WorkItemResource($workItem->refresh()->load(['location', 'workType.group', 'contractor'])),
        ]);
    }

    /**
     * POST /blocks/{block}/work-items/set-quantity
     *
     * Нэг ажлын төрлийн БҮХ мөрөнд тоо хэмжээ оруулна.
     *
     * Яагаад бөөнөөр: айлын түвшний нэг ажлын төрөл 16 давхрын барилгад 144
     * мөр үүсгэдэг. Тэдгээрийг гар аргаар нэг нэгээр нь бөглөх нь бодит
     * ажлын урсгалд боломжгүй.
     */
    public function setQuantity(Request $request, Block $block): JsonResponse
    {
        $this->authorizeEdit($request);

        $validated = $request->validate([
            'workTypeId' => ['required', 'uuid', 'exists:work_types,id'],
            'plannedQty' => ['required', 'numeric', 'min:0'],
            /**
             * Аль хэдийн тоо хэмжээтэй мөрийг дарж бичих эсэх.
             *
             * Анхдагчаар ҮГҮЙ. Эс бөгөөс нэг товч дарахад зөв бөглөсөн 144
             * мөр устах эрсдэлтэй — засах гэж байгаад эвдэх нь хамгийн муу.
             */
            'overwriteExisting' => ['nullable', 'boolean'],
            'updateDesign' => ['nullable', 'boolean'],
        ]);

        $qty = (float) $validated['plannedQty'];
        $overwrite = (bool) ($validated['overwriteExisting'] ?? false);

        $query = WorkItem::query()
            ->where('block_id', $block->id)
            ->where('work_type_id', $validated['workTypeId']);

        if (! $overwrite) {
            $query->where('planned_qty', 0);
        }

        // Батлагдсан хэмжээнээс бага төлөвлөгөө тавьвал хувь 100-аас давна.
        $conflicts = (clone $query)->where('accepted_qty', '>', $qty)->count();

        abort_if(
            $conflicts > 0,
            409,
            "{$conflicts} ажилд батлагдсан хэмжээ {$qty}-аас их байна. "
            .'Төлөвлөгөөг батлагдсанаас бага болгох боломжгүй.'
        );

        $affected = DB::transaction(function () use ($query, $qty) {
            $ids = (clone $query)->pluck('id');

            WorkItem::whereIn('id', $ids)->update(['planned_qty' => $qty]);

            // Төлөв (`not_started`/`completed`) нь төлөвлөгөөнөөс хамаардаг тул
            // мөр бүрийг дахин бодно.
            foreach (WorkItem::whereIn('id', $ids)->get() as $item) {
                $item->recalculate();
            }

            return $ids->count();
        });

        // Загварыг мөн засах — эс бөгөөс дараагийн блок ижил нүхтэй үүснэ.
        $designUpdated = false;
        if ($validated['updateDesign'] ?? true) {
            $designUpdated = $this->updateDesign($block, $validated['workTypeId'], $qty);
        }

        return response()->json([
            'data' => [
                'affected' => $affected,
                'designUpdated' => $designUpdated,
            ],
        ]);
    }

    /**
     * POST /blocks/{block}/work-items
     *
     * Блокт ажлын төрлүүдийг нэмнэ.
     *
     * ОЛОН ТӨРЛИЙГ НЭГ ДОР: барилгын төлөвлөлт «нэг давхарт ямар ажлууд
     * хийгдэх вэ» гэсэн БАГЦААР явдаг (олон улсад location-based planning /
     * takt package гэдэг). Нэг нэгээр нь 15 удаа диалог нээх нь бодит
     * ажлын урсгалд тохирохгүй.
     *
     * Төрөл бүр өөрийн ТҮВШНИЙ бүх байршилд буух тул давхрын 3 төрөл 6
     * давхартай блокт 18 мөр болно.
     */
    public function addWorkType(Request $request, Block $block): JsonResponse
    {
        $this->authorizeEdit($request);

        $validated = $request->validate([
            // Нэг эсвэл олон — хоёуланг нь дэмжинэ.
            'workTypeId' => ['nullable', 'uuid', 'exists:work_types,id'],
            'workTypeIds' => ['nullable', 'array', 'min:1'],
            'workTypeIds.*' => ['uuid', 'exists:work_types,id'],
            'plannedQty' => ['nullable', 'numeric', 'min:0'],
            'locationId' => ['nullable', 'uuid', 'exists:locations,id'],
            'contractorId' => ['nullable', 'uuid', 'exists:contractors,id'],
            'plannedStartDate' => ['nullable', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
        ]);

        $ids = $validated['workTypeIds'] ?? array_filter([$validated['workTypeId'] ?? null]);

        abort_if($ids === [], 422, 'Ажлын төрөл сонгоно уу.');

        $workTypes = WorkType::whereIn('id', $ids)->with('group')->get();
        $created = 0;
        $skipped = [];

        // Гараар нэмсэн ажил ч нэг хуваарьт багтана — эс бөгөөс загвараар
        // үүссэн ажил хуваарьтай, гараар нэмсэн нь огноогүй болж хоёр өөр
        // жишгээр хэмжигдэнэ.
        $takt = TaktSchedule::forBlock($block, $validated['plannedStartDate'] ?? null);

        DB::transaction(function () use ($block, $workTypes, $validated, $takt, &$created, &$skipped) {
            foreach ($workTypes as $workType) {
                $targets = $block->locations()
                    ->when(
                        ! empty($validated['locationId']),
                        fn ($q) => $q->where('id', $validated['locationId']),
                        fn ($q) => $q->where('level', $workType->level),
                    )
                    ->orderBy('sequence_number')
                    ->get();

                if ($targets->isEmpty()) {
                    $skipped[] = ['name' => $workType->name, 'reason' => 'Тохирох байршил алга'];

                    continue;
                }

                // Давхардлаас сэргийлнэ: ижил төрөл + ижил байршилд хоёр мөр
                // үүсвэл тоо хэмжээ давхарлаж, гүйцэтгэлийн хувь худал болно.
                $existing = WorkItem::where('block_id', $block->id)
                    ->where('work_type_id', $workType->id)
                    ->pluck('location_id')
                    ->all();

                $fresh = $targets->reject(fn ($l) => in_array($l->id, $existing, true));

                if ($fresh->isEmpty()) {
                    $skipped[] = ['name' => $workType->name, 'reason' => 'Аль хэдийн нэмэгдсэн'];

                    continue;
                }

                $order = $workType->group?->build_order ?? TaktSchedule::DEFAULT_ORDER;

                foreach ($fresh as $location) {
                    [$start, $end] = $takt->window(
                        $workType->level,
                        (int) $location->sequence_number,
                        $order,
                    );

                    $block->workItems()->create([
                        'location_id' => $location->id,
                        'work_type_id' => $workType->id,
                        'contractor_id' => $validated['contractorId'] ?? null,
                        'name' => $workType->name.' — '.$location->name,
                        'unit' => $workType->unit,
                        'planned_qty' => $validated['plannedQty'] ?? 0,
                        'planned_start_date' => $start,
                        'planned_end_date' => $validated['plannedEndDate'] ?? $end,
                        'status' => 'not_started',
                        'review_state' => 'none',
                    ]);
                }

                $created += $fresh->count();
            }
        });

        // Бүгд алгасагдвал хэрэглэгчид ямар нэг зүйл болсон мэт харагдах ёсгүй.
        abort_if(
            $created === 0,
            409,
            'Сонгосон ажлууд аль хэдийн нэмэгдсэн эсвэл тохирох байршил алга.'
        );

        return response()->json([
            'data' => ['created' => $created, 'skipped' => $skipped],
        ], 201);
    }

    /**
     * DELETE /work-items/{workItem}
     *
     * Гараар нэмсэн ажлыг буцаан авна. Гүйцэтгэл бүртгэгдсэн бол устгахгүй —
     * түүх алга болно.
     */
    public function destroy(Request $request, WorkItem $workItem): JsonResponse
    {
        $this->authorizeEdit($request);

        abort_if(
            (float) $workItem->reported_qty > 0,
            409,
            'Энэ ажилд гүйцэтгэл бүртгэгдсэн тул устгах боломжгүй.'
        );

        $workItem->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /blocks/{block}/missing-quantities
     *
     * Тоо хэмжээгүй ажлын төрлүүд — "юуг гүйцээх вэ" гэсэн жагсаалт.
     */
    public function missingQuantities(Block $block): JsonResponse
    {
        $rows = WorkItem::query()
            ->where('block_id', $block->id)
            ->where('planned_qty', 0)
            ->join('work_types as wt', 'wt.id', '=', 'work_items.work_type_id')
            ->groupBy('wt.id', 'wt.name', 'wt.unit', 'wt.level')
            ->select('wt.id', 'wt.name', 'wt.unit', 'wt.level')
            ->selectRaw('count(*) as work_items')
            ->orderBy('wt.name')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'workTypeId' => $r->id,
                'name' => $r->name,
                'unit' => $r->unit,
                'level' => $r->level,
                'workItems' => (int) $r->work_items,
            ])->all(),
        ]);
    }

    /** Блокийн загварт мөн бичнэ — дараагийн блок зөв үүснэ. */
    private function updateDesign(Block $block, string $workTypeId, float $qty): bool
    {
        if (! $block->block_design_id) {
            return false;
        }

        $item = DesignItem::where('block_design_id', $block->block_design_id)
            ->where('work_type_id', $workTypeId)
            ->first();

        if (! $item || $item->qty_per_location !== null) {
            return false;
        }

        $item->update(['qty_per_location' => $qty]);

        return true;
    }

    private function guardNotBelowAccepted(WorkItem $workItem, float $qty): void
    {
        abort_if(
            $qty < (float) $workItem->accepted_qty,
            409,
            "Энэ ажилд {$workItem->accepted_qty} {$workItem->unit} аль хэдийн батлагдсан байна. "
            .'Төлөвлөгөөг түүнээс бага болгох боломжгүй.'
        );
    }

    /**
     * Төлөвлөгөө засах эрх.
     *
     * Тоо хэмжээ бол төслийн ХАМРАХ ХҮРЭЭ — талбайн инженер өөрөө өөрчилвөл
     * гүйцэтгэлийн хувь утгагүй болно. Handbook: "Төслийн менежер — plan
     * creation/update".
     */
    /**
     * POST /blocks/{block}/schedule
     *
     * Блокийн бүх ажлын огноог давхрын хугацаагаар ДАХИН тооцно.
     *
     * ЯАГААД ХЭРЭГТЭЙ: энэ хугацаа нь таамаг — «давхар тутам 5 хоног» гэж эхэлсэн ч
     * эхний 3 давхар 8 хоног авсан бол үлдсэн хуваарь бүхэлдээ худал болно.
     * Олон улсад үүнийг re-baseline гэдэг: гүйцэтгэлээ хараад хэмнэлээ засаж,
     * хуваарийг дахин татдаг.
     *
     * БАТЛАГДСАН ажлыг ХӨНДӨХГҮЙ: дууссан ажлын төлөвлөгөөт огноог хойшлуулж
     * болвол хоцролт бүрийг арилгах боломжтой болно — хэмжилт утгагүй болно.
     */
    public function schedule(Request $request, Block $block): JsonResponse
    {
        $this->authorizeEdit($request);

        $validated = $request->validate([
            'taktDays' => ['required', 'integer', 'min:1', 'max:60'],
            'startDate' => ['nullable', 'date'],
        ], [
            // Laravel-ийн анхны бичвэр «takt days» гэсэн англи талбарын нэрийг
            // хэрэглэгчид үзүүлнэ — тэр нь ойлгомжгүй.
            'taktDays.required' => 'Давхрын хугацааг оруулна уу.',
            'taktDays.integer' => 'Давхрын хугацаа бүтэн тоо байна.',
            'taktDays.min' => 'Давхрын хугацаа хамгийн багадаа 1 ажлын өдөр.',
            'taktDays.max' => 'Давхрын хугацаа хамгийн ихдээ 60 ажлын өдөр.',
            'startDate.date' => 'Эхлэх огноо буруу байна.',
        ]);

        $block->update([
            'takt_days' => $validated['taktDays'],
            'start_date' => $validated['startDate'] ?? $block->start_date,
        ]);

        $takt = TaktSchedule::forBlock($block->fresh());
        $updated = 0;
        $frozen = 0;

        DB::transaction(function () use ($block, $takt, &$updated, &$frozen) {
            $block->workItems()
                ->with(['location', 'workType.group'])
                ->chunkById(500, function ($items) use ($takt, &$updated, &$frozen) {
                    foreach ($items as $item) {
                        if ($item->accepted_qty > 0) {
                            $frozen++;

                            continue;
                        }

                        [$start, $end] = $takt->window(
                            $item->workType->level,
                            (int) ($item->location->sequence_number ?? 0),
                            $item->workType->group?->build_order ?? TaktSchedule::DEFAULT_ORDER,
                        );

                        $item->update([
                            'planned_start_date' => $start,
                            'planned_end_date' => $end,
                        ]);
                        $updated++;
                    }
                });
        });

        return response()->json([
            'data' => [
                'taktDays' => $takt->taktDays(),
                'updated' => $updated,
                'frozen' => $frozen,
            ],
        ]);
    }

    private function authorizeEdit(Request $request): void
    {
        abort_unless(
            $request->user()?->canEditPlan(),
            403,
            'Танд төлөвлөгөөт тоо хэмжээ засах эрх байхгүй.'
        );
    }
}
