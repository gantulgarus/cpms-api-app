<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Гүйцэтгэгчийн нэвтрэх эрх.
 *
 * Захиалагч: "Ажил гүйцэтгэх хугацаанд олгогдсон кодоор нэвтэрнэ".
 * Тиймээс кодыг ХЭН олгож, ХЭН харж чадахыг сервер шийднэ — дэлгэц дээр
 * товч нуух нь хамгаалалт биш.
 */
class ContractorTest extends TestCase
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

    private function contractor(array $attrs = []): Contractor
    {
        return Contractor::create(['name' => 'Гоо Засал ХХК', ...$attrs]);
    }

    // -----------------------------------------------------------------
    // Кодыг хэн харах вэ
    // -----------------------------------------------------------------

    public function test_manager_sees_the_access_code(): void
    {
        $this->contractor(['access_code' => 'GOO-2026', 'access_code_expires_at' => now()->addYear()]);

        $this->actingAs($this->user('project_manager'))
            ->getJson('/api/v1/contractors')
            ->assertOk()
            ->assertJsonPath('data.0.accessCode', 'GOO-2026')
            ->assertJsonPath('data.0.hasValidAccessCode', true);
    }

    public function test_non_manager_never_sees_the_access_code(): void
    {
        $this->contractor(['access_code' => 'GOO-2026', 'access_code_expires_at' => now()->addYear()]);

        $response = $this->actingAs($this->user('site_engineer'))
            ->getJson('/api/v1/contractors')
            ->assertOk();

        $this->assertArrayNotHasKey('accessCode', $response->json('data.0'));
        // Эрх байгаа эсэхийг мэдэх нь аюулгүй — кодыг нь мэдэх нь аюултай.
        $this->assertTrue($response->json('data.0.hasValidAccessCode'));
    }

    // -----------------------------------------------------------------
    // Код олгох, хаах
    // -----------------------------------------------------------------

    public function test_manager_can_issue_a_code(): void
    {
        $contractor = $this->contractor();

        $code = $this->actingAs($this->user('director'))
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertOk()
            ->json('data.accessCode');

        // Ойлгомжгүй тэмдэгт (0/O, 1/I) утсаар уншихад андуурагдана — санамсаргүй
        // хэсэгт орох ёсгүй.
        $this->assertMatchesRegularExpression('/^GOO-[A-Z2-9]{6}$/', $code);
        $this->assertSame(
            0,
            preg_match_all('/[01IO]/', substr($code, 4)),
            "Кодын санамсаргүй хэсэгт андуурам тэмдэгт орсон: {$code}",
        );
    }

    public function test_issuing_a_new_code_invalidates_the_old_one(): void
    {
        $contractor = $this->contractor([
            'access_code' => 'GOO-2026',
            'access_code_expires_at' => now()->addYear(),
        ]);

        $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertOk();

        // Төлөөлөгч солигдоход хуучин код тэр дороо ажиллахаа болих ёстой.
        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])
            ->assertStatus(422);
    }

    public function test_revoking_closes_access_immediately(): void
    {
        $contractor = $this->contractor([
            'access_code' => 'GOO-2026',
            'access_code_expires_at' => now()->addYear(),
        ]);

        $this->actingAs($this->user('admin'))
            ->deleteJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertOk()
            ->assertJsonPath('data.hasValidAccessCode', false);

        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])
            ->assertStatus(422);
    }

    public function test_site_engineer_cannot_issue_a_code(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user('site_engineer'))
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertForbidden();
    }

    public function test_contractor_cannot_issue_a_code_for_itself(): void
    {
        $contractor = $this->contractor();
        $rep = User::create([
            'name' => 'Төлөөлөгч',
            'email' => 'rep@cpms.test',
            'password' => Hash::make('password'),
            'role' => 'contractor',
            'contractor_id' => $contractor->id,
        ]);

        $this->actingAs($rep)
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Нэвтрэх
    // -----------------------------------------------------------------

    public function test_contractor_logs_in_with_an_issued_code(): void
    {
        $contractor = $this->contractor();

        $code = $this->actingAs($this->user('director'))
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->json('data.accessCode');

        $token = $this->postJson('/api/v1/auth/contractor-login', ['code' => $code])
            ->assertOk()
            ->json('data.token');

        // Дээр `actingAs($director)` хийсэн нь guard-д кэшлэгдсэн байна. Тест
        // дотор апп нэг instance хэвээр үлддэг тул дараагийн хүсэлт Bearer
        // token-ыг үл харгалзан ЗАХИРЛААР орно — production-д хүсэлт бүр шинэ
        // процесс тул ийм зүйл болохгүй. Кэшийг гараар цэвэрлэнэ.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'contractor')
            ->assertJsonPath('data.contractorId', $contractor->id)
            // Гүйцэтгэгч тайлагнана, гэхдээ өөрийгөө батлахгүй.
            ->assertJsonPath('data.canReportProgress', true)
            ->assertJsonPath('data.canInspect', false)
            ->assertJsonPath('data.canManageContractors', false);
    }

    // -----------------------------------------------------------------
    // Код сэргээх нь ХАНДАЛТЫГ ҮНЭХЭЭР таслах ёстой
    // -----------------------------------------------------------------

    /**
     * Байсан нүхний тухайлсан шалгалт.
     *
     * Код солих нь ганцаараа хандалтыг таслахгүй байв: кодоор нэвтэрмэгц
     * Sanctum token үүсэх бөгөөд тэр нь кодоос ХАМААРАЛГҮЙ амьдардаг. Тиймээс
     * "код сэргээлээ" гэсэн үйлдэл хуурамч аюулгүй байдал төрүүлж, ажлаас
     * гарсан төлөөлөгчийн утас нэвтэрсэн хэвээр үлддэг байсан.
     */
    public function test_reissuing_the_code_signs_out_the_old_device(): void
    {
        $contractor = $this->contractor([
            'access_code' => 'GOO-2026',
            'access_code_expires_at' => now()->addYear(),
        ]);

        // Төлөөлөгч утаснаасаа нэвтэрнэ.
        $token = $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])
            ->assertOk()
            ->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk();

        // Админ код сэргээнэ.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertOk()
            ->assertJsonPath('meta.signedOutDevices', 1);

        // Хуучин утас ГАРСАН байх ёстой.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_revoking_the_code_signs_out_the_old_device(): void
    {
        $contractor = $this->contractor([
            'access_code' => 'GOO-2026',
            'access_code_expires_at' => now()->addYear(),
        ]);

        $token = $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])
            ->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('director'))
            ->deleteJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertOk()
            ->assertJsonPath('meta.signedOutDevices', 1);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_new_code_lets_the_replacement_representative_in(): void
    {
        $contractor = $this->contractor([
            'access_code' => 'GOO-2026',
            'access_code_expires_at' => now()->addYear(),
        ]);

        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])->assertOk();

        $this->app['auth']->forgetGuards();
        $newCode = $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->json('data.accessCode');

        // Хуучин код ажиллахаа болино, шинэ нь ажиллана — солигдсон төлөөлөгч
        // ажлаа үргэлжлүүлэх боломжтой.
        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/contractor-login', ['code' => $newCode])->assertOk();

        // Данс нь ижил хэвээр — өмнөх гүйцэтгэлийн түүх эзэнгүй болохгүй.
        $this->assertSame(1, User::where('contractor_id', $contractor->id)->count());
    }

    public function test_signed_out_count_is_zero_when_nobody_logged_in(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/contractors/{$contractor->id}/access-code")
            ->assertOk()
            ->assertJsonPath('meta.signedOutDevices', 0);
    }

    public function test_expired_code_does_not_work(): void
    {
        $this->contractor([
            'access_code' => 'GOO-2025',
            'access_code_expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2025'])
            ->assertStatus(422);
    }

    public function test_second_login_reuses_the_same_representative_account(): void
    {
        $contractor = $this->contractor([
            'access_code' => 'GOO-2026',
            'access_code_expires_at' => now()->addYear(),
        ]);

        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])->assertOk();
        $this->postJson('/api/v1/auth/contractor-login', ['code' => 'GOO-2026'])->assertOk();

        // "Туслан гүйцэтгэгчээс 1 хүн" — утсаа сольсон ч шинэ хэрэглэгч үүсэхгүй.
        $this->assertSame(1, User::where('contractor_id', $contractor->id)->count());
    }
}
