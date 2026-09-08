<?php

namespace Tests\Feature;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Чанарын шалгах хуудас.
 *
 * Захиалагчийн дүрэм: "Зөвхөн зураг дээр үндэслэн ажил батлахгүй. Checklist,
 * хэмжилт, хяналтын approval шаардлагатай."
 *
 * Энэ тест нь тэр дүрэм КОД дотор мөрдөгдөж байгаа эсэхийг шалгана. Хамгийн
 * чухал нь `test_cannot_approve_without_the_checklist` — тэр унавал систем
 * захиалагчийн шаардлагыг зөрчиж эхэлсэн гэсэн үг.
 */
class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;
    private User $engineer;
    private User $inspector;
    private WorkTypeGroup $guardedGroup;
    private ChecklistTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        /** @var Project $project */
        $project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $design = BlockDesign::where('floors', 12)->firstOrFail();

        $this->block = $project->blocks()->create([
            'block_design_id' => $design->id,
            'name' => 'А блок',
            'building_no' => '1',
            'floors' => $design->floors,
            'units_per_floor' => $design->units_per_floor,
            'start_date' => '2026-09-01',
        ]);
        (new ApplyBlockDesign($this->block->id, $design->id, '2026-09-01'))->handle();

        $this->engineer = User::factory()->create(['role' => 'site_engineer']);
        $this->inspector = User::factory()->create(['role' => 'inspector']);

        // Нэг бүлэгт л хуудас тавина — нөгөө бүлгүүд хаалтгүй үлдэнэ.
        //
        // Бүлгийг ЖАГСААЛТААС биш, энэ блокт ҮНЭХЭЭР үүссэн ажлаас сонгоно.
        // Загварт ороогүй бүлгийг сонговол тест өгөгдөл олдохгүй унана.
        $sample = WorkItem::where('block_id', $this->block->id)
            ->where('planned_qty', '>', 10)
            ->firstOrFail();

        $this->guardedGroup = WorkType::findOrFail($sample->work_type_id)->group;
        $this->template = ChecklistTemplate::create([
            'name' => 'Тест — чанарын шалгалт',
            'work_type_group_id' => $this->guardedGroup->id,
        ]);
        foreach ([['Түвшин зөв', true], ['Заадас жигд', true], ['Цэвэрлэгээ', false]] as $i => [$text, $req]) {
            $this->template->items()->create([
                'sequence_number' => $i,
                'text' => $text,
                'is_required' => $req,
            ]);
        }
        $this->template->load('items');
    }

    /** Хуудастай бүлгийн ажил, мэдээлэгдсэн байдалтай. */
    private function guardedItem(float $qty = 5): WorkItem
    {
        $typeIds = WorkType::where('work_type_group_id', $this->guardedGroup->id)->pluck('id');

        $item = WorkItem::where('block_id', $this->block->id)
            ->whereIn('work_type_id', $typeIds)
            ->where('planned_qty', '>', $qty)
            ->where('reported_qty', 0)
            ->firstOrFail();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => $qty])
            ->assertCreated();

        return $item->refresh();
    }

    /** @return array<int, array{itemId: string, result: string}> */
    private function answers(string $default = 'pass', ?string $firstResult = null): array
    {
        return $this->template->items->values()->map(fn ($item, $i) => [
            'itemId' => $item->id,
            'result' => $i === 0 ? ($firstResult ?? $default) : $default,
        ])->all();
    }

    // -----------------------------------------------------------------
    // Хаалт
    // -----------------------------------------------------------------

    public function test_checklist_endpoint_returns_the_template_for_the_work_item(): void
    {
        $item = $this->guardedItem();

        $data = $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/work-items/{$item->id}/checklist")
            ->assertOk()
            ->json('data');

        $this->assertSame($this->template->id, $data['id']);
        $this->assertCount(3, $data['items']);
        // Дараалал хадгалагдана — талбай дээр дэс дарааллаар шалгана.
        $this->assertSame('Түвшин зөв', $data['items'][0]['text']);
    }

    public function test_cannot_approve_without_the_checklist(): void
    {
        $item = $this->guardedItem();

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.name', 'ValidationError');

        // Батлагдаагүй нь өгөгдөл дээр ч харагдах ёстой.
        $this->assertSame(0.0, (float) $item->refresh()->accepted_qty);
    }

    public function test_cannot_approve_with_a_partially_filled_checklist(): void
    {
        $item = $this->guardedItem();

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
                'checklist' => [['itemId' => $this->template->items[0]->id, 'result' => 'pass']],
            ])
            ->assertStatus(422);
    }

    public function test_cannot_approve_when_a_required_item_failed(): void
    {
        $item = $this->guardedItem();

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
                'checklist' => $this->answers('pass', 'fail'),
            ])
            ->assertStatus(422);
    }

    public function test_can_reject_when_a_required_item_failed(): void
    {
        $item = $this->guardedItem();

        // Тэнцээгүй байх нь ЯГ буцаах шалтгаан — хаалт нь татгалзахад
        // хамаарах ёсгүй.
        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'rejected',
                'acceptedQty' => 0,
                'reason' => 'Түвшин зөрүүтэй',
                'checklist' => $this->answers('pass', 'fail'),
            ])
            ->assertCreated();

        $this->assertSame('returned', $item->refresh()->review_state);
    }

    public function test_approves_when_every_required_item_passed(): void
    {
        $item = $this->guardedItem();

        $response = $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
                'checklist' => $this->answers('pass'),
            ])
            ->assertCreated();

        $this->assertCount(3, $response->json('data.checklist'));
        $this->assertSame(5.0, (float) $item->refresh()->accepted_qty);
    }

    public function test_optional_item_may_fail_without_blocking(): void
    {
        $item = $this->guardedItem();

        // Гурав дахь зүйл нь заавал биш — тэнцээгүй ч батлагдана.
        $answers = $this->answers('pass');
        $answers[2]['result'] = 'fail';

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
                'checklist' => $answers,
            ])
            ->assertCreated();
    }

    public function test_answers_from_another_template_are_rejected(): void
    {
        $item = $this->guardedItem();

        $other = ChecklistTemplate::create([
            'name' => 'Өөр хуудас',
            'work_type_group_id' => WorkTypeGroup::where('id', '!=', $this->guardedGroup->id)
                ->firstOrFail()->id,
        ]);
        $foreign = $other->items()->create(['text' => 'Хамаарахгүй зүйл', 'is_required' => true]);

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
                'checklist' => [...$this->answers('pass'), ['itemId' => $foreign->id, 'result' => 'pass']],
            ])
            ->assertStatus(422);
    }

    /**
     * Хамгийн чухал аюулгүйн хавхлага.
     *
     * Хуудас тохируулаагүй бүлгийг хаавал загвар бичигдэх хүртэл БҮХ
     * баталгаажуулалт зогсоно — өнөөдөр ажиллаж байгаа талбайг гацаана.
     */
    public function test_work_type_without_a_template_is_not_blocked(): void
    {
        $freeTypeIds = WorkType::where('work_type_group_id', '!=', $this->guardedGroup->id)->pluck('id');

        $item = WorkItem::where('block_id', $this->block->id)
            ->whereIn('work_type_id', $freeTypeIds)
            ->where('planned_qty', '>', 5)
            ->first();

        $this->assertNotNull($item, 'Хуудасгүй бүлгийн ажил тест өгөгдөлд байх ёстой.');

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
            ->assertCreated();

        $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/work-items/{$item->id}/checklist")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
            ])
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // Загвар удирдах
    // -----------------------------------------------------------------

    public function test_work_type_template_wins_over_the_group_template(): void
    {
        $item = $this->guardedItem();

        $specific = ChecklistTemplate::create([
            'name' => 'Зөвхөн энэ төрөлд',
            'work_type_id' => $item->work_type_id,
        ]);
        $specific->items()->create(['text' => 'Тусгай шалгалт', 'is_required' => true]);

        // Нарийвчилсан загвар давамгайлна — эс бөгөөс ерөнхий хуудас
        // тусгай шаардлагыг дардаг.
        $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/work-items/{$item->id}/checklist")
            ->assertOk()
            ->assertJsonPath('data.id', $specific->id);
    }

    public function test_general_engineer_can_create_a_template_with_items(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'general_engineer']), 'sanctum');

        $this->postJson('/api/v1/checklist-templates', [
            'name' => 'Шинэ хуудас',
            'groupId' => $this->guardedGroup->id,
            'items' => [
                ['text' => 'Эхний шалгалт'],
                ['text' => 'Хоёр дахь', 'isRequired' => false],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.isRequired', false);
    }

    public function test_site_engineer_cannot_create_a_template(): void
    {
        $this->actingAs($this->engineer, 'sanctum')
            ->postJson('/api/v1/checklist-templates', [
                'name' => 'Хууль бус',
                'groupId' => $this->guardedGroup->id,
            ])
            ->assertForbidden();
    }

    public function test_template_must_target_exactly_one_thing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');

        // Хоосон — ямар ч ажилд хамаарахгүй.
        $this->postJson('/api/v1/checklist-templates', ['name' => 'Хоосон'])
            ->assertStatus(422);

        // Хоёулаа — аль нь давамгайлах нь ойлгомжгүй.
        $this->postJson('/api/v1/checklist-templates', [
            'name' => 'Хоёул',
            'groupId' => $this->guardedGroup->id,
            'workTypeId' => WorkType::first()->id,
        ])->assertStatus(422);
    }

    public function test_used_template_is_deactivated_instead_of_deleted(): void
    {
        $item = $this->guardedItem();

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
                'checklist' => $this->answers('pass'),
            ])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->deleteJson("/api/v1/checklist-templates/{$this->template->id}")
            ->assertOk()
            ->assertJsonPath('meta.deactivated', true);

        // Хийгдсэн шалгалтын хариулт эзэнгүй болох ёсгүй.
        $this->assertDatabaseHas('checklist_templates', [
            'id' => $this->template->id,
            'is_active' => false,
        ]);
    }

    public function test_inspection_history_keeps_the_checklist_answers(): void
    {
        $item = $this->guardedItem();

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
                'checklist' => $this->answers('pass'),
            ])->assertCreated();

        // Маргаан гарахад "юуг шалгаж баталсан" нь харагдах ёстой.
        $history = $this->getJson("/api/v1/work-items/{$item->id}/inspections")
            ->assertOk()
            ->json('data.0.checklist');

        $this->assertCount(3, $history);
        $this->assertSame('Түвшин зөв', $history[0]['text']);
        $this->assertSame('pass', $history[0]['result']);
    }
}
