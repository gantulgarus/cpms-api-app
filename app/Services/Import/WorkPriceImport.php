<?php

namespace App\Services\Import;

use App\Models\Block;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\WorkBudgetImport;
use App\Services\XlsxReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Prices only: a file cannot create work or change its measurements or history. */
class WorkPriceImport
{
    public static function codeFor(WorkType $type): string
    {
        return $type->catalogEntry ? 'WT-'.str_pad((string) $type->catalogEntry->id, 6, '0', STR_PAD_LEFT)
            : ($type->code ?? 'TYPE:'.$type->id);
    }

    public function report(Block $block, XlsxReader $reader): array
    {
        $works = $block->workItems()->with('workType.catalogEntry')->get();
        $types = $works->pluck('workType')->unique('id');
        $byCode = [];
        foreach ($types as $type) {
            foreach (array_unique(array_filter([self::codeFor($type), $type->code, 'TYPE:'.$type->id])) as $code) $byCode[$code][] = $type;
        }
        $plan = []; $errors = []; $sourceRows = 0; $skipped = 0; $seen = [];
        $normalizeUnit = fn ($unit) => str_replace(['²', '³'], ['2', '3'], trim((string) $unit));
        foreach ($reader->rows(WorkBudgetImport::SHEET, 30) as $number => $row) {
            if ($number < 4) continue;
            $code = trim((string) ($row[2] ?? ''));
            $name = trim((string) ($row[5] ?? $row[4] ?? ''));
            $raw = $row[29] ?? null;
            if ($code === '' && $name === '' && ($raw === null || $raw === '')) continue;
            $sourceRows++;
            if ($raw === null || $raw === '') { $skipped++; continue; }
            $reason = null;
            $matches = $byCode[$code] ?? [];
            if ($code === '' || count($matches) !== 1) $reason = 'Код энэ барилгын ажилтай нэг утгаар таарахгүй. Эхлээд загвараас эсвэл гараар ажил нэмнэ үү.';
            elseif (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0) $reason = 'Нэгж үнэ 0 буюу түүнээс их тоо байх ёстой.';
            else {
                $type = $matches[0];
                if ($normalizeUnit($row[8] ?? '') !== $normalizeUnit($type->unit)) $reason = 'Хэмжих нэгж зөрсөн эсвэл хоосон.';
                elseif (isset($seen[$type->id])) $reason = 'Ижил ажлын үнэ файлд давхардсан.';
                else {
                    $seen[$type->id] = true;
                    foreach ($works->where('work_type_id', $type->id) as $work) {
                        if ($normalizeUnit($work->unit) !== $normalizeUnit($type->unit)) { $reason = 'Барилгын ажлын нэгж лавлахтай зөрсөн.'; break; }
                        $plan[] = ['id' => $work->id, 'unitPrice' => round((float) $raw, 2), 'quantity' => (float) $work->planned_qty];
                    }
                }
            }
            if ($reason) $errors[] = ['row' => $number, 'code' => $code, 'name' => $name, 'reason' => $reason];
        }
        $groups = collect($errors)->countBy('reason')->map(fn ($count, $reason) => ['reason' => $reason, 'count' => $count])->values()->all();
        return [
            'type' => 'quantities', 'typeLabel' => 'Ажлын хөлсний үнэ', 'sheet' => WorkBudgetImport::SHEET,
            'blockId' => $block->id, 'blockName' => $block->name,
            'canApply' => count($plan) > 0 && $errors === [], 'plan' => $plan,
            'summary' => ['sourceRows' => $sourceRows, 'planned' => count($plan), 'rejected' => count($errors), 'skipped' => $skipped, 'existing' => $works->count()],
            'sections' => [['title' => 'Ажлын нэгж үнэ', 'rows' => [
                ['label' => 'Үнэ шинэчлэх ажил', 'value' => count($plan), 'format' => 'count'],
                ['label' => 'Одоогийн тоо хэмжээгээр тооцсон дүн', 'value' => round(array_sum(array_map(fn ($line) => $line['quantity'] * $line['unitPrice'], $plan)), 2), 'format' => 'money'],
            ]]],
            'warnings' => [], 'errors' => $errors, 'errorsByReason' => $groups,
        ];
    }

    public function apply(Block $block, array $plan): array
    {
        DB::transaction(function () use ($block, $plan) {
            foreach ($plan as $line) {
                $work = WorkItem::where('block_id', $block->id)->lockForUpdate()->find($line['id']);
                if (!$work) throw new RuntimeException('Ажлын жагсаалт өөрчлөгдсөн. Файлаа дахин урьдчилан харна уу.');
                $work->budget_unit_price = $line['unitPrice'];
                $work->save();
            }
        });
        return ['items' => $block->workItems()->count(), 'withoutQuantity' => $block->workItems()->where('planned_qty', '<=', 0)->count(), 'created' => 0, 'updated' => count($plan), 'pruned' => 0, 'conflicts' => []];
    }
}
