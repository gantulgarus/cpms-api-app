<?php

namespace App\Services;

use App\Models\Agreement;
use App\Models\Block;
use App\Models\Contractor;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Тохиролцооны хуудасны урсгал.
 *
 * ГОЛ ЗАРЧИМ: ажлын мөрүүд ТӨСВӨӨС татагдана. Гэрээний мэргэжилтэн 64 мөрийг
 * гараар бичих нь алдаа гаргах цорын гагц зам — тоо хэмжээ төсөвтэй зөрвөл
 * акт, төлбөр бүхэлдээ буруу болно.
 */
class AgreementService
{
    /**
     * Блок + ажлын бүлгээс тохиролцооны мөрүүдийг БЭЛТГЭНЭ.
     *
     * Хадгалахгүй — дэлгэц дээр харуулж, гэрээний мэргэжилтэн үнэ тавьж,
     * мөр хасаж байж хадгална.
     *
     * @param  list<string>  $groupIds
     * @return list<array<string, mixed>>
     */
    public function draftLines(Block $block, array $groupIds): array
    {
        $rows = WorkItem::query()
            ->with('workType.group')
            ->where('block_id', $block->id)
            ->when($groupIds !== [], fn ($q) => $q->whereHas(
                'workType',
                fn ($w) => $w->whereIn('work_type_group_id', $groupIds)
            ))
            ->where('planned_qty', '>', 0)
            ->get();

        /*
         * Ажлын ТӨРЛӨӨР нэгтгэнэ.
         *
         * Блокт нэг ажлын төрөл 148 мөр байж болно (айл бүрд). Гэрээнд тэднийг
         * тус тусад нь бичих нь утгагүй: тохиролцоо нь «шавардлага 2,400 м²»
         * гэж нэг мөр байх ёстой.
         */
        $grouped = [];

        foreach ($rows as $item) {
            $key = $item->work_type_id;

            $grouped[$key] ??= [
                'workTypeId' => $key,
                'label' => $item->workType->name,
                'groupName' => $item->workType->group?->name,
                'unit' => $item->unit,
                'quantity' => 0.0,
                /*
                 * Төсвийн үнэ — мөрүүдэд ижил байх ёстой. Зөрвөл ХАМГИЙН
                 * ТОМЫГ авна: доогуур үнээр гэрээлүүлэх эрсдэлээс
                 * хамгаална, мөн зөрүүг тайланд гаргана.
                 */
                'budgetUnitPrice' => null,
                'priceConflict' => false,
            ];

            $grouped[$key]['quantity'] += (float) $item->planned_qty;

            $price = $item->budget_unit_price !== null ? (float) $item->budget_unit_price : null;

            if ($price !== null) {
                $current = $grouped[$key]['budgetUnitPrice'];

                if ($current !== null && abs($current - $price) > 0.009) {
                    $grouped[$key]['priceConflict'] = true;
                }

                $grouped[$key]['budgetUnitPrice'] = max($current ?? 0, $price);
            }
        }

        return array_values(array_map(function (array $row) {
            $row['quantity'] = round($row['quantity'], 3);

            return $row;
        }, $grouped));
    }

