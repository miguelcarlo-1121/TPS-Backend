<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_forms', function (Blueprint $table) {
            $table->longText('signature_data')->nullable()->after('signed_date');
            $table->longText('guardian_signature_data')->nullable()->after('guardian_relation');
        });
    }

    public function down(): void
    {
        Schema::table('consent_forms', function (Blueprint $table) {
            $table->dropColumn(['signature_data', 'guardian_signature_data']);
        });
    }
};
