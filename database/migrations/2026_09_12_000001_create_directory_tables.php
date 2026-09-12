<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: firms, geographic areas, and the amenity vocabulary.
 *
 * Areas carry the scan-coverage flag that gates the 3D upgrade (FR-M4-02).
 * Coverage is data, never hard-coded — Operations edits it without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['brokerage', 'developer']);
            $table->string('cac_number')->nullable();
            $table->enum('verification_state', ['unverified', 'pending', 'verified', 'rejected', 'suspended'])
                  ->default('unverified');
            $table->timestamps();
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('city');
            $table->string('state');

            // FR-M4-02: the 3D upgrade is only offered inside an active coverage area.
            $table->boolean('is_scan_coverage')->default(false);

            $table->decimal('centroid_lat', 10, 7)->nullable();
            $table->decimal('centroid_lng', 10, 7)->nullable();
            $table->unsignedTinyInteger('default_zoom')->default(14);
            $table->timestamps();

            $table->index(['state', 'city']);
            $table->index('is_scan_coverage');
        });

        // FR-M5-09: the Nigerian amenity set, not a US one.
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('group')->default('general');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_filterable')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE organisations COMMENT = 'Brokerage firms and developers (FR-M1-08)'");
        DB::statement("ALTER TABLE areas COMMENT = 'Search areas and 3D scan coverage (FR-M4-02)'");
    }

    public function down(): void
    {
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('areas');
        Schema::dropIfExists('organisations');
    }
};
