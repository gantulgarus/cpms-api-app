<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Давхрын хугацаа — нэг давхарт ногдох ажлын өдрийн тоо.
 *
 * Олон улсын нэр томьёогоор "takt" тул баганын нэр `takt_days` хэвээр.
 *
 * Барилга бүр өөр хэмнэлтэй: 4 давхрын үйлчилгээний барилга 3 хоногийн
 * хугацаатай, 16 давхрын орон сууц 7 хоногийнхоор явж болно. Тиймээс
 * төслийн биш, БЛОКИЙН шинж чанар.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('blocks', 'takt_days')) {
            return;
        }

        Schema::table('blocks', function (Blueprint $table) {
            $table->unsignedTinyInteger('takt_days')->default(5)->after('units_per_floor');
        });
    }

    public function down(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            $table->dropColumn('takt_days');
        });
    }
};
