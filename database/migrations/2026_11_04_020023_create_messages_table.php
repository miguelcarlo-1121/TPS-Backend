
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();

            $table->unsignedInteger('sender_id');
            $table->unsignedInteger('receiver_id');

            // Connect messages to booking_payments
            $table->unsignedBigInteger('booking_payment_id')->nullable();

            $table->text('message');
            $table->timestamps();

            // Foreign keys
            $table->foreign('sender_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('receiver_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('booking_payment_id')
                ->references('id')->on('booking_payments')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
