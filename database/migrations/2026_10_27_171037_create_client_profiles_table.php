<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('client_profiles', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('user_id')->unique();
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $table->string('full_name', 150);
        $table->date('birthdate');
        $table->string('email', 190);
        $table->string('mobile', 11);
        $table->string('emergency_name', 150);
        $table->string('emergency_relation', 60);
        $table->string('emergency_phone', 11);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_profiles');
    }
};
