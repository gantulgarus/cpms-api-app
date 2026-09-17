<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Танилцуулгын өгөгдлийг тэмдэглэх туг.
 *
 * ЯАГААД ТУГ ХЭРЭГТЭЙ ВЭ: demo өгөгдлийг дараа нь устгах шаардлагатай ч түүнийг
 * НЭРЭЭР нь таних нь аюултай. Demo компани «Инэл ХХК» гэж нэрлэгдсэн бөгөөд тэр
 * нь ЖИНХЭНЭ захиалагчийн нэр — нэрээр хайж устгавал жинхэнэ төслийн өгөгдөл
 * хамт устах эрсдэлтэй.
 *
 * Туг нь ердөө гурван хүснэгтэд хэрэгтэй. Бусад бүх өгөгдөл (блок, байршил,
 * ажлын нэгж, явц, шалгалт, зураг, саатал) төслөөс cascade-аар унжина тул
 * төслийг устгахад өөрсдөө арилна.
 *
 * Ажлын төрөл, чанарын хуудас зэрэг ЛАВЛАХ САН тугладаггүй: тэдгээрийг жинхэнэ
 * төсөл мөн адил хэрэглэнэ, устгах ёсгүй.
 */
return new class extends Migration
{
    private const TABLES = ['projects', 'users', 'contractors'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_demo')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('is_demo');
            });
        }
    }
};
