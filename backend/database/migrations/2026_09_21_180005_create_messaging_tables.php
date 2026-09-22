<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->text('last_message')->nullable();
            $table->timestamps();
        });

        // Participants of a chat. `seen` tracks whether that participant has read the latest message.
        Schema::create('chat_user', function (Blueprint $table) {
            $table->foreignUlid('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('seen')->default(false);

            $table->primary(['chat_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('scam_flag', 16)->default('safe')->index();
            $table->timestamps();

            $table->index(['chat_id', 'created_at']);
        });

        Schema::create('inquiries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('pending');
            // Not unique: one student/owner chat serves every inquiry between the same two people.
            $table->foreignUlid('chat_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['post_id', 'student_id', 'status']);
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('chat_user');
        Schema::dropIfExists('chats');
    }
};
