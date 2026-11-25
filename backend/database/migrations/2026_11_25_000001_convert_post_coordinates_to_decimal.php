<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordinates were first stored as strings (a leftover from the MongoDB schema). As text they
 * cannot be range-filtered or indexed numerically, which the "near campus" search needs.
 *
 * DECIMAL(10,7) keeps ~1 cm precision. They become nullable: a listing without a pin is valid,
 * it just does not appear on the map. The search indexes are also reshaped around the
 * queries the listing page actually runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // `using` is only emitted on PostgreSQL, which cannot cast varchar -> numeric implicitly.
            $table->decimal('latitude', 10, 7)->nullable()->using("nullif(latitude, '')::numeric(10,7)")->change();
            $table->decimal('longitude', 10, 7)->nullable()->using("nullif(longitude, '')::numeric(10,7)")->change();
        });

        Schema::table('posts', function (Blueprint $table) {
            // Listing page: WHERE status = ? [AND city = ?] ORDER/RANGE BY rent.
            $table->index(['status', 'city', 'rent'], 'posts_status_city_rent_index');
            // Near-campus search: bounding box on both coordinates.
            $table->index(['latitude', 'longitude'], 'posts_coordinates_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_coordinates_index');
            $table->dropIndex('posts_status_city_rent_index');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('latitude')->nullable(false)->change();
            $table->string('longitude')->nullable(false)->change();
        });
    }
};
