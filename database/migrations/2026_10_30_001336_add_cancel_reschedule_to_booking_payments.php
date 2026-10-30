<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payments', function (Blueprint $t) {
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->unsignedTinyInteger('reschedule_count')->default(0);
            $t->dateTime('rescheduled_at')->nullable();
            $t->dateTime('original_start_at')->nullable(); // unang oras bago ang unang reschedule
        });
    }

    public function down(): void
    {
        Schema::table('booking_payments', function (Blueprint $t) {
            $t->dropColumn(['cancelled_at', 'cancel_reason', 'reschedule_count', 'rescheduled_at', 'original_start_at']);
        });
    }
};