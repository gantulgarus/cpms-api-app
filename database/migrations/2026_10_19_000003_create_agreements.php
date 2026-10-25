<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ажил гүйцэтгэх тохиролцооны хуудас.
 *
 * ЭХ СУРВАЛЖ: захиалагчийн «АЖИЛ ГҮЙЦЭТГЭХ ТОХИРОЛЦООНЫ ХУУДАС» бланк.
 *
 * ЯАГААД СИСТЕМИЙН ТЭНХЛЭГ ВЭ: МӨНГӨ ЭНДЭЭС системд орно. Одоогийн акт нь
 * зөвхөн тоо хэмжээ (м², м³) агуулдаг. Тохиролцоо орсны дараа акт нь
 * дүн бодож, барьцаа, бартер, торгууль суутгаж, «олгох дүн» гаргана.
 *
 * ХУВААРЬ: нэг гүйцэтгэгч × нэг БЛОК. Бланк дээр «...... төсөл ...... блок
 * ............ажил» гэж байгаа тул хамрах хүрээ нь блок. Нэг гүйцэтгэгч
 * хоёр блокт ажиллавал хоёр тохиролцоо — төлбөр, барьцаа тусдаа тооцогдоно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /** Автомат дугаар: «ТХ-2026-001». */
            $table->string('number', 32)->unique();

            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contractor_id')->constrained()->restrictOnDelete();

            $table->string('scope')->nullable();   // «Дотор засал», «Цахилгаан»
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            /*
             * Төлбөрийн нөхцөл.
             *
             * `barter_percent + cash_percent = 100` байх ёстой — бланк дээр
             * «Үүнээс бартер ....% ; бэлэн мөнгө ....%» гэж байгаа. Дүрмийг
             * FormRequest шалгана: нийлбэр 100 биш бол төлбөр тооцоо бүхэлдээ
             * буруу болно.
             */
            $table->decimal('retention_percent', 5, 2)->default(0);   // барьцаа
            $table->decimal('barter_percent', 5, 2)->default(0);
            $table->decimal('cash_percent', 5, 2)->default(100);
            $table->decimal('barter_amount', 16, 2)->nullable();
            $table->string('barter_note')->nullable();               // бартерын нэр

            /*
             * Баталгаат хугацаа — бланк дээр «3 жил / Улсын комисс хүлээж
             * авсанаас хойш», «Засвар үйлчилгээ 36 сар». Анхдагч утга нь
             * тэдгээр боловч гэрээ тус бүрд өөр байж болно.
             */
            $table->unsignedSmallInteger('warranty_years')->default(3);
            $table->unsignedSmallInteger('maintenance_months')->default(36);

            /** Ажлын хоног, хүн хүч. */
            $table->unsignedTinyInteger('work_days_per_week')->nullable();
            $table->string('rest_day', 20)->nullable();
            $table->unsignedSmallInteger('headcount')->nullable();

            /*
             * Төлөв: draft → active → completed | cancelled.
             *
             * БАТЛАГДСАНЫ ДАРАА МӨР ЗАСАГДАХГҮЙ. Акт нь тохиролцооны үнэ
             * дээр тогтдог тул үнэ хойшоо хөдөлвөл гарсан акт бүр худал
             * болно.
             */
            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['block_id', 'status']);
            $table->index('contractor_id');
        });

        Schema::create('agreement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained()->cascadeOnDelete();

            /*
             * Ажлын төрөлтэй ХОЛБОГДОНО.
             *
             * Чөлөөт текст байвал акт нь гүйцэтгэлийг гэрээний мөртэй
             * тааруулж чадахгүй — төлбөр гараар бодогдоно. Холбоос нь
             * энэ бүх ажлын гол ашиг.
             */
            $table->foreignUuid('work_type_id')->constrained()->restrictOnDelete();

            /** Бланкны «Ажлын нэр» — гэрээнд өөр нэрээр бичигдэж болно. */
            $table->string('label')->nullable();
            $table->string('unit', 20);
            $table->decimal('quantity', 14, 3);

            /*
             * Хэлэлцээрийн нэгж үнэ (НӨАТ-тай) ба төсвийн үнэ.
             *
             * Хоёрыг ТУСАД НЬ хадгална: захиалагчийн шийдвэрээр гэрээний үнэ
             * төсвөөс өөр байж болно. Төсвийн үнийг гэрээ бичих үед хөшөөлж
             * авна (snapshot) — төсөв хожим дахин импортлогдоход гэрээний
             * харьцуулалт хөдлөх ёсгүй.
             */
            $table->decimal('unit_price', 16, 2);
            $table->decimal('budget_unit_price', 16, 2)->nullable();

            /** Нэмэлт ажил — бланк дээр тусдаа багана. */
            $table->boolean('is_extra')->default(false);

            $table->unsignedSmallInteger('sequence_number')->default(0);
            $table->timestamps();

            $table->index('agreement_id');
            // Нэг тохиролцоонд ижил ажлын төрөл хоёр мөр болбол дүн давхарлана.
            $table->unique(['agreement_id', 'work_type_id', 'is_extra']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_lines');
        Schema::dropIfExists('agreements');
    }
};
