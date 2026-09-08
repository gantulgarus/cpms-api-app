<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistTemplateResource;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\WorkItem;
use App\Services\ChecklistGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Чанарын шалгах хуудасны загвар.
 *
 * Ерөнхий инженер ажлын төрөл бүрт "юуг шалгах вэ" гэдгийг тодорхойлно.
 * Загвар байгаа ажлыг checklist бөглөхгүйгээр батлах боломжгүй болно
 * (`ChecklistGate`).
 */
class ChecklistController extends Controller
{
    public function __construct(private readonly ChecklistGate $gate) {}

    /**
     * GET /work-items/{workItem}/checklist
     *
     * Дэлгэц шалгалт хийхээсээ өмнө "юу бөглөх вэ" гэдгийг эндээс асууна.
     * Загваргүй бол `data: null` — хаалт байхгүй гэсэн үг.
     */
    public function forWorkItem(WorkItem $workItem): JsonResponse
    {
        $template = $this->gate->templateFor($workItem);

        return response()->json([
            'data' => $template ? new ChecklistTemplateResource($template) : null,
        ]);
    }

    /** GET /checklist-templates */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ChecklistTemplate::query()
            ->with(['items', 'workType', 'group'])
            ->when($request->query('workTypeId'), fn ($q, $v) => $q->where('work_type_id', $v))
            ->when($request->query('groupId'), fn ($q, $v) => $q->where('work_type_group_id', $v))
            ->orderBy('name');

        return ChecklistTemplateResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate($this->rules());
        $this->guardTarget($validated);

        $template = ChecklistTemplate::create([
            'name' => $validated['name'],
            'work_type_id' => $validated['workTypeId'] ?? null,
            'work_type_group_id' => $validated['groupId'] ?? null,
            'is_active' => $validated['isActive'] ?? true,
        ]);

        // Загварыг зүйлүүдтэй нь нэг дуудлагаар үүсгэх боломжтой — хоосон
        // загвар үлдээх нь хаалт үүсгэхгүй тул алдаа руу хөтөлнө.
        foreach ($validated['items'] ?? [] as $i => $item) {
            $template->items()->create([
                'sequence_number' => $item['sequenceNumber'] ?? $i,
                'text' => $item['text'],
                'guidance' => $item['guidance'] ?? null,
                'is_required' => $item['isRequired'] ?? true,
            ]);
        }

        return response()->json(
            ['data' => new ChecklistTemplateResource($template->load(['items', 'workType', 'group']))],
            201
        );
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $checklistTemplate->update(array_filter([
            'name' => $validated['name'] ?? null,
        ], fn ($v) => $v !== null) + [
            'is_active' => $validated['isActive'] ?? $checklistTemplate->is_active,
        ]);

        return response()->json([
            'data' => new ChecklistTemplateResource($checklistTemplate->load(['items', 'workType', 'group'])),
        ]);
    }

    /**
     * DELETE /checklist-templates/{template}
     *
     * Ашиглагдсан загварыг устгахгүй — хийгдсэн шалгалтын хариултууд эзэнгүй
     * болно. Оронд нь идэвхгүй болгоно.
     */
    public function destroy(Request $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $this->authorizeManage($request);

        $used = Inspection::whereHas(
            'checklistAnswers.item',
            fn ($q) => $q->where('checklist_template_id', $checklistTemplate->id)
        )->exists();

        if ($used) {
            $checklistTemplate->update(['is_active' => false]);

            return response()->json([
                'data' => new ChecklistTemplateResource($checklistTemplate->load(['items', 'workType', 'group'])),
                'meta' => ['deactivated' => true],
            ]);
        }

        $checklistTemplate->delete();

        return response()->json(null, 204);
    }

    // -----------------------------------------------------------------
    // Зүйлүүд
    // -----------------------------------------------------------------

    public function storeItem(Request $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'guidance' => ['nullable', 'string', 'max:255'],
            'isRequired' => ['nullable', 'boolean'],
            'sequenceNumber' => ['nullable', 'integer', 'min:0'],
        ]);

        $checklistTemplate->items()->create([
            'text' => $validated['text'],
            'guidance' => $validated['guidance'] ?? null,
            'is_required' => $validated['isRequired'] ?? true,
            'sequence_number' => $validated['sequenceNumber']
                ?? ((int) $checklistTemplate->items()->max('sequence_number') + 1),
        ]);

        return response()->json([
            'data' => new ChecklistTemplateResource($checklistTemplate->load(['items', 'workType', 'group'])),
        ], 201);
    }

    public function destroyItem(Request $request, ChecklistItem $checklistItem): JsonResponse
    {
        $this->authorizeManage($request);

        $checklistItem->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'workTypeId' => ['nullable', 'uuid', 'exists:work_types,id'],
            'groupId' => ['nullable', 'uuid', 'exists:work_type_groups,id'],
            'isActive' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array'],
            'items.*.text' => ['required', 'string', 'max:255'],
            'items.*.guidance' => ['nullable', 'string', 'max:255'],
            'items.*.isRequired' => ['nullable', 'boolean'],
            'items.*.sequenceNumber' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Загвар нь ямар нэг зүйлд холбогдсон байх ёстой.
     *
     * Хоёулаа хоосон бол ямар ч ажилд хамаарахгүй, хоёулаа дүүрэн бол аль нь
     * давамгайлах нь ойлгомжгүй болно.
     */
    private function guardTarget(array $validated): void
    {
        $hasType = ! empty($validated['workTypeId']);
        $hasGroup = ! empty($validated['groupId']);

        abort_if(
            ! $hasType && ! $hasGroup,
            422,
            'Загварыг ажлын төрөл эсвэл бүлэгт холбоно уу.'
        );

        abort_if(
            $hasType && $hasGroup,
            422,
            'Ажлын төрөл ба бүлгийн аль нэгийг сонгоно уу — хоёуланг нь биш.'
        );
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            $request->user()?->canManageReferenceData(),
            403,
            'Танд чанарын хуудас засах эрх байхгүй.'
        );
    }
}
