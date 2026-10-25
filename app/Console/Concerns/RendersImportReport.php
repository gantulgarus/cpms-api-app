<?php

namespace App\Console\Concerns;

/**
 * `BudgetImportRunner`-ийн тайланг консол руу буулгана.
 *
 * Тайлан нь вэб болон командад НЭГ ижил бүтэцтэй — энд зөвхөн хэвлэнэ.
 * Ингэснээр «командад харагддаг ч дэлгэцэд харагддаггүй» анхааруулга
 * үүсэх боломжгүй.
 */
trait RendersImportReport
{
    /** @param array<string, mixed> $report */
    protected function renderReport(array $report): void
    {
        $summary = $report['summary'];

        $this->info("Блок: {$report['blockName']}  ·  {$report['typeLabel']}");
        $this->table(['Юу', 'Тоо'], [
            ['Файлаас уншсан мөр', number_format($summary['sourceRows'])],
            ['Оруулах мөр', number_format($summary['planned'])],
            ['Алдаатай мөр', number_format($summary['rejected'])],
            ['Алгассан дэд нийлбэр', number_format($summary['skipped'])],
            ['Одоо блокт байгаа мөр', number_format($summary['existing'])],
        ]);

        foreach ($report['sections'] as $section) {
            $this->newLine();
            $this->info($section['title'].':');
            $this->table(
                ['Юу', 'Утга'],
                array_map(fn ($row) => [
                    $row['label'],
                    $this->formatValue($row['value'], $row['format']),
                ], $section['rows']),
            );

            foreach ($section['rows'] as $row) {
                if (isset($row['note'])) {
                    $this->line("  · {$row['label']}: {$row['note']}");
                }
            }
        }

        foreach ($report['warnings'] as $warning) {
            $this->newLine();

            match ($warning['level']) {
                'danger', 'warn' => $this->warn($warning['title'].':'),
                default => $this->line($warning['title'].':'),
            };

            foreach ($warning['items'] as $item) {
                $this->line("  · {$item}");
            }
        }

        $this->renderErrors($report);
    }

    /** @param array<string, mixed> $report */
    private function renderErrors(array $report): void
    {
        if ($report['errors'] === []) {
            return;
        }

        $limit = max(1, (int) $this->option('errors'));
        $errors = $report['errors'];

        $this->newLine();
        $this->warn(count($errors).' мөр импортлогдохгүй:');
        $this->table(
            ['Мөр', 'Код', 'Нэр', 'Шалтгаан'],
            array_map(
                fn ($e) => [$e['row'], $e['code'], mb_substr($e['name'], 0, 28), $e['reason']],
                array_slice($errors, 0, $limit),
            ),
        );

        if (count($errors) > $limit) {
            $this->line('  … мөн '.(count($errors) - $limit).' мөр. Бүгдийг харах: --errors=1000');
        }

        $this->newLine();
        $this->line('Шалтгаанаар:');
        foreach ($report['errorsByReason'] as $group) {
            $this->line(sprintf('  %4d × %s', $group['count'], $group['reason']));
        }
    }

    private function formatValue(mixed $value, string $format): string
    {
        return match ($format) {
            'money' => number_format((float) $value, 2).'₮',
            'percent' => $value === null ? '—' : sprintf('%+.2f%%', (float) $value),
            default => number_format((float) $value),
        };
    }
}
