<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('work_items', function (Blueprint $table) {
            $table->boolean('schedule_manual')->default(false);
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->timestamp('approved_completed_at')->nullable();
        });
        // Approval timestamps are evidence; old planned dates are never physical dates.
        DB::table('work_items')->where('status', 'completed')->update(['approved_completed_at' => DB::raw("(SELECT MAX(inspected_at) FROM inspections WHERE inspections.work_item_id = work_items.id AND stage = 'final_approval' AND accepted_qty > 0)")]);
    }
    public function down(): void { Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn(['schedule_manual', 'actual_start_date', 'actual_end_date', 'approved_completed_at'])); }
};
