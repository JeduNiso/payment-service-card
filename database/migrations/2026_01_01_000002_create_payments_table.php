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
        if (Schema::hasTable('redirect_payment')) {
            return;
        }

        Schema::create('redirect_payment', function (Blueprint $table) {
            $table->id();
            $table->string('bookCode')->default('');
            $table->string('total')->default('0');
            $table->string('currency')->default('BOB');
            $table->string('service')->default('cybersource');
            $table->string('name')->default('');
            $table->string('lastName')->default('');
            $table->string('mail')->default('');
            $table->string('description')->default('');
            $table->string('paymentType')->default('');
            $table->longText('postdata')->default('{}');
            $table->longText('response')->default('{}');
            $table->string('extAuthorization')->default('');
            $table->string('extErrorCode')->default('');
            $table->string('extCode')->default('');
            $table->string('extId')->default('');
            $table->string('state')->default('0');
            $table->dateTime('creationDate')->nullable();
            $table->dateTime('paymentDate')->nullable();
            $table->longText('book')->default('{}');
            $table->string('invoiceName')->default('');
            $table->string('invoiceNit')->default('');
            $table->timestamp('updated_at')->nullable();
            $table->string('qrId')->default('');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redirect_payment');
    }
};
