<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\WorkItem;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Бодит барилгыг командаар үүсгэх.
 *
 * ГОЛ ШИНЖ: команд нь дарааллыг ХЭРЭГЛЭХГҮЙ. Вебийн урсгал загвар буулгахыг
 * queue руу шиддэг бөгөөд сервер дээр `queue:work` ажиллаагүй бол блок үүсээд
 * ажил нь үүсэхгүй үлддэг. Энэ команд тэр эрсдэлийг бүрэн тойрно.
 */
class ImportBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);
        Company::create(['name' => 'Инэл ХХК']);
    }

    public function test_it_creates_a_project_a_block_and_every_work_item(): void
    {
        // Дараалал огт хэрэглэгдэхгүйг батлана.
        Queue::fake();

        $this->artisan('cpms:import-block', [
            '--create-project' => 'А1 блокийн төсөл',
            '--name' => 'А1 блок',
            '--building-no' => 'A1',
            '--start' => '2026-10-01',
            '--design' => 16,
        ])->assertSuccessful();

        Queue::assertNothingPushed();

        $project = Project::where('name', 'А1 блокийн төсөл')->firstOrFail();
        // Бодит төсөл — `demo:purge` устгах ёсгүй.
        $this->assertFalse((bool) $project->is_demo);

        $block = $project->blocks()->where('name', 'А1 блок')->firstOrFail();
        $this->assertSame(16, $block->floors);
        $this->assertSame('2026-10-01', $block->start_date->toDateString());

        // Байршил ба ажил ХОЁУЛАА үүссэн байх ёстой.
        $this->assertGreaterThan(0, $block->locations()->count());
        $this->assertGreaterThan(
            1000,
            WorkItem::where('block_id', $block->id)->count(),
            '16 давхрын загвараас мянга гаруй ажлын мөр гарах ёстой.'
        );
    }

    public function test_every_work_item_gets_a_quantity_and_a_schedule(): void
    {
        $this->artisan('cpms:import-block', [
            '--create-project' => 'Төсөл',
            '--name' => 'А1 блок',
            '--building-no' => 'A1',
            '--start' => '2026-10-01',
        ])->assertSuccessful();

        $items = WorkItem::query();

        /*
         * Тоо хэмжээгүй мөр гарвал тэр ажилд ГҮЙЦЭТГЭЛ ОРУУЛАХ БОЛОМЖГҮЙ.
         * Төсвөөс бодит тоо орж ирсний дараа ийм мөр үлдэх ёсгүй.
         */
        $this->assertSame(
            0,
            (clone $items)->where('planned_qty', '<=', 0)->count(),
            'Тоо хэмжээгүй ажлын мөр үүссэн байна.'
        );

        // Огноогүй мөр бол хоцролт тооцогдохгүй — хуваарь утгагүй болно.
        $this->assertSame(0, (clone $items)->whereNull('planned_end_date')->count());
        $this->assertSame(0, (clone $items)->whereNull('planned_start_date')->count());
    }

    public function test_it_refuses_to_create_the_same_block_twice(): void
    {
        $args = [
            '--create-project' => 'Төсөл',
            '--name' => 'А1 блок',
            '--building-no' => 'A1',
            '--start' => '2026-10-01',
        ];

        $this->artisan('cpms:import-block', $args)->assertSuccessful();

        // Хоёр дахь удаа ажиллуулбал 3,600 мөр ДАВХАРДАНА — тоо хэмжээ хоёр
        // дахин болж, гүйцэтгэлийн хувь бүхэлдээ худал болно.
        $project = Project::where('name', 'Төсөл')->firstOrFail();
        $before = WorkItem::count();

        $this->artisan('cpms:import-block', [
            '--project' => $project->id,
            '--name' => 'А1 блок',
            '--building-no' => 'A1',
            '--start' => '2026-10-01',
        ])->assertFailed();

        $this->assertSame($before, WorkItem::count());
    }

    public function test_it_stops_when_the_project_is_ambiguous(): void
    {
        $company = Company::firstOrFail();
        $company->projects()->create(['name' => 'Нэгдүгээр', 'status' => 'active']);
        $company->projects()->create(['name' => 'Хоёрдугаар', 'status' => 'active']);

        // Буруу төсөлд 3,600 мөр үүсгэвэл гараар цэвэрлэхэд хэцүү тул
        // таамаглахын оронд зогсоно.
        $this->artisan('cpms:import-block', [
            '--name' => 'А1 блок',
            '--building-no' => 'A1',
            '--start' => '2026-10-01',
        ])->assertFailed();

        $this->assertSame(0, WorkItem::count());
    }

    public function test_required_options_are_checked(): void
    {
        $this->artisan('cpms:import-block', ['--name' => 'А1 блок'])->assertFailed();
    }
}
