<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Төслийг UI-аас үүсгэх.
 *
 * `demo:purge`-ийн дараа сервер төсөлгүй үлдэж, блок үүсгэх товч идэвхгүй
 * болдог байв — төслийг зөвхөн tinker-ээр үүсгэх боломжтой байсан.
 */
class ProjectCreateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => 'Тест '.$role,
            'email' => $role.'@cpms.test',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_admin_creates_project_under_existing_company(): void
    {
        $company = Company::create(['name' => 'Инэл ХХК']);

        $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/projects', [
                'name' => '75 барилгын цогцолбор',
                'companyId' => $company->id,
                'startDate' => '2026-10-25',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', '75 барилгын цогцолбор')
            ->assertJsonPath('data.companyName', 'Инэл ХХК')
            ->assertJsonPath('data.status', 'active');

        $this->assertFalse(Project::first()->is_demo);
    }

    public function test_admin_creates_company_and_project_together_on_empty_server(): void
    {
        $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/projects', ['name' => 'Шинэ төсөл', 'companyName' => 'Шинэ ХХК'])
            ->assertCreated()
            ->assertJsonPath('data.companyName', 'Шинэ ХХК');

        $this->assertSame(1, Company::count());
    }

    public function test_company_is_required(): void
    {
        $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/projects', ['name' => 'Шинэ төсөл'])
            ->assertUnprocessable();

        $this->assertSame(0, Project::count());
    }

    public function test_non_admin_cannot_create_project(): void
    {
        $company = Company::create(['name' => 'Инэл ХХК']);

        foreach (['director', 'general_engineer', 'project_manager'] as $role) {
            $this->actingAs($this->user($role))
                ->postJson('/api/v1/projects', ['name' => 'X', 'companyId' => $company->id])
                ->assertForbidden();
        }

        $this->assertSame(0, Project::count());
    }

    public function test_me_exposes_project_permission(): void
    {
        $this->actingAs($this->user('admin'))
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.canManageProjects', true);

        $this->actingAs($this->user('director'))
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.canManageProjects', false);
    }

    public function test_companies_list_is_admin_only(): void
    {
        Company::create(['name' => 'Инэл ХХК']);

        $this->actingAs($this->user('admin'))
            ->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Инэл ХХК');

        $this->actingAs($this->user('director'))
            ->getJson('/api/v1/companies')
            ->assertForbidden();
    }
}
