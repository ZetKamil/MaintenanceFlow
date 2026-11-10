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
        Schema::create('maintenance_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('performed_at');
            $table->text('performed_work');
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->date('next_due_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_reports');
    }
};
