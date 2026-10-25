<?php

namespace App\Services;

use App\Models\MaterialOrder;

/**
 * Материалын захиалгыг захиалагчийн БЛАНКИЙН хэлбэрээр xlsx болгоно.
 *
 * Дөрвөн бланк нэг загвараар гарна — багана болгоноороо ижил. Арматурын
 * хуудсанд нэгж жин, нийт жин хоёр нэмэгдэнэ: үйлдвэр ширхгээр биш
 * ТОННООР нийлүүлдэг.
 *
 * ТӨСВИЙН ҮЛДЭГДЛИЙН БАГАНА нь цаасан бланк дээр БАЙХГҮЙ. Гарын үсэг
 * зурж байгаа хүн «энэ блокт төсвөөр 29,184 ширхэг пин, өмнө нь 18,000
 * захиалсан» гэдгийг мэдэх ёстой — энэ нь системийн гол нэмэр тул
 * нуух нь утгагүй.
 */
class MaterialOrderSheet
{
    /**
     * @param  array<string, array{budget: float, ordered: float, remaining: float, overBy: float}>  $balances
     */
    public function build(MaterialOrder $order, array $balances, string $path): string
    {
        $order->loadMissing(['block.project.company', 'location', 'lines', 'requestedBy', 'receivedBy', 'confirmedBy']);

        $rebar = $order->isRebar();

        $w = new XlsxWriter(mb_substr($order->kindLabel(), 0, 31));
        $w->widths($rebar
            ? [5, 30, 14, 10, 12, 12, 12, 13, 12, 12, 20]
            : [5, 34, 14, 10, 12, 12, 13, 12, 12, 24]);

        $w->row([[mb_strtoupper($order->kindLabel()).' — ЗАХИАЛГЫН ХУУДАС', XlsxWriter::TITLE]]);
        $w->mergeLastRow(0, $rebar ? 10 : 9);

        $w->row([['№ '.$order->number, XlsxWriter::LABEL]]);
        $w->row();

        $w->row([
            ['Гүйцэтгэгч байгууллага:', XlsxWriter::LABEL],
            $order->block->project?->company?->name ?? 'Инэл ХХК',
            '',
            ['Захиалга өгсөн огноо:', XlsxWriter::LABEL],
            $this->date($order->submitted_at?->toDateString() ?? $order->created_at?->toDateString()),
        ]);

        $w->row([
            ['Төсөл / Блок:', XlsxWriter::LABEL],
            trim(($order->block->project?->name ?? '').' / '.$order->block->name, ' /'),
            '',
            ['Шаардах огноо:', XlsxWriter::LABEL],
            $this->date($order->needed_by?->toDateString()),
        ]);

        if ($order->location) {
            $w->row([['Хүргэх байршил:', XlsxWriter::LABEL], $order->location->name]);
        }

        $w->row();
        $w->row($this->header($rebar));

        $no = 0;

        foreach ($order->lines as $line) {
            $no++;
            $balance = $balances[$line->id] ?? null;
            $cells = [
                [$no, XlsxWriter::CELL],
                [$line->name, XlsxWriter::CELL],
                [$line->stock_qty, XlsxWriter::NUMBER],
                [$line->unit, XlsxWriter::CELL],
                [$line->quantity, XlsxWriter::NUMBER],
                [$line->approved_qty, XlsxWriter::NUMBER],
            ];

            if ($rebar) {
                $cells[] = [$line->unit_weight_kg, XlsxWriter::NUMBER];
                $cells[] = [$line->totalWeightKg(), XlsxWriter::NUMBER];
            }

            $cells[] = [$line->delivered_qty, XlsxWriter::NUMBER];
            // Төсвийн үлдэгдэл — энэ захиалгыг ОРУУЛААГҮЙ тоо.
            $cells[] = [$balance ? $balance['remaining'] : null, XlsxWriter::NUMBER];
            $cells[] = [$line->note, XlsxWriter::CELL];

            $w->row($cells);
        }

        $this->totals($w, $order, $rebar);
        $this->warnings($w, $order, $balances, $rebar);
        $this->signatures($w, $order, $rebar);

        return $w->save($path);
    }

