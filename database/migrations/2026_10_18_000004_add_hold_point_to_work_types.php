<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шалгалтын цэг (hold point).
 *
 * Далд хийгдэх ажил — арматур, гидроизоляц, дарагдах шугам — батлагдахаас
 * өмнө дараагийн ажил эхэлж болохгүй. Бетон цутгасны дараа доторх арматурыг
 * хэн ч шалгаж чадахгүй тул «дараа шалгана» гэдэг нь утгагүй.
 *
 * ХОЁР ШАТТАЙ БАТАЛГААЖУУЛАЛТЫГ УТГАТАЙ БОЛГОХ ТАЛБАР: шат байгаа ч
 * дараагийн ажил зогсдоггүй бол гүйцэтгэгч шалгуулалгүй үргэлжлүүлж,
 * каскад нь зөвхөн цаасан дээр үлдэнэ.
 *
 * Анхдагч нь `false`: аль ажил далд болохыг ЗАХИАЛАГЧ шийднэ, код
 * таамаглахгүй. `work-types.json`-д тодорхой тэмдэглэнэ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_types', function (Blueprint $table) {
            $table->boolean('is_hold_point')->default(false)->after('inspection_stages');
        });
    }

    public function down(): void
    {
        Schema::table('work_types', fn (Blueprint $t) => $t->dropColumn('is_hold_point'));
    }
};
