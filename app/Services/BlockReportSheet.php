<?php

namespace App\Services;

/**
 * Блокийн ерөнхий тайланг xlsx болгоно.
 *
 * НЭГ ХУУДАС, таван хэсэг. Хэсэг бүрийг тусдаа sheet болговол хүн
 * гүйлгэж харьцуулах боломжгүй болно — энэ тайлангийн утга нь яг
 * ХАРЬЦУУЛАЛТ: 95% дууссан барилга төсвөө 40% хэтрүүлсэн, 60 хоног
 * хоцорсон байж болно.
 */
class BlockReportSheet
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function build(array $report, string $path): string
    {
        $w = new XlsxWriter('Блокийн тайлан');
        $w->widths([34, 22, 22, 22]);

        $b = $report['block'];

        $w->row([['БЛОКИЙН ЕРӨНХИЙ ТАЙЛАН', XlsxWriter::TITLE]]);
        $w->mergeLastRow(0, 3);
        $w->row([[$b['name'].' · '.($b['projectName'] ?? ''), XlsxWriter::SUBTITLE]]);
        $w->mergeLastRow(0, 3);
        $w->row([[
            'Гаргасан: '.str_replace('-', '.', substr((string) $report['generatedAt'], 0, 10)),
            XlsxWriter::SUBTITLE,
        ]]);
        $w->row();

        $this->pairs($w, 'Барилга', [
            ['Нэр', $b['name']],
            ['Барилга №', $b['buildingNo']],
            ['Зориулалт', $b['purpose']],
            ['Давхар', $b['floors']],
            ['Давхарт айл', $b['unitsPerFloor']],
            ['Эхэлсэн', $this->date($b['startDate'])],
        ]);

        $p = $report['progress'];
        $this->pairs($w, 'Гүйцэтгэл', [
            ['Гүйцэтгэл (%)', $p['percentage']],
            ['Нийт ажил', $p['workItems']],
            ['Дууссан ажил', $p['completed']],
            ['Хүлээгдэж буй', $p['pending']],
            ['Буцаагдсан', $p['returned']],
            ['Тоо хэмжээгүй ажил', $p['unmeasured']],
        ]);

        // --- Ажлын бүлгээр ---
        $w->row();
        $w->row([['Ажлын бүлгээр', XlsxWriter::LABEL]]);
        $w->row([
            ['Бүлэг', XlsxWriter::HEADER],
            ['Ажил', XlsxWriter::HEADER],
            ['Дууссан', XlsxWriter::HEADER],
            ['Гүйцэтгэл %', XlsxWriter::HEADER],
        ]);

        foreach ($report['byGroup'] as $g) {
            $w->row([
                [$g['name'], XlsxWriter::CELL],
                [$g['items'], XlsxWriter::NUMBER],
                [$g['completed'], XlsxWriter::NUMBER],
                [$g['percentage'], XlsxWriter::NUMBER],
            ]);
        }

        $m = $report['money'];
        $this->pairs($w, 'Мөнгө', [
            ['Төсвийн дүн (₮)', $m['budgetAmount']],
            ['Гэрээний дүн (₮)', $m['agreedAmount']],
            ['Актын дүн (₮)', $m['acceptedAmount']],
            ['Гэрээ ↔ төсөв (%)', $m['agreedVariancePercent']],
            ['Акт ↔ гэрээ (%)', $m['acceptedVariancePercent']],
            ['Тохиролцоо', $m['agreementCount']],
            ['Төсөвт үнэгүй ажил', $m['withoutBudgetPrice']],
        ]);

        $s = $report['schedule'];
        $this->pairs($w, 'Хугацаа', [
            ['Эхлэх (төлөвлөсөн)', $this->date($s['plannedStart'])],
            ['Дуусах (одоогийн)', $this->date($s['plannedEnd'])],
            ['Дуусах (үндсэн амлалт)', $this->date($s['baselineEnd'])],
            ['Гулсалт (хоног)', $s['slippageDays']],
            ['Сунгагдсан ажил', $s['extendedItems']],
            ['Хугацаа хэтэрсэн ажил', $s['overdueItems']],
        ]);

        $q = $report['quality'];
        $this->pairs($w, 'Чанар', [
            ['Шалгалт', $q['inspections']],
            ['Татгалзсан', $q['rejected']],
            ['Хэсэгчлэн батласан', $q['partial']],
            ['Татгалзлын хувь (%)', $q['rejectionRate']],
            ['Нээлттэй саатал', $q['openIssues']],
            ['Шийдэгдсэн саатал', $q['resolvedIssues']],
        ]);

        $mat = $report['materials'];
        $this->pairs($w, 'Материал', [
            ['Төсөвт материал', $mat['budgetedItems']],
            ['Бодит үнээр (₮)', $mat['actualAmount']],
            ['Зах зээлийн үнээр (₮)', $mat['marketAmount']],
            ['Зах зээл ↔ бодит (%)', $mat['marketVariancePercent']],
            ['Бетон захиалга', $mat['concreteOrders']],
            ['Материалын захиалга', $mat['materialOrders']],
            ['Нийлүүлэгдсэн бетон (м³)', $mat['concreteDelivered']],
        ]);

        return $w->save($path);
    }

    /**
     * «Нэр → утга» хэлбэрийн блок.
     *
     * @param  array<int, array{0: string, 1: mixed}>  $rows
     */
    private function pairs(XlsxWriter $w, string $title, array $rows): void
    {
        $w->row();
        $w->row([[$title, XlsxWriter::LABEL]]);

        foreach ($rows as [$label, $value]) {
            $w->row([
                [$label, XlsxWriter::CELL],
                // Тоог ТООГООР бичнэ — Excel дээр нэмж, эрэмбэлж болох
                // ёстой. Хоосныг «—» гэж бичих нь 0-оос ялгаатай.
                is_numeric($value)
                    ? [$value, XlsxWriter::NUMBER]
                    : [$value ?? '—', XlsxWriter::CELL],
            ]);
        }
    }

    private function date(?string $value): string
    {
        return $value ? str_replace('-', '.', substr($value, 0, 10)) : '—';
    }
}
