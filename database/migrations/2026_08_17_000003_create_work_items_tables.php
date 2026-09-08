<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WorkItem ба түүний гүйцэтгэлийн түүх.
 *
 * `reported_qty` / `accepted_qty` нь `progress_entries` ба `inspections`-ээс
 * хуримтлагдсан дүн бөгөөд `work_items`-д ХАДГАЛАГДАНА. Жагсаалт бүрд дэд
 * хүснэгтээс дахин бодох нь 3,290 мөр дээр N+1 болно. Явц/шалгалт нэмэгдэх
 * бүрд transaction дотор `WorkItem::recalculate()` шинэчилнэ.
 *
 * `accepted_qty` нь үлдэгдэл ба хувийг тодорхойлно — мэдээлсэн нь биш,
 * батлагдсан нь. Захиалагчийн шаардлага.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_type_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('contractor_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code', 60)->nullable();      // A-05-A2-PARQ маягаар үүснэ
            $table->string('name');
            $table->string('unit', 20);

            $table->decimal('planned_qty', 14, 3)->default(0);
            $table->decimal('reported_qty', 14, 3)->default(0);
            $table->decimal('accepted_qty', 14, 3)->default(0);

            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('status', 20)->default('not_started');
            $table->string('review_state', 20)->default('none');

            // Дахин хийх ажил — эх ажилтайгаа холбогдож, зардал тусад нь гарна.
            $table->foreignUuid('rework_of_id')->nullable()->references('id')->on('work_items')->nullOnDelete();

            $table->timestamps();

            // Дэлгэцийн бүх шүүлтүүр эдгээрээр явна.
            $table->index(['block_id', 'review_state']);
            $table->index(['block_id', 'work_type_id']);
            $table->index(['block_id', 'planned_end_date']);
            $table->index('location_id');
            $table->index('contractor_id');
        });

        Schema::create('progress_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('contractor_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('completed_qty', 14, 3);
            $table->unsignedSmallInteger('workers_count')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            // Түүхийг үргэлж шинэ→хуучин эрэмбэлнэ.
            $table->index(['work_item_id', 'recorded_at']);
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();

            // Хоёр шат: ерөнхий гүйцэтгэгчийн хяналт → захиалагчийн хяналт.
            $table->string('stage', 30);          // general_contractor · client
            $table->string('result', 20);         // accepted · rejected · partial
            $table->decimal('accepted_qty', 14, 3)->default(0);
            $table->decimal('rejected_qty', 14, 3)->default(0);
            $table->text('reason')->nullable();   // татгалзсан бол заавал
            $table->foreignUuid('rework_work_item_id')->nullable()->references('id')->on('work_items')->nullOnDelete();
            $table->timestamp('inspected_at');
            $table->timestamps();

            $table->index(['work_item_id', 'inspected_at']);
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('progress_entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20);           // before · progress · after
            $table->string('path');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at')->nullable();
            // Батлагдсаны дараа устгах боломжгүй (RULE-10).
            $table->boolean('locked')->default(false);
            $table->timestamps();

            $table->index('work_item_id');
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            // 7 ангилал захиалагчаас: гүйцэтгэгч олдоогүй, хугацаандаа ирээгүй,
            // тоног төхөөрөмж, материал тасалдсан, цаг агаар, гомдол, осол.
            $table->string('category', 40);
            $table->string('severity', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->text('description')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['work_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('progress_entries');
        Schema::dropIfExists('work_items');
    }
};
