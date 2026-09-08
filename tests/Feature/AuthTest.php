<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Нэвтрэлт ба эрхийн хяналт.
 *
 * Хамгийн чухал нь сүүлийн хэсэг: хамрах хүрээ нь UI-д нуух зүйл БИШ, серверт
 * мөрдөгдөх ёстой. Талбайн инженер API руу шууд хандаад өөр блокийн өгөгдөл
 * авч чадах эсэхийг шалгана (захиалагчийн RULE-09).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'director', array $attrs = []): User
    {
        return User::create([
            'name' => 'Тест хэрэглэгч',
            'email' => $role.'@cpms.test',
            'password' => Hash::make('password'),
            'role' => $role,
            ...$attrs,
        ]);
    }

    // -----------------------------------------------------------------
    // Нэвтрэх
    // -----------------------------------------------------------------

    public function test_login_returns_a_token_and_user(): void
    {
        $this->makeUser('admin');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@cpms.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'role']]])
            ->assertJsonPath('data.user.role', 'admin');

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeUser('admin');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@cpms.test',
            'password' => 'буруу',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'байхгүй@cpms.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $this->makeUser('site_engineer', ['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'site_engineer@cpms.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_token_grants_access_to_protected_endpoints(): void
    {
        $this->makeUser('admin');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@cpms.test',
            'password' => 'password',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_protected_endpoints_reject_missing_or_bad_tokens(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer буруу-token')
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->makeUser('director');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'director@cpms.test',
            'password' => 'password',
        ])->json('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // Token нь өгөгдлийн сангаас устсан эсэх — жинхэнэ шалгуур энэ.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Тест дотор апп нэг instance хэвээр үлддэг тул эхний хүсэлтээр
        // шийдэгдсэн хэрэглэгч guard-д кэшлэгдэж, дараагийн хүсэлтэд дахин
        // ашиглагдана. Production-д хүсэлт бүр шинэ процесс тул ийм зүйл
        // болохгүй. Тестэд кэшийг гараар цэвэрлэнэ.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Гүйцэтгэгчийн кодоор нэвтрэх
    // -----------------------------------------------------------------

    public function test_contractor_logs_in_with_an_access_code(): void
    {
        Contractor::create([
            'name' => 'Түшиг Констракшн',
            'access_code' => 'TSH-2026',
            'access_code_expires_at' => now()->addMonth(),
        ]);

        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'TSH-2026'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'contractor');
    }

    public function test_expired_contractor_code_is_rejected(): void
    {
        Contractor::create([
            'name' => 'Хугацаа дууссан',
            'access_code' => 'OLD-2020',
            'access_code_expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'OLD-2020'])
            ->assertStatus(422)->assertJsonValidationErrorFor('code');
    }

    public function test_unknown_contractor_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'БАЙХГҮЙ'])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Хамрах хүрээ — серверт мөрдөгдөх ёстой
    // -----------------------------------------------------------------

    /** @return array{0: Block, 1: Block} */
    private function twoBlocks(): array
    {
        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);

        return [
            $project->blocks()->create(['name' => 'А блок', 'floors' => 4, 'units_per_floor' => 2]),
            $project->blocks()->create(['name' => 'Б блок', 'floors' => 4, 'units_per_floor' => 2]),
        ];
    }

    public function test_site_engineer_cannot_read_a_block_outside_their_scope(): void
    {
        [$own, $other] = $this->twoBlocks();

        $engineer = $this->makeUser('site_engineer', ['scope_block_ids' => [$own->id]]);
        $this->actingAs($engineer, 'sanctum');

        $this->getJson("/api/v1/blocks/{$own->id}")->assertOk();
        $this->getJson("/api/v1/blocks/{$other->id}")->assertForbidden();
        $this->getJson("/api/v1/blocks/{$other->id}/summary")->assertForbidden();
        $this->getJson("/api/v1/blocks/{$other->id}/work-items")->assertForbidden();
    }

    public function test_director_sees_every_block(): void
    {
        [$a, $b] = $this->twoBlocks();

        $this->actingAs($this->makeUser('director'), 'sanctum');

        $this->getJson("/api/v1/blocks/{$a->id}")->assertOk();
        $this->getJson("/api/v1/blocks/{$b->id}")->assertOk();
    }

    public function test_engineer_without_a_scope_sees_every_block(): void
    {
        // Хамрах хүрээ хоосон = хязгаарлаагүй. Оноогоогүй хэрэглэгчийг
        // санамсаргүйгээр хаахгүйн тулд ийм байдлаар шийдсэн.
        [$a] = $this->twoBlocks();

        $this->actingAs($this->makeUser('site_engineer', ['scope_block_ids' => []]), 'sanctum');

        $this->getJson("/api/v1/blocks/{$a->id}")->assertOk();
    }

    public function test_scope_is_reported_by_the_me_endpoint(): void
    {
        [$own] = $this->twoBlocks();

        $engineer = $this->makeUser('site_engineer', ['scope_block_ids' => [$own->id]]);
        $this->actingAs($engineer, 'sanctum');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'site_engineer')
            ->assertJsonPath('data.scopeBlockIds', [$own->id]);
    }
}
