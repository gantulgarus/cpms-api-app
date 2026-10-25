<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Материалын төсөв.
 *
 * ЭХ СУРВАЛЖ: захиалагчийн «Материалын төсөв» хуудас (AJLIIN_TOO_HEMJEE_26_06_A1).
 *
 * ГУРВАН ХҮСНЭГТ ЯАГААД ХЭРЭГТЭЙ ВЭ:
 *
 *   materials                 — лавлах. «Ф8», «Суурь бетон М250». ТӨСӨЛД нэг удаа.
 *   material_budget_items     — тухайн БЛОКИЙН мөр: үнэ, нийт тоо.
 *   material_budget_quantities— тэр мөрийн БАЙРШЛААР задаргаа.
 *
 * Нэг хүснэгтэд шахаж болохгүй: лавлах нь 75 барилгад дахин хэрэглэгдэнэ,
 * тоо хэмжээ нь блок бүрт өөр, задаргаа нь давхар бүрт өөр. Нийлүүлбэл
 * «Ф8» гэдэг нэр 75 × 20 = 1,500 удаа давтагдаж бичигдэх бөгөөд нэрийг нь
 * засах болгонд 1,500 мөр зөрнө.
 *
 * ЭНЭ НЬ ЖОР БИШ. Материалын тоо хэмжээ нь тухайн блокийн БОДИТ тоо —
 * «1 м² ханад хэдэн тоосго» гэсэн давтагдах харьцаа биш. Тийм учраас блок
 * бүрт тусад нь импортлогдоно. Жор болгох оролдлого (R3) нь ТУСДАА ажил
 * бөгөөд түүнийг хийхийн тулд эхлээд эдгээр бодит тоонууд хэрэгтэй.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();

            /*
             * Төсөв дээрх код («2.2.2»). Энэ нь материалыг таних ЦОРЫН ГАГЦ
             * тогтвортой түлхүүр — нэр нь «Суурь», «Суурь бетон», «суурийн
             * бетон» гэж гурван файлд гурван янзаар бичигдэнэ.
             */
            $table->string('code', 32);
            $table->string('name');

            /*
             * Марк буюу хэмжээс: «М250», «200x1200мм», «ф8».
             *
             * Төсвийн файлд энэ нь «Материалын хэмжээ» гэсэн ТӨӨРӨГДҮҮЛЭХ
             * толгойтой багана. Тоо хэмжээ биш — бетоны анги, хэвийн
             * хэмжээс. Нэрээс салгаж хадгалбал М150 ба М250-ийг хольж
             * захиалах алдаа гарахгүй.
             */
            $table->string('grade', 64)->nullable();
            $table->string('unit', 16);

            $table->timestamps();

            $table->unique(['project_id', 'code']);
            $table->index(['project_id', 'name']);
        });

        Schema::create('material_budget_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('material_id')->constrained()->cascadeOnDelete();

            /*
             * Аль ажилд зарцуулагдах материал вэ.
             *
             * ХООСОН БАЙЖ БОЛНО. Захиалагчийн файлд ажлын нэр нь нэгтгэсэн
             * нүдэнд бичигдсэн бөгөөд материалын код (2.2.x) нь ажлын
             * кодтой (2.6.1) шууд таарахгүй. Холбоог албадвал 273 мөрийн
             * 269 нь импортлогдохгүй болно.
             *
             * Холбоогүй ч үнэ, тоо хэмжээ нь үнэ цэнтэй: материалын нийт
             * өртөг, захиалгын хуудас хоёулаа холбоогүйгээр ажиллана.
             * Холбоо нь зөвхөн «энэ ажил хэдэн төгрөгийн материал иднэ»
             * гэдгийг тооцоход хэрэгтэй.
             */
            $table->foreignUuid('work_type_id')->nullable()->constrained()->nullOnDelete();

            /** Төсөв дээр бичигдсэн ажлын нэр — холбогдоогүй ч ул мөр үлдэнэ. */
            $table->string('work_label')->nullable();

            /*
             * ХОЁР ҮНЭ.
             *
             * «Бодит» = төсөвт батлагдсан үнэ, төлбөр тооцоо үүн дээр тогтоно.
             * «Зах зээл» = өнөөдрийн худалдан авах үнэ.
             *
             * Нэгийг нь хаях нь мэдээлэл алдах: хоёрын зөрүү нь төсөв хэтрэх
             * эсэхийн ЦОРЫН ГАГЦ эрт дохио. Суурийн бетон дээр 320,000 ба
             * 338,000 — 5.6% зөрүү нь 920 м³ дээр 16.5 сая төгрөг.
             *
             * Хоосон байж болно: төсөвт үнэ тавигдаагүй мөр бий. 0 гэж
             * бичвэл «үнэгүй материал» гэсэн утга гарна.
             */
            $table->decimal('actual_unit_price', 16, 2)->nullable();
            $table->decimal('market_unit_price', 16, 2)->nullable();

            /*
             * Байршлуудын НИЙЛБЭР — задаргаанаас бодогдож хадгалагдана.
             *
             * Давхардсан хадгалалт боловч зориуд: материалын нийт өртөг
             * гаргах бүрт 20 мөрийн задаргааг нэгтгэх нь 273 × 20 мөрийн
             * асуулга болно. Импорт нь задаргаа ба нийлбэрийг НЭГ зэрэг
             * бичих тул зөрөх боломжгүй.
             */
            $table->decimal('total_qty', 16, 3)->default(0);

            /** Төсвийн файлын хэдэн дэх мөрөөс ирсэн — алдаа мөшгихөд. */
            $table->unsignedInteger('source_row')->nullable();

            $table->timestamps();

            $table->unique(['block_id', 'material_id']);
            $table->index(['block_id', 'work_type_id']);
        });

        Schema::create('material_budget_quantities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('material_budget_item_id')->constrained()->cascadeOnDelete();

            /*
             * Байршлын мод руу ШУУД холбоно.
             *
             * Төсвийн файлын багана нь «Суурь │ Зоорийн давхар │ 1-р давхар
             * … │ Техникийн давхар │ Парпет» — энэ нь системийн байршлын
             * модтой ЯГ ижил. Текстээр хадгалбал «1-р давхар» ба «1 давхар»
             * хоёр өөр зүйл болж, давхрын тайлан хоосон гарна.
             */
            $table->foreignUuid('location_id')->constrained()->cascadeOnDelete();

            $table->decimal('quantity', 16, 3);

            $table->unique(['material_budget_item_id', 'location_id'], 'mbq_item_location_unique');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_budget_quantities');
        Schema::dropIfExists('material_budget_items');
        Schema::dropIfExists('materials');
    }
};
