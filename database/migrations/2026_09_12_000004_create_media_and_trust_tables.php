<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media (M3) and the trust record behind the RealSure badge (M6).
 *
 * Media carries provenance (FR-M3-10) because a seeker must be able to tell a
 * technician's capture from a phone video an agent shot. It also carries its own
 * moderation state (FR-M3-12) — video is the easiest place to get content past
 * a reviewer who is scanning photographs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('kind', [
                'photo', 'video', 'tour_3d', 'pano_360', 'floor_plan', 'drone', 'street_view',
            ]);

            // Self-hosted assets use disk+path. Third-party media (Matterport, Vimeo)
            // uses provider+provider_ref and is referenced, never re-hosted (NFR-09).
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_ref')->nullable();

            // Every gated medium needs a poster so the page renders at rest (NFR-02).
            $table->string('poster_path')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();

            // FR-M3-11: renditions written by the transcode job, never in a web request.
            $table->json('renditions')->nullable();

            // FR-M3-08: perceptual hash, so the same photo on another listing is detectable.
            $table->string('phash', 64)->nullable();

            $table->enum('source', ['lister', 'agentpro_technician'])->default('lister');
            $table->timestamp('captured_at')->nullable();

            $table->enum('moderation_state', ['pending', 'approved', 'rejected'])->default('pending');

            $table->boolean('is_cover')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['property_id', 'kind', 'moderation_state']);
            $table->index('phash');
        });

        // FR-M6-02: a badge that does not say what was checked is worth nothing.
        Schema::create('realsure_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('component', 64);
            $table->boolean('completed')->default(false);
            $table->date('completed_on')->nullable();
            $table->foreignId('officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evidence_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'component']);
        });

        // FR-M9-04: what counts as an interaction, and therefore who gets alerted.
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['save', 'hide', 'rate', 'report', 'contact']);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('contact_mode', 16)->nullable();  // phone | whatsapp | email
            $table->string('reason_code', 64)->nullable();   // reports
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'property_id', 'kind']);
            $table->index(['property_id', 'kind']);
        });

        // FR-M5-07: promoted to R1 — this is the loop that brings a seeker back.
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('criteria');
            $table->json('bounds')->nullable();
            $table->enum('frequency', ['instant', 'daily', 'off'])->default('daily');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'frequency']);
        });

        // FR-M12-05 / SEC-12: append-only. No model deletes from this table.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('interactions');
        Schema::dropIfExists('realsure_records');
        Schema::dropIfExists('media_assets');
    }
};
