<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Downpayment % at note (isang row lang)
        Schema::create('payment_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('downpayment_percent')->default(50);
            $t->text('note')->nullable();
            $t->timestamps();
        });

        // Presyo kada service
        Schema::create('service_prices', function (Blueprint $t) {
            $t->string('service_id', 50)->primary();
            $t->decimal('price', 10, 2)->default(0);
            $t->timestamps();
        });

        // GCash, Maya, bank, atbp. (si admin lang ang nag-eedit)
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->string('letter', 2)->default('?');
            $t->string('badge', 9)->default('#4a9b8e');
            $t->string('account_name', 120)->nullable();
            $t->string('account_number', 60)->nullable();
            $t->string('bank', 80)->nullable();
            $t->string('qr_path')->nullable();
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        // Bayad ng client (ito rin ang nagba-block ng slot pagkatapos ng hold)
        Schema::create('booking_payments', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 20)->unique();
            $t->unsignedInteger('user_id')->index();
            $t->unsignedBigInteger('provider_id')->nullable()->index();
            $t->unsignedInteger('psychologist_user_id')->nullable()->index(); // psychotherapy lang
            $t->string('service_id', 50);
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->string('payment_type', 10);               // full | down
            $t->decimal('total_amount', 10, 2);
            $t->decimal('amount_paid', 10, 2);
            $t->decimal('balance', 10, 2)->default(0);
            $t->string('method_name', 60);
            $t->string('wallet_number', 20);
            $t->string('reference_number', 60);
            $t->string('receipt_path');
            $t->string('status', 20)->default('to_verify'); // to_verify | verified | rejected
            $t->unsignedInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->string('reject_reason')->nullable();
            $t->timestamps();

            $t->index(['provider_id', 'start_at', 'end_at']);
        });

        // Default values (palitan ni admin sa Payment methods page)
        $now = now();
        DB::table('payment_settings')->insert(['downpayment_percent' => 50, 'note' => null, 'created_at' => $now, 'updated_at' => $now]);

        foreach (['psychotherapy', 'aba', 'assessment'] as $s) {
            DB::table('service_prices')->insert(['service_id' => $s, 'price' => 1500, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('payment_methods')->insert([
            ['name' => 'GCash', 'letter' => 'G', 'badge' => '#1e83e2', 'enabled' => false, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Maya',  'letter' => 'M', 'badge' => '#3d865b', 'enabled' => false, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('service_prices');
        Schema::dropIfExists('payment_settings');
    }
};