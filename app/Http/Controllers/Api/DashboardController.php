<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Төслийн түвшний нэгтгэл — блок бүрээр НЭГ query.
 *
 * Блок болгонд тусад нь дуудвал 75 барилгад 75 query болно.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly WorkScope $scope) {}

    /** Нээлттэй асуудлын тоо — хэрэглэгчийн харах эрхтэй ажлуудын дотор. */
    private function openIssues(Request $request, Project $project): int
    {
        $visible = WorkItem::query()
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->select('id');

        $this->scope->workItems($visible, $request->user());

        return Issue::where('status', 'open')->whereIn('work_item_id', $visible)->count();
    }

    /** @return array<int, array{category: string, categoryLabel: string, count: int}> */
    private function issuesByCategory(Request $request, Project $project): array
    {
        $visible = WorkItem::query()
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->select('id');

        $this->scope->workItems($visible, $request->user());

        return Issue::query()
            ->where('status', 'open')
            ->whereIn('work_item_id', $visible)
            ->groupBy('category')
            ->selectRaw('category, count(*) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'categoryLabel' => Issue::CATEGORY_LABELS[$r->category] ?? $r->category,
                'count' => (int) $r->total,
            ])
            ->all();
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $query = DB::table('work_items as wi')
            ->join('blocks as b', 'b.id', '=', 'wi.block_id')
            ->where('b.project_id', $project->id);

        // Гүйцэтгэгчийн хувьд энэ нь "төслийн явц" биш "миний ажлын явц".
        // Хамрах хүрээг мартвал 3% нь 60% болж харагдана.
        $this->scope->rawWorkItems($query, $request->user());

        $rows = $query
            ->groupBy('b.id', 'b.name')
            ->select('b.id', 'b.name')
            ->selectRaw('sum(wi.planned_qty) as planned_qty')
            // Мэдээлэгдсэн ба батлагдсаны ЗӨРҮҮ нь маргааны эх үүсвэр —
            // хоёуланг нь буцаана, дэлгэц дээр ялгаж харуулна.
            ->selectRaw('sum(wi.reported_qty) as reported_qty')
            ->selectRaw('sum(wi.accepted_qty) as accepted_qty')
            /*
             * АЖЛЫН ТОО.
             *
             * ЯАГААД ХЭМЖЭЭ БИШ ВЭ: тоо хэмжээг нэмэх нь м², м³, ширхгийг
             * нийлүүлнэ гэсэн үг — «306,970 эхлээгүй» гэсэн тоо ЮУ 306,970
             * болохыг хэлж чадахгүй. Гүйцэтгэлийн акт дээр бид үүнийг зориуд
             * зайлсхийж нэгж тус бүрээр дүн гаргадаг; самбар дээр мөн адил
             * байх ёстой.
             *
             * Ажлын мөрийн тоо нь нэгжгүй: 3,290 ажлаас 358 нь дууссан гэдэг
             * нь шалгаж болохуйц, ойлгомжтой. Сул тал нь том, жижиг ажил
             * ижил жинтэй — гэхдээ худал тооноос үнэн бүдүүн тоо дээр.
             *
             * `status` нь аль хэдийн ГУРВАН харилцан үл огтлолцох бүлэгтэй
             * (completed / in_progress / not_started) тул нийлбэр нь үргэлж
             * нийт тоотой тэнцэнэ.
             */
            ->selectRaw('count(*) as total_items')
            ->selectRaw("sum(case when wi.status = 'completed' then 1 else 0 end) as completed_items")
            ->selectRaw("sum(case when wi.status = 'in_progress' then 1 else 0 end) as in_progress_items")
            /*
             * Ажил бүрийн ӨӨРИЙН хувийн НИЙЛБЭР.
             *
             * Хувь нь энэ нийлбэрийг ажлын тоонд хуваасан дундаж — өөрөөр
             * хэлбэл мөр бүр ижил жинтэй, нэгж нь хоорондоо хамаагүй. Дутуу
             * хийгдсэн ажил хагас оноо авна: зөвхөн бүрэн дууссаныг тоолбол
             * 44% нь эхэлсэн төсөл 3% гэж харагдана.
             *
             * `least()` нь SQLite-д байхгүй тул CASE-ээр бичив.
             */
            ->selectRaw(
                'sum(case
                    when wi.planned_qty <= 0 then 0
                    when wi.accepted_qty >= wi.planned_qty then 1.0
                    else wi.accepted_qty / wi.planned_qty
                end) as progress_sum'
            )
            ->selectRaw("sum(case when wi.review_state = 'pending' then 1 else 0 end) as pending")
            ->selectRaw(
                "sum(case when wi.status <> 'completed' and wi.planned_end_date < ? then 1 else 0 end) as overdue",
                [now()->toDateString()]
            )
            ->orderBy('b.name')
            ->get();

        $planned = $rows->sum(fn ($r) => (float) $r->planned_qty);
        $reported = $rows->sum(fn ($r) => (float) $r->reported_qty);
        $accepted = $rows->sum(fn ($r) => (float) $r->accepted_qty);

        $totalItems = (int) $rows->sum(fn ($r) => (int) $r->total_items);
        $completedItems = (int) $rows->sum(fn ($r) => (int) $r->completed_items);
        $inProgressItems = (int) $rows->sum(fn ($r) => (int) $r->in_progress_items);
        $progressSum = (float) $rows->sum(fn ($r) => (float) $r->progress_sum);

        return response()->json([
            'data' => [
                /*
                 * Хувь нь АЖЛЫН МӨРҮҮДИЙН ДУНДАЖ.
                 *
                 * Урьд нь тоо хэмжээний нийлбэрээр бодогддог байв — тэр нь
                 * м², м³, ширхгийг нийлүүлдэг тул ширхгээр хэмжигддэг ажил
                 * руу татагдана. Дундаж нь мөр бүрийг ижил жинтэй болгоно.
                 */
                'percentage' => $totalItems > 0
                    ? (int) round($progressSum / $totalItems * 100)
                    : 0,
                'totalItems' => $totalItems,
                'completedItems' => $completedItems,
                'inProgressItems' => $inProgressItems,
                'notStartedItems' => max($totalItems - $completedItems - $inProgressItems, 0),
                // Тоо хэмжээ нь ХЭВЭЭР буцна: блокийн дэлгэрэнгүй, акт зэрэгт
                // хэрэгтэй. Зөвхөн самбарын толгойн хувь нь өөрчлөгдөв.
                'plannedQty' => round($planned, 3),
                'reportedQty' => round($reported, 3),
                'acceptedQty' => round($accepted, 3),
                'blocks' => $rows->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'percentage' => (int) $r->total_items > 0
                        ? (int) round((float) $r->progress_sum / (int) $r->total_items * 100)
                        : 0,
                    'totalItems' => (int) $r->total_items,
                    'completedItems' => (int) $r->completed_items,
                    'inProgressItems' => (int) $r->in_progress_items,
                    'notStartedItems' => max(
                        (int) $r->total_items - (int) $r->completed_items - (int) $r->in_progress_items,
                        0
                    ),
                    'plannedQty' => round((float) $r->planned_qty, 3),
                    'reportedQty' => round((float) $r->reported_qty, 3),
                    'acceptedQty' => round((float) $r->accepted_qty, 3),
                    'pendingInspections' => (int) $r->pending,
                    'overdue' => (int) $r->overdue,
                ])->all(),
                'pendingInspections' => (int) $rows->sum(fn ($r) => (int) $r->pending),
                'overdueWorkItems' => (int) $rows->sum(fn ($r) => (int) $r->overdue),
                // Урьд нь хатуу 0 байсан — хүснэгт нь байсан ч хэзээ ч
                // уншигддаггүй байв. Хамрах хүрээг мөн мөрдүүлнэ.
                'openIssues' => $this->openIssues($request, $project),
                // "17 ажил хоцорсон" гэдэг тоо ганцаараа шийдвэр гаргуулахгүй.
                // "Түүний 12 нь материал дутсанаас" гэдэг нь гаргуулна.
                'issuesByCategory' => $this->issuesByCategory($request, $project),
            ],
        ]);
    }
}
