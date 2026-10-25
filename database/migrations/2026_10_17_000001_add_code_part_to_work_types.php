<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Төсвийн «ажлын бүрдэл»-ийн нэр.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: төсвийн код нь ДАВХАРДАЖ болдог. «17.1.6 Плитаны ажил»
 * гэсэн нэг код төсөвт таван мөрөнд задардаг:
 *
 *     17.1.6 │ Айлын 00 өрөө шал
 *     17.1.6 │ Айлын үүд хэсэгт
 *     17.1.6 │ Айлын тагт
 *     17.1.6 │ Нийтийн эдэлбэр
 *     17.1.6 │ Айлын 00 өрөө хана
 *
 * Манай системд эдгээр нь тус тусдаа ажлын төрөл. Зөвхөн кодоор холбовол
 * тавуулаа нэг төрөл рүү буух буюу дөрөв нь алдагдана. Тиймээс «код +
 * бүрдлийн нэр» хослолоор ялгана.
 *
 * Хоосон байж болно: кодоороо давхардахгүй төрөлд хэрэггүй.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_types', function (Blueprint $table) {
            $table->string('code_part')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('work_types', fn (Blueprint $table) => $table->dropColumn('code_part'));
    }
};
