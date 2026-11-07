<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slot_holds', function (Blueprint $table) {
            $table->unsignedBigInteger('assessment_type_id')->nullable()->after('service_id');
            $table->string('assessment_type_name', 150)->nullable()->after('assessment_type_id');
        });

        Schema::table('booking_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('assessment_type_id')->nullable()->after('service_id');
            $table->string('assessment_type_name', 150)->nullable()->after('assessment_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('slot_holds', function (Blueprint $table) {
            $table->dropColumn(['assessment_type_id', 'assessment_type_name']);
        });

        Schema::table('booking_payments', function (Blueprint $table) {
            $table->dropColumn(['assessment_type_id', 'assessment_type_name']);
        });
    }
};

