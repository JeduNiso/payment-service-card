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
        if (! Schema::hasTable('auth_user')) {
            Schema::create('auth_user', function (Blueprint $table) {
                $table->id();
                $table->string('password');
                $table->timestamp('last_login')->nullable();
                $table->boolean('is_superuser')->default(false);
                $table->string('username', 150);
                $table->string('first_name', 150);
                $table->string('last_name', 150);
                $table->string('email', 254);
                $table->boolean('is_staff')->default(false);
                $table->boolean('is_active')->default(true);
                $table->dateTime('date_joined');
                $table->text('customer_url')->default('');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_user');
    }
};
