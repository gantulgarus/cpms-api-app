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
     * `type=inspection` — ямар нэг шатыг хүлээж буй (анхдагч)
     * `type=check`      — 1-р шат: талбайн инженер шалгахыг хүлээж буй
     * `type=approval`   — 2-р шат: хяналтын инженер батлахыг хүлээж буй
     * `type=returned`   — буцаагдсан, дахин хийх шаардлагатай
     * `type=overdue`    — хугацаа хэтэрсэн
     *
     * `blockId` — НЭГ блокоор шүүнэ.
     *
     * ЯАГААД ХЭРЭГТЭЙ ВЭ: 75 барилгын цогцолборт бүх блокийн ажил нэг
     * жагсаалтад холилдоно. «Өнөөдөр юу батлах вэ» гэдэгт блок хамаагүй
     * нь ҮНЭН, харин «А1 рүү явж байгаа тул А1-ийнхээ ажлыг нэг дор
     * дуусгая» гэдэг нь бас үнэн — хоёулаа хэрэгтэй.
     */
    public function index(Request $request, Project $project): WorkItemCollection
    {
        $type = $request->query('type', 'inspection');

        $query = WorkItem::query()
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->with(['location', 'workType.group', 'contractor', 'block']);

        $this->scope->workItems($query, $request->user());

        if ($blockId = $request->query('blockId')) {
            $query->where('block_id', $blockId);
        }

        /*
         * Хоёр шат болсон тул «батлахыг хүлээж буй» нь ХЭНИЙГ хүлээж байгаагаас
         * хамаарч хоёр өөр дараалал.
         *
         * Талбайн инженер ба хяналтын инженер НЭГ жагсаалт хуваалцвал
         * хоёулаа 3,711 мөрийг шүүж өөрийнхийг хайх болно — дараалал гэдэг
         * үгийн утга нь тэр хайлтыг арилгах байсан.
         */
        match ($type) {
            'check' => $query->where('review_state', 'pending_check'),
            'approval' => $query->where('review_state', 'pending_approval'),
            'returned' => $query->whereIn('review_state', WorkItem::RETURNED_STATES),
            'overdue' => $query
                ->where('status', '!=', 'completed')
                ->whereDate('planned_end_date', '<', now()->toDateString()),
            default => $query->whereIn('review_state', WorkItem::PENDING_STATES),
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

        // Тоонууд нь жагсаалттай ИЖИЛ шүүлтүүрийг дагана — эс бөгөөс
        // таб дээр «12» гэж бичигдээд жагсаалтад 3 мөр гарна.
        if ($blockId = $request->query('blockId')) {
            $query->where('wi.block_id', $blockId);
        }

        $row = $query
            ->selectRaw('sum('.WorkItem::pendingSql('wi').') as inspection')
            ->selectRaw("sum(case when wi.review_state = 'pending_check' then 1 else 0 end) as check_stage")
            ->selectRaw("sum(case when wi.review_state = 'pending_approval' then 1 else 0 end) as approval_stage")
            ->selectRaw('sum('.WorkItem::returnedSql('wi').') as returned')
            ->selectRaw(
                "sum(case when wi.status <> 'completed' and wi.planned_end_date < ? then 1 else 0 end) as overdue",
                [now()->toDateString()]
            )
            ->first();

        return response()->json([
            'data' => [
                'inspection' => (int) ($row->inspection ?? 0),
                // Шат тус бүрийн тоо — таб дээр «та батлах 12» гэж харуулна.
                'check' => (int) ($row->check_stage ?? 0),
                'approval' => (int) ($row->approval_stage ?? 0),
                'returned' => (int) ($row->returned ?? 0),
                'overdue' => (int) ($row->overdue ?? 0),
            ],
        ]);
    }
}
