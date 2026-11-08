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
            $table->decimal('assessment_downpayment_amount', 10, 2)->default(750);
        });

        $settings = DB::table('payment_settings')->first();
        $minimumPrice = DB::table('assessment_types')
            ->where('enabled', true)
            ->min('price');

        if ($settings && $minimumPrice > 0) {
            DB::table('payment_settings')->where('id', $settings->id)->update([
                'assessment_downpayment_amount' => round($minimumPrice * (int) $settings->downpayment_percent / 100, 2),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn('assessment_downpayment_amount');
        });
    }
};
