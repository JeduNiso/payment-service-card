<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('auth_user', function (Blueprint $table) {
            if (! Schema::hasColumn('auth_user', 'merchant_notification_api_key')) {
                $table->text('merchant_notification_api_key')->nullable()->after('customer_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auth_user', function (Blueprint $table) {
            if (Schema::hasColumn('auth_user', 'merchant_notification_api_key')) {
                $table->dropColumn('merchant_notification_api_key');
            }
        });
    }
};
