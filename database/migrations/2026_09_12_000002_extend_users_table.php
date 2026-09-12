<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account categories and identity verification (M1).
 *
 * verification_state is the gate on publishing (FR-M1-05). A lister who is not
 * 'verified' cannot move a property out of draft — enforced in the policy layer,
 * not only in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');

            // FR-M1-04: the five sign-up categories drive dashboard, KYC depth and seats.
            $table->enum('category', [
                'seeker',
                'independent_agent',
                'property_owner',
                'sellers_agent',
                'developer',
                'brokerage_firm',
            ])->default('seeker')->after('email');

            $table->foreignId('organisation_id')->nullable()->after('category')
                  ->constrained('organisations')->nullOnDelete();

            $table->enum('org_role', ['owner', 'manager', 'agent'])->nullable()->after('organisation_id');

            $table->string('phone', 32)->nullable()->after('org_role');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');

            // FR-M1-06
            $table->enum('verification_state', ['unverified', 'pending', 'verified', 'rejected', 'suspended'])
                  ->default('unverified')->after('phone_verified_at');
            $table->timestamp('verified_at')->nullable()->after('verification_state');
            $table->string('verification_vendor', 32)->nullable()->after('verified_at');
            $table->string('verification_reference')->nullable()->after('verification_vendor');

            // Staff flags — 2FA is mandatory for these (SEC-06).
            $table->boolean('is_staff')->default(false)->after('verification_reference');
            $table->enum('staff_role', ['moderator', 'realsure_officer', 'technician', 'finance', 'admin'])
                  ->nullable()->after('is_staff');

            // FR-M9-07: per-channel notification preferences with quiet hours.
            $table->json('notification_preferences')->nullable()->after('staff_role');

            // FR-M8-04 / FR-M5-10: the seeker's commute destination.
            $table->string('commute_label')->nullable()->after('notification_preferences');
            $table->decimal('commute_lat', 10, 7)->nullable()->after('commute_label');
            $table->decimal('commute_lng', 10, 7)->nullable()->after('commute_lat');

            $table->softDeletes();

            $table->index('category');
            $table->index('verification_state');
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organisation_id');
            $table->dropColumn([
                'uuid', 'category', 'org_role', 'phone', 'phone_verified_at',
                'verification_state', 'verified_at', 'verification_vendor', 'verification_reference',
                'is_staff', 'staff_role', 'notification_preferences',
                'commute_label', 'commute_lat', 'commute_lng', 'deleted_at',
            ]);
        });
    }
};
