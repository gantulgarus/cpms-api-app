<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInspectionRequest;
use App\Http\Requests\StoreProgressRequest;
use App\Http\Resources\InspectionResource;
use App\Http\Resources\ProgressEntryResource;
use App\Http\Resources\WorkItemCollection;
use App\Http\Resources\WorkItemResource;
use App\Models\Block;
use App\Models\ProgressEntry;
use App\Models\WorkItem;
use App\Services\BlockSummaryService;
use App\Services\WorkItemFilter;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WorkItemController extends Controller
{
    /** Жагсаалтын хамгийн их хуудасны хэмжээ — client `meta.total`-оор үргэлжлүүлнэ. */
    private const MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly WorkItemFilter $filter,
        private readonly BlockSummaryService $summary,
        private readonly WorkScope $scope,
    ) {}

    /** GET /blocks/{block}/work-items */
    public function index(Request $request, Block $block): WorkItemCollection
    {
        // `$block->workItems()` нь HasMany харилцаа буцаадаг бөгөөд шүүлтүүр нь
        // Eloquent\Builder хүлээдэг. Тиймээс query-г шууд үүсгэнэ.
        $query = WorkItem::query()
            ->where('block_id', $block->id)
            ->with(['location', 'workType.group', 'contractor'])
            ->orderBy('name');

        $this->filter->apply($query, $request);

        $pageSize = min((int) $request->query('pageSize', 50), self::MAX_PAGE_SIZE);

        // WorkItemCollection нь `meta`-г гэрээний `{ total, page, pageSize }`
        // хэлбэрт оруулна — Laravel-ийн `current_page`/`per_page` биш.
        return new WorkItemCollection($query->paginate(max($pageSize, 1)));
    }

    /** GET /blocks/{block}/summary */
    public function summary(Request $request, Block $block): JsonResponse
    {
        $groupBy = $request->query('groupBy', 'floor');

        abort_unless(in_array($groupBy, BlockSummaryService::GROUPINGS, true), 422, 'groupBy буруу.');

        // Нэгтгэл нь жагсаалттай ЯГ ижил хамрах хүрээтэй байх ёстой. Эс бөгөөс
        // гүйцэтгэгчид "5 давхар: 204 ажил" гэж бичээд, дарахад 12 гарч ирнэ.
        $user = $request->user();

        return response()->json([
            'data' => $this->summary->summarize(
                $block,
                $groupBy,
                fn ($query) => $this->scope->rawWorkItems($query, $user),
            ),
        ]);
    }

    /** GET /work-items/{workItem} */
    public function show(WorkItem $workItem): JsonResponse
    {
        $workItem->load(['location', 'workType.group', 'contractor']);
        // Татгалзсан нийт хэмжээ = дахин хийсэн ажлын хэмжээ. Тусдаа мөр
        // үүсгэхгүйгээр дахин хийлтийн зардлыг ингэж хэмжинэ.
        $workItem->loadSum('inspections', 'rejected_qty');

        return response()->json(['data' => new WorkItemResource($workItem)]);
    }

    /** GET /work-items/{workItem}/progress — үргэлж шинэ→хуучин. */
    public function progress(WorkItem $workItem): AnonymousResourceCollection
    {
        return ProgressEntryResource::collection(
            $workItem->progressEntries()
                ->with(['reportedBy', 'photos.uploadedBy'])
                ->latest('recorded_at')
                ->get()
        );
    }

    /**
     * POST /work-items/{workItem}/progress
     *
     * Дүнг transaction дотор шинэчилнэ — зэрэг ирсэн хоёр мэдээлэл нэг нэгнийгээ
     * дарж бичихээс сэргийлнэ.
     */
    public function storeProgress(StoreProgressRequest $request, WorkItem $workItem): JsonResponse
    {
        $entry = DB::transaction(function () use ($request, $workItem) {
            $locked = WorkItem::lockForUpdate()->find($workItem->id);

            $entry = $locked->progressEntries()->create([
                'reported_by_id' => $request->user()?->id,
                'contractor_id' => $locked->contractor_id,
                'completed_qty' => $request->validated('completedQty'),
                'workers_count' => $request->validated('workersCount'),
                'remarks' => $request->validated('remarks'),
                'recorded_at' => $request->validated('recordedAt') ?? now(),
            ]);

            $locked->recalculate();

            return $entry;
        });

        return response()->json(
            ['data' => new ProgressEntryResource($entry->load(['reportedBy', 'photos']))],
            201,
        );
    }

    /** GET /work-items/{workItem}/inspections */
    public function inspections(WorkItem $workItem): AnonymousResourceCollection
    {
        return InspectionResource::collection(
            $workItem->inspections()
                ->with(['inspector', 'checklistAnswers.item'])
                ->latest('inspected_at')->get()
        );
    }

    /**
     * POST /work-items/{workItem}/inspections
     *
     * ТАТГАЛЗСАН ХЭМЖЭЭ нь мэдээлсэн дүнгээс ХАСАГДАНА (`recalculate()`).
     * Тиймээс гүйцэтгэгч засвараа ижил мөрөн дээрээ дахин мэдээлнэ — тусад
     * нь «дахин хийх» ажлын мөр үүсгэхгүй.
     *
     * Урьд нь шинэ мөр үүсгэдэг байсан нь хоёр алдаа авчирдаг байв:
     * блокийн төлөвлөгөөт хэмжээ хоёр дахин болох, эх мөр нь үүрд 0 хувьтай
     * гацах. Дахин хийлтийн хэмжээ нь шалгалтын түүхэнд (`rejected_qty`)
     * хадгалагдаж байгаа тул мэдээлэл алдагдахгүй.
     */
    public function storeInspection(StoreInspectionRequest $request, WorkItem $workItem): JsonResponse
    {
        $inspection = DB::transaction(function () use ($request, $workItem) {
            $locked = WorkItem::lockForUpdate()->find($workItem->id);

            $acceptedQty = (float) $request->validated('acceptedQty', 0);
            $pending = (float) $locked->reported_qty - (float) $locked->accepted_qty;

            $inspection = $locked->inspections()->create([
                'inspector_id' => $request->user()?->id,
                'stage' => $request->validated('stage'),
                'result' => $request->validated('result'),
                'accepted_qty' => $acceptedQty,
                /*
                 * Татгалзсан хэмжээ ба ХЯНАГДААГҮЙ хэмжээ хоёр ӨӨР зүйл.
                 *
                 * «Батлав» гэсэн шалгалт нь 40-өөс 10-ыг батлахад үлдсэн 30
                 * нь татгалзсан биш — зүгээр л хараахан хянагдаагүй. Хэрэв
                 * татгалзсан гэж тэмдэглэвэл шаардлагагүй «дахин хийх» ажил
                 * үүсч, гүйцэтгэгч буруутгагдана.
                 */
                'rejected_qty' => $request->validated('result') === 'accepted'
                    ? 0
                    : round(max($pending - $acceptedQty, 0), 3),
                'reason' => $request->validated('reason'),
                'inspected_at' => now(),
            ]);

            // Чанарын хуудасны хариулт — шалгалтын салшгүй хэсэг. Транзакц
            // дотор хадгална: хариултгүй шалгалт үлдэх ёсгүй.
            foreach ((array) $request->validated('checklist', []) as $answer) {
                $inspection->checklistAnswers()->create([
                    'checklist_item_id' => $answer['itemId'],
                    'result' => $answer['result'],
                    'note' => $answer['note'] ?? null,
                ]);
            }

            // Шалгалт хийгдсэн тул одоо байгаа зургууд баримт болж түгжигдэнэ
            // (RULE-10: батлагдсан нотолгоог дараа нь өөрчлөхгүй).
            $locked->photos()->where('locked', false)->update(['locked' => true]);

            $locked->recalculate();

            return $inspection;
        });

        return response()->json([
            'data' => new InspectionResource($inspection->load('checklistAnswers.item')),
        ], 201);
    }

    /** DELETE — батлагдсан явцыг устгахыг хориглоно (append-only загвар). */
    public function destroyProgress(ProgressEntry $progress): JsonResponse
    {
        $workItem = $progress->workItem;

        abort_if(
            $workItem->inspections()->where('inspected_at', '>=', $progress->recorded_at)->exists(),
            409,
            'Шалгалт хийгдсэн явцыг устгах боломжгүй.'
        );

        DB::transaction(function () use ($progress, $workItem) {
            $progress->delete();
            $workItem->recalculate();
        });

        return response()->json(null, 204);
    }
}
