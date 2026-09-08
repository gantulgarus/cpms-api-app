<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Админы удирдлагын хэсэг: хэрэглэгч ба лавлах сан.
 *
 * Хэрэглэгч үүсгэх боломжгүй байсан нь системийн хамгийн том нүх байв —
 * бүгд нэг дансаар нэвтэрвэл "хэн мэдээлсэн, хэн баталсан" гэсэн бүтэн
 * бүтэц утгагүй болно.
 */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $attrs = []): User
    {
        return User::create([
            'name' => 'Тест '.$role,
            'email' => $role.'-'.uniqid().'@cpms.test',
            'password' => Hash::make('password'),
            'role' => $role,
            ...$attrs,
        ]);
    }

    private function asAdmin(): User
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    // -----------------------------------------------------------------
    // Хэрэглэгч
    // -----------------------------------------------------------------

    public function test_admin_creates_a_user_who_can_then_log_in(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Б.Болд',
            'email' => 'bold@cpms.mn',
            'password' => 'nuutsug123',
            'role' => 'inspector',
        ])->assertCreated();

        $response->assertJsonPath('data.role', 'inspector')
            ->assertJsonPath('data.roleLabel', 'Хяналтын инженер')
            ->assertJsonPath('data.canInspect', true)
            ->assertJsonPath('data.canReportProgress', false);

        // Хамгийн чухал шалгалт: үүсгэсэн данс ҮНЭХЭЭР ажиллах ёстой.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'bold@cpms.mn',
            'password' => 'nuutsug123',
        ])->assertOk()->assertJsonPath('data.user.role', 'inspector');
    }

    public function test_password_is_generated_when_not_supplied(): void
    {
        $this->asAdmin();

        $temp = $this->postJson('/api/v1/users', [
            'name' => 'Д.Дорж',
            'email' => 'dorj@cpms.mn',
            'role' => 'site_engineer',
        ])->assertCreated()->json('meta.temporaryPassword');

        // Хоосон нууц үгтэй данс үүсгэхийг зөвшөөрөхгүй — түр үг заавал гарна.
        $this->assertIsString($temp);
        $this->assertGreaterThanOrEqual(8, strlen($temp));

        $this->postJson('/api/v1/auth/login', ['email' => 'dorj@cpms.mn', 'password' => $temp])
            ->assertOk();
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->asAdmin();
        $this->user('site_engineer', ['email' => 'davhardsan@cpms.mn']);

        $this->postJson('/api/v1/users', [
            'name' => 'Хоёр дахь',
            'email' => 'davhardsan@cpms.mn',
            'role' => 'inspector',
        ])->assertStatus(422);
    }

    public function test_scope_can_be_assigned_and_cleared(): void
    {
        $this->asAdmin();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $block = $project->blocks()->create(['name' => 'А блок', 'floors' => 4, 'units_per_floor' => 2]);

        $id = $this->postJson('/api/v1/users', [
            'name' => 'Талбайн инженер',
            'email' => 'talbai@cpms.mn',
            'role' => 'site_engineer',
            'scopeBlockIds' => [$block->id],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/users/{$id}")->assertJsonPath('data.scopeBlockIds', [$block->id]);

        // Хоосон массив бол "хязгаарлалт авах" гэсэн хүчинтэй үйлдэл — үл тоомсорлож болохгүй.
        $this->patchJson("/api/v1/users/{$id}", ['scopeBlockIds' => []])
            ->assertOk()
            ->assertJsonPath('data.scopeBlockIds', []);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $this->asAdmin();

        $target = $this->user('site_engineer', ['email' => 'garsan@cpms.mn']);

        $this->deleteJson("/api/v1/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $this->postJson('/api/v1/auth/login', ['email' => 'garsan@cpms.mn', 'password' => 'password'])
            ->assertStatus(422);

        // Данс устгагдаагүй — түүний мэдээлсэн явц эзэнгүй үлдэх ёсгүй.
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_last_admin_cannot_be_deactivated(): void
    {
        $admin = $this->asAdmin();
        $second = $this->user('admin');

        // Хоёр админ байхад нэгийг нь хааж болно.
        $this->deleteJson("/api/v1/users/{$second->id}")->assertOk();

        // Үлдсэн ганцыг хаавал системд хэн ч орж чадахгүй болно.
        $this->actingAs($admin, 'sanctum');
        $other = $this->user('director');
        $this->actingAs($other, 'sanctum');
        $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->asAdmin();

        $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = $this->asAdmin();

        // Өөрийгөө талбайн инженер болговол хэрэглэгч удирдах эрхээ алдана.
        $this->patchJson("/api/v1/users/{$admin->id}", ['role' => 'site_engineer'])
            ->assertStatus(422);
    }

    public function test_reset_password_revokes_existing_tokens(): void
    {
        $this->asAdmin();
        $target = $this->user('site_engineer', ['email' => 'martsan@cpms.mn']);
        $target->createToken('mobile');

        $this->assertSame(1, $target->tokens()->count());

        $new = $this->postJson("/api/v1/users/{$target->id}/reset-password")
            ->assertOk()
            ->json('data.temporaryPassword');

        // Нууц үг мартсан гэдэг нь ихэвчлэн утас алдсан гэсэн үг.
        $this->assertSame(0, $target->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'martsan@cpms.mn', 'password' => $new])
            ->assertOk();
    }

    public function test_only_admin_and_director_manage_users(): void
    {
        foreach (['site_engineer', 'inspector', 'project_manager', 'general_engineer'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');
            $this->getJson('/api/v1/users')->assertForbidden();
            $this->postJson('/api/v1/users', [
                'name' => 'Хууль бус',
                'email' => "hool-{$role}@cpms.mn",
                'role' => 'admin',
            ])->assertForbidden();
        }

        $this->actingAs($this->user('director'), 'sanctum');
        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_roles_endpoint_describes_each_role(): void
    {
        $this->asAdmin();

        $roles = $this->getJson('/api/v1/users/roles')->assertOk()->json('data');

        $this->assertCount(count(User::ROLES), $roles);

        $inspector = collect($roles)->firstWhere('value', 'inspector');
        $this->assertTrue($inspector['canInspect']);
        $this->assertFalse($inspector['canReportProgress']);
    }

    // -----------------------------------------------------------------
    // Лавлах сан
    // -----------------------------------------------------------------

    public function test_general_engineer_can_add_a_work_type(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $this->actingAs($this->user('general_engineer'), 'sanctum');

        $group = WorkTypeGroup::first();

        $this->postJson('/api/v1/work-types', [
            'groupId' => $group->id,
            'name' => 'Шинэ материал угсрах',
            'unit' => 'м2',
            'level' => 'floor',
        ])->assertCreated()->assertJsonPath('data.name', 'Шинэ материал угсрах');
    }

    public function test_site_engineer_cannot_edit_reference_data(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $this->actingAs($this->user('site_engineer'), 'sanctum');

        $this->postJson('/api/v1/work-types', [
            'groupId' => WorkTypeGroup::first()->id,
            'name' => 'Хууль бус',
            'unit' => 'ш',
            'level' => 'block',
        ])->assertForbidden();
    }

    public function test_work_type_in_use_cannot_change_unit_or_level(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $admin = $this->asAdmin();

        [$block, $workType] = $this->blockWithOneWorkItem();
        $this->actingAs($admin, 'sanctum');

        // Нэр засах нь аюулгүй — үсгийн алдаа засах эрх байх ёстой.
        $this->patchJson("/api/v1/work-types/{$workType->id}", ['name' => 'Зөв нэр'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Зөв нэр');

        // Нэгж солих нь м²-т хэмжсэн бүх гүйцэтгэлийг утгагүй болгоно.
        $this->patchJson("/api/v1/work-types/{$workType->id}", ['unit' => 'ширхэг'])
            ->assertStatus(409);

        // Түвшин солих нь одоо байгаа бичлэгүүдийг буруу байршилтай болгоно.
        $newLevel = $workType->level === 'floor' ? 'unit' : 'floor';
        $this->patchJson("/api/v1/work-types/{$workType->id}", ['level' => $newLevel])
            ->assertStatus(409);
    }

    public function test_work_type_in_use_cannot_be_deleted(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $admin = $this->asAdmin();

        [, $workType] = $this->blockWithOneWorkItem();
        $this->actingAs($admin, 'sanctum');

        $this->deleteJson("/api/v1/work-types/{$workType->id}")->assertStatus(409);
    }

    public function test_unused_work_type_can_be_deleted(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $this->asAdmin();

        $id = $this->postJson('/api/v1/work-types', [
            'groupId' => WorkTypeGroup::first()->id,
            'name' => 'Түр төрөл',
            'unit' => 'ш',
            'level' => 'block',
        ])->json('data.id');

        $this->deleteJson("/api/v1/work-types/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('work_types', ['id' => $id]);
    }

    public function test_group_with_work_types_cannot_be_deleted(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $this->asAdmin();

        $group = WorkTypeGroup::has('workTypes')->firstOrFail();

        $this->deleteJson("/api/v1/work-type-groups/{$group->id}")->assertStatus(409);
    }

    public function test_group_list_reports_work_type_counts(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $this->actingAs($this->user('site_engineer'), 'sanctum');

        $groups = $this->getJson('/api/v1/work-type-groups')->assertOk()->json('data');

        $this->assertNotEmpty($groups);
        $this->assertSame(
            WorkType::count(),
            array_sum(array_column($groups, 'workTypeCount')),
            'Бүлгүүдийн нийлбэр нийт ажлын төрлийн тоотой таарах ёстой.'
        );
    }

    /** @return array{0: \App\Models\Block, 1: WorkType} */
    private function blockWithOneWorkItem(): array
    {
        $company = Company::create(['name' => 'Инэл ХХК']);
        /** @var Project $project */
        $project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $block = $project->blocks()->create(['name' => 'А блок', 'floors' => 2, 'units_per_floor' => 1]);

        $workType = WorkType::where('level', 'floor')->firstOrFail();
        $location = $block->locations()->create([
            'parent_id' => null,
            'level' => 'block',
            'name' => 'А блок',
            'path' => 'А блок',
            'path_key' => '/root/',
            'sequence_number' => 0,
        ]);

        $block->workItems()->create([
            'location_id' => $location->id,
            'work_type_id' => $workType->id,
            'name' => $workType->name,
            'unit' => $workType->unit,
            'planned_qty' => 100,
            'status' => 'not_started',
            'review_state' => 'none',
        ]);

        return [$block, $workType];
    }
}
