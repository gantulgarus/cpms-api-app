<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_order')->nullable();
            $table->index(['block_id', 'plan_order']);
        });
        Schema::create('work_item_dependencies', function (Blueprint $table) {
            $table->foreignUuid('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignUuid('predecessor_id')->constrained('work_items')->cascadeOnDelete();
            $table->primary(['work_item_id', 'predecessor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_dependencies');
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['block_id', 'plan_order']);
            $table->dropColumn('plan_order');
        });
    }
};
