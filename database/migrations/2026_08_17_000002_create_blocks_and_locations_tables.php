<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Зураг төслийн загвар, блок (барилга), байршлын мод.
 *
 * Загварыг эхэлж үүсгэнэ — SQLite нь хүснэгт үүссэний дараа foreign key нэмэхийг
 * дэмждэггүй тул `blocks` доторх FK-г мөрд нь шууд зарлана.
 *
 * `locations.path_key` нь materialized path — `/rootId/floorId/unitId`.
 * "5-р давхрын бүх ажил" гэсэн хүсэлт рекурсгүйгээр, ганц индекстэй
 * `like '/root/floor/%'` query болно. 3,290 мөр × 75 блок дээр энэ ялгаа
 * шийдвэрлэх ач холбогдолтой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_designs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('purpose')->nullable();
            $table->unsignedSmallInteger('floors');
            $table->unsignedSmallInteger('units_per_floor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('design_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('block_design_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_type_id')->constrained()->cascadeOnDelete();
            // Нэг байршилд ногдох тоо хэмжээ. null = тодорхойгүй (Хавсралт-2-ын
            // 47 төрлөөс 30 нь ийм) — үлдэгдэл бодогдохгүй тул дэлгэц анхааруулна.
            $table->decimal('qty_per_location', 14, 3)->nullable();
            $table->unsignedSmallInteger('sequence_number')->default(0);
            $table->timestamps();

            $table->unique(['block_design_id', 'work_type_id']);
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('block_design_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('building_no', 20)->nullable();   // ерөнхий төлөвлөгөөний дугаар
            $table->string('purpose')->nullable();           // Хавсралт-1-ийн 12 зориулалт
            $table->unsignedSmallInteger('floors')->default(0);
            $table->unsignedSmallInteger('units_per_floor')->default(0);
            $table->date('start_date')->nullable();
            $table->string('status', 20)->default('not_started');
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->references('id')->on('locations')->cascadeOnDelete();
            $table->string('level', 20);          // block · entrance · floor · unit · room · common
            $table->string('name');
            $table->string('path');               // "А блок › 5-р давхар › А2" — харуулахад
            $table->string('path_key', 500);      // "/uuid/uuid/uuid" — удмаар шүүхэд
            $table->integer('sequence_number')->default(0);
            $table->timestamps();

            $table->index(['block_id', 'level']);
            $table->index('parent_id');
        });

        $this->indexPathKey();
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('design_items');
        Schema::dropIfExists('block_designs');
    }

    /**
     * `like '/prefix%'` хайлтыг индекслэнэ.
     *
     * PostgreSQL-д энгийн btree индекс нь `LIKE`-д ажиллахгүй (locale-аас
     * шалтгаална) тул `text_pattern_ops` заавал хэрэгтэй.
     */
    private function indexPathKey(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX locations_path_key_prefix_idx ON locations (path_key text_pattern_ops)');

            return;
        }

        Schema::table('locations', fn (Blueprint $t) => $t->index('path_key'));
    }
};
