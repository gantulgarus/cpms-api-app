<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Албан тушаал ба алба нэгж.
 *
 * ЯАГААД `role`-ээс ТУСДАА ВЭ: системийн үүрэг ба албан тушаал нь таарахгүй
 * байж болдог. Б.Сайхантамир «Гэрээний мэргэжилтэн» атлаа төсвийн 8 ажлын
 * хяналтыг хариуцдаг тул `inspector` үүрэгтэй. Хоёрыг нэг багананд нэгтгэвэл
 * «яагаад гэрээний мэргэжилтэн гүйцэтгэл батлаад байгаа юм?» гэсэн асуулт
 * хэзээ ч тайлагдахгүй болно.
 *
 * Мөн хэрэглэгчийн жагсаалтад ижил үүрэгтэй 6 талбайн инженерийг
 * ялгах өөр мэдээлэл болно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('position')->nullable()->after('role');
            $table->string('unit')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['position', 'unit']);
        });
    }
};
