<?php

namespace App\Services;

use App\Models\Location;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * WorkItem жагсаалтын шүүлтүүр.
 *
 * Жагсаалт ба нэгтгэл хоёр ижил шүүлтүүрийг хүлээж авах ёстой — эс бөгөөс
 * нэгтгэлд "204 ажил" гэж бичээд, дарахад өөр тоо гарна.
 */
class WorkItemFilter
{
    public function __construct(private readonly WorkScope $scope) {}

    public function apply(Builder $query, Request $request): Builder
    {
        // Хамрах хүрээг ХАМГИЙН ЭХЭНД тавина: доорх шүүлтүүрүүд нь хэрэглэгчийн
        // хүсэлт, энэ нь хориг. Хэрэглэгч `contractorId=<өөр компани>` гэж
        // илгээсэн ч энэ мөр нь давхарлаж хязгаарлана.
        $this->scope->workItems($query, $request->user());

        if ($locationId = $request->query('locationId')) {
            $this->byLocation($query, $locationId, $request->boolean('includeDescendants'));
        }

        $query
            ->when($request->query('workTypeId'), fn ($q, $v) => $q->where('work_type_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            /*
             * `reviewState` нь нэг төлөв ЭСВЭЛ бүлэг байж болно.
             *
             * Хоёр шат болоход «батлахыг хүлээж буй» гэсэн ойлголт хоёр
             * төлөв рүү салсан. Дэлгэц дээр «нийт хүлээгдэж буй 412»
             * гэсэн карт дарахад хоёуланг нь харуулах хэрэгтэй — тиймээс
             * `pending` ба `returned` нь БҮЛГИЙН нэр болж үлдэнэ.
             *
             * Бүлгийг далд дүрэм болгохгүй, `WorkItem`-ийн тогтмолоос
             * уншина: шинэ төлөв нэмэгдвэл энд дагаж өөрчлөгдөнө.
             */
            ->when($request->query('reviewState'), fn ($q, $v) => match ($v) {
                'pending' => $q->whereIn('review_state', WorkItem::PENDING_STATES),
                'returned' => $q->whereIn('review_state', WorkItem::RETURNED_STATES),
                default => $q->where('review_state', $v),
            })
            ->when($request->query('workTypeGroupId'), fn ($q, $v) => $q->whereHas(
                'workType',
                fn ($w) => $w->where('work_type_group_id', $v)
            ))
            ->when($request->query('overdueDays'), fn ($q, $v) => $q
                ->where('status', '!=', 'completed')
                ->whereDate('planned_end_date', '<', now()->subDays((int) $v)))
            ->when($request->query('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"));

        // `contractorId=none` — хариуцагч оноогдоогүй ажлууд.
        $contractorId = $request->query('contractorId');
        if ($contractorId === 'none') {
            $query->whereNull('contractor_id');
        } elseif ($contractorId) {
            $query->where('contractor_id', $contractorId);
        }

        /*
         * `inspectorId=none` — хяналтын инженер оноогдоогүй ажлууд.
         *
         * Энэ нь ердийн шүүлтүүр биш, ХЭРЭГТЭЙ самбар: төсвийн 36 мөрд
         * инженер заагаагүй тул тэдгээрийн гүйцэтгэл батлахыг хүлээхэд хэн
         * рүү ч явахгүй. Жагсаалтаар харуулж чадахгүй бол хэн ч эс мэдэнэ.
         *
         * `inspectorId=me` — хяналтын инженер өөрийн ажлыг хурдан олох зам.
         */
        $inspectorId = $request->query('inspectorId');
        if ($inspectorId === 'none') {
            $query->whereNull('inspector_id');
        } elseif ($inspectorId === 'me') {
            $query->where('inspector_id', $request->user()?->id);
        } elseif ($inspectorId) {
            $query->where('inspector_id', $inspectorId);
        }

        return $query;
    }

    /**
     * Байршлаар шүүх. `includeDescendants` үед materialized path ашиглана —
     * рекурс query хэрэггүй, индекстэй prefix хайлт болно.
     */
    private function byLocation(Builder $query, string $locationId, bool $includeDescendants): void
    {
        if (! $includeDescendants) {
            $query->where('location_id', $locationId);

            return;
        }

        $root = Location::find($locationId);

        if (! $root) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn(
            'location_id',
            Location::query()
                ->where('path_key', 'like', $root->path_key.'%')
                ->select('id')
        );
    }
}
