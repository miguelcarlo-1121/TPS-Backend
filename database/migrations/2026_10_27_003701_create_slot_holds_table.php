<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('provider_id');
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('service_id', 50);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['provider_id', 'start_at', 'end_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_holds');
    }
};