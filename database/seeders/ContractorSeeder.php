<?php

namespace Database\Seeders;

use App\Models\Contractor;
use Illuminate\Database\Seeder;

/**
 * Инэл ХХК-ийн туслан гүйцэтгэгчид.
 *
 * ЭХ СУРВАЛЖ: «Ажлын дэс дараалал (жишээ).xlsx» — B3 блокийн 244 алхмын
 * хуваарь. Тэнд ажил бүрийн хажууд гүйцэтгэгч бичигдсэн байдаг.
 *
 * ХЯЗГААРЛАЛТ: тэр файл нь B3 блокийн тул А1 блокт өөр гүйцэтгэгч байж
 * магадгүй. Мөн РД, утас, дансны дугаар НЭГ Ч ФАЙЛД БАЙХГҮЙ — «Ажил
 * гүйцэтгэх тохиролцооны хуудас» нь хоосон бланк. Тиймээс энд ЗӨВХӨН нэр
 * ба ажлын чиглэл орно; бусдыг захиалагчаас авч `/contractors` дэлгэцээр
 * нөхнө.
 *
 * ХУВЬ ХҮН: таван бүртгэл нь компани биш, баг ахлагчийн нэр (Ц.Идэрмөнх,
 * Б.Мөнх-Ирээдүй г.м). Хуваарьт тэд гүйцэтгэгчийн баганад бичигдсэн тул
 * системд ч гүйцэтгэгч болно — акт, төлбөр тэдэн рүү явдаг.
 *
 * `is_demo = false`: эдгээр нь ЖИНХЭНЭ өгөгдөл, `demo:purge` устгах ёсгүй.
 */
class ContractorSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/contractors.json')), true);

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $existing = Contractor::where('name', $row['name'])->first();

            if ($existing) {
                /*
                 * Нэвтрэх код, холбоо барих мэдээллийг ХӨНДӨХГҮЙ.
                 *
                 * Seeder-ийг өгөгдөл шинэчлэх бүрд ажиллуулдаг. Кодыг
                 * дарж бичвэл тараасан нэвтрэх код бүр хүчингүй болж,
                 * гүйцэтгэгчид гэнэт системээс гарна.
                 */
                $existing->update([
                    'trade_specialty' => $row['tradeSpecialty'],
                    'category' => $row['category'],
                ]);
                $updated++;

                continue;
            }

            Contractor::create([
                'name' => $row['name'],
                'company_name' => $row['isCompany'] ? $row['name'] : null,
                'trade_specialty' => $row['tradeSpecialty'],
                'category' => $row['category'],
                'status' => 'active',
                'is_demo' => false,
            ]);
            $created++;
        }

        $this->command?->info("{$created} гүйцэтгэгч үүсэв, {$updated} шинэчлэгдэв.");
        $missing = Contractor::whereNull('registration_no')->count();
        $this->command?->warn("{$missing} гүйцэтгэгчийн РД, данс ДУТУУ — тохиролцоо бичих боломжгүй.");
        $this->command?->line('Захиалагчаас авч /contractors дэлгэцээр нөхнө.');
    }
}
