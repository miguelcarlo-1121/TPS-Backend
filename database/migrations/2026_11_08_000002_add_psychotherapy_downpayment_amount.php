<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->decimal('psychotherapy_downpayment_amount', 10, 2)->default(750);
        });

        $settings = DB::table('payment_settings')->first();
        $price = (float) DB::table('service_prices')->where('service_id', 'psychotherapy')->value('price');

        if ($settings && $price > 0) {
            DB::table('payment_settings')->where('id', $settings->id)->update([
                'psychotherapy_downpayment_amount' => round($price * (int) $settings->downpayment_percent / 100, 2),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn('psychotherapy_downpayment_amount');
        });
    }
};
