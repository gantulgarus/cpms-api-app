<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkItemCollection;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Надаас юу хүлээж байна" — төслийн хэмжээнд нэг жагсаалт.
 *
 * Урьд нь хяналтын инженер батлах ажлаа олохын тулд блок бүрийг нээж, 3,290
 * мөрийг шүүх ёстой байв. Ажил нь блокоор биш ЦАГААР зохион байгуулагддаг:
 * "өнөөдөр юу батлах вэ" гэсэн асуултад блок хамаагүй.
 *
 * Хамрах хүрээг `WorkScope` мөрдүүлнэ — гүйцэтгэгч энэ дэлгэцээр дамжуулж
 * бусдын ажлыг харах ёсгүй.
 */
class QueueController extends Controller
{
    private const MAX_PAGE_SIZE = 200;

    public function __construct(private readonly WorkScope $scope) {}

    /**
     * GET /projects/{project}/queue
     *
     * `type=inspection` — батлахыг хүлээж буй (анхдагч)
     * `type=returned`   — буцаагдсан, дахин хийх шаардлагатай
     * `type=overdue`    — хугацаа хэтэрсэн
     */
    public function index(Request $request, Project $project): WorkItemCollection
    {
        $type = $request->query('type', 'inspection');

        $query = WorkItem::query()
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->with(['location', 'workType.group', 'contractor', 'block']);

        $this->scope->workItems($query, $request->user());

        match ($type) {
            'returned' => $query->where('review_state', 'returned'),
            'overdue' => $query
                ->where('status', '!=', 'completed')
                ->whereDate('planned_end_date', '<', now()->toDateString()),
            default => $query->where('review_state', 'pending'),
        };

        // Хамгийн удаан хүлээсэн нь дээр — хүлээлт нь дараалалд орох шалгуур.
        $query->orderByRaw('planned_end_date is null, planned_end_date asc')->orderBy('name');

        $pageSize = min((int) $request->query('pageSize', 50), self::MAX_PAGE_SIZE);

        return new WorkItemCollection($query->paginate(max($pageSize, 1)));
    }

    /**
     * GET /projects/{project}/queue/counts
     *
     * Дэлгэцийн таб дээрх тоонууд — гурван жагсаалтыг тус тусад нь татахгүй,
     * НЭГ query-гээр гарна.
     */
    public function counts(Request $request, Project $project): JsonResponse
    {
        $query = DB::table('work_items as wi')
            ->join('blocks as b', 'b.id', '=', 'wi.block_id')
            ->where('b.project_id', $project->id);

        $this->scope->rawWorkItems($query, $request->user());

        $row = $query
            ->selectRaw("sum(case when wi.review_state = 'pending' then 1 else 0 end) as inspection")
            ->selectRaw("sum(case when wi.review_state = 'returned' then 1 else 0 end) as returned")
            ->selectRaw(
                "sum(case when wi.status <> 'completed' and wi.planned_end_date < ? then 1 else 0 end) as overdue",
                [now()->toDateString()]
            )
            ->first();

        return response()->json([
            'data' => [
                'inspection' => (int) ($row->inspection ?? 0),
                'returned' => (int) ($row->returned ?? 0),
                'overdue' => (int) ($row->overdue ?? 0),
            ],
        ]);
    }
}
