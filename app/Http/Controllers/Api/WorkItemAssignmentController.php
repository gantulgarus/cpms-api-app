<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkItemResource;
use App\Models\Block;
use App\Models\Contractor;
use App\Models\WorkItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ажилд туслан гүйцэтгэгч оноох.
 *
 * ЯАГААД ХЭРЭГТЭЙ: `ApplyBlockDesign` нь ажил үүсгэхдээ `contractor_id = null`
 * гэж тавьдаг. Оноох зам байхгүй байсан тул шинэ блокийн БҮХ ажил хариуцагчгүй
 * үлдэж, гүйцэтгэгчийн бүхэл функц (нэвтрэх код, хамрах хүрээ, өөрөө явц
 * оруулах) ажиллах өгөгдөлгүй болдог байв.
 *
 * Оноолт нь голдуу АЖЛЫН БҮЛГЭЭР явна: "Гоо Засал ХХК — дотор засал бүхэлдээ".
 * Тиймээс бөөнөөр оноох нь үндсэн үйлдэл, ганцаарчилсан нь засвар.
 */
class WorkItemAssignmentController extends Controller
{
    /**
     * POST /blocks/{block}/work-items/assign
     *
     * Ажлын бүлэг эсвэл төрлөөр бөөнөөр ононо.
     */
    public function assign(Request $request, Block $block): JsonResponse
    {
        $this->authorizeAssign($request);

        $validated = $request->validate([
            // Хоёрын аль нэгээр л зорилтоо заана.
            'workTypeGroupId' => ['nullable', 'uuid', 'exists:work_type_groups,id'],
            'workTypeId' => ['nullable', 'uuid', 'exists:work_types,id'],
            /** `null` = хариуцагчийг авах. */
            'contractorId' => ['nullable', 'uuid', 'exists:contractors,id'],
            /**
             * Аль хэдийн хариуцагчтай ажлыг солих эсэх.
             *
             * Анхдагчаар ҮГҮЙ: "Засал бүлгийг Гоо Засалд өг" гэж дарахад
             * өөр компанид зориудаар оноосон 20 ажил чимээгүй шилжих ёсгүй.
             */
            'reassignExisting' => ['nullable', 'boolean'],
        ]);

        abort_if(
            empty($validated['workTypeGroupId']) && empty($validated['workTypeId']),
            422,
            'Ажлын бүлэг эсвэл ажлын төрлийг заана уу.'
        );

        $query = WorkItem::query()->where('block_id', $block->id);

        if (! empty($validated['workTypeId'])) {
            $query->where('work_type_id', $validated['workTypeId']);
        } else {
            $query->whereHas(
                'workType',
                fn ($q) => $q->where('work_type_group_id', $validated['workTypeGroupId'])
            );
        }

        if (! ($validated['reassignExisting'] ?? false)) {
            $query->whereNull('contractor_id');
        }

        // Гүйцэтгэл бүртгэгдсэн ажлын хариуцагчийг солих нь түүхийг гуйвуулна:
        // өмнөх компанийн хийсэн ажил шинэ компанийнх мэт харагдана.
        $started = (clone $query)
            ->where('reported_qty', '>', 0)
            ->when(
                ! empty($validated['contractorId']),
                fn ($q) => $q->where('contractor_id', '!=', $validated['contractorId'])
            )
            ->whereNotNull('contractor_id')
            ->count();

        abort_if(
            $started > 0,
            409,
            "{$started} ажилд гүйцэтгэл аль хэдийн бүртгэгдсэн байна. "
            .'Хариуцагчийг нь солих боломжгүй — гүйцэтгэлийн түүх өөр компанид шилжинэ.'
        );

        $affected = DB::transaction(function () use ($query, $validated) {
            $ids = (clone $query)->pluck('id');

            WorkItem::whereIn('id', $ids)->update([
                'contractor_id' => $validated['contractorId'] ?? null,
            ]);

            return $ids->count();
        });

        return response()->json([
            'data' => [
                'affected' => $affected,
                'contractorId' => $validated['contractorId'] ?? null,
            ],
        ]);
    }

    /**
     * PATCH /work-items/{workItem}/contractor
     *
     * Нэг ажлын хариуцагчийг солино — бөөний оноолтын дараах засвар.
     */
    public function assignOne(Request $request, WorkItem $workItem): JsonResponse
    {
        $this->authorizeAssign($request);

        $validated = $request->validate([
            'contractorId' => ['nullable', 'uuid', 'exists:contractors,id'],
        ]);

        $next = $validated['contractorId'] ?? null;

        abort_if(
            (float) $workItem->reported_qty > 0
                && $workItem->contractor_id !== null
                && $workItem->contractor_id !== $next,
            409,
            'Энэ ажилд гүйцэтгэл бүртгэгдсэн тул хариуцагчийг солих боломжгүй.'
        );

        $workItem->update(['contractor_id' => $next]);

        return response()->json([
            'data' => new WorkItemResource(
                $workItem->refresh()->load(['location', 'workType.group', 'contractor'])
            ),
        ]);
    }

    /**
     * GET /blocks/{block}/assignments
     *
     * Ажлын бүлэг бүр хэнд оноогдсоныг харуулна — "юу онооход үлдсэн бэ".
     */
    public function summary(Block $block): JsonResponse
    {
        $rows = DB::table('work_items as wi')
            ->join('work_types as wt', 'wt.id', '=', 'wi.work_type_id')
            ->join('work_type_groups as g', 'g.id', '=', 'wt.work_type_group_id')
            ->where('wi.block_id', $block->id)
            ->groupBy('g.id', 'g.name', 'wi.contractor_id')
            ->select('g.id as group_id', 'g.name as group_name', 'wi.contractor_id')
            ->selectRaw('count(*) as work_items')
            ->orderBy('g.name')
            ->get();

        $names = Contractor::whereIn('id', $rows->pluck('contractor_id')->filter())
            ->pluck('name', 'id');

        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->group_id] ??= [
                'groupId' => $row->group_id,
                'groupName' => $row->group_name,
                'workItems' => 0,
                'unassigned' => 0,
                'contractors' => [],
            ];

            $groups[$row->group_id]['workItems'] += (int) $row->work_items;

            if ($row->contractor_id === null) {
                $groups[$row->group_id]['unassigned'] += (int) $row->work_items;

                continue;
            }

            $groups[$row->group_id]['contractors'][] = [
                'id' => $row->contractor_id,
                'name' => $names[$row->contractor_id] ?? '—',
                'workItems' => (int) $row->work_items,
            ];
        }

        return response()->json(['data' => array_values($groups)]);
    }

    /**
     * Хариуцагч оноох эрх.
     *
     * Хэн ямар ажил хийхийг тогтоох нь гэрээний шийдвэр — талбайн инженер
     * өөрөө өөрчилвөл акт, төлбөр буруу компанид очно.
     */
    private function authorizeAssign(Request $request): void
    {
        abort_unless(
            $request->user()?->canManageContractors(),
            403,
            'Танд гүйцэтгэгч оноох эрх байхгүй.'
        );
    }
}
