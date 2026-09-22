<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roommate_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('university')->nullable();
            $table->string('faculty')->nullable();
            $table->string('year_of_study')->nullable();
            $table->unsignedInteger('budget_min')->nullable();
            $table->unsignedInteger('budget_max')->nullable();
            $table->string('preferred_city')->nullable();
            $table->string('preferred_area')->nullable();
            $table->string('sleep_schedule')->nullable();
            $table->unsignedTinyInteger('cleanliness_level')->nullable();
            $table->string('study_habit')->nullable();
            $table->boolean('smoking_allowed')->nullable();
            $table->boolean('pets_allowed')->nullable();
            $table->string('food_preference')->nullable();
            $table->unsignedTinyInteger('sociability_level')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('roommate_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('user_b_id')->constrained('users')->cascadeOnDelete();
            $table->double('score');
            $table->json('reasons')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roommate_matches');
        Schema::dropIfExists('roommate_preferences');
    }
};
