<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CLI-аас хэрэглэгч үүсгэх, системийг цоожлохоос сэргийлэх.
 *
 * Хэрэглэгч бүртгэх ДЭЛГЭЦ рүү орохын тулд нэвтэрсэн байх ёстой. Тиймээс
 * ганц ч данс үлдээгүй үед вебээр гарах зам байхгүй — энэ команд цорын
 * ганц гарц.
 */
class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_who_can_log_in(): void
    {
        $this->artisan('cpms:create-user', [
            '--name' => 'Б.Батбаяр',
            '--email' => 'admin@cpms.mn',
            '--role' => 'admin',
            '--password' => 'нууц-үг-12345',
        ])->assertSuccessful();

        $user = User::where('email', 'admin@cpms.mn')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        // Бодит данс — `demo:purge` устгах ёсгүй.
        $this->assertFalse((bool) $user->is_demo);
        $this->assertTrue(Hash::check('нууц-үг-12345', $user->password));

        // Хамгийн чухал нь: ҮНЭХЭЭР нэвтэрч чадах эсэх.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@cpms.mn',
            'password' => 'нууц-үг-12345',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_duplicate_email_is_refused_but_reset_works(): void
    {
        $args = ['--name' => 'А', '--email' => 'a@cpms.mn', '--password' => 'анхны-нууц-үг'];
        $this->artisan('cpms:create-user', $args)->assertSuccessful();

        // Санамсаргүй дарж бичихээс сэргийлнэ.
        $this->artisan('cpms:create-user', $args)->assertFailed();

        $this->artisan('cpms:create-user', $args + ['--reset' => true])->assertSuccessful();
        $this->assertSame(1, User::where('email', 'a@cpms.mn')->count());
    }

    public function test_unknown_role_is_refused(): void
    {
        $this->artisan('cpms:create-user', [
            '--name' => 'А', '--email' => 'b@cpms.mn', '--role' => 'хаан',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * Цэвэрлэгээ системийг ЦООЖЛОХ ёсгүй.
     *
     * Энэ нь бодитоор тохиолдсон: `demo:purge` demo админыг устгасны дараа
     * хэн ч нэвтэрч чадахгүй болсон.
     */
    public function test_purge_refuses_to_delete_the_last_administrator(): void
    {
        Company::create(['name' => 'Инэл ХХК']);
        $demoAdmin = User::factory()->create(['role' => 'admin', 'is_demo' => true]);

        $this->artisan('demo:purge', ['--force' => true])->assertFailed();

        $this->assertNotNull($demoAdmin->fresh(), 'Сүүлчийн админ устсан байна.');
    }

    public function test_purge_proceeds_once_a_real_administrator_exists(): void
    {
        Company::create(['name' => 'Инэл ХХК']);
        $demoAdmin = User::factory()->create(['role' => 'admin', 'is_demo' => true]);

        $this->artisan('cpms:create-user', [
            '--name' => 'Бодит админ', '--email' => 'real@cpms.mn',
            '--role' => 'admin', '--password' => 'нууц-үг-12345',
        ])->assertSuccessful();

        $this->artisan('demo:purge', ['--force' => true])->assertSuccessful();

        $this->assertNull($demoAdmin->fresh());
        $this->assertNotNull(User::where('email', 'real@cpms.mn')->first());
    }
}
