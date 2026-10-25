<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Төсвийн нэгж үнэ.
 *
 * ЭХ СУРВАЛЖ: «Ажлын хөлсний төсөв» хуудасны AD багана. Жишээ: газар шороо
 * 25,000₮/м³, нийт 77,760,000₮.
 *
 * ЯАГААД АЖЛЫН МӨРӨН ДЭЭР ВЭ: үнэ нь ажлын төрөл + БЛОКООС хамаарна. Нэг
 * ажлын төрөл хоёр блокт өөр үнэтэй байж болно (өөр үед гэрээлсэн). Ажлын
 * төрөл дээр хадгалвал хоёр блок хоорондоо дарж бичнэ.
 *
 * ХЭРЭГЛЭЭ: тохиролцооны хуудсанд нэгж үнэ нь ХЭЛЭЛЦЭЭРИЙН дүнгээр орох
 * бөгөөд төсвийнхөөс өөр байж болно (захиалагчийн шийдвэр). Хоёрыг хажуу
 * хажууд тавьснаар «төсвөөс 15% дээш гэрээлсэн» гэдэг нь тэр дор нь
 * харагдана — өөрөөр бол 77 саяын ажил 90 саяар гэрээлэгдээд хэн ч
 * мэдэхгүй.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->decimal('budget_unit_price', 16, 2)->nullable()->after('planned_qty');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', fn (Blueprint $t) => $t->dropColumn('budget_unit_price'));
    }
};
