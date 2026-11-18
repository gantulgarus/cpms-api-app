<?php
namespace App\Services;
use App\Models\WorkItem;
use Illuminate\Validation\ValidationException;

class WorkDates
{
    public function plan(WorkItem $item, string $start, string $end): void
    {
        abort_if($item->accepted_qty > 0, 409, 'Батлагдсан гүйцэтгэлтэй ажлын огноог дахин төлөвлөх боломжгүй. Хугацаа сунгах хүсэлт ашиглана уу.');
        abort_if($end < $start, 422, 'Дуусах огноо эхлэхээс өмнө байж болохгүй.');
        $item->planned_start_date = $start;
        $item->planned_end_date = $end;
        $item->schedule_manual = true;
        // Once work has started, keep the original target for delay comparisons.
        if (! $item->progressEntries()->exists() && ! $item->actual_start_date && ! $item->extensionRequests()->exists()) $item->baseline_end_date = $end;
        $item->save();
    }

    public function actual(WorkItem $item, ?string $start, ?string $end, ?float $reportingQty = null): void
    {
        if ($end && ! $start) throw ValidationException::withMessages(['actualStartDate' => 'Эхэлсэн огноог оруулна уу.']);
        if ($start && $end && $end < $start) throw ValidationException::withMessages(['actualEndDate' => 'Дууссан огноо эхэлснээс өмнө байж болохгүй.']);
        $today = now('Asia/Ulaanbaatar')->toDateString();
        if (($start && $start > $today) || ($end && $end > $today)) throw ValidationException::withMessages(['actualStartDate' => 'Бодит огноо ирээдүйд байж болохгүй.']);
        $reported = (float) $item->reported_qty + ($reportingQty ?? 0);
        if ($end && ($item->planned_qty <= 0 || $reported + 0.001 < (float) $item->planned_qty)) throw ValidationException::withMessages(['actualEndDate' => 'Бүх тоо хэмжээний гүйцэтгэл мэдээлэгдсэний дараа дууссан огноо оруулна уу.']);
        $item->actual_start_date = $start;
        $item->actual_end_date = $end;
        $item->save();
    }
}
