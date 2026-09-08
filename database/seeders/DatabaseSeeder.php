<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * `php artisan migrate:fresh --seed` энэ ангиллыг дуудна.
 *
 * Laravel-ийн анхдагч `test@example.com` хэрэглэгчийн оронд CPMS-ийн бүтэн
 * туршилтын орчныг үүсгэнэ — хэрэглэгчид, компани, төсөл, ажлын төрлийн сан,
 * бүтэн блок. Эс бөгөөс `--seed` ажиллаад ч нэвтрэх хэрэглэгч байхгүй байна.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
