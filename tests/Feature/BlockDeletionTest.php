<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Inspection;
use App\Models\Location;
use App\Models\Photo;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Блок устгах.
 *
 * ХАМГИЙН АЮУЛТАЙ ҮЙЛДЭЛ: байршил, ажлын мөр, явц, шалгалт, зураг,
 * төсөв, захиалга бүгд каскадаар дагаж устана. Тиймээс хоёр түгжээтэй —
 * зөвхөн админ, бөгөөд блокийн нэрийг бичиж баталгаажуулна.
 */
class BlockDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);

        $this->block = $project->blocks()->create([
            'name' => 'Туршилт байшин',
            'building_no' => 'T1',
            'floors' => 1,
            'units_per_floor' => 2,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 1, 2);

        $this->admin = User::create([
            'name' => 'Системийн админ', 'email' => 'admin@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'admin',
        ]);
    }

    // ───────────────────────── эрх ─────────────────────────

    /**
     * ЗӨВХӨН АДМИН устгана — ерөнхий инженер ч болохгүй.
     *
     * Блок устгах нь өдөр тутмын ажил биш: буруу үүсгэсэн блокийг
     * цөөхөн удаа устгана. Эрхийг өргөн тавих шалтгаан алга.
     */
    public function test_only_an_admin_may_delete_a_block(): void
    {
        foreach (['general_engineer', 'director', 'project_manager', 'site_engineer'] as $role) {
            $user = User::create([
                'name' => "Хэрэглэгч {$role}",
                'email' => "{$role}@inel.mn",
                'password' => bcrypt('secret123'),
                'role' => $role,
            ]);

            $this->actingAs($user)
                ->deleteJson("/api/v1/blocks/{$this->block->id}", ['confirmName' => $this->block->name])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('blocks', ['id' => $this->block->id]);
    }

    /** Нөлөөллийн тооллогыг ч зөвхөн админ харна. */
    public function test_the_impact_preview_is_admin_only(): void
    {
        $engineer = User::create([
            'name' => 'Ерөнхий инженер', 'email' => 'ge@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'general_engineer',
        ]);

        $this->actingAs($engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/deletion-impact")
            ->assertForbidden();
    }

    // ───────────────────────── баталгаажуулалт ─────────────────────────

    /**
     * НЭР ТААРААГҮЙ бол устгахгүй.
     *
     * Санамсаргүй дарах боломжийг бүрэн хаана: 1,991 мөр, бүх гүйцэтгэл
     * нэг товчоор алга болох үйлдэлд «Тийм үү?» гэсэн асуулт хангалтгүй.
     */
    public function test_a_wrong_confirmation_name_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/blocks/{$this->block->id}", ['confirmName' => 'Өөр нэр'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Нэр таарахгүй'));

        $this->assertDatabaseHas('blocks', ['id' => $this->block->id]);
    }

    /** Нэргүй хүсэлтийг татгалзана. */
    public function test_the_confirmation_name_is_required(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/blocks/{$this->block->id}", [])
            ->assertStatus(422);

        $this->assertDatabaseHas('blocks', ['id' => $this->block->id]);
    }

    // ───────────────────────── устгалт ─────────────────────────

    /** Нэр таарвал блок ба түүний БҮХ өгөгдөл устана. */
    public function test_a_confirmed_delete_removes_the_block_and_its_data(): void
    {
        [$item] = $this->workWithHistory();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/blocks/{$this->block->id}", [
                'confirmName' => 'Туршилт байшин',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Туршилт байшин');

        $this->assertDatabaseMissing('blocks', ['id' => $this->block->id]);
        $this->assertSame(0, Location::where('block_id', $this->block->id)->count());
        $this->assertSame(0, WorkItem::where('block_id', $this->block->id)->count());
        $this->assertSame(0, ProgressEntry::where('work_item_id', $item->id)->count());
        $this->assertSame(0, Inspection::where('work_item_id', $item->id)->count());
        $this->assertSame(0, Photo::where('work_item_id', $item->id)->count());
    }

    /**
     * ЗУРАГ ДИСКНЭЭС ч устана.
     *
     * Өгөгдлийн сангийн каскад нь мөрийг устгадаг, ФАЙЛЫГ биш. Эс
     * бөгөөс дискэн дээр эзэнгүй зураг хуримтлагдана.
     */
    public function test_photo_files_are_removed_from_disk(): void
    {
        Storage::fake('local');

        [$item] = $this->workWithHistory();

        $path = UploadedFile::fake()->image('zurag.jpg')->store('photos', 'local');

        Photo::create([
            'work_item_id' => $item->id,
            'path' => $path,
            'type' => 'progress',
            'uploaded_by_id' => $this->admin->id,
        ]);

        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/blocks/{$this->block->id}", ['confirmName' => 'Туршилт байшин'])
            ->assertOk();

        Storage::disk('local')->assertMissing($path);
    }

    /** Бусад блок ХӨНДӨГДӨХГҮЙ. */
    public function test_other_blocks_are_untouched(): void
    {
        $other = $this->block->project->blocks()->create([
            'name' => 'Өөр блок',
            'building_no' => 'T2',
            'floors' => 1,
            'units_per_floor' => 1,
            'start_date' => '2026-10-01',
        ]);
        app(\App\Services\BlockLocationBuilder::class)->build($other, 1, 1);

        $before = Location::where('block_id', $other->id)->count();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/blocks/{$this->block->id}", ['confirmName' => 'Туршилт байшин'])
            ->assertOk();

        $this->assertDatabaseHas('blocks', ['id' => $other->id]);
        $this->assertSame($before, Location::where('block_id', $other->id)->count());
    }

    // ───────────────────────── нөлөөллийн тоо ─────────────────────────

    /**
     * Устгахын өмнө юу алга болохыг ТООГООР хэлнэ.
     *
     * Эдгээр тоонуудгүйгээр «устгах уу?» гэсэн асуулт утгагүй: хүн юу
     * алдаж байгаагаа мэдэхгүй байж «тийм» гэж хариулна.
     */
    public function test_the_impact_preview_counts_what_will_be_lost(): void
    {
        $this->workWithHistory();

        $impact = $this->actingAs($this->admin)
            ->getJson("/api/v1/blocks/{$this->block->id}/deletion-impact")
            ->assertOk()
            ->json('data');

        $this->assertSame('Туршилт байшин', $impact['blockName']);
        $this->assertGreaterThan(0, $impact['locations']);
        $this->assertSame(1, $impact['workItems']);
        $this->assertSame(1, $impact['progressEntries']);
        $this->assertSame(1, $impact['inspections']);

        /*
         * БАТАЛГААЖСАН шалгалт — хамгийн чухал тоо. Эдгээр нь актад
         * орсон, төлбөр хийгдсэн байж болзошгүй.
         */
        $this->assertSame(1, $impact['acceptedInspections']);
    }

    // ───────────────────────── туслах ─────────────────────────

    /**
     * Нэг ажлын мөр + явц + батлагдсан шалгалт.
     *
     * @return array{0: WorkItem}
     */
    private function workWithHistory(): array
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $location = $this->block->locations()->where('name', '1-р давхар')->firstOrFail();

        $item = WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $location->id,
            'work_type_id' => $type->id,
            'name' => $type->name,
            'unit' => $type->unit,
            'planned_qty' => 100,
            'reported_qty' => 40,
            'accepted_qty' => 40,
            'status' => 'in_progress',
            'review_state' => 'approved',
        ]);

        ProgressEntry::create([
            'work_item_id' => $item->id,
            'reported_by_id' => $this->admin->id,
            'completed_qty' => 40,
            'recorded_at' => now(),
        ]);

        Inspection::create([
            'work_item_id' => $item->id,
            'inspector_id' => $this->admin->id,
            'stage' => Inspection::STAGE_FINAL,
            'result' => 'accepted',
            'accepted_qty' => 40,
            'rejected_qty' => 0,
            'inspected_at' => now(),
        ]);

        return [$item];
    }
}
