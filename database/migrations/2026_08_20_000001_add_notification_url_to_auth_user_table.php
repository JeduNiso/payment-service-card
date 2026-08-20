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
        Schema::table('auth_user', function (Blueprint $table) {
            if (! Schema::hasColumn('auth_user', 'notification_url')) {
                $table->text('notification_url')->nullable()->after('customer_url');
            }
        });

        // customer_url used to double as the merchant's webhook base URL (read by
        // notifyMerchantWithUserToken()) *and* as a general "where does this
        // customer end up" field that other flows (e.g. urlToRedirect on
        // /api/payments/session) also write to — the two purposes collided and a
        // payment's urlToRedirect could overwrite a merchant's real webhook URL.
        // Backfill notification_url from the current customer_url once, so already
        // configured merchants keep working; going forward notifyMerchantWithUserToken()
        // reads notification_url instead, and customer_url is free to keep being the
        // general redirect-target field without touching it.
        if (Schema::hasColumn('auth_user', 'notification_url') && Schema::hasColumn('auth_user', 'customer_url')) {
            DB::table('auth_user')
                ->whereNull('notification_url')
                ->whereNotNull('customer_url')
                ->update(['notification_url' => DB::raw('customer_url')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auth_user', function (Blueprint $table) {
            if (Schema::hasColumn('auth_user', 'notification_url')) {
                $table->dropColumn('notification_url');
            }
        });
    }
};
