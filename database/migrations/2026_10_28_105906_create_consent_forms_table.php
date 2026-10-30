<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_forms', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('booking_payment_id')->unique(); // isang consent bawat booking
            $t->unsignedInteger('user_id')->index();
            $t->longText('intake');   // JSON: Psychotherapy Intake Form
            $t->longText('consent');  // JSON: mga tinick na agreement
            $t->longText('dsm5');     // JSON: 23 sagot (0-4)
            $t->string('signed_name', 150);
            $t->date('signed_date');
            $t->boolean('is_minor')->default(false);
            $t->string('guardian_name', 150)->nullable();
            $t->string('guardian_relation', 60)->nullable();
            $t->dateTime('submitted_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_forms');
    }
};