<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('payment_user')) {
            return;
        }

        Schema::table('payment_user', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_user', 'customer_url')) {
                $table->text('customer_url')->nullable()->after('redirect_payment_id');
            }
        });

        // customer_url on auth_user was a single mutable value shared by every payment
        // for that user — a payment made with urlToRedirect overwrote it for *all*
        // future payments, not just that one. Recording it per payment_user row instead
        // means each payment keeps its own value, and "what URL should this customer
        // go back to" becomes a per-payment fact instead of a fact about the user.
        //
        // Best-effort backfill: every existing payment_user row for a given auth_user
        // gets that user's current (last known) customer_url, since there's no real
        // per-payment history to recover.
        if (Schema::hasColumn('payment_user', 'customer_url') && Schema::hasColumn('auth_user', 'customer_url')) {
            DB::statement(
                'UPDATE payment_user pu ' .
                'JOIN auth_user au ON au.id = pu.auth_user_id ' .
                'SET pu.customer_url = au.customer_url ' .
                'WHERE pu.customer_url IS NULL AND au.customer_url IS NOT NULL'
            );
        }

        if (Schema::hasColumn('auth_user', 'customer_url')) {
            Schema::table('auth_user', function (Blueprint $table) {
                $table->dropColumn('customer_url');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('auth_user', 'customer_url')) {
            Schema::table('auth_user', function (Blueprint $table) {
                $table->text('customer_url')->nullable()->after('id');
            });
        }

        if (Schema::hasTable('payment_user') && Schema::hasColumn('payment_user', 'customer_url')) {
            DB::statement(
                'UPDATE auth_user au ' .
                'JOIN payment_user pu ON pu.auth_user_id = au.id ' .
                'SET au.customer_url = pu.customer_url ' .
                'WHERE pu.customer_url IS NOT NULL'
            );

            Schema::table('payment_user', function (Blueprint $table) {
                $table->dropColumn('customer_url');
            });
        }
    }
};
