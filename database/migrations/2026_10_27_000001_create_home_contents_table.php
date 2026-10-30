<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('home_contents', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->default('home');
            $table->json('content');
            $table->string('updated_by', 64)->nullable(); // id ng admin na huling nag-edit
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_contents');
    }
};
