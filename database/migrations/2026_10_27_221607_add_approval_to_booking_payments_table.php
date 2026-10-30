<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payments', function (Blueprint $t) {
            $t->string('approval_status', 20)->default('pending')->after('status'); // pending | approved | declined
            $t->unsignedInteger('approved_by')->nullable()->after('approval_status');
            $t->dateTime('approved_at')->nullable()->after('approved_by');
            $t->string('decline_reason')->nullable()->after('approved_at');
            $t->dateTime('completed_at')->nullable()->after('decline_reason');
        });
    }

    public function down(): void
    {
        Schema::table('booking_payments', function (Blueprint $t) {
            $t->dropColumn(['approval_status', 'approved_by', 'approved_at', 'decline_reason', 'completed_at']);
        });
    }
};