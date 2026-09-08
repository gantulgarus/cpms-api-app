<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\Project;
use App\Services\AcceptanceReport;
use App\Services\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Тайлан.
 *
 * Эхнийх нь ГҮЙЦЭТГЭЛИЙН АКТ: гүйцэтгэгч × хугацааны хооронд захиалагчийн
 * хяналтад батлагдсан ажлын жагсаалт. Гэрээний албанд тооцоо хийхэд шууд
 * ашиглагдана.
 */
class ReportController extends Controller
{
    public function __construct(private readonly AcceptanceReport $report) {}

    /** GET /projects/{project}/reports/acceptance — урьдчилан харах (JSON). */
    public function acceptance(Request $request, Project $project): JsonResponse
    {
        return response()->json(['data' => $this->build($request, $project)]);
    }

    /** GET /projects/{project}/reports/acceptance.xlsx — Excel татах. */
    public function acceptanceXlsx(Request $request, Project $project): BinaryFileResponse
    {
        $data = $this->build($request, $project);

        $sheet = (new XlsxWriter('Гүйцэтгэлийн акт'))
            ->widths([5, 18, 26, 34, 8, 14, 12, 20]);

        $this->writeHeader($sheet, $data);
        $this->writeBody($sheet, $data);
        $this->writeSignatures($sheet);

        // `local` диск нь `storage/app/private` — вэбээс шууд хандах
        // боломжгүй. Файл нь хариу илгээгдмэгц устана.
        $name = $this->fileName($data);
        $path = Storage::disk('local')->path('reports/'.$name);
        Storage::disk('local')->makeDirectory('reports');
        $sheet->save($path);

        return response()
            ->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    // -- Дотоод -----------------------------------------------------------

    private function build(Request $request, Project $project): array
    {
        $validated = $request->validate([
            'contractorId' => ['nullable', 'uuid', 'exists:contractors,id'],
            'blockId' => ['nullable', 'uuid', 'exists:blocks,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'stage' => ['nullable', Rule::in(Inspection::STAGES)],
        ], [
            'from.required' => 'Эхлэх огноог сонгоно уу.',
            'to.required' => 'Дуусах огноог сонгоно уу.',
            'to.after_or_equal' => 'Дуусах огноо эхлэхээсээ өмнө байж болохгүй.',
        ]);

        return $this->report->build(
            $project,
            $request->user(),
            $validated['contractorId'] ?? null,
            $validated['from'],
            $validated['to'],
            $validated['stage'] ?? 'client',
            $validated['blockId'] ?? null,
        );
    }

    private function writeHeader(XlsxWriter $sheet, array $data): void
    {
        $stageLabel = $data['stage'] === 'client'
            ? 'Захиалагчийн хяналтад батлагдсан'
            : 'Ерөнхий гүйцэтгэгчийн хяналтад батлагдсан';

        $sheet->row([['ГҮЙЦЭТГЭЛИЙН АКТ', XlsxWriter::TITLE]])->mergeLastRow(0, 7);
        $sheet->row([[$data['project']['name'], XlsxWriter::SUBTITLE]])->mergeLastRow(0, 7);
        $sheet->row();

        $rows = [
            ['Гүйцэтгэгч', $data['contractor']['name'] ?? 'Бүх гүйцэтгэгч'],
            ['Хугацаа', $data['period']['from'].' — '.$data['period']['to']],
            ['Үндэслэл', $stageLabel],
            ['Хамрах хүрээ', $data['workItemCount'].' ажил · '.$data['inspectionCount'].' шалгалт'],
            ['Тайлан гаргасан', Carbon::now()->timezone('Asia/Ulaanbaatar')->format('Y-m-d H:i')],
        ];

        foreach ($rows as [$label, $value]) {
            $sheet->row([[$label, XlsxWriter::LABEL], null, [$value, XlsxWriter::PLAIN]]);
            // Гарчиг богино нүдэнд таслагдахгүйн тулд A:B нэгтгэнэ.
            $sheet->mergeLastRow(0, 1);
            $sheet->mergeLastRow(2, 7);
        }

        $sheet->row();
    }

    private function writeBody(XlsxWriter $sheet, array $data): void
    {
        $sheet->row([
            ['№', XlsxWriter::HEADER],
            ['Барилга', XlsxWriter::HEADER],
            ['Байршил', XlsxWriter::HEADER],
            ['Ажлын нэр', XlsxWriter::HEADER],
            ['Нэгж', XlsxWriter::HEADER],
            ['Батлагдсан', XlsxWriter::HEADER],
            ['Огноо', XlsxWriter::HEADER],
            ['Шалгасан', XlsxWriter::HEADER],
        ]);

        if ($data['groups'] === []) {
            $sheet->row([['Энэ хугацаанд батлагдсан ажил алга.', XlsxWriter::CELL]]);
            $sheet->mergeLastRow(0, 7);

            return;
        }

        $no = 0;
        foreach ($data['groups'] as $group) {
            $sheet->row([[$group['name'], XlsxWriter::TOTAL_TEXT]]);
            $sheet->mergeLastRow(0, 7);

            foreach ($group['rows'] as $row) {
                $sheet->row([
                    [++$no, XlsxWriter::CELL],
                    [$row['blockName'], XlsxWriter::CELL],
                    [$row['locationPath'], XlsxWriter::CELL],
                    [$row['workTypeName'], XlsxWriter::CELL],
                    [$row['unit'], XlsxWriter::CELL],
                    [$row['acceptedQty'], XlsxWriter::NUMBER],
                    [Carbon::parse($row['inspectedAt'])->timezone('Asia/Ulaanbaatar')->format('Y-m-d'), XlsxWriter::CELL],
                    [$row['inspectorName'] ?? '—', XlsxWriter::CELL],
                ]);
            }

            $this->writeUnitTotals($sheet, $group['totals'], 'Дүн');
        }

        $sheet->row();
        $this->writeUnitTotals($sheet, $data['totals'], 'НИЙТ ДҮН');
    }

    /**
     * Нэгж тус бүрээр дүн.
     *
     * м² ба м³-ийг нэг тоо болгож нэмэх нь утгагүй тул нэгж бүр өөрийн
     * мөртэй байна.
     */
    private function writeUnitTotals(XlsxWriter $sheet, array $totals, string $label): void
    {
        foreach ($totals as $t) {
            $sheet->row([
                ['', XlsxWriter::TOTAL_TEXT],
                ['', XlsxWriter::TOTAL_TEXT],
                ['', XlsxWriter::TOTAL_TEXT],
                [$label, XlsxWriter::TOTAL_TEXT],
                [$t['unit'], XlsxWriter::TOTAL_TEXT],
                [$t['qty'], XlsxWriter::TOTAL_NUMBER],
                ['', XlsxWriter::TOTAL_TEXT],
                ['', XlsxWriter::TOTAL_TEXT],
            ]);
        }
    }

    /** Гарын үсгийн мөр — акт хэвлэгдэж гарын үсэг зурагдана. */
    private function writeSignatures(XlsxWriter $sheet): void
    {
        $sheet->row();
        $sheet->row();
        $sheet->row([
            ['Хүлээлгэн өгсөн (гүйцэтгэгч):', XlsxWriter::LABEL],
            null,
            ['..............................', XlsxWriter::PLAIN],
            null,
            ['Хүлээн авсан (захиалагч):', XlsxWriter::LABEL],
            null,
            ['..............................', XlsxWriter::PLAIN],
        ]);
        $sheet->row([
            ['/ нэр, гарын үсэг /', XlsxWriter::SUBTITLE],
            null,
            null,
            null,
            ['/ нэр, гарын үсэг /', XlsxWriter::SUBTITLE],
        ]);
    }

    private function fileName(array $data): string
    {
        $who = $data['contractor']['name'] ?? 'bugd';
        // Файлын нэрэнд кирилл, хоосон зай орох нь татаж авахад асуудал
        // үүсгэдэг тул латинчилж, аюулгүй тэмдэгт үлдээнэ.
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $this->latinize($who));
        $slug = trim((string) $slug, '-') ?: 'contractor';

        return sprintf('akt-%s-%s_%s.xlsx', $slug, $data['period']['from'], $data['period']['to']);
    }

    private function latinize(string $value): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'ө' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ү' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
            'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $lower = mb_strtolower($value, 'UTF-8');

        return strtr($lower, $map);
    }
}
