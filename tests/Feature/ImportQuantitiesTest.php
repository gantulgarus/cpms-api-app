<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\WorkBudgetImport;
use App\Services\XlsxReader;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Төсвийн Excel-ээс тоо хэмжээ импортлох.
 *
 * ГОЛ ЗАРЧИМ: урьдчилан харах ба бичих нь ИЖИЛ кодоор явна. Тиймээс эндэх
 * тестүүд задлагчийг шалгавал командын зан төлөв мөн шалгагдана.
 */
class ImportQuantitiesTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);

        $this->block = $project->blocks()->create([
            'name' => 'А1 блок',
            'building_no' => 'A1',
            'floors' => 2,
            'units_per_floor' => 3,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 2, 3);
    }

    /**
     * Хамгийн чухал тест: тоо хэмжээ нь ажлын төрлийн ТҮВШНЭЭС хамаарч
     * өөр өөр байршилд буудаг.
     */
    public function test_quantities_land_on_the_level_of_the_work_type(): void
    {
        $floor = Location::where('block_id', $this->block->id)->where('name', '1-р давхар')->firstOrFail();
        $units = Location::where('parent_id', $floor->id)->count();

        $this->assertSame(3, $units, 'Бэлтгэл: давхарт 3 айл байх ёстой.');

        $block = WorkType::where('level', 'block')->whereNotNull('code')->firstOrFail();
        $floorType = WorkType::where('level', 'floor')->whereNotNull('code')->firstOrFail();
        $unitType = WorkType::where('level', 'unit')->whereNotNull('code')->firstOrFail();

        $file = $this->makeWorkbook([
            [$block->code, $block->name, $block->unit, ['1-р давхар' => 100]],
            [$floorType->code, $floorType->name, $floorType->unit, ['1-р давхар' => 60]],
            [$unitType->code, $unitType->name, $unitType->unit, ['1-р давхар' => 30]],
        ]);

        $import = $this->parse($file);

        $this->assertSame([], $import->errors(), json_encode($import->errors(), JSON_UNESCAPED_UNICODE));

        $plan = collect($import->plan());

        // Барилгын түвшин — НЭГ мөр, үндэс байршилд.
        $this->assertCount(1, $plan->where('workTypeId', $block->id));

        // Давхрын түвшин — НЭГ мөр, тэр давхарт, тоо нь хэвээр.
        $onFloor = $plan->where('workTypeId', $floorType->id);
        $this->assertCount(1, $onFloor);
        $this->assertSame(60.0, $onFloor->first()['quantity']);

        // Айлын түвшин — 3 мөр, давхрын тоо ТЭНЦҮҮ хуваагдана.
        $onUnits = $plan->where('workTypeId', $unitType->id);
        $this->assertCount(3, $onUnits);
        $this->assertSame(10.0, $onUnits->first()['quantity'], '30 ÷ 3 айл = 10.');
    }

    public function test_rows_the_system_cannot_match_are_reported_not_skipped(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $file = $this->makeWorkbook([
            ['99.99.99', 'Байхгүй ажил', 'м2', ['1-р давхар' => 10]],
            [$type->code, $type->name, 'кв.м', ['1-р давхар' => 10]],
            [$type->code, $type->name, $type->unit, ['1-р давхар' => '#REF!']],
            [$type->code, $type->name, $type->unit, []],
        ]);

        $errors = collect($this->parse($file)->errors());

        // Чимээгүй алгасах нь хамгийн муу: төсөв бүрэн ороогүйг хэн ч мэдэхгүй.
        $this->assertCount(4, $errors);
        $this->assertStringContainsString('кодтой', $errors[0]['reason']);
        $this->assertStringContainsString('Танигдахгүй нэгж', $errors[1]['reason']);
        $this->assertStringContainsString('Эвдэрсэн', $errors[2]['reason']);
        $this->assertStringContainsString('олдсонгүй', $errors[3]['reason']);
    }

    /**
     * Байхгүй давхрын тоо хэмжээг ЧИМЭЭГҮЙ алгасахгүй.
     *
     * Төсөв нь 16 давхрын багананд «Суурь» гэх мэт системд байхгүй хэсгүүдийг
     * агуулдаг. Мэдээлэхгүй бол төсвийн нүд хаана ч буухгүй, тоо дутуу орсныг
     * хэн ч мэдэхгүй — импортын хамгийн хортой алдаа.
     *
     * ТЭМДЭГЛЭЛ: өмнө нь энэ тест «Техникийн давхар»-аар шалгадаг байв. Тэр
     * давхрыг одоо импорт өөрөө нөхдөг болсон тул шалгуур утгаа алдаж, зөвхөн
     * хуучин зан төлөвийг хамгаалж байсан.
     */
    public function test_floors_missing_from_the_block_are_reported(): void
    {
        $missing = 'Суурь';

        $this->assertSame(
            0,
            Location::where('block_id', $this->block->id)->where('name', $missing)->count(),
            "Бэлтгэл: «{$missing}» байршил байх ёсгүй.",
        );

        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $file = $this->makeWorkbook(
            [[$type->code, $type->name, $type->unit, [$missing => 10]]],
            ['1-р давхар', $missing],
        );

        $import = $this->parse($file);

        $this->assertContains($missing, $import->missingLocations());
        $this->assertSame([], $import->plan());
    }

    /**
     * Техникийн давхрыг «байхгүй» гэж мэдээлэхгүй — өөрөө нөхдөг.
     *
     * Энэ хоёр тест хамтдаа хилийг хаана: нөхдөг давхар нь тайланд
     * гомдол болж гарах ёсгүй, нөхдөггүй нь гарах ёстой.
     */
    public function test_the_technical_floor_is_not_reported_as_missing(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $file = $this->makeWorkbook(
            [[$type->code, $type->name, $type->unit, [\App\Services\BlockLocationBuilder::TECHNICAL_FLOOR => 10]]],
            ['1-р давхар', \App\Services\BlockLocationBuilder::TECHNICAL_FLOOR],
        );

        $import = $this->parse($file);

        $this->assertSame([], $import->missingLocations());
        $this->assertCount(1, $import->plan());
    }

    public function test_apply_replaces_quantities_and_wipes_progress(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $floor = Location::where('block_id', $this->block->id)->where('name', '1-р давхар')->firstOrFail();

        $item = WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $floor->id,
            'work_type_id' => $type->id,
            'name' => $type->name,
            'unit' => $type->unit,
            'planned_qty' => 648,
            'reported_qty' => 600,
            'accepted_qty' => 600,
            'status' => 'in_progress',
            'review_state' => 'approved',
        ]);
        ProgressEntry::create([
            'work_item_id' => $item->id,
            'reported_by_id' => User::factory()->create()->id,
            'completed_qty' => 600,
            'recorded_at' => now(),
        ]);

        $file = $this->makeWorkbook([[$type->code, $type->name, $type->unit, ['1-р давхар' => 15]]]);

        $this->artisan('cpms:import-quantities', [
            '--file' => $file,
            '--block' => 'А1 блок',
            '--apply' => true,
            '--force' => true,
        ])->assertSuccessful();

        $item->refresh();

        // Тоо хэмжээ таамгаас БОДИТ утга руу солигдоно.
        $this->assertSame('15.000', (string) $item->planned_qty);
        /*
         * Гүйцэтгэл устах ЁСТОЙ. 648 гэж бодоод 600 мэдээлсэн ажил шинэ
         * тоогоор 4000% гүйцэтгэлтэй болно.
         */
        $this->assertSame('0.000', (string) $item->reported_qty);
        $this->assertSame('not_started', $item->status);
        $this->assertSame(0, ProgressEntry::count());
    }

    /**
     * Техникийн давхрыг байгаа блокт НӨХНӨ.
     *
     * Энэ давхрыг системд хожим нэмсэн тул өмнө үүссэн блокт байхгүй. Байхгүй
     * бол төсвийн 16 нүдний тоо хэмжээ хаана ч буухгүй, чимээгүй алдагдана.
     */
    public function test_missing_technical_floor_is_created_on_import(): void
    {
        Location::where('block_id', $this->block->id)
            ->where('name', \App\Services\BlockLocationBuilder::TECHNICAL_FLOOR)
            ->get()
            ->each(function ($floor) {
                Location::where('parent_id', $floor->id)->delete();
                $floor->delete();
            });

        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $file = $this->makeWorkbook(
            [[$type->code, $type->name, $type->unit, ['Техникийн давхар' => 116.1]]],
            ['1-р давхар', 'Техникийн давхар'],
        );

        $this->artisan('cpms:import-quantities', [
            '--file' => $file, '--block' => 'А1 блок', '--apply' => true, '--force' => true,
        ])->assertSuccessful();

        $floor = Location::where('block_id', $this->block->id)
            ->where('name', 'Техникийн давхар')
            ->firstOrFail();

        // Давхар + түүний дөрвөн зай.
        $this->assertSame(4, Location::where('parent_id', $floor->id)->count());
        $this->assertSame(1, WorkItem::where('location_id', $floor->id)->count());
    }

    /**
     * Урьдчилан харах нь бичилттэй ИЖИЛ кодоор явж, эцэст нь буцаана.
     *
     * Тиймээс дайвар үйлдэл (дутуу байршил үүсгэх) ч мөн буцах ёстой —
     * эс бөгөөс «юу ч өөрчлөгдөөгүй» гэдэг худал болно.
     */
    public function test_preview_rolls_back_even_the_locations_it_would_create(): void
    {
        Location::where('block_id', $this->block->id)
            ->where('name', \App\Services\BlockLocationBuilder::TECHNICAL_FLOOR)
            ->get()
            ->each(function ($floor) {
                Location::where('parent_id', $floor->id)->delete();
                $floor->delete();
            });

        $before = Location::where('block_id', $this->block->id)->count();
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $file = $this->makeWorkbook([[$type->code, $type->name, $type->unit, ['1-р давхар' => 15]]]);

        $this->artisan('cpms:import-quantities', ['--file' => $file, '--block' => 'А1 блок'])
            ->assertSuccessful();

        $this->assertSame($before, Location::where('block_id', $this->block->id)->count());
        $this->assertSame(0, WorkItem::count());
    }

    /**
     * Төсвийн «Хяналт шалгалт хийх инженер» багана хэрэглэгч рүү холбогдоно.
     *
     * Төсөвт ЗӨВХӨН өвөр нэр бичигддэг («Хулан», «Тамир»). Холбоос нь
     * `staff.json`-ийн `budgetName`-д тодорхой бичигдсэн — овог хасаж
     * таамагласан бол «Тамир» нь Б.Сайхантамир эсэхийг КОД шийдэж байна
     * гэсэн үг болно.
     */
    public function test_the_budget_inspector_column_is_matched_to_a_user(): void
    {
        $this->seed(\Database\Seeders\StaffSeeder::class);

        $khulan = User::where('email', 'khulan@inel.mn')->firstOrFail();
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $import = $this->parse($this->makeWorkbook([
            [$type->code, $type->name, $type->unit, ['1-р давхар' => 10], 'Хулан'],
        ]));

        $this->assertSame([], $import->errors(), json_encode($import->errors(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($khulan->id, $import->plan()[0]['inspectorId']);
        $this->assertSame([], $import->unknownInspectors());
    }

    /**
     * Таних боломжгүй нэрийг ЧИМЭЭГҮЙ алгасахгүй.
     *
     * Хоосон орхих нь зөв (таамаглахаас дээр), гэвч мэдээлэхгүй бол тэр
     * ажлуудын хяналт хэн рүү ч явахгүй байгааг хэн ч мэдэхгүй.
     */
    public function test_an_unknown_inspector_name_is_reported_not_guessed(): void
    {
        $this->seed(\Database\Seeders\StaffSeeder::class);

        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $import = $this->parse($this->makeWorkbook([
            [$type->code, $type->name, $type->unit, ['1-р давхар' => 10], 'Болд'],
        ]));

        $this->assertNull($import->plan()[0]['inspectorId'], 'Таамаглах ёсгүй.');
        $this->assertSame(['Болд' => 1], $import->unknownInspectors());
    }

    /**
     * Нэг нүдэнд хоёр нэр — эхнийх оногдож, нөгөө нь тайланд гарна.
     *
     * Бодит төсөвт «Тамир, Хулан» гэсэн нэг мөр бий. Багана нь нэг
     * хариуцагч гэсэн утгатай тул хоёуланг хадгалах арга алга.
     */
    public function test_two_names_in_one_cell_use_the_first_and_are_reported(): void
    {
        $this->seed(\Database\Seeders\StaffSeeder::class);

        $tamir = User::where('email', 'saikhantamir@inel.mn')->firstOrFail();
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $import = $this->parse($this->makeWorkbook([
            [$type->code, $type->name, $type->unit, ['1-р давхар' => 10], 'Тамир, Хулан'],
        ]));

        $this->assertSame($tamir->id, $import->plan()[0]['inspectorId']);
        $this->assertSame(['Тамир, Хулан' => 1], $import->sharedInspectors());
        $this->assertSame([], $import->unknownInspectors());
    }

    /**
     * Инженер оноогдоогүй мөр нь ХООСОН бичигдэнэ, хуучныг үлдээхгүй.
     *
     * Төсвөөс хариуцлага хасагдсан бол системд ул мөргүй үргэлжлэх ёсгүй.
     */
    public function test_apply_clears_an_inspector_the_budget_no_longer_names(): void
    {
        $this->seed(\Database\Seeders\StaffSeeder::class);

        $khulan = User::where('email', 'khulan@inel.mn')->firstOrFail();
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $floor = Location::where('block_id', $this->block->id)->where('name', '1-р давхар')->firstOrFail();

        $item = WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $floor->id,
            'work_type_id' => $type->id,
            'name' => $type->name,
            'unit' => $type->unit,
            'planned_qty' => 10,
            'inspector_id' => $khulan->id,
            'status' => 'not_started',
            'review_state' => 'none',
        ]);

        $file = $this->makeWorkbook([
            [$type->code, $type->name, $type->unit, ['1-р давхар' => 10]],   // инженергүй
        ]);

        $this->artisan('cpms:import-quantities', [
            '--file' => $file, '--block' => 'А1 блок', '--apply' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertNull($item->refresh()->inspector_id);
    }

    public function test_without_apply_nothing_changes(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $file = $this->makeWorkbook([[$type->code, $type->name, $type->unit, ['1-р давхар' => 15]]]);

        $this->artisan('cpms:import-quantities', ['--file' => $file, '--block' => 'А1 блок'])
            ->assertSuccessful();

        $this->assertSame(0, WorkItem::count(), 'Урьдчилан харах нь юу ч бичих ёсгүй.');
    }

    // -----------------------------------------------------------------

    private function parse(string $file): WorkBudgetImport
    {
        $types = [];

        foreach (WorkType::whereNotNull('code')->get() as $type) {
            $types[$type->code] ??= $type;

            if ($type->code_part) {
                $types[$type->code.'|'.mb_strtolower($type->code_part)] = $type;
            }
        }

        $inspectors = [];

        foreach (json_decode(file_get_contents(database_path('data/staff.json')), true) as $row) {
            if (! isset($row['budgetName'])) {
                continue;
            }

            $user = User::where('email', $row['email'])->first();

            if ($user) {
                $inspectors[mb_strtolower($row['budgetName'])] = $user->id;
            }
        }

        return (new WorkBudgetImport($this->block))
            ->parse(new XlsxReader($file), $types, $inspectors);
    }

    /**
     * Төсвийн бүтэцтэй туршилтын .xlsx үүсгэнэ.
     *
     * `XlsxWriter`-ийг ашиглана — бичигч, уншигч хоёр ИЖИЛ файлын форматыг
     * ойлгож байгааг дайвар байдлаар шалгана.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4?: string}>  $rows
     * @param  array<int, string>  $floors
     */
    private function makeWorkbook(array $rows, array $floors = ['1-р давхар', '2-р давхар']): string
    {
        $header2 = array_pad([], 9, '');
        $header2[8] = 'Хэмжих нэгж';

        $header3 = array_pad([], 9, '');
        foreach ($floors as $i => $name) {
            $header3[9 + $i] = $name;
        }

        $sheet = [array_pad([], 9, ''), $header2, $header3];

        foreach ($rows as $row) {
            [$code, $name, $unit, $quantities] = $row;
            $line = array_pad([], 9 + count($floors), '');
            $line[2] = $code;
            $line[5] = $name;
            $line[6] = $row[4] ?? '';   // Хяналт шалгалт хийх инженер
            $line[8] = $unit;

            foreach ($floors as $i => $floorName) {
                if (array_key_exists($floorName, $quantities)) {
                    $line[9 + $i] = $quantities[$floorName];
                }
            }

            $sheet[] = $line;
        }

        $writer = new \App\Services\XlsxWriter(WorkBudgetImport::SHEET);

        foreach ($sheet as $line) {
            $writer->row($line);
        }

        return $writer->save(tempnam(sys_get_temp_dir(), 'budget').'.xlsx');
    }
}
