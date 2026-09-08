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

    /** GET /projects/{project}/issues — төслийн бүх асуудал. */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $query = Issue::query()
            ->with(['workItem.location', 'reportedBy'])
            ->whereIn('work_item_id', $this->visibleWorkItems($request, $project))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->query('severity'), fn ($q, $v) => $q->where('severity', $v))
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
    private function visibleWorkItems(Request $request, Project $project)
    {
        $query = WorkItem::query()
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->select('id');

        return $this->scope->workItems($query, $request->user());
    }
}
