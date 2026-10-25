<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExtensionRequestResource;
use App\Models\ExtensionRequest;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\ExtensionService;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Хугацаа сунгах хүсэлтийн дараалал.
 *
 * Хүсэлт нь ХААНА Ч харагдахгүй бол урсгал нь байхгүйтэй адил: гүйцэтгэгч
 * хүсэлт илгээгээд хариу хүлээж, ерөнхий инженер түүнийг хэзээ ч хардаггүй.
 * Тиймээс дараалал нь функцийн салшгүй хэсэг.
 */
class ExtensionRequestController extends Controller
{
    public function __construct(private readonly WorkScope $scope) {}

    /**
     * GET /projects/{project}/extension-requests
     *
     * `status=pending` (анхдагч) · `approved` · `rejected` · `all`
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $status = $request->query('status', 'pending');

        $items = WorkItem::query()->whereIn('block_id', $project->blocks()->select('id'));
        $this->scope->workItems($items, $request->user());

        $query = ExtensionRequest::query()
            ->with(['workItem.location', 'requestedBy', 'decidedBy'])
            ->whereIn('work_item_id', $items->select('id'))
            ->when(
                $status !== 'all',
                fn ($q) => $q->where('status', $status),
            )
            // Хамгийн эрт хүссэн нь дээр — хүлээлт нь эрэмбийн шалгуур.
            ->orderBy('created_at');

        return ExtensionRequestResource::collection($query->get());
    }

    /** GET /work-items/{workItem}/extension-requests — тухайн ажлын түүх. */
    public function forWorkItem(WorkItem $workItem): AnonymousResourceCollection
    {
        return ExtensionRequestResource::collection(
            $workItem->extensionRequests()
                ->with(['requestedBy', 'decidedBy'])
                ->latest('created_at')
                ->get()
        );
    }

    /** POST /extension-requests/{extensionRequest}/approve */
    public function approve(Request $request, ExtensionRequest $extensionRequest): JsonResponse
    {
        $this->authorizeDecision($request);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $decided = app(ExtensionService::class)
            ->approve($extensionRequest, $request->user(), $validated['note'] ?? null);

        return response()->json([
            'data' => new ExtensionRequestResource($decided->load(['requestedBy', 'decidedBy'])),
        ]);
    }

    /**
     * POST /extension-requests/{extensionRequest}/reject
     *
     * Шалтгаан ЗААВАЛ: «болохгүй» гэж орхивол хүсэгч юу засахаа мэдэхгүй,
     * ижил хүсэлтийг дахин илгээнэ.
     */
    public function reject(Request $request, ExtensionRequest $extensionRequest): JsonResponse
    {
        $this->authorizeDecision($request);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'note.required' => 'Татгалзах шалтгааныг бичнэ үү.',
            'note.min' => 'Шалтгаан хэт тодорхойгүй — дор хаяж 10 тэмдэгт бичнэ үү.',
        ]);

        $decided = app(ExtensionService::class)
            ->reject($extensionRequest, $request->user(), $validated['note']);

        return response()->json([
            'data' => new ExtensionRequestResource($decided->load(['requestedBy', 'decidedBy'])),
        ]);
    }

    /**
     * Сунгалт батлах эрх.
     *
     * Төслийн менежер ОРООГҮЙ — хуваарийн гулсалтыг хамгийн их нуумаар
     * байдаг хүн нь тэр хуваарийг хариуцдаг хүн.
     */
    private function authorizeDecision(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role, ExtensionRequest::APPROVER_ROLES, true),
            403,
            'Хугацаа сунгалтыг зөвхөн ерөнхий инженер, админ батална.'
        );
    }
}
