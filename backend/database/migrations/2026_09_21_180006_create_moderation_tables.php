<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type', 32);
            // Polymorphic by convention (user / boarding / bookingRequest / message id), so no FK.
            $table->string('target_id');
            $table->text('reason');
            $table->text('description')->nullable();
            $table->text('proof_image')->nullable();
            $table->string('status', 32)->default('open');
            $table->text('admin_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index(['reporter_id', 'status']);
            $table->index('status');
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->string('target_id')->nullable();
            $table->string('subject');
            $table->text('description');
            $table->string('status', 32)->default('open');
            $table->text('admin_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('reports');
    }
};
