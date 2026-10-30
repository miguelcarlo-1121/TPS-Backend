<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payments', function (Blueprint $t) {
            // Kapag may laman, tapos na ang booking flow ng client (consent/confirmation)
            $t->dateTime('flow_done_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_payments', function (Blueprint $t) {
            $t->dropColumn('flow_done_at');
        });
    }
};