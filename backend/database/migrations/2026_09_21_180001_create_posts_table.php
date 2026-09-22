<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('rent');
            $table->json('images')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('area')->nullable();
            $table->string('latitude');
            $table->string('longitude');
            $table->string('boarding_type', 32);
            $table->string('status', 32)->default('available');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('bathroom_count')->nullable();
            $table->string('preferred_tenant_gender', 32)->default('any');
            $table->timestamps();

            $table->index(['city', 'area']);
            $table->index('status');
            $table->index('rent');
        });

        Schema::create('post_details', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->boolean('wifi')->default(false);
            $table->boolean('meals_provided')->default(false);
            $table->boolean('kitchen_access')->default(false);
            $table->boolean('parking')->default(false);
            $table->boolean('attached_bathroom')->default(false);
            $table->boolean('furnished')->default(false);
            $table->boolean('pet_allowed')->default(false);
            $table->string('nearest_campus')->nullable();
            $table->unsignedInteger('distance_to_campus')->nullable();
            $table->unsignedInteger('distance_to_bus_stop')->nullable();
            $table->unsignedInteger('distance_to_town')->nullable();
            $table->text('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'post_id']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('saved_posts');
        Schema::dropIfExists('post_details');
        Schema::dropIfExists('posts');
    }
};
