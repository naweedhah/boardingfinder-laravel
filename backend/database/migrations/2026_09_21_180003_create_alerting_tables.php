<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('city')->nullable();
            $table->string('area')->nullable();
            $table->unsignedInteger('min_budget')->nullable();
            $table->unsignedInteger('max_budget')->nullable();
            $table->string('preferred_tenant_gender', 32)->nullable();
            $table->string('boarding_type', 32)->nullable();
            $table->unsignedInteger('min_capacity')->nullable();
            $table->boolean('wifi_required')->nullable();
            $table->boolean('meals_required')->nullable();
            $table->boolean('attached_bathroom_needed')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'post_id']);
            $table->index(['user_id', 'post_id']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            $table->string('channel', 16);
            $table->string('title');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('booking_updates')->default(true);
            $table->boolean('watchlist_alerts')->default(true);
            $table->boolean('search_alerts')->default(true);
            $table->boolean('price_alerts')->default(true);
            $table->boolean('demand_alerts')->default(true);
            $table->boolean('inquiry_alerts')->default(true);
            $table->boolean('roommate_alerts')->default(true);
            $table->boolean('safety_alerts')->default(true);
            $table->boolean('moderation_alerts')->default(true);
            $table->boolean('account_security_alerts')->default(true);
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('code');
            $table->string('purpose');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('watchlists');
    }
};