    /** @return array<int, mixed> */
    private function header(bool $rebar): array
    {
        $cells = [
            ['Д/д', XlsxWriter::HEADER],
            ['Материалын нэр (Стандарт)', XlsxWriter::HEADER],
            ['Агуулах үлдэгдэл', XlsxWriter::HEADER],
            ['Хэмжих нэгж', XlsxWriter::HEADER],
            ['Захиалгын тоо хэмжээ', XlsxWriter::HEADER],
            ['Зөвшөөрсөн тоо хэмжээ', XlsxWriter::HEADER],
        ];

        if ($rebar) {
            $cells[] = ['Нэгж жин /кг/', XlsxWriter::HEADER];
            $cells[] = ['Нийт жин /кг/', XlsxWriter::HEADER];
        }

        $cells[] = ['Нийлүүлэлт', XlsxWriter::HEADER];
        $cells[] = ['Төсвийн үлдэгдэл', XlsxWriter::HEADER];
        $cells[] = ['Тайлбар', XlsxWriter::HEADER];

        return $cells;
    }

    /**
     * Арматурын «Бүгд жин /кг/» мөр.
     *
     * Зөвхөн арматурт — бусад хуудсанд ширхэг, шуудай, боодлыг нийлүүлэх
     * нь утгагүй. Нэгж холилдсон нийлбэр нь тоо мэт харагдаад худал
     * мэдээлэл өгнө.
     */
    private function totals(XlsxWriter $w, MaterialOrder $order, bool $rebar): void
    {
        if (! $rebar) {
            return;
        }

        $total = 0.0;

        foreach ($order->lines as $line) {
            $total += $line->totalWeightKg() ?? 0.0;
        }

        $w->row([
            '', ['Бүгд жин /кг/', XlsxWriter::TOTAL_TEXT], '', '', '', '', '',
            [round($total, 3), XlsxWriter::TOTAL_NUMBER],
        ]);
    }

    /**
     * Төсвөөс хэтэрсэн мөрүүдийг ТЭР ДОР НЬ нэрлэнэ.
     *
     * Үлдэгдлийн багана нь мөр бүрт байгаа ч 27 мөрийн дунд сөрөг тоо
     * хайх нь ажил. Гарын үсэг зурахын өмнө харах ёстой зүйл дээд талдаа
     * байх ёстой.
     *
     * @param  array<string, array<string, float>>  $balances
     */
    private function warnings(XlsxWriter $w, MaterialOrder $order, array $balances, bool $rebar): void
    {
        $over = [];

        foreach ($order->lines as $line) {
            $balance = $balances[$line->id] ?? null;

            if ($balance === null) {
                continue;
            }

            $asked = $line->approved_qty ?? $line->quantity;

            if ($asked > $balance['remaining']) {
                $over[] = sprintf(
                    '%s — %s %s-ээр хэтэрч байна',
                    $line->name,
                    rtrim(rtrim(number_format($asked - $balance['remaining'], 3, '.', ''), '0'), '.'),
                    $line->unit,
                );
            }
        }

        if ($over === []) {
            return;
        }

        $w->row();
        $w->row([['АНХААР: төсвөөс хэтэрсэн мөрүүд', XlsxWriter::LABEL]]);
        $w->mergeLastRow(0, $rebar ? 10 : 9);

        foreach ($over as $line) {
            $w->row(['', $line]);
        }
    }

    private function signatures(XlsxWriter $w, MaterialOrder $order, bool $rebar): void
    {
        $w->row();
        $w->row();

        $rows = [
            ['Захиалга өгсөн: ', $order->requestedBy?->name, 'Гүйцэтгэгч талын инженер'],
            ['Захиалга авсан: ', $order->receivedBy?->name, 'Захиалагч талын инженер'],
            ['Захиалга баталгаажуулсан: ', $order->confirmedBy?->name, 'Захиалагч талын хяналтын инженер'],
        ];

        $last = $rebar ? 10 : 9;

        foreach ($rows as [$label, $name, $role]) {
            $w->row(array_merge(
                ['', [$label.'______________', XlsxWriter::LABEL]],
                array_fill(0, $last - 3, ''),
                ['/'.($name ?? ' ………………………….. ').'/'],
            ));
            $w->row(array_merge(array_fill(0, $last, ''), [[$role, XlsxWriter::SUBTITLE]]));
        }

        $w->row(array_merge(array_fill(0, $last, ''), [['ТАМГА', XlsxWriter::SUBTITLE]]));
    }

    private function date(?string $value): string
    {
        return $value ? str_replace('-', '.', $value) : '';
    }
}
