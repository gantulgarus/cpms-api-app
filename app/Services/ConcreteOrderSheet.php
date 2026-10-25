<?php

namespace App\Services;

use App\Models\ConcreteOrder;

/**
 * Бетон захиалгыг захиалагчийн БЛАНКИЙН хэлбэрээр xlsx болгоно.
 *
 * Мөрийн дараалал нь «БЕТОН ЗУУРМАГ ЗАХИАЛГА, НИЙЛҮҮЛЭЛТИЙН ХУУДАС»
 * бланктай ЯГ таарна — хэвлээд гарын үсэг зуруулах тул танил харагдах
 * ёстой. Шинэ хэлбэр зохиох нь хүмүүсийг дахин сургана гэсэн үг.
 *
 * НЭГ ЗҮЙЛ НЭМЭГДСЭН: төсвийн үлдэгдлийн мөр. Цаасан бланк дээр байхгүй
 * ч гарын үсэг зурж байгаа хүн «энэ давхарт төсвөөр 240, өмнө нь 180
 * захиалсан» гэдгийг мэдэх ёстой. Энэ нь цаасан хуудсаас ялгарах гол
 * зүйл тул нуух нь утгагүй.
 */
class ConcreteOrderSheet
{
    private const CHECKED = '☒';

    private const UNCHECKED = '꙱';

