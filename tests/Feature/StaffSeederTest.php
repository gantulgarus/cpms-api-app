<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_are_created_with_their_roles(): void
    {
        $this->seed(StaffSeeder::class);

        $rows = json_decode(file_get_contents(database_path('data/staff.json')), true);

        $this->assertSame(count($rows), User::count());

        $tamir = User::where('email', 'saikhantamir@inel.mn')->firstOrFail();

        /*
         * Албан тушаал ба системийн эрх нь ТААРАХГҮЙ байж болно. Б.Сайхантамир
         * гэрээний мэргэжилтэн атлаа төсвийн 8 ажлын хяналтыг хариуцдаг тул
         * захиалагчийн шийдвэрээр батлах эрхтэй. Хоёр талбарыг нэгтгэх гэсэн
         * эрмэлзлийг энэ тест таслана.
         */
        $this->assertSame('inspector', $tamir->role);
        $this->assertSame('Гэрээний мэргэжилтэн', $tamir->position);
        $this->assertTrue($tamir->canInspect());
    }

    /**
     * Бүх `budgetName` нь жинхэнэ хэрэглэгч рүү хүрнэ.
     *
     * Эс бөгөөс импорт нь нэрийг таних боломжгүй гэж шийдэж, 26 мөрийн
     * хяналт хариуцагчгүй үлдэнэ — чимээгүй.
     */
    public function test_every_budget_name_resolves(): void
    {
        $this->seed(StaffSeeder::class);

        $rows = array_filter(
            json_decode(file_get_contents(database_path('data/staff.json')), true),
            fn ($row) => isset($row['budgetName']),
        );

        $this->assertCount(4, $rows, 'Төсөвт дөрвөн хяналтын инженер байна.');

        foreach ($rows as $row) {
            $user = User::where('email', $row['email'])->first();

            $this->assertNotNull($user, "{$row['name']} бүртгэгдсэнгүй.");
            $this->assertTrue(
                $user->canInspect(),
                "{$row['name']} хяналт хариуцдаг ч батлах эрхгүй байна.",
            );
        }
    }

    /**
     * Дахин ажиллуулахад нууц үг ХӨНДӨГДӨХГҮЙ.
     *
     * Seeder-ийг өгөгдөл шинэчлэх бүрд ажиллуулдаг. Нууц үг тухай бүр
     * шинэчлэгдвэл 14 хүн гэнэт системээс гарч, дахин тараах шаардлага
     * гарна.
     */
    public function test_rerunning_does_not_reset_passwords(): void
    {
        $this->seed(StaffSeeder::class);

        $user = User::where('email', 'khulan@inel.mn')->firstOrFail();
        $user->update(['password' => Hash::make('өөрөө-сольсон'), 'is_active' => false]);

        $this->seed(StaffSeeder::class);
        $user->refresh();

        $this->assertTrue(Hash::check('өөрөө-сольсон', $user->password));
        $this->assertFalse($user->is_active, 'Хаагдсан эрхийг seeder дахин нээх ёсгүй.');
        $this->assertSame(14, User::count(), 'Хоёр дахь удаад хуулбар үүсэх ёсгүй.');
    }
}
