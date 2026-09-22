<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('applicant_name')->nullable();
            $table->string('applicant_phone', 32)->nullable();
            $table->string('applicant_address')->nullable();
            $table->string('applicant_nic', 32)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('requested_move_in')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'status']);
            $table->index(['student_id', 'status']);
            $table->index('owner_id');
        });

        Schema::create('booking_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('status', 32)->default('pending');
            $table->string('method')->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('proof_image')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('booking_requests');
    }
};