    /**
     * @param  array{budget: float, ordered: float, remaining: float, overBy: float}  $balance
     */
    public function build(ConcreteOrder $order, array $balance, string $path): string
    {
        $order->loadMissing(['block', 'location', 'material', 'requestedBy', 'confirmedBy', 'receivedBy']);

        $w = new XlsxWriter('Бетон захиалга');
        $w->widths([6, 34, 22, 22, 20, 26]);

        $w->row([['БЕТОН ЗУУРМАГ ЗАХИАЛГА, НИЙЛҮҮЛЭЛТИЙН ХУУДАС', XlsxWriter::TITLE]]);
        $w->mergeLastRow(0, 5);

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
            ['Захиалагч овог нэр:', XlsxWriter::LABEL],
            $order->requestedBy?->name ?? '',
        ]);

        $w->row([
            ['*  Бетон зуурмаг авахаас '.ConcreteOrder::LEAD_DAYS
                .' хоногийн өмнө захиалга өгнө.', XlsxWriter::SUBTITLE],
        ]);
        $w->mergeLastRow(0, 5);
        $w->row();

        // --- Бланкны үндсэн хүснэгт ---
        $w->row([
            ['Д/д', XlsxWriter::HEADER],
            ['Нэр', XlsxWriter::HEADER],
            ['Захиалга', XlsxWriter::HEADER],
            ['', XlsxWriter::HEADER],
            ['Нийлүүлэлт', XlsxWriter::HEADER],
            ['Тайлбар', XlsxWriter::HEADER],
        ]);

        $this->line($w, 1, 'Бетон зуурмаг авах он/сар/өдөр', $this->date($order->pour_date?->toDateString()));

        /*
         * «Эхлэх цаг» нь бланк дээр ХОЁР мөр: үдээс өмнө / үдээс хойш.
         * Хоёуланг нь үлдээж, сонгогдсоныг нь тэмдэглэнэ — цаасан дээр
         * яг ийм харагддаг.
         */
        $this->line(
            $w, 2, ' Эхлэх цаг',
            'Үдээс өмнө:  '.$this->mark($order->pour_half === 'am'),
            $order->pour_half === 'am' ? 'Цаг: '.($order->pour_time ?? '') : 'Цаг: ___________',
        );
        $this->line(
            $w, null, '',
            'Үдээс хойш: '.$this->mark($order->pour_half === 'pm'),
            $order->pour_half === 'pm' ? 'Цаг: '.($order->pour_time ?? '') : 'Цаг: ___________',
        );

        $this->line($w, 3, ' Блок/Давхар', $order->block->name.' / '.$order->location->name);
        $this->line($w, 4, ' Хийц нэршил', $order->structure);
        $this->line($w, 5, 'Бетоны марк ', $order->grade ?? $order->material->grade ?? '');
        $this->line($w, 6, 'Бетон анги', $order->concrete_class ?? '');
        $this->line($w, 7, 'Конусан суулт ', $order->slump_cm ? $order->slump_cm.' см' : '');
        $this->line(
            $w, 8, 'Захиалах хэмжээ /м3/',
            [$order->quantity, XlsxWriter::NUMBER],
            $order->delivered_qty !== null ? [$order->delivered_qty, XlsxWriter::NUMBER] : '',
        );
        $this->line(
            $w, 9, 'Техник/Помп',
            'Тийм: '.$this->mark($order->needs_pump),
            'Үгүй: '.$this->mark(! $order->needs_pump),
        );

        $this->balanceBlock($w, $order, $balance);
        $this->signatures($w, $order);

        return $w->save($path);
    }

    /**
     * Төсвийн үлдэгдэл — бланк дээр БАЙХГҮЙ, системээс нэмэгдсэн хэсэг.
     *
     * @param  array{budget: float, ordered: float, remaining: float, overBy: float}  $balance
     */
    private function balanceBlock(XlsxWriter $w, ConcreteOrder $order, array $balance): void
    {
        $w->row();
        $w->row([['Төсвийн үлдэгдэл — '.$order->location->name.', '.$order->material->label(), XlsxWriter::LABEL]]);
        $w->mergeLastRow(0, 5);

        $w->row([
            ['', XlsxWriter::PLAIN],
            ['Төсөвт /м3/', XlsxWriter::HEADER],
            ['Өмнө захиалсан', XlsxWriter::HEADER],
            ['Энэ захиалга', XlsxWriter::HEADER],
            ['Үлдэгдэл', XlsxWriter::HEADER],
        ]);

        /*
         * ЭНЭ захиалгын хэмжээ — төлвөөс ХАМААРАХГҮЙ.
         *
         * `consumedQty()` нь драфт дээр 0 буцаадаг (төсөв иддэггүй тул
         * зөв). Харин ЭНЭ хуудас бол тэр захиалгын тухай баримт:
         * «захиалсны дараа хэд үлдэх вэ» гэдгийг харуулах ёстой.
         * Драфтыг 0 гэж үзвэл хуудас үлдэгдлийг хэтрүүлж харуулна.
         */
        $thisOrder = $order->delivered_qty ?? $order->quantity;

        $w->row([
            '',
            [$balance['budget'], XlsxWriter::NUMBER],
            [$balance['ordered'], XlsxWriter::NUMBER],
            [$thisOrder, XlsxWriter::NUMBER],
            [round($balance['remaining'] - $thisOrder, 3), XlsxWriter::TOTAL_NUMBER],
        ]);

        /*
         * ХЭТЭРСЭН БОЛ ТЭР ДОР НЬ. Гарын үсэг зурж байгаа хүн энэ мөрийг
         * хоёр хуудас доош гүйлгэж хайх ёсгүй.
         */
        $over = round($thisOrder - $balance['remaining'], 3);

        if ($over > 0) {
            $w->row([['АНХААР: төсвөөс '.$over.' м³-ээр ХЭТЭРЧ байна.', XlsxWriter::LABEL]]);
            $w->mergeLastRow(0, 5);
        }

        if ($order->isRush()) {
            $w->row([['АНХААР: '.ConcreteOrder::LEAD_DAYS
                .' хоногийн хугацаанаас хожуу захиалга.', XlsxWriter::LABEL]]);
            $w->mergeLastRow(0, 5);
        }
    }

    private function signatures(XlsxWriter $w, ConcreteOrder $order): void
    {
        $w->row();
        $w->row();

        $rows = [
            ['Захиалга өгсөн: ', $order->requestedBy?->name, 'Гүйцэтгэгч талын инженер'],
            ['Захиалга авсан: ', $order->receivedBy?->name, 'Захиалагч талын инженер'],
            ['Захиалга баталгаажуулсан: ', $order->confirmedBy?->name, 'Захиалагч талын хяналтын инженер'],
        ];

        foreach ($rows as [$label, $name, $role]) {
            $w->row([
                '', '',
                [$label.'______________', XlsxWriter::LABEL],
                '', '',
                // Нэр нь мэдэгдэж байвал бичнэ; үгүй бол бланкны цэгүүд.
                '/'.($name ?? ' ………………………….. ').'/',
            ]);
            $w->row(['', '', '', '', '', [$role, XlsxWriter::SUBTITLE]]);
        }

        $w->row(['', '', '', '', '', ['ТАМГА', XlsxWriter::SUBTITLE]]);
    }

    /** Бланкны нэг мөр. */
    private function line(
        XlsxWriter $w,
        ?int $no,
        string $name,
        mixed $order = '',
        mixed $extra = '',
        mixed $delivery = '',
    ): void {
        $w->row([
            [$no ?? '', XlsxWriter::CELL],
            [$name, XlsxWriter::CELL],
            is_array($order) ? $order : [$order, XlsxWriter::CELL],
            is_array($extra) ? $extra : [$extra, XlsxWriter::CELL],
            is_array($delivery) ? $delivery : [$delivery, XlsxWriter::CELL],
            ['', XlsxWriter::CELL],
        ]);
    }

    private function mark(bool $on): string
    {
        return $on ? self::CHECKED : self::UNCHECKED;
    }

    private function date(?string $value): string
    {
        return $value ? str_replace('-', '.', $value) : '';
    }
}
