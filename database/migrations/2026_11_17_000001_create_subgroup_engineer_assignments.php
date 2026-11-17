<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('block_subgroup_engineer_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_type_group_id')->constrained()->cascadeOnDelete();
            $table->string('subgroup_number', 50);
            $table->foreignId('site_engineer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['block_id', 'work_type_group_id', 'subgroup_number'], 'block_subgroup_engineer_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('block_subgroup_engineer_assignments'); }
};
