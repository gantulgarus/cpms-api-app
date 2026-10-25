<?php

namespace App\Services;

use App\Models\Agreement;

/**
 * «Ажил гүйцэтгэх тохиролцооны хуудас» — xlsx.
 *
 * Захиалагчийн бланкны ДАРААЛЛЫГ хадгална: гүйцэтгэгчийн мэдээлэл → ажил,
 * хугацаа, төлбөр → ажлын гүйцэтгэлийн хүснэгт → хоног, хүн хүч →
 * баталгаажуулалт. Дарааллыг өөрчилбөл хэвлээд гарын үсэг зуруулахад
 * хүмүүс хайх зүйлээ олохгүй.
 *
 * НӨАТ: бланк дээр «Нэгж үнэ /НӨАТ/-тай» гэж байгаа тул үнэ нь НӨАТ
 * шингэсэн дүн. Систем НӨАТ-ыг тусад НЬ бодохгүй — гэрээний тоо тэр
 * хэлбэрээрээ орно.
 */
class AgreementSheet
{
    public function build(Agreement $agreement, string $path): string
    {
        $agreement->loadMissing(['block.project', 'contractor', 'lines.workType', 'approvedBy']);

        $sheet = new XlsxWriter('Тохиролцоо');
        $c = $agreement->contractor;

        $sheet->row([['АЖИЛ ГҮЙЦЭТГЭХ ТОХИРОЛЦООНЫ ХУУДАС', XlsxWriter::TITLE]])->mergeLastRow(0, 5);
        $sheet->row([[$agreement->number, XlsxWriter::SUBTITLE]])->mergeLastRow(0, 5);
        $sheet->row();

        // --- Ажил гүйцэтгэгчийн мэдээлэл ---
        $sheet->row([['Ажил гүйцэтгэгчийн мэдээлэл', XlsxWriter::HEADER]])->mergeLastRow(0, 5);
        $this->pair($sheet, 'Гүйцэтгэгч', $c->name);
        $this->pair($sheet, 'Регистрийн дугаар', $c->registration_no ?? '—');
        $this->pair($sheet, 'Төлбөр хүлээн авах данс', trim(($c->bank_account ?? '—').' / '.($c->bank_name ?? '—')));
        $this->pair($sheet, 'Холбоо барих', trim(($c->contact_person ? $c->contact_person.' · ' : '').($c->phone ?? '—')));
        $sheet->row();

        // --- Гүйцэтгэх ажил, хугацаа ---
        $sheet->row([['Гүйцэтгэх ажил, хугацаа', XlsxWriter::HEADER]])->mergeLastRow(0, 5);
        $this->pair($sheet, 'Төсөл', $agreement->block->project->name ?? '—');
        $this->pair($sheet, 'Блок', $agreement->block->name);
        $this->pair($sheet, 'Ажил', $agreement->scope ?? '—');
        $this->pair($sheet, 'Ажил эхлэх', $agreement->start_date?->toDateString() ?? '—');
        $this->pair($sheet, 'Ажил хүлээлгэн өгөх', $agreement->end_date?->toDateString() ?? '—');
        $sheet->row();

        // --- Төлбөрийн нөхцөл ---
        $sheet->row([['Төлбөрийн нөхцөл', XlsxWriter::HEADER]])->mergeLastRow(0, 5);
        $this->pair($sheet, 'Нийт үнийн дүн (НӨАТ-тай)', $this->money($agreement->total_amount));
        $this->pair($sheet, 'Барьцаа', $this->percent($agreement->retention_percent).' · '.$this->money($agreement->retention_amount));
        $this->pair($sheet, 'Бартер', $this->percent($agreement->barter_percent));
        $this->pair($sheet, 'Бэлэн мөнгө', $this->percent($agreement->cash_percent));

        if ($agreement->barter_amount || $agreement->barter_note) {
            $this->pair($sheet, 'Бартерын дүн, нэр', trim($this->money((float) $agreement->barter_amount).' · '.($agreement->barter_note ?? '')));
        }

        $this->pair($sheet, 'Баталгаат хугацаа', $agreement->warranty_years.' жил / Улсын комисс хүлээж авснаас хойш');
        $this->pair($sheet, 'Засвар үйлчилгээ', $agreement->maintenance_months.' сар');
        $sheet->row();

        // --- Ажлын гүйцэтгэл ---
        $sheet->row([['Ажлын гүйцэтгэл', XlsxWriter::HEADER]])->mergeLastRow(0, 5);
        $sheet->row([
            ['Д/д', XlsxWriter::HEADER],
            ['Ажлын нэр', XlsxWriter::HEADER],
            ['Хэмжих нэгж', XlsxWriter::HEADER],
            ['Тоо хэмжээ', XlsxWriter::HEADER],
            ['Нэгж үнэ (НӨАТ-тай)', XlsxWriter::HEADER],
            ['Дүн', XlsxWriter::HEADER],
        ]);

        foreach ($agreement->lines as $i => $line) {
            $sheet->row([
                [$i + 1, XlsxWriter::CELL],
                [($line->label ?? $line->workType->name).($line->is_extra ? ' (нэмэлт)' : ''), XlsxWriter::CELL],
                [$line->unit, XlsxWriter::CELL],
                [(float) $line->quantity, XlsxWriter::NUMBER],
                [(float) $line->unit_price, XlsxWriter::NUMBER],
                [$line->amount, XlsxWriter::NUMBER],
            ]);
        }

        $sheet->row([
            ['', XlsxWriter::PLAIN],
            ['Нийт', XlsxWriter::TOTAL_TEXT],
            ['', XlsxWriter::PLAIN],
            ['', XlsxWriter::PLAIN],
            ['', XlsxWriter::PLAIN],
            [$agreement->total_amount, XlsxWriter::TOTAL_NUMBER],
        ]);
        $sheet->row();

        /*
         * ТӨСВИЙН ХАРЬЦУУЛАЛТ — бланк дээр БАЙХГҮЙ, системийн нэмэлт.
         *
         * Гэрээний үнэ төсвөөс өөр байж болно (захиалагчийн шийдвэр). Зөрүүг
         * хуудсан дээр гаргаснаар гарын үсэг зурахаас ӨМНӨ харагдана —
         * өөрөөр 77 саяын ажил 90 саяар гэрээлэгдээд хэн ч мэдэхгүй.
         */
        if ($agreement->budget_amount > 0) {
            $sheet->row([['Төсөвтэй харьцуулалт', XlsxWriter::HEADER]])->mergeLastRow(0, 5);
            $this->pair($sheet, 'Төсвийн дүн', $this->money($agreement->budget_amount));
            $this->pair($sheet, 'Гэрээний дүн', $this->money($agreement->total_amount));
            $this->pair($sheet, 'Зөрүү', sprintf(
                '%s%s%%  (%s)',
                $agreement->variance_percent > 0 ? '+' : '',
                number_format((float) $agreement->variance_percent, 1),
                $this->money($agreement->total_amount - $agreement->budget_amount),
            ));
            $sheet->row();
        }

        // --- Ажлын хоног, хүн хүч ---
        $sheet->row([['Ажлын хоног, хүн хүч', XlsxWriter::HEADER]])->mergeLastRow(0, 5);
        $this->pair($sheet, 'Долоо хоногийн ажлын өдөр', $agreement->work_days_per_week ? $agreement->work_days_per_week.' өдөр' : '—');
        $this->pair($sheet, 'Амрах гариг', $agreement->rest_day ?? '—');
        $this->pair($sheet, 'Талбайд ажиллах хүн', $agreement->headcount ? $agreement->headcount.' хүн' : '—');

        if ($agreement->notes) {
            $sheet->row();
            $this->pair($sheet, 'Тэмдэглэл', $agreement->notes);
        }

        $sheet->row();

        // --- Баталгаажуулалт ---
        $sheet->row([['БАТАЛГААЖУУЛСАН', XlsxWriter::HEADER]])->mergeLastRow(0, 5);
        $this->pair($sheet, 'Захиалагчийг төлөөлж', '«Инэл» ХХК — '.($agreement->approvedBy?->name ?? '.......................'));
        $this->pair($sheet, 'Гүйцэтгэгчийг төлөөлж', $c->name.' — '.($c->contact_person ?? '.......................'));
        $this->pair($sheet, 'Огноо', $agreement->approved_at?->toDateString() ?? '.......................');

        if ($agreement->status === 'draft') {
            $sheet->row();
            $sheet->row([['АНХААР: драфт — батлагдаагүй.', XlsxWriter::SUBTITLE]])->mergeLastRow(0, 5);
        }

        return $sheet->save($path);
    }

    /** Нэр + утга — бланкны «....................» мөрүүдийн орлуулга. */
    private function pair(XlsxWriter $sheet, string $label, string $value): void
    {
        $sheet->row([
            [$label, XlsxWriter::LABEL],
            [$value, XlsxWriter::CELL],
        ])->mergeLastRow(1, 5);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2).'₮';
    }

    private function percent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%';
    }
}
