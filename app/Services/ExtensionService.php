<?php

namespace App\Services;

use App\Models\ExtensionRequest;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Хугацаа сунгах урсгал.
 *
 * ГОЛ ЗАРЧИМ: `baseline_end_date` нь ХЭЗЭЭ Ч хөдөлдөггүй. Сунгалт зөвхөн
 * `planned_end_date`-ийг хойшлуулна. Ингэснээр «үндсэн төлөвлөгөөнөөс хэр
 * гулссан» гэдэг тоо нуугдах арга байхгүй болно.
 *
 * Хоёр зам НЭГ код дундуур явна:
 *
 *   · батлах эрхтэй хүн сунгавал → хүсэлт үүсч, ТЭР ДОР НЬ батлагдана
 *   · бусад хүн сунгавал        → хүсэлт `pending` болж хүлээнэ
 *
 * Хоёрыг тусдаа бичвэл «шууд сунгалт» нь баримт үлдээхгүй зам болж,
 * хяналт нь зөвхөн сул үүргүүдэд хамаарна.
 */
class ExtensionService
{
    /**
     * Сунгалт хүсэх. Эрхтэй хүн бол шууд батлагдана.
     */
    public function request(
        WorkItem $workItem,
        User $user,
        string $requestedEndDate,
        string $category,
        string $reason,
    ): ExtensionRequest {
        $current = $workItem->planned_end_date;
        $next = \Carbon\Carbon::parse($requestedEndDate)->startOfDay();

        /*
         * Хойшлуулах нь сунгах. Огноог УРАГШ татах нь өөр үйлдэл бөгөөд
         * хоцролтыг хиймлээр үүсгэнэ — тэр нь сунгалтын урвуу биш,
         * дахин төлөвлөлт.
         */
        if ($current && $next->lte($current)) {
            throw ValidationException::withMessages([
                'plannedEndDate' => sprintf(
                    'Шинэ огноо одоогийнхоос (%s) хойш байх ёстой.',
                    $current->toDateString(),
                ),
            ]);
        }

        /*
         * Нэг ажилд ХОЁР хүлээгдэж буй хүсэлт байж болохгүй.
         *
         * Эс бөгөөс хоёр хүн өөр өөр огноо хүсээд, аль нь батлагдсанаас
         * хамаарч хуваарь санамсаргүй болно.
         */
        $openRequest = $workItem->extensionRequests()->where('status', 'pending')->first();

        if ($openRequest) {
            throw ValidationException::withMessages([
                'plannedEndDate' => sprintf(
                    'Энэ ажилд %s хүртэл сунгах хүсэлт аль хэдийн хүлээгдэж байна.',
                    $openRequest->requested_end_date->toDateString(),
                ),
            ]);
        }

        return DB::transaction(function () use ($workItem, $user, $current, $next, $category, $reason) {
            $request = $workItem->extensionRequests()->create([
                'requested_by_id' => $user->id,
                'current_end_date' => $current?->toDateString(),
                'requested_end_date' => $next->toDateString(),
                'category' => $category,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            if (in_array($user->role, ExtensionRequest::APPROVER_ROLES, true)) {
                $this->approve($request, $user, null);
            }

            return $request->refresh();
        });
    }

    /**
     * Хүсэлтийг батлаад огноог хойшлуулна.
     */
    public function approve(ExtensionRequest $request, User $user, ?string $note): ExtensionRequest
    {
        $this->guardPending($request);

        return DB::transaction(function () use ($request, $user, $note) {
            $item = $request->workItem;

            /*
             * ЗӨВХӨН `planned_end_date` хөдөлнө. `baseline_end_date` хэвээр —
             * тэр нь гулсалтыг хэмжих цорын гагц тогтмол цэг.
             */
            $item->update(['planned_end_date' => $request->requested_end_date->toDateString()]);

            // Батлагдсан сунгалт нь сааталд бүртгэгдэнэ — хоцролтын шалтгаан
            // нэг дор хуримтлагдах ёстой.
            $issue = $item->issues()->create([
                'reported_by_id' => $request->requested_by_id,
                'category' => $request->category,
                'severity' => 'medium',
                'status' => 'open',
                'description' => sprintf(
                    'Хугацаа сунгав: %s → %s (%d хоног). %s',
                    $request->current_end_date?->toDateString() ?? '—',
                    $request->requested_end_date->toDateString(),
                    $request->requested_days,
                    $request->reason,
                ),
            ]);

            $request->update([
                'status' => 'approved',
                'decided_by_id' => $user->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'issue_id' => $issue->id,
            ]);

            return $request->refresh();
        });
    }

    /**
     * Татгалзах — шалтгаан ЗААВАЛ.
     *
     * «Болохгүй» гэж орхивол хүсэгч юу засахаа мэдэхгүй, дараа нь ижил
     * хүсэлтийг дахин илгээнэ.
     */
    public function reject(ExtensionRequest $request, User $user, string $note): ExtensionRequest
    {
        $this->guardPending($request);

        $request->update([
            'status' => 'rejected',
            'decided_by_id' => $user->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        return $request->refresh();
    }

    private function guardPending(ExtensionRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Энэ хүсэлт аль хэдийн шийдэгдсэн байна.',
            ]);
        }
    }
}
