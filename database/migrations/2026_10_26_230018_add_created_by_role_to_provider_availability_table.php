<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('provider_availability', function (Blueprint $table) {
           
            $table->string('created_by_role', 20)->default('admin')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('provider_availability', function (Blueprint $table) {
            $table->dropColumn('created_by_role');
        });
    }
};