<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бетон зуурмагийн захиалга.
 *
 * ЭХ СУРВАЛЖ: захиалагчийн «БЕТОН ЗУУРМАГ ЗАХИАЛГА, НИЙЛҮҮЛЭЛТИЙН ХУУДАС»
 * бланк. Талбар бүр тэр хуудасны мөр.
 *
 * ЯАГААД СИСТЕМД ОРОХ ЁСТОЙ ВЭ: цаасан хуудас нь «энэ давхарт төсвөөр
 * хэдэн м³ байсан, өмнө нь хэд захиалсан бэ» гэдгийг ХЭЛЖ ЧАДАХГҮЙ.
 * Материалын төсөв давхар бүрийн бетоныг м³-ээр аль хэдийн агуулдаг тул
 * систем үлдэгдлийг бодож чадна. Хэтэрсэн захиалга нь хамгийн түгээмэл,
 * хамгийн үнэтэй алдаа: илүү ирсэн бетоныг буцаах аргагүй, хатна.
 *
 * ЗАХИАЛГА НЬ ТӨСВИЙГ ИДНЭ. Үүнээс хойш материалын төсөв бол зөвхөн
 * хадгалагдсан тоо биш — бодит үлдэгдлийн эх сурвалж.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concrete_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /** Автомат дугаар: «БЗ-2026-001». Бланк дээрх «№ ______». */
            $table->string('number', 32)->unique();

            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();

            /*
             * «Блок/Давхар» мөр.
             *
             * Байршлын мод руу ШУУД холбоно, текстээр биш: үлдэгдлийг
             * бодохын тулд материалын төсвийн яг тэр байршилтай тулгах
             * шаардлагатай. «5-р давхар» ба «5 давхар» хоёр өөр тэмдэгт
             * мөр болбол үлдэгдэл хэзээ ч олдохгүй.
             */
            $table->foreignUuid('location_id')->constrained()->cascadeOnDelete();

            /*
             * Аль төсвийн мөрнөөс иднэ вэ.
             *
             * Марк, нэгж үнэ, төсөвт тоо хэмжээ бүгд эндээс. Хоосон байж
             * БОЛОХГҮЙ: төсөвгүй захиалга нь хяналтгүй зарлага.
             */
            $table->foreignUuid('material_id')->constrained()->restrictOnDelete();

            /** «Бетон зуурмаг авах он/сар/өдөр». */
            $table->date('pour_date');

            /** «Эхлэх цаг» — үдээс өмнө/хойш ба цаг. */
            $table->string('pour_half', 2)->nullable();   // am | pm
            $table->string('pour_time', 5)->nullable();   // «08:30»

            /** «Хийц нэршил» — багана, хучилт, суурь. */
            $table->string('structure');

            /*
             * «Бетоны марк» ба «Бетон анги».
             *
             * Марк нь материалын лавлахаас ирнэ (М250) — захиалгын мөрөнд
             * ХУУЛБАРЛАЖ хадгална. Лавлах дараа засагдвал гарсан захиалга
             * өөрчлөгдөх ёсгүй: тэр нь хэвлэгдэж, гарын үсэг зурагдсан
             * баримт.
             */
            $table->string('grade', 32)->nullable();
            $table->string('concrete_class', 16)->nullable();   // B20, B25

            /** «Конусан суулт» — см. */
            $table->unsignedSmallInteger('slump_cm')->nullable();

            /** «Захиалах хэмжээ /м3/». */
            $table->decimal('quantity', 12, 3);

            /** «Техник/Помп: Тийм ꙱ Үгүй ꙱». */
            $table->boolean('needs_pump')->default(false);

            /*
             * Төлөв.
             *
             *   draft     — бичиж байгаа, төсөв иддэггүй
             *   submitted — илгээсэн, ТӨСӨВ ИДНЭ
             *   confirmed — захиалагч тал баталгаажуулсан
             *   delivered — нийлүүлэгдсэн, бодит хэмжээ бичигдсэн
             *   cancelled — цуцлагдсан, төсөв суллагдана
             */
            $table->string('status', 16)->default('draft');

            /*
             * Бодит нийлүүлэлт.
             *
             * Захиалсан ба ирсэн хэмжээ ЗӨРДӨГ: машин 7 м³-аар ирдэг тул
             * 18 м³ захиалбал 21 ирнэ. Үлдэгдлийг бодитоор барихын тулд
             * ирснийг нь тусад нь бичнэ.
             */
            $table->decimal('delivered_qty', 12, 3)->nullable();
            $table->date('delivered_at')->nullable();

            $table->text('notes')->nullable();

            /*
             * Гурван гарын үсэг — бланк дээрх дараалал:
             *   Захиалга өгсөн            — гүйцэтгэгч талын инженер
             *   Захиалга авсан            — захиалагч талын инженер
             *   Захиалга баталгаажуулсан  — захиалагч талын хяналтын инженер
             */
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['block_id', 'status']);
            $table->index(['location_id', 'material_id']);
            $table->index('pour_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concrete_orders');
    }
};
