<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_site_engineer_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_type_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_engineer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['block_id', 'work_type_group_id'], 'block_site_engineer_group_unique');
            $table->index(['site_engineer_id', 'block_id'], 'site_engineer_block_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_site_engineer_assignments');
    }
};
