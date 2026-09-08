<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чанарын checklist.
 *
 * Захиалагчийн дүрэм: "Зөвхөн зураг дээр үндэслэн ажил батлахгүй. Checklist,
 * хэмжилт, хяналтын approval шаардлагатай." Мөн "Ажил тус бүрийн checklist
 * өөр байж болно" — тиймээс загвар нь ажлын төрөлд холбогдоно.
 *
 * Загварыг ХОЁР түвшинд холбож болно:
 *   - `work_type_id`       — тухайн ажлын төрөлд яг тохирсон
 *   - `work_type_group_id` — бүлэг бүхэлдээ (47 төрөл тус бүрт бичихгүйн тулд)
 *
 * Шийдэх дараалал: эхлээд төрлийнх, байхгүй бол бүлгийнх. Ингэснээр ерөнхий
 * инженер 15 бүлэгт нэг удаа бичээд эхэлж, шаардлагатай төрөлд нь дараа нь
 * нарийвчилж болно.
 */
return new class extends Migration
{
    /**
     * ХҮСНЭГТ БҮРИЙГ ТУСАД НЬ ШАЛГАНА.
     *
     * MySQL-д DDL нь транзакцгүй: гурав дахь хүснэгт дээр алдаа гарвал эхний
     * хоёр нь үүсчихсэн атлаа migration бүртгэгдэхгүй үлдэнэ. Дараа нь дахин
     * ажиллуулахад "table already exists" гэж унана. Доорх шалгалт нь хагас
     * гүйцэтгэсэн migration-ыг гараар цэвэрлэхгүйгээр үргэлжлүүлэх боломж өгнө.
     */
    public function up(): void
    {
        if (! Schema::hasTable('checklist_templates')) {
            Schema::create('checklist_templates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->foreignUuid('work_type_id')->nullable()
                    ->constrained()->cascadeOnDelete();
                $table->foreignUuid('work_type_group_id')->nullable()
                    ->constrained()->cascadeOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('checklist_items')) {
            Schema::create('checklist_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('checklist_template_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence_number')->default(0);
                $table->string('text');
                /** Нэмэлт тайлбар — талбай дээр юуг яаж шалгахыг заана. */
                $table->string('guidance')->nullable();
                /**
                 * Заавал шалгах зүйл.
                 *
                 * Заавал зүйл "тэнцээгүй" бол ажлыг БАТЛАХ БОЛОМЖГҮЙ. Энэ бол
                 * checklist-ийг зүгээр нэг маягт биш, жинхэнэ хаалт болгодог хэсэг.
                 */
                $table->boolean('is_required')->default(true);
                $table->timestamps();

                $table->index(['checklist_template_id', 'sequence_number'], 'ci_template_sequence_index');
            });
        }

        if (! Schema::hasTable('inspection_checklist_answers')) {
            Schema::create('inspection_checklist_answers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('inspection_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('checklist_item_id')->constrained()->cascadeOnDelete();
                $table->string('result', 10);          // pass · fail · na
                $table->text('note')->nullable();
                $table->timestamps();

                // Нэг шалгалтад нэг зүйл нэг л удаа хариулагдана.
                //
                // Нэрийг ГАРААР өгсөн: автоматаар үүсэх нэр 67 тэмдэгт болж,
                // MySQL-ийн 64 тэмдэгтийн хязгаараас хэтэрдэг.
                $table->unique(['inspection_id', 'checklist_item_id'], 'ica_inspection_item_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_checklist_answers');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_templates');
    }
};