    /**
     * Тохиролцоо үүсгэнэ — драфт.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(Block $block, Contractor $contractor, User $user, array $data, array $lines): Agreement
    {
        $this->guardContractor($contractor);
        $this->guardPercents($data);

        return DB::transaction(function () use ($block, $contractor, $user, $data, $lines) {
            $agreement = Agreement::create([
                ...$data,
                'number' => $this->nextNumber(),
                'block_id' => $block->id,
                'contractor_id' => $contractor->id,
                'status' => 'draft',
                'created_by_id' => $user->id,
            ]);

            $this->replaceLines($agreement, $lines);

            return $agreement->refresh();
        });
    }

    /**
     * Мөрүүдийг бүхэлд нь СОЛИНО.
     *
     * Хэсэгчилсэн засвар нь дэлгэц ба сервер хоёрын мөрийн жагсаалтыг салгах
     * эрсдэлтэй: хасагдсан мөр серверт үлдэж, нийт дүн зөрнө.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function replaceLines(Agreement $agreement, array $lines): void
    {
        if (! $agreement->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Батлагдсан тохиролцооны мөрийг засах боломжгүй. '
                    .'Акт нь эдгээр үнэ дээр тогтдог.',
            ]);
        }

        DB::transaction(function () use ($agreement, $lines) {
            $agreement->lines()->delete();

            foreach (array_values($lines) as $i => $line) {
                $agreement->lines()->create([
                    'work_type_id' => $line['workTypeId'],
                    'label' => $line['label'] ?? null,
                    'unit' => $line['unit'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unitPrice'],
                    /*
                     * Төсвийн үнийг ХӨШӨӨЛЖ авна.
                     *
                     * Төсөв хожим дахин импортлогдоход гэрээний харьцуулалт
                     * хөдлөх ёсгүй — тэр нь гэрээ бичих үеийн баримт.
                     */
                    'budget_unit_price' => $line['budgetUnitPrice'] ?? null,
                    'is_extra' => (bool) ($line['isExtra'] ?? false),
                    'sequence_number' => $i + 1,
                ]);
            }
        });
    }

    /**
     * Батлах — мөр түгжигдэнэ.
     */
    public function approve(Agreement $agreement, User $user): Agreement
    {
        if ($agreement->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Зөвхөн драфт тохиролцоо батлагдана.',
            ]);
        }

        $agreement->load('lines');

        if ($agreement->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Ажлын мөргүй тохиролцоо батлагдахгүй — төлбөр бодогдох зүйл алга.',
            ]);
        }

        /*
         * Үнэгүй мөр батлагдахгүй.
         *
         * 0₮-ийн мөр нь актад орох боловч дүн нэмэхгүй. Гэрээний мэргэжилтэн
         * үнэ тавихаа мартсан гэдэг нь хамгийн магадлалтай тайлбар — батлах
         * нь түүнийг мөнхжүүлнэ.
         */
        $free = $agreement->lines->filter(fn ($l) => (float) $l->unit_price <= 0);

        if ($free->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    '%d мөрд нэгж үнэ тавигдаагүй: %s',
                    $free->count(),
                    $free->take(3)->map(fn ($l) => $l->label ?? $l->workType?->name)->implode(', '),
                ),
            ]);
        }

        $agreement->update([
            'status' => 'active',
            'approved_by_id' => $user->id,
            'approved_at' => now(),
        ]);

        return $agreement->refresh();
    }

    /** Бартер + бэлэн = 100%. */
    private function guardPercents(array $data): void
    {
        $sum = (float) ($data['barter_percent'] ?? 0) + (float) ($data['cash_percent'] ?? 0);

        if (abs($sum - 100) > 0.01) {
            throw ValidationException::withMessages([
                'cashPercent' => sprintf(
                    'Бартер ба бэлэн мөнгөний нийлбэр 100%% байх ёстой — одоо %s%%.',
                    rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.'),
                ),
            ]);
        }
    }

    /**
     * Профайл дутуу гүйцэтгэгчтэй тохиролцоо бичигдэхгүй.
     *
     * РД, данс, банкгүйгээр хуудас хэвлэгдэхгүй, төлбөр гарахгүй. Дутуу
     * байхад бичих нь зөвхөн асуудлыг хожим гаргана.
     */
    private function guardContractor(Contractor $contractor): void
    {
        if (! $contractor->canTakeWork()) {
            throw ValidationException::withMessages([
                'contractorId' => sprintf(
                    '%s нь «%s» төлөвтэй — тохиролцоо бичих боломжгүй.',
                    $contractor->name,
                    Contractor::STATUSES[$contractor->status] ?? '—',
                ),
            ]);
        }

        $missing = $contractor->missingContractFields();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'contractorId' => 'Гүйцэтгэгчийн мэдээлэл дутуу: '.implode(', ', $missing).'.',
            ]);
        }
    }

    /**
     * Дараагийн дугаар — «ТХ-2026-001».
     *
     * Он тус бүрд 1-ээс эхэлнэ: гэрээний дугаарлалт нь жилээр явдаг практик.
     */
    private function nextNumber(): string
    {
        $year = now()->year;
        $prefix = "ТХ-{$year}-";

        $last = Agreement::where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
