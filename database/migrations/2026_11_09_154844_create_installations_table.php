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
        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('source_system')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('name');
            $table->string('installation_type')->default('OTHER')->index();
            $table->string('location_street')->nullable();
            $table->string('location_postal_code')->nullable();
            $table->string('location_city')->nullable();
            $table->boolean('is_active')->default(true);
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
        Schema::dropIfExists('installations');
    }
};
