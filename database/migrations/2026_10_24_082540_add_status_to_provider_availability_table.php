<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_availability', function (Blueprint $table) {
            // 'available' = open for booking, 'unavailable' = closed for the entire day
            $table->string('status', 20)->default('available')->after('end_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_availability', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
