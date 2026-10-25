<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExtensionRequest;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Хугацаа сунгахыг хүсэлт болгов.
 *
 * ГОЛ ЗОРИЛГО: хоцролтыг НУУХ арга байхгүй гэдгийг тогтоох. Урьд нь огноог
 * шууд өөрчилдөг байсан тул хоцорсон хүн огноогоо хойшлуулаад хоцроогүй
 * болчихдог — нэг товч дарахад тайлан өөрөө засагддаг бол тайлан хэнийг ч
 * хянахгүй.
 */
class ExtensionRequestTest extends TestCase
{
    use RefreshDatabase;

    private WorkItem $item;

    private User $engineer;    // талбайн инженер — хүсэлт гаргана

    private User $chief;       // ерөнхий инженер — батална

    private User $manager;     // төслийн менежер — батлах эрхгүй

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $block = $project->blocks()->create([
            'name' => 'А1 блок', 'building_no' => 'A1',
            'floors' => 1, 'units_per_floor' => 1, 'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($block, 1, 1);

        $group = WorkTypeGroup::create(['name' => 'Засал', 'sequence_number' => 1, 'build_order' => 7]);
        $type = WorkType::create([
            'name' => 'Шавардлага', 'work_type_group_id' => $group->id,
            'level' => 'floor', 'unit' => 'м2',
        ]);

        $this->item = WorkItem::create([
            'block_id' => $block->id,
            'location_id' => Location::where('block_id', $block->id)->where('level', 'floor')->firstOrFail()->id,
            'work_type_id' => $type->id,
            'name' => $type->name, 'unit' => $type->unit,
            'planned_qty' => 100,
            'planned_end_date' => now()->subDays(10)->toDateString(),
            'status' => 'not_started', 'review_state' => 'none',
        ]);

        $this->engineer = User::factory()->create(['role' => 'site_engineer']);
        $this->chief = User::factory()->create(['role' => 'general_engineer']);
        $this->manager = User::factory()->create(['role' => 'project_manager']);
    }

    /**
     * ХАМГИЙН ЧУХАЛ ТЕСТ: сунгалт нь үндсэн төлөвлөгөөг ХӨНДӨХГҮЙ.
     *
     * Хоцролтыг хоёр талаас хэмжинэ — амлалтаасаа хоцорсон эсэх, мөн үндсэн
     * төлөвлөгөөнөөс хэр гулссан. Хоёрдугаарыг нуух арга байх ёсгүй.
     */
    public function test_an_approved_extension_never_hides_the_baseline_slip(): void
    {
        $baseline = $this->item->baseline_end_date->toDateString();

        $this->assertSame(
            $this->item->planned_end_date->toDateString(),
            $baseline,
            'Бэлтгэл: үүсгэх үед хоёр огноо тэнцүү байх ёстой.',
        );
        $this->assertGreaterThan(0, $this->item->overdue_days, 'Бэлтгэл: ажил хоцорсон байх ёстой.');

        Sanctum::actingAs($this->chief);
        $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(20)->toDateString(),
            'category' => 'material_shortage',
            'reason' => 'Цонхны хүргэлт хоцорсон тул заслыг хойшлуулав.',
        ])->assertOk();

        $this->item->refresh();

        // Амлалт хөдөлсөн — одоо хоцроогүй.
        $this->assertSame(0, $this->item->overdue_days);

