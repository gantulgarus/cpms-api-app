<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkTypeGroupResource;
use App\Http\Resources\WorkTypeResource;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Ажлын төрлийн лавлах сан.
 *
 * Захиалагчийн Хавсралт-2-оос 47 төрөл гарсан ч энэ жагсаалт хөлдүү биш:
 * шинэ материал, шинэ ажил гарах бүрд програмист дуудах шаардлагагүй байх
 * ёстой. Тиймээс дэлгэцээс нэмж, засах боломжтой.
 *
 * ГЭХДЭЭ ашиглагдаж эхэлсэн төрлийг устгахыг хориглоно — тэр нь мянган
 * ажлын нэгжийн эцэг, устгавал гүйцэтгэлийн түүх эзэнгүй болно.
 */
class WorkTypeController extends Controller
{
    // -----------------------------------------------------------------
    // Бүлэг
    // -----------------------------------------------------------------

    public function groups(): AnonymousResourceCollection
    {
        return WorkTypeGroupResource::collection(
            WorkTypeGroup::withCount('workTypes')->orderBy('sequence_number')->get()
        );
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('work_type_groups', 'name')],
            'sequenceNumber' => ['nullable', 'integer', 'min:0'],
            'buildOrder' => ['nullable', 'integer', 'min:0'],
        ]);

        $group = WorkTypeGroup::create([
            'name' => $validated['name'],
            'sequence_number' => $validated['sequenceNumber'] ?? $this->nextGroupSequence(),
            'build_order' => $validated['buildOrder'] ?? $this->nextGroupSequence(),
        ]);

        return response()->json(['data' => new WorkTypeGroupResource($group->loadCount('workTypes'))], 201);
    }

    public function updateGroup(Request $request, WorkTypeGroup $workTypeGroup): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255',
                Rule::unique('work_type_groups', 'name')->ignore($workTypeGroup->id)],
            'sequenceNumber' => ['nullable', 'integer', 'min:0'],
            'buildOrder' => ['nullable', 'integer', 'min:0'],
        ]);

        $workTypeGroup->update(array_filter([
            'name' => $validated['name'] ?? null,
            'sequence_number' => $validated['sequenceNumber'] ?? null,
            'build_order' => $validated['buildOrder'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['data' => new WorkTypeGroupResource($workTypeGroup->loadCount('workTypes'))]);
    }

    public function destroyGroup(Request $request, WorkTypeGroup $workTypeGroup): JsonResponse
    {
        $this->authorizeManage($request);

        abort_if(
            $workTypeGroup->workTypes()->exists(),
            409,
            'Энэ бүлэгт ажлын төрөл байгаа тул устгах боломжгүй.'
        );

        $workTypeGroup->delete();

        return response()->json(null, 204);
    }

    // -----------------------------------------------------------------
    // Ажлын төрөл
    // -----------------------------------------------------------------

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = WorkType::query()
            ->with('group')
            // Ашиглагдаж эхэлсэн эсэх — дэлгэц "устгах" товчийг унтраахад.
            ->withCount('workItems')
            ->when($request->query('groupId'), fn ($q, $v) => $q->where('work_type_group_id', $v))
            ->when($request->query('level'), fn ($q, $v) => $q->where('level', $v))
            ->when($request->query('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('sequence_number');

        return WorkTypeResource::collection($query->get());
    }

    public function show(WorkType $workType): JsonResponse
    {
        return response()->json(['data' => new WorkTypeResource($workType->load('group'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate($this->rules());

        $workType = WorkType::create([
            'work_type_group_id' => $validated['groupId'],
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'unit' => $validated['unit'],
            'level' => $validated['level'],
            'sequence_number' => $validated['sequenceNumber'] ?? $this->nextTypeSequence(),
        ]);

        return response()->json(['data' => new WorkTypeResource($workType->load('group'))], 201);
    }

    /**
     * Засах.
     *
     * `level` ба `unit` нь ашиглагдаж эхэлсэн бол ЦООЖТОЙ. Түвшин өөрчлөх нь
     * "энэ ажил давхар бүрт биш, айл бүрт" гэсэн үг — одоо байгаа мянган
     * бичлэг буруу байршилтай үлдэнэ. Нэгж солих нь м²-т хэмжсэн гүйцэтгэлийг
     * ширхэг болгож, бүх тоог утгагүй болгоно.
     */
    public function update(Request $request, WorkType $workType): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate($this->rules($workType));
        $inUse = WorkItem::where('work_type_id', $workType->id)->exists();

        if ($inUse) {
            foreach (['unit' => 'нэгж', 'level' => 'түвшин'] as $field => $label) {
                abort_if(
                    isset($validated[$field]) && $validated[$field] !== $workType->{$field},
                    409,
                    "Энэ төрлөөр ажил үүссэн тул {$label} өөрчлөх боломжгүй. Шинэ төрөл үүсгэнэ үү."
                );
            }
        }

        $workType->update(array_filter([
            'work_type_group_id' => $validated['groupId'] ?? null,
            'name' => $validated['name'] ?? null,
            'code' => $validated['code'] ?? null,
            'unit' => $validated['unit'] ?? null,
            'level' => $validated['level'] ?? null,
            'sequence_number' => $validated['sequenceNumber'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['data' => new WorkTypeResource($workType->refresh()->load('group'))]);
    }

    public function destroy(Request $request, WorkType $workType): JsonResponse
    {
        $this->authorizeManage($request);

        $used = WorkItem::where('work_type_id', $workType->id)->count();

        abort_if(
            $used > 0,
            409,
            "Энэ төрлөөр {$used} ажил үүссэн тул устгах боломжгүй."
        );

        $workType->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(?WorkType $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return [
            'groupId' => [$required, 'uuid', 'exists:work_type_groups,id'],
            'name' => [$required, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'unit' => [$required, 'string', 'max:20'],
            'level' => [$required, Rule::in(WorkType::LEVELS)],
            'sequenceNumber' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function nextGroupSequence(): int
    {
        return (int) WorkTypeGroup::max('sequence_number') + 1;
    }

    private function nextTypeSequence(): int
    {
        return (int) WorkType::max('sequence_number') + 1;
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            $request->user()?->canManageReferenceData(),
            403,
            'Танд лавлах сан засах эрх байхгүй.'
        );
    }
}
