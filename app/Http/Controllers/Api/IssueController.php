<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IssueResource;
use App\Models\Issue;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Ажил саатсан шалтгааны бүртгэл.
 *
 * Захиалагчийн нэрлэсэн ангиллууд: гүйцэтгэгч ирээгүй, гүйцэтгэгч хоцорсон,
 * техник эвдэрсэн, материал дутсан, цаг агаар, гомдол, осол.
 *
 * Яагаад чухал: "хоцорсон" гэдэг тоо ганцаараа ямар ч шийдвэр гаргуулахгүй.
 * "17 ажил хоцорсоны 12 нь материал дутсанаас" гэдэг нь шийдвэр гаргуулна.
 */
class IssueController extends Controller
{
    public function __construct(private readonly WorkScope $scope) {}

    /**
     * GET /projects/{project}/issues — төслийн бүх асуудал.
     *
     * Шүүлтүүр: status, category, severity, blockId.
     *
     * ЯАГААД БАРИЛГААР ШҮҮНЭ: «материал дутсан» гэсэн 40 бүртгэл нэг барилга
     * дээр овоорсон бол ханган нийлүүлэлтийн асуудал биш, тэр объектын
     * логистикийн асуудал. Ялгаж харахгүй бол хоёулаа ижил харагдана.
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $query = Issue::query()
            ->with(['workItem.location', 'workItem.block', 'reportedBy'])
            ->whereIn('work_item_id', $this->visibleWorkItems($request, $project, $request->query('blockId')))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->query('severity'), fn ($q, $v) => $q->where('severity', $v))
            // Нээлттэй нь эхэнд — шийдэгдсэн бүртгэл түүх, нээлттэй нь ажил.
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->latest('created_at');

        return IssueResource::collection($query->get());
    }

    /** GET /work-items/{workItem}/issues */
    public function forWorkItem(WorkItem $workItem): AnonymousResourceCollection
    {
        return IssueResource::collection(
            $workItem->issues()->with('reportedBy')->latest('created_at')->get()
        );
    }

    /** POST /work-items/{workItem}/issues */
    public function store(Request $request, WorkItem $workItem): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(Issue::CATEGORIES)],
            'severity' => ['nullable', Rule::in(Issue::SEVERITIES)],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $issue = $workItem->issues()->create([
            'reported_by_id' => $request->user()?->id,
            'category' => $validated['category'],
            'severity' => $validated['severity'] ?? 'medium',
            'status' => 'open',
            'description' => $validated['description'],
        ]);

        return response()->json(['data' => new IssueResource($issue->load('reportedBy'))], 201);
    }

    /**
     * PATCH /issues/{issue} — шийдвэрлэх эсвэл дахин нээх.
     *
     * Мэдээлсэн хүн өөрөө хааж болно: асуудал нь буруутан хайх бус, хаана
     * саатал байгааг харуулах хэрэгсэл. Хаахад саад тавивал хүмүүс огт
     * бүртгэхээ болино.
     */
    public function update(Request $request, Issue $issue): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Issue::STATUSES)],
            'severity' => ['nullable', Rule::in(Issue::SEVERITIES)],
        ]);

        $issue->update([
            'status' => $validated['status'],
            'severity' => $validated['severity'] ?? $issue->severity,
            'resolved_at' => $validated['status'] === 'resolved' ? now() : null,
        ]);

        return response()->json(['data' => new IssueResource($issue->refresh()->load('reportedBy'))]);
    }

    /** Хэрэглэгчийн харах эрхтэй ажлын id-ууд — гүйцэтгэгч бусдын асуудлыг харахгүй. */
    private function visibleWorkItems(Request $request, Project $project, ?string $blockId = null)
    {
        $query = WorkItem::query()
            ->whereIn('block_id', $project->blocks()->select('id'))
            // Блокийн шүүлтийг ЭНД тавина — төслийн хүрээнд үлдэх тул өөр
            // төслийн блокийн id дамжуулж өгөгдөл гаргаж авах боломжгүй.
            ->when($blockId, fn ($q, $v) => $q->where('block_id', $v))
            ->select('id');

        return $this->scope->workItems($query, $request->user());
    }
}
