<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExtensionRequestResource;
use App\Services\ExtensionService;
use App\Http\Resources\WorkItemResource;
use App\Models\Block;
use App\Models\DesignItem;
use App\Models\Issue;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\TaktSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
    /** Алгасах шалтгаан — статус тодорхойлоход хэрэглэгдэнэ. */
    private const NO_LOCATION = 'Тохирох байршил алга';

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
            'plannedStartDate' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'plannedEndDate' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);

        DB::transaction(function () use ($workItem, $validated) {
            $item = WorkItem::lockForUpdate()->findOrFail($workItem->id);
            if (isset($validated['plannedQty'])) {
                $this->guardNotBelowAccepted($item, (float) $validated['plannedQty']);
                $item->planned_qty = $validated['plannedQty'];
                $item->save();
            }
            if (isset($validated['plannedStartDate']) || isset($validated['plannedEndDate'])) {
                $start = $validated['plannedStartDate'] ?? $item->planned_start_date?->toDateString();
                $end = $validated['plannedEndDate'] ?? $item->planned_end_date?->toDateString();
                abort_unless($start && $end, 422, 'Эхлэх, дуусах огноог хоёуланг оруулна уу.');
                app(\App\Services\WorkDates::class)->plan($item, $start, $end);
            }
            $item->recalculate();
        });

        return response()->json([
            'data' => new WorkItemResource($workItem->refresh()->load(['location', 'workType.group', 'contractor'])),
        ]);
    }

    public function setDates(Request $request, Block $block): JsonResponse
    {
        $this->authorizeEdit($request);
        $input = $request->validate([
            'workTypeId' => ['required', 'uuid', 'exists:work_types,id'],
            'locationIds' => ['required', 'array', 'min:1'],
            'locationIds.*' => ['required', 'uuid', 'distinct', Rule::exists('locations', 'id')->where('block_id', $block->id)],
            'plannedStartDate' => ['required', 'date_format:Y-m-d'],
            'plannedEndDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:plannedStartDate'],
            'overwriteManual' => ['sometimes', 'boolean'],
        ]);
        $result = DB::transaction(function () use ($block, $input) {
            $items = $block->workItems()->where('work_type_id', $input['workTypeId'])->whereIn('location_id', $input['locationIds'])->lockForUpdate()->get();
            abort_if($items->isEmpty(), 422, 'Сонгосон байршилд энэ ажил алга.');
            $updated = 0; $skipped = 0;
            foreach ($items as $item) {
                if ($item->accepted_qty > 0 || ($item->schedule_manual && ! ($input['overwriteManual'] ?? false))) { $skipped++; continue; }
                app(\App\Services\WorkDates::class)->plan($item, $input['plannedStartDate'], $input['plannedEndDate']);
                $updated++;
            }
            return compact('updated', 'skipped');
        });
        return response()->json(['data' => $result]);
    }

    public function actualDates(Request $request, WorkItem $workItem): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canEditPlan() || $user?->canReportProgress(), 403, 'Танд бодит огноо бүртгэх эрх байхгүй.');
        if ($user->isContractorRep()) abort_unless($workItem->contractor_id === $user->contractor_id, 403);
        $input = $request->validate([
            'actualStartDate' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Ulaanbaatar')->toDateString()],
            'actualEndDate' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Ulaanbaatar')->toDateString()],
        ]);
        DB::transaction(function () use ($workItem, $input) {
            $item = WorkItem::lockForUpdate()->findOrFail($workItem->id);
            app(\App\Services\WorkDates::class)->actual($item, $input['actualStartDate'], $input['actualEndDate']);
            $item->recalculate();
        });
        return response()->json(['data' => new WorkItemResource($workItem->fresh()->load(['location', 'workType.group', 'contractor', 'inspector']))]);
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
            'locationId' => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('block_id', $block->id), 'prohibits:locationIds'],
            'locationIds' => ['sometimes', 'required', 'array', 'min:1'],
            'locationIds.*' => ['required', 'uuid', 'distinct', Rule::exists('locations', 'id')->where('block_id', $block->id)],
            'contractorId' => ['nullable', 'uuid', 'exists:contractors,id'],
            'plannedStartDate' => ['nullable', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
        ]);

        $ids = $validated['workTypeIds'] ?? array_filter([$validated['workTypeId'] ?? null]);

        abort_if($ids === [], 422, 'Ажлын төрөл сонгоно уу.');

        $workTypes = WorkType::whereIn('id', $ids)->with(['group', 'catalogEntry'])->get()
            ->sortBy(fn ($type) => array_search($type->id, $ids, true))->values();
        $created = 0;
        $skipped = [];

        // Гараар нэмсэн ажил ч нэг хуваарьт багтана — эс бөгөөс загвараар
        // үүссэн ажил хуваарьтай, гараар нэмсэн нь огноогүй болж хоёр өөр
        // жишгээр хэмжигдэнэ.
        $takt = TaktSchedule::forBlock($block, $validated['plannedStartDate'] ?? null);

        DB::transaction(function () use ($block, $workTypes, $validated, $takt, &$created, &$skipped) {
            Block::whereKey($block->id)->lockForUpdate()->firstOrFail();
            $planOrder = app(\App\Services\WorkExecutionPlan::class)->appendFrom($block);
            foreach ($workTypes as $workType) {
                $targets = $block->locations()
                    ->where('level', $workType->level)
                    ->when(isset($validated['locationIds']), fn ($q) => $q->whereIn('id', $validated['locationIds']))
                    ->when(! empty($validated['locationId']), fn ($q) => $q->where('id', $validated['locationId']))
                    ->orderBy('sequence_number')
                    ->with('parent')
                    ->get();

                if ($workType->catalogEntry) {
                    $targets = $targets->filter(fn ($location) => $location->level === $workType->level
                        && app(\App\Services\CatalogFloorScope::class)->matches($workType->catalogEntry, $location));
                }

                if ($targets->isEmpty()) {
                    $skipped[] = ['name' => $workType->name, 'reason' => self::NO_LOCATION];

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
                        'plan_order' => ++$planOrder,
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
            if ($created === 0) {
                $noLocation = collect($skipped)->every(fn ($row) => $row['reason'] === self::NO_LOCATION);
                abort($noLocation ? 422 : 409, $noLocation
                    ? 'Сонгосон ажлын төрөлд тохирох байршил блокт байхгүй.'
                    : 'Сонгосон ажлууд аль хэдийн нэмэгдсэн.');
            }
        });


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

        $takt = new TaktSchedule($validated['startDate'] ?? $block->start_date?->toDateString() ?? now()->toDateString(), $validated['taktDays'], max((int) $block->floors, 1));
        $updated = 0;
        $frozen = 0;

        DB::transaction(function () use ($block, $takt, $validated, &$updated, &$frozen) {
            $block->update(['takt_days' => $validated['taktDays'], 'start_date' => $validated['startDate'] ?? $block->start_date]);
            $block->workItems()
                ->with(['location', 'workType.group'])->withExists('progressEntries')
                ->chunkById(500, function ($items) use ($takt, &$updated, &$frozen) {
                    foreach ($items as $item) {
                        if ($item->accepted_qty > 0 || $item->schedule_manual || $item->actual_start_date || $item->reported_qty > 0 || $item->progress_entries_exists) {
                            $frozen++;

                            continue;
                        }

                        [$start, $end] = $takt->window(
                            $item->workType->level,
                            (int) ($item->location->sequence_number ?? 0),
                            $item->workType->group?->build_order ?? TaktSchedule::DEFAULT_ORDER,
                        );

                        /*
                         * Такт хуваарийг дахин татах нь ДАХИН ТӨЛӨВЛӨЛТ,
                         * сунгалт БИШ — тиймээс үндсэн огноо нь ч дагаж
                         * шинэчлэгдэнэ.
                         *
                         * Эс бөгөөс давхрын хугацааг 5-аас 12 хоног болгоход
                         * бүх ажил гэнэт «үндсэн төлөвлөгөөнөөс гулссан» гэж
                         * харагдана — гулсалт нь хэнээс ч шалтгаалаагүй
                         * хэдий ч.
                         */
                        $item->update([
                            'planned_start_date' => $start,
                            'planned_end_date' => $end,
                            'baseline_end_date' => $end,
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

    /**
     * POST /work-items/{workItem}/extend
     *
     * Хугацаа хэтэрсэн ажлын дуусах огноог сунгана.
     *
     * ЯАГААД ШАЛТГААН ЗААВАЛ ВЭ: огноог чимээгүй хойшлуулж болдог бол
     * «хугацаа хэтэрсэн» гэсэн тоо утгагүй болно — хэн ч хэзээ ч хоцрохгүй.
     * Шалтгаан нь мөн статистик болж хуримтлагдана: «17 ажил хоцорсон, түүний
     * 12 нь материал дутсанаас» гэдэг нь шийдвэр гаргуулдаг, «17 хоцорсон»
     * гэдэг нь гаргуулдаггүй.
     *
     * Шалтгааныг АСУУДЛЫН бүртгэлд хадгална — тусдаа хүснэгт үүсгэх
     * шаардлагагүй бөгөөд хоцролтын статистик нэг дороос гарна.
     */
    /**
     * POST /work-items/{workItem}/extend
     *
     * Хугацаа сунгах ХҮСЭЛТ үүсгэнэ.
     *
     * ЯАГААД ХҮСЭЛТ ВЭ: урьд нь огноог ШУУД өөрчилдөг байв. Тиймээс
     * «хугацаа хэтэрсэн» гэсэн тоо утгагүй болдог — хоцорсон хүн огноогоо
     * хойшлуулаад хоцроогүй болчихно. Нэг товч дарахад тайлан өөрөө
     * засагддаг бол тайлан хэнийг ч хянахгүй.
     *
     * Батлах эрхтэй хүн (ерөнхий инженер, захирал, админ) сунгавал хүсэлт
     * нь ТЭР ДОР НЬ батлагдана — тэд эрх мэдэлтэй. Гэхдээ баримт үлдэнэ:
     * хэн, хэзээ, ямар шалтгаанаар гэдэг нь бүртгэгдэнэ.
     *
     * Эрхээ ЭНД өргөн барина: талбайн инженер, гүйцэтгэгч ч хүсэлт
     * гаргаж чадна — хуваарь хэтэрч байгааг хамгийн эрт мэддэг хүн тэд.
     */
    public function extend(Request $request, WorkItem $workItem): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user?->canReportProgress() || $user?->canEditPlan(),
            403,
            'Танд хугацаа сунгах хүсэлт гаргах эрх байхгүй.'
        );

        // Гүйцэтгэгч зөвхөн ӨӨРИЙН ажилд хүсэлт гаргана.
        abort_if(
            $user->isContractorRep() && $workItem->contractor_id !== $user->contractor_id,
            403,
            'Энэ ажил таны хариуцлагад байхгүй.'
        );

        $validated = $request->validate([
            'plannedEndDate' => ['required', 'date'],
            'category' => ['required', Rule::in(Issue::CATEGORIES)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'plannedEndDate.required' => 'Шинэ дуусах огноог сонгоно уу.',
            'category.required' => 'Саатлын шалтгааныг сонгоно уу.',
            'reason.required' => 'Тайлбар бичнэ үү.',
            'reason.min' => 'Тайлбар хэт тодорхойгүй — дор хаяж 10 тэмдэгт бичнэ үү.',
        ]);

        $extension = app(ExtensionService::class)->request(
            $workItem,
            $user,
            $validated['plannedEndDate'],
            $validated['category'],
            $validated['reason'],
        );

        return response()->json([
            'data' => new ExtensionRequestResource($extension->load(['requestedBy', 'decidedBy'])),
            'meta' => [
                /*
                 * Дэлгэц хоёр тохиолдлыг ЯЛГАХ ёстой: батлагдсан бол огноо
                 * хөдөлсөн, хүлээгдэж байгаа бол хөдлөөгүй. Нэг мессеж
                 * харуулбал хэрэглэгч сунгалаа гэж бодоод явна.
                 */
                'applied' => $extension->status === 'approved',
                'workItem' => new WorkItemResource(
                    $workItem->refresh()->load(['location', 'workType.group', 'contractor'])
                ),
            ],
        ], $extension->status === 'approved' ? 200 : 202);
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
