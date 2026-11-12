<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            $table->index('user_id');
            $table->dropUnique('client_profiles_user_id_unique');
            $table->unsignedBigInteger('slot_hold_id')->nullable()->unique();
            $table->unsignedBigInteger('booking_payment_id')->nullable()->unique();
            $table->foreign('booking_payment_id')
                ->references('id')
                ->on('booking_payments')
                ->nullOnDelete();
        });

        DB::table('client_profiles')->orderBy('id')->get(['id', 'user_id'])->each(function ($profile) {
            $paymentId = DB::table('booking_payments')
                ->where('user_id', $profile->user_id)
                ->orderByDesc('id')
                ->value('id');

            if ($paymentId) {
                DB::table('client_profiles')
                    ->where('id', $profile->id)
                    ->update(['booking_payment_id' => $paymentId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            $table->dropForeign(['booking_payment_id']);
            $table->dropUnique('client_profiles_booking_payment_id_unique');
            $table->dropUnique('client_profiles_slot_hold_id_unique');
            $table->dropColumn(['booking_payment_id', 'slot_hold_id']);
            $table->unique('user_id');
            $table->dropIndex('client_profiles_user_id_index');
        });
    }
};
