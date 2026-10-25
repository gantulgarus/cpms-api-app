<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Инэл ХХК-ийн ажиллагсадыг системийн хэрэглэгч болгон бүртгэнэ.
 *
 * ЯАГААД ЗӨВХӨН 14 ХҮН ВЭ: «Ажиллагсадын нэр.xlsx»-д 30 хүн бий. Үүнээс
 * кранист, жолооч, туслах ажилтан (16 хүн) системд юу ч хийхгүй — нэвтрэх эрх
 * өгвөл хэзээ ч ороогүй хоосон бүртгэл болж, хэрэглэгчийн жагсаалтыг
 * бөглөрүүлнэ. Санхүү, нягтлан, захиргааны менежерүүд ч гүйцэтгэлийн
 * урсгалд ороогүй тул хассан. Шаардлага гарвал `staff.json`-д нэмэхэд
 * хүрэлцэнэ.
 *
 * ҮҮРЭГ ОНООХ: албан тушаалаас шууд хөрвүүлээгүй. Б.Сайхантамир нь
 * «Гэрээний мэргэжилтэн» атлаа төсвийн 8 ажлын хяналтыг хариуцдаг тул
 * захиалагчийн шийдвэрээр `inspector` үүрэг авсан. Албан тушаал ба системийн
 * эрх нь ҮРГЭЛЖ таарахгүй — тиймээс үүргийг `staff.json`-д ТОДОРХОЙ бичнэ,
 * кодод таамаглахгүй.
 *
 * НУУЦ ҮГ: санамсаргүй үүсгэж `storage/app/staff-passwords.csv`-д бичнэ.
 * Терминал дээр хэвлэхгүй — түүх, бүртгэлд үлдэнэ. Файлыг тараасны дараа
 * устгана. Дахин ажиллуулахад БАЙГАА хэрэглэгчийн нууц үг хөндөгдөхгүй:
 * эс бөгөөс өгөгдлийг шинэчлэх бүрд бүгдийг гадагшлуулна.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/staff.json')), true);

        $created = [];
        $updated = 0;

        foreach ($rows as $row) {
            $existing = User::where('email', $row['email'])->first();

            if ($existing) {
                /*
                 * Нууц үг, is_active-ийг ХӨНДӨХГҮЙ. Хүн нэвтрэх эрхээ
                 * хаагдсан байж болно — seeder түүнийг дахин нээх ёсгүй.
                 */
                $existing->update([
                    'name' => $row['name'],
                    'role' => $row['role'],
                    'position' => $row['position'],
                    'unit' => $row['unit'],
                ]);
                $updated++;

                continue;
            }

            $password = Str::password(12, symbols: false);

            User::create([
                'name' => $row['name'],
                'email' => $row['email'],
                'password' => Hash::make($password),
                'role' => $row['role'],
                'position' => $row['position'],
                'unit' => $row['unit'],
                'is_active' => true,
                'is_demo' => false,
            ]);

            $created[] = [$row['name'], $row['email'], User::ROLES[$row['role']], $password];
        }

        $this->command?->info("{$updated} хэрэглэгч шинэчлэгдэв, ".count($created)." шинээр үүсэв.");

        if ($created === []) {
            return;
        }

        $path = storage_path('app/staff-passwords.csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Нэр', 'И-мэйл', 'Үүрэг', 'Нууц үг']);
        foreach ($created as $line) {
            fputcsv($handle, $line);
        }
        fclose($handle);
        chmod($path, 0600);

        $this->command?->warn("Нууц үг: {$path}");
        $this->command?->warn('Тараасны дараа файлыг УСТГА.');
    }
}
