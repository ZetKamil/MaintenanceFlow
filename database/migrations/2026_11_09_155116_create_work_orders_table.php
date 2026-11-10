<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_system')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->date('cycle_due_date')->nullable();
            $table->string('status')->default('needs_contact')->index();
            $table->dateTime('scheduled_start_at')->nullable()->index();
            $table->dateTime('scheduled_end_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->timestamps();

            // Composite unique index for idempotent CSV import
            $table->unique(['source_system', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
