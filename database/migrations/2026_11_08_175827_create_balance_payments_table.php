<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration {
    public function up(): void
    {
        Schema::create('balance_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('booking_payment_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->decimal('amount', 10, 2);
            $t->string('method_name', 80);
            $t->string('account_name', 120);
            $t->string('receipt_path');
            $t->string('status', 20)->default('to_verify'); // to_verify | verified | rejected
            $t->string('reject_reason', 255)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_payments');
    }
};