<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social sign-in (FR-M1-02).
 *
 * TWO CHANGES, and the first is the one with teeth.
 *
 * `password` becomes nullable. An account created through Google has no
 * password and never had one, and the alternatives are worse: a random hash
 * nobody holds is indistinguishable in the column from a real one, which means
 * neither the application nor a person reading the table can tell whether an
 * account has a password — and "do you have a password" is exactly the question
 * the account screen has to answer to know whether to offer *set* or *change*.
 * NULL says it plainly.
 *
 * A separate table rather than a `google_id` column on users, because the
 * question "which accounts are linked to which provider" is a list, not a
 * property, and the second provider — Apple is the one this market asks for
 * next — should be a row rather than another migration. The unique constraint
 * on (provider, provider_user_id) is what stops one Google account being
 * attached to two Agentpro accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            /*
             * The address the provider gave us at link time, kept for the audit
             * trail rather than for matching. Somebody can change their Google
             * address later; the subject id is what identifies them, and a
             * lookup that keyed on email would follow the change and hand the
             * account to whoever now owns the old address.
             */
            $table->string('provider_email')->nullable();
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
