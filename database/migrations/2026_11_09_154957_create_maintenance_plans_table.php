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
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('source_system')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('maintenance_type')->default('PERIODIC');
            $table->unsignedInteger('interval_months')->default(12);
            $table->date('last_completed_at')->nullable();
            $table->date('next_due_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->text('source_notes')->nullable();
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
        Schema::dropIfExists('maintenance_plans');
    }
};
