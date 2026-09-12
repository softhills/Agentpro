<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Property -> Unit spine (FR-M2-13, open decision Q15).
 *
 * Shared attributes — address, location, title, media, amenities — live on the
 * property. Anything that can differ between two flats in the same block —
 * price, bedrooms, floor, availability — lives on the unit. A single dwelling is
 * a property with exactly one unit, so every query has one shape.
 *
 * The location column is written with raw DDL rather than the schema builder
 * because a SPATIAL index requires a NOT NULL column. That index is what makes
 * viewport search affordable on this stack (see PRD section 17).
 *
 * PORTABILITY: the DDL deliberately omits MySQL 8's `SRID 4326` column
 * attribute, which MariaDB rejects outright — XAMPP ships MariaDB, production is
 * specified as MySQL 8, and this schema has to build on both. Coordinates are
 * therefore stored as plain cartesian POINT(lng, lat) and distance is computed
 * with ST_Distance_Sphere, which both engines implement and which returns
 * metres regardless of SRID. lat/lng are also kept as decimals: they are what
 * the map pane and the seeder actually read, and they keep radius maths
 * portable if the spatial functions ever diverge again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            // SEC-10: public URLs use the uuid, never the sequential id.
            $table->uuid('uuid')->unique();

            $table->foreignId('lister_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->enum('listing_type', ['land', 'house', 'apartment']);
            $table->enum('intent', ['rent', 'sale']);
            $table->enum('build_status', ['fully_built', 'under_construction'])->default('fully_built');
            $table->date('expected_completion')->nullable();

            // Null for land — FR-M2-01 makes field applicability depend on type.
            $table->enum('finish', ['furnished', 'unfurnished', 'core', 'carcass'])->nullable();

            $table->string('address_line');
            $table->string('city');
            $table->string('state');

            // Read by the map pane and the search query; also the portable fallback
            // for radius maths. The POINT column below is added by raw DDL.
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            // FR-M5-04: cached at creation so search still works if the API is down.
            $table->string('what3words', 120)->nullable();

            // FR-M2-11: lister-supplied, rendered rel="nofollow ugc", never fetched
            // server-side (SEC-09).
            $table->string('website_url')->nullable();

            // FR-M2-05
            $table->enum('lifecycle_state', [
                'draft', 'submitted', 'under_review', 'published',
                'unpublished', 'expired', 'sold', 'rented', 'rejected',
            ])->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();          // FR-M2-08 display duration
            $table->timestamp('content_updated_at')->nullable();  // FR-M2-14 freshness
            $table->timestamp('realsure_verified_at')->nullable();// FR-M6-01

            $table->string('rejection_reason_code', 64)->nullable();
            $table->text('rejection_note')->nullable();

            $table->unsignedInteger('view_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // FR-M2-09: sold/rented are excluded from default results but stay findable.
            $table->index(['lifecycle_state', 'published_at']);
            $table->index(['intent', 'listing_type', 'lifecycle_state']);
            $table->index('area_id');
            $table->index('expires_at');
            $table->index('realsure_verified_at');
        });

        // Spatial column + index. Both engines refuse a SPATIAL index on a nullable
        // column, and MariaDB refuses the `SRID 4326` attribute entirely — see the
        // portability note at the top of this file.
        DB::statement('ALTER TABLE properties ADD COLUMN location POINT NOT NULL AFTER lng');
        DB::statement('ALTER TABLE properties ADD SPATIAL INDEX properties_location_spatial (location)');
        DB::statement('ALTER TABLE properties ADD FULLTEXT INDEX properties_text_ft (title, description, address_line)');

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->string('label')->nullable();          // "Flat 3B", null for single dwellings
            $table->boolean('is_primary')->default(false);// the unit shown on the card

            $table->decimal('price', 15, 2);
            // FR-M7-06: a price without a period is not a price.
            $table->enum('price_period', ['year', 'month', 'night', 'once'])->default('year');

            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('toilets')->nullable();   // Nigerian convention
            $table->unsignedInteger('floor_area_sqm')->nullable();// m², never sq ft
            $table->string('floor_label', 32)->nullable();

            $table->enum('status', ['available', 'reserved', 'taken'])->default('available');
            $table->date('available_from')->nullable();

            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index(['price', 'price_period']);
            $table->index('bedrooms');
        });

        // FR-M2-04 / FR-M7-07: the price-drop tag is derived from this, never chosen.
        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->enum('price_period', ['year', 'month', 'night', 'once']);
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['unit_id', 'effective_at']);
        });

        // M7: publishing is blocked until this is complete.
        Schema::create('fee_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->decimal('amount', 15, 2);
            $table->enum('calc_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('percentage_rate', 5, 2)->nullable();
            $table->boolean('is_refundable')->default(false);   // caution deposit
            $table->string('payee')->nullable();                // FR-M7-04
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('unit_id');
        });

        // FR-M6-04: closed vocabulary, free text is not accepted.
        Schema::create('title_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('title_type', 64);
            $table->enum('stage', ['available', 'in_progress'])->default('available');
            $table->timestamp('declared_at')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'title_type']);
        });

        Schema::create('amenity_property', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['property_id', 'amenity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_property');
        Schema::dropIfExists('title_claims');
        Schema::dropIfExists('fee_lines');
        Schema::dropIfExists('price_histories');
        Schema::dropIfExists('units');
        Schema::dropIfExists('properties');
    }
};
