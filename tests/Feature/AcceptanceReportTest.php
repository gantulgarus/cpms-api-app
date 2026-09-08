<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Гүйцэтгэлийн акт.
 *
 * Энэ бол захиалагчтай ТООЦОО хийх баримт тул алдаа нь мөнгөн дүнд шууд
 * хүрнэ. Гурван зүйлийг онцгойлон хамгаална:
 *
 *   1. Хугацааны шүүлт — өнгөрсөн сарын ажил дахин актлагдвал давхар тооцоо.
 *   2. Нэгж холихгүй — м² ба м³-ийг нэмэх нь утгагүй.
 *   3. Хамрах хүрээ — гүйцэтгэгч бусдын ажлыг актлах ёсгүй.
 */
class AcceptanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Block $block;

    private Contractor $goo;

    private Contractor $other;

    private User $engineer;

    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $this->project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $this->block = $this->project->blocks()->create([
            'name' => 'А блок', 'floors' => 4, 'units_per_floor' => 2,
        ]);

        $this->goo = Contractor::create(['name' => 'Гоо Засал ХХК']);
        $this->other = Contractor::create(['name' => 'Бат Барилга ХХК']);

        $this->engineer = User::factory()->create(['role' => 'site_engineer']);
        $this->inspector = User::factory()->create(['role' => 'inspector']);
    }

    // -- Туслах --------------------------------------------------------

    private function workItem(string $unit, Contractor $contractor, string $group = 'Өрлөг'): WorkItem
    {
        static $n = 0;
        $n++;

        $location = Location::create([
            'block_id' => $this->block->id,
            'parent_id' => null,
            'level' => 'block',
            'name' => "Байршил {$n}",
            'path' => "А блок · байршил {$n}",
            'path_key' => "/root-{$n}/",
            'sequence_number' => $n,
        ]);

        $wtg = WorkTypeGroup::firstOrCreate(['name' => $group], ['build_order' => 1]);
        $workType = WorkType::create([
            'work_type_group_id' => $wtg->id,
            'name' => "Ажил {$n}",
            'unit' => $unit,
            'level' => 'block',
        ]);

        return WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $location->id,
            'work_type_id' => $workType->id,
            'contractor_id' => $contractor->id,
            'name' => "Ажил {$n}",
            'unit' => $unit,
            'planned_qty' => 1000,
        ]);
    }

    /** Гүйцэтгэл мэдээлээд заасан огноогоор баталгаажуулна. */
    private function accept(WorkItem $item, float $qty, string $date, string $stage = 'client'): void
    {
        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => $qty])
            ->assertCreated();

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => $stage, 'result' => 'accepted', 'acceptedQty' => $qty,
            ])
            ->assertCreated();

        // Шалгалтын огноог хүссэн өдөр рүү шилжүүлнэ — `inspected_at` нь
        // серверийн `now()` тул тестээс шууд өгөх боломжгүй.
        $item->inspections()->latest('inspected_at')->first()
            ->forceFill(['inspected_at' => Carbon::parse($date)])->saveQuietly();
    }

    private function report(array $params = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->inspector, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/acceptance?".http_build_query(
                array_merge(['from' => '2026-01-01', 'to' => '2026-12-31'], $params)
            ))
            ->assertOk()
            ->json('data');
    }

    // -- Хугацааны шүүлт ------------------------------------------------

    public function test_only_inspections_inside_the_period_are_listed(): void
    {
        $a = $this->workItem('м2', $this->goo);
        $b = $this->workItem('м2', $this->goo);

        $this->accept($a, 100, '2026-03-15');
        $this->accept($b, 50, '2026-08-20');

        $march = $this->report(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertSame(1, $march['inspectionCount']);
        $this->assertEqualsWithDelta(100, $march['totals'][0]['qty'], 0.001);
    }

    public function test_the_period_boundaries_are_inclusive(): void
    {
        // Сарын сүүлчийн өдрийн шалгалт актаас унавал тэр ажил хоёр сарын
        // аль алинд нь ороогүй болж, мөнхөд алга болно.
        $item = $this->workItem('м2', $this->goo);
        $this->accept($item, 40, '2026-03-31 18:30:00');

        $march = $this->report(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertSame(1, $march['inspectionCount']);
    }

    public function test_a_reversed_period_is_rejected(): void
    {
        $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/acceptance?from=2026-06-30&to=2026-01-01")
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('to');
    }

    // -- Юу актад орох вэ -----------------------------------------------

    public function test_rejected_work_never_appears_in_the_act(): void
    {
        $item = $this->workItem('м2', $this->goo);

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 80]);
        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client', 'result' => 'rejected', 'acceptedQty' => 0,
                'reason' => 'Чанар хангаагүй',
            ])->assertCreated();

        $this->assertSame(0, $this->report()['inspectionCount']);
    }

    public function test_the_stage_filter_separates_client_from_internal_control(): void
    {
        // Ерөнхий гүйцэтгэгчийн дотоод хяналт нь захиалагчийн акт БИШ.
        $item = $this->workItem('м2', $this->goo);
        $this->accept($item, 60, '2026-04-10', 'general_contractor');

        $this->assertSame(0, $this->report()['inspectionCount']);
        $this->assertSame(1, $this->report(['stage' => 'general_contractor'])['inspectionCount']);
    }

    // -- Нэгж ------------------------------------------------------------

    public function test_totals_are_split_by_unit_and_never_summed_together(): void
    {
        // 100 м² + 20 м³ = 120 гэсэн тоо нь утгагүй бөгөөд гэрээний тооцоог
        // бүхэлд нь буруу болгоно.
        $this->accept($this->workItem('м2', $this->goo), 100, '2026-05-05');
        $this->accept($this->workItem('м3', $this->goo), 20, '2026-05-06');

        $totals = collect($this->report()['totals'])->keyBy('unit');

        $this->assertCount(2, $totals);
        $this->assertEqualsWithDelta(100, $totals['м2']['qty'], 0.001);
        $this->assertEqualsWithDelta(20, $totals['м3']['qty'], 0.001);
    }

    public function test_group_totals_add_up_to_the_grand_total(): void
    {
        $this->accept($this->workItem('м2', $this->goo, 'Өрлөг'), 100, '2026-05-05');
        $this->accept($this->workItem('м2', $this->goo, 'Засал'), 40, '2026-05-06');

        $report = $this->report();
        $sum = collect($report['groups'])
            ->flatMap(fn ($g) => $g['totals'])
            ->where('unit', 'м2')
            ->sum('qty');

        $this->assertCount(2, $report['groups']);
        $this->assertEqualsWithDelta(140, $sum, 0.001);
        $this->assertEqualsWithDelta(140, $report['totals'][0]['qty'], 0.001);
    }

    // -- Хамрах хүрээ ----------------------------------------------------

    public function test_the_contractor_filter_limits_the_act(): void
    {
        $this->accept($this->workItem('м2', $this->goo), 100, '2026-05-05');
        $this->accept($this->workItem('м2', $this->other), 70, '2026-05-06');

        $all = $this->report();
        $mine = $this->report(['contractorId' => $this->goo->id]);

        $this->assertSame(2, $all['inspectionCount']);
        $this->assertSame(1, $mine['inspectionCount']);
        $this->assertSame('Гоо Засал ХХК', $mine['contractor']['name']);
    }

    public function test_a_contractor_can_only_see_their_own_act(): void
    {
        // Гүйцэтгэгч бусдын гүйцэтгэлийг актаар нь харвал арилжааны нууц
        // задарна.
        $this->accept($this->workItem('м2', $this->goo), 100, '2026-05-05');
        $this->accept($this->workItem('м2', $this->other), 70, '2026-05-06');

        $rep = User::factory()->create(['role' => 'contractor', 'contractor_id' => $this->goo->id]);

        $report = $this->report([], $rep);

        $this->assertSame(1, $report['inspectionCount']);
    }

    // -- Excel -----------------------------------------------------------

    public function test_the_xlsx_download_returns_a_real_spreadsheet(): void
    {
        $this->accept($this->workItem('м2', $this->goo), 100, '2026-05-05');

        $response = $this->actingAs($this->inspector, 'sanctum')
            ->get("/api/v1/projects/{$this->project->id}/reports/acceptance.xlsx?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );

        $path = tempnam(sys_get_temp_dir(), 'akt').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        // .xlsx нь ZIP архив — задарч байвал файл эвдрээгүй гэсэн үг.
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Файл ZIP архив биш байна.');
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString(
            'ГҮЙЦЭТГЭЛИЙН АКТ',
            (string) $zip->getFromName('xl/worksheets/sheet1.xml')
        );
        $zip->close();
        @unlink($path);
    }

    public function test_an_empty_period_still_produces_a_valid_file(): void
    {
        // Хоосон акт нь алдаа биш — «энэ сард хүлээж авсан ажил алга» гэсэн
        // баримт өөрөө хэрэгтэй.
        $this->actingAs($this->inspector, 'sanctum')
            ->get("/api/v1/projects/{$this->project->id}/reports/acceptance.xlsx?from=2026-01-01&to=2026-01-31")
            ->assertOk();
    }
}