        // Гэвч ҮНДСЭН огноо хэвээр: гулсалт нь үлдэнэ.
        $this->assertSame($baseline, $this->item->baseline_end_date->toDateString());
        $this->assertGreaterThan(
            0,
            $this->item->baseline_overdue_days,
            'Үндсэн төлөвлөгөөний гулсалт нь сунгалтаар арилах ЁСГҮЙ.',
        );
        $this->assertSame(30, $this->item->extended_days, '10 хоног хоцорсоноос 20 хоног хойш.');
    }

    /** Талбайн инженер сунгавал ХҮЛЭЭГДЭНЭ — огноо хөдлөхгүй. */
    public function test_a_site_engineer_can_only_request(): void
    {
        $before = $this->item->planned_end_date->toDateString();

        Sanctum::actingAs($this->engineer);
        $response = $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'Хасах 28 хэмд шавардлага хийх боломжгүй байв.',
        ])->assertStatus(202);

        $this->assertFalse($response->json('meta.applied'));
        $this->assertSame('pending', $response->json('data.status'));

        // Огноо ХӨДЛӨӨГҮЙ — хүсэлт нь шийдвэр биш.
        $this->assertSame($before, $this->item->refresh()->planned_end_date->toDateString());
        $this->assertGreaterThan(0, $this->item->overdue_days, 'Хүсэлт гаргаад хоцролт арилах ЁСГҮЙ.');
    }

    /** Ерөнхий инженер батлахад огноо хөдөлнө. */
    public function test_the_chief_engineer_approves_a_pending_request(): void
    {
        Sanctum::actingAs($this->engineer);
        $id = $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'Хасах 28 хэмд шавардлага хийх боломжгүй байв.',
        ])->json('data.id');

        Sanctum::actingAs($this->chief);
        $this->postJson("/api/v1/extension-requests/{$id}/approve", ['note' => 'Зөвшөөрөв'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decidedBy.name', $this->chief->name);

        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $this->item->refresh()->planned_end_date->toDateString(),
        );
    }

    /**
     * ТӨСЛИЙН МЕНЕЖЕР батлах эрхгүй.
     *
     * Хуваарийн гулсалтыг хамгийн их нуумаар байдаг хүн нь тэр хуваарийг
     * хариуцдаг хүн. Батлах нь түүнээс ДЭЭШ шатлалын шийдвэр.
     */
    public function test_a_project_manager_cannot_approve(): void
    {
        Sanctum::actingAs($this->engineer);
        $id = $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'Хасах 28 хэмд шавардлага хийх боломжгүй байв.',
        ])->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v1/extension-requests/{$id}/approve")->assertStatus(403);

        $this->assertSame('pending', ExtensionRequest::findOrFail($id)->status);
    }

    /** Татгалзахад шалтгаан ЗААВАЛ — «болохгүй» гэж орхиж болохгүй. */
    public function test_rejecting_requires_a_reason(): void
    {
        Sanctum::actingAs($this->engineer);
        $id = $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'Хасах 28 хэмд шавардлага хийх боломжгүй байв.',
        ])->json('data.id');

        Sanctum::actingAs($this->chief);
        $this->postJson("/api/v1/extension-requests/{$id}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->postJson("/api/v1/extension-requests/{$id}/reject", [
            'note' => 'Хүйтний улмаас гэж бичсэн ч тэр өдрүүдэд -5 хэм байсан.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        // Татгалзсан хүсэлт огноог ХӨНДӨХГҮЙ.
        $this->assertGreaterThan(0, $this->item->refresh()->overdue_days);
    }

    /** Нэг ажилд хоёр хүлээгдэж буй хүсэлт байж болохгүй. */
    public function test_a_second_pending_request_is_refused(): void
    {
        Sanctum::actingAs($this->engineer);

        $payload = [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'Хасах 28 хэмд шавардлага хийх боломжгүй байв.',
        ];

        $this->postJson("/api/v1/work-items/{$this->item->id}/extend", $payload)->assertStatus(202);

        $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            ...$payload,
            'plannedEndDate' => now()->addDays(14)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('plannedEndDate');
    }

    /** Шийдэгдсэн хүсэлтийг дахин шийдэхгүй. */
    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        Sanctum::actingAs($this->chief);
        $id = $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'Хасах 28 хэмд шавардлага хийх боломжгүй байв.',
        ])->json('data.id');

        $this->postJson("/api/v1/extension-requests/{$id}/reject", [
            'note' => 'Аль хэдийн батлагдсан хүсэлтийг татгалзах оролдлого.',
        ])->assertStatus(422);
    }

    /** Огноог УРАГШ татах нь сунгалт биш. */
    public function test_pulling_the_date_earlier_is_refused(): void
    {
        Sanctum::actingAs($this->chief);

        $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->subDays(20)->toDateString(),
            'category' => 'weather',
            'reason' => 'Урагшлуулах гэсэн оролдлого.',
        ])->assertStatus(422)->assertJsonValidationErrors('plannedEndDate');
    }

    /** Тайлбар хэт тодорхойгүй бол хүлээж авахгүй. */
    public function test_a_vague_reason_is_refused(): void
    {
        Sanctum::actingAs($this->chief);

        $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'хүйтэн',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /** Батлагдсан сунгалт сааталд бүртгэгдэнэ — шалтгаан нэг дор хуримтлагдана. */
    public function test_an_approved_extension_is_recorded_as_an_issue(): void
    {
        Sanctum::actingAs($this->chief);
        $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'material_shortage',
            'reason' => 'Цонхны хүргэлт хоёр долоо хоног хоцорсон.',
        ])->assertOk();

        $issue = $this->item->issues()->latest('created_at')->firstOrFail();

        $this->assertSame('material_shortage', $issue->category);
        $this->assertStringContainsString('хоног', $issue->description);
        $this->assertStringContainsString('Цонхны хүргэлт', $issue->description);
    }

    /** Дараалал нь хүлээгдэж буй хүсэлтийг харуулна. */
    public function test_the_queue_lists_pending_requests(): void
    {
        Sanctum::actingAs($this->engineer);
        $this->postJson("/api/v1/work-items/{$this->item->id}/extend", [
            'plannedEndDate' => now()->addDays(7)->toDateString(),
            'category' => 'weather',
            'reason' => 'Хасах 28 хэмд шавардлага хийх боломжгүй байв.',
        ])->assertStatus(202);

        Sanctum::actingAs($this->chief);
        $project = Project::firstOrFail();

        $rows = $this->getJson("/api/v1/projects/{$project->id}/extension-requests")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->item->name, $rows[0]['workItemName']);
        $this->assertSame(7 + 10, $rows[0]['requestedDays'], '10 хоног хоцорсоноос 7 хоног хойш.');
    }

    /**
     * ТАКТ хуваарийг дахин татах нь ДАХИН ТӨЛӨВЛӨЛТ — үндсэн огноо дагана.
     *
     * Эс бөгөөс давхрын хугацааг 5-аас 12 хоног болгоход бүх ажил гэнэт
     * «үндсэн төлөвлөгөөнөөс гулссан» гэж харагдана.
     */
    public function test_rescheduling_moves_the_baseline_too(): void
    {
        $block = $this->item->block;

        Sanctum::actingAs($this->chief);
        $this->postJson("/api/v1/blocks/{$block->id}/schedule", ['taktDays' => 12])->assertOk();

        $this->item->refresh();

        $this->assertSame(
            $this->item->planned_end_date->toDateString(),
            $this->item->baseline_end_date->toDateString(),
            'Дахин төлөвлөлтийн дараа хоёр огноо тэнцүү байх ёстой.',
        );
        $this->assertSame(0, $this->item->extended_days);
    }
}
