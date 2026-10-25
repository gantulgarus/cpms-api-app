<?php

namespace Tests\Feature;

use App\Models\Contractor;
use Database\Seeders\ContractorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_contractor_from_the_schedule_is_registered(): void
    {
        $this->seed(ContractorSeeder::class);

        $rows = json_decode(file_get_contents(database_path('data/contractors.json')), true);

        $this->assertSame(count($rows), Contractor::count());
        $this->assertGreaterThanOrEqual(16, Contractor::whereNotNull('company_name')->count());

        // Ажлын чиглэл нь оноох үед хайх гол талбар — хоосон байж болохгүй.
        $this->assertSame(
            0,
            Contractor::whereNull('trade_specialty')->count(),
            'Чиглэлгүй гүйцэтгэгч нь оноох дэлгэц дээр хайгдахгүй.',
        );
    }

    /**
     * ЖИНХЭНЭ өгөгдөл тул `demo:purge` устгах ЁСГҮЙ.
     *
     * Урьд нь demo өгөгдлийг нэрээр устгадаг байсан нь жинхэнэ захиалагчийн
     * өгөгдлийг хамт устгах эрсдэл үүсгэсэн — тугаар л ялгана.
     */
    public function test_contractors_are_not_flagged_as_demo(): void
    {
        $this->seed(ContractorSeeder::class);

        $this->assertSame(0, Contractor::where('is_demo', true)->count());
    }

    /**
     * Дахин ажиллуулахад НЭВТРЭХ КОД хөндөгдөхгүй.
     *
     * Кодыг дарж бичвэл тараасан код бүр хүчингүй болж, гүйцэтгэгчид гэнэт
     * системээс гарна. Seeder-ийг өгөгдөл шинэчлэх бүрд ажиллуулдаг тул
     * энэ нь байнгын эрсдэл.
     */
    public function test_rerunning_keeps_access_codes_and_contacts(): void
    {
        $this->seed(ContractorSeeder::class);

        $one = Contractor::firstOrFail();
        $one->update([
            'access_code' => 'TSH-2026',
            'access_code_expires_at' => '2027-01-01',
            'phone' => '99119911',
            'contact_person' => 'Б.Болд',
        ]);

        $this->seed(ContractorSeeder::class);
        $one->refresh();

        $this->assertSame('TSH-2026', $one->access_code);
        $this->assertSame('99119911', $one->phone);
        $this->assertSame('Б.Болд', $one->contact_person);
        $this->assertSame(
            count(json_decode(file_get_contents(database_path('data/contractors.json')), true)),
            Contractor::count(),
            'Хоёр дахь удаад хуулбар үүсэх ёсгүй.',
        );
    }

    /** Нэр нь ДАВХАРДАХГҮЙ — ижил компани хоёр мөр болбол акт хуваагдана. */
    public function test_names_are_unique(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/contractors.json')), true);
        $names = array_column($rows, 'name');

        $this->assertSame(
            count($names),
            count(array_unique($names)),
            'Давхардсан нэр: '.implode(', ', array_diff_assoc($names, array_unique($names))),
        );
    }
}
