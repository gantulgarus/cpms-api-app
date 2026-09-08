<?php

namespace App\Services;

use App\Models\Location;
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
            ->when($request->query('reviewState'), fn ($q, $v) => $q->where('review_state', $v))
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
