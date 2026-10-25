<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Хугацаа сунгахыг ХҮСЭЛТ болгоно.
 *
 * ЯАГААД: одоо огноог ШУУД өөрчилдөг. Тиймээс «хугацаа хэтэрсэн» гэсэн тоо
 * утгагүй — хоцорсон хүн огноогоо хойшлуулаад хоцроогүй болчихдог. Нэг
 * товч дарахад тайлан өөрөө засагддаг бол тайлан хэнийг ч хянахгүй.
 *
 * ХОЁР ОГНОО:
 *
 *   `baseline_end_date`  — ҮНДСЭН төлөвлөгөө. Сунгалт үүнийг ХӨНДӨХГҮЙ.
 *   `planned_end_date`   — өнөөдрийн амлалт. Батлагдсан сунгалтаар хөдөлнө.
 *
 * Ингэснээр хоцролтыг ХОЁР талаас хэмжинэ: амлалтаасаа хоцорсон эсэх, мөн
 * үндсэн төлөвлөгөөнөөс хэр гулссан. Хоёрдугаарыг нуух арга байхгүй.
 *
 * Такт хуваарийг дахин татахад (`/blocks/{id}/schedule`) үндсэн төлөвлөгөө
 * нь ӨӨРӨӨ шинэчлэгдэнэ — тэр нь сунгалт биш, дахин төлөвлөлт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->date('baseline_end_date')->nullable()->after('planned_end_date');
        });

        // Байгаа мөрүүдийн үндсэн огноо нь өнөөдрийн амлалт — өөр эх сурвалж алга.
        DB::statement('update work_items set baseline_end_date = planned_end_date');

        Schema::create('extension_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('current_end_date')->nullable();
            $table->date('requested_end_date');

            /** Саатлын ангилал — `Issue::CATEGORIES`-тэй ижил лавлах. */
            $table->string('category', 40);
            $table->text('reason');

            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            /*
             * Батлагдсан хүсэлт нь сааталд бүртгэгдэнэ — хоцролтын шалтгаан
             * нь нэг дор хуримтлагдах ёстой.
             */
            $table->foreignUuid('issue_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['work_item_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_requests');
        Schema::table('work_items', fn (Blueprint $t) => $t->dropColumn('baseline_end_date'));
    }
};
