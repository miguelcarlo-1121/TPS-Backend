<?php

namespace Tests\Feature;

use App\Models\ClientProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientProfileBookingHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('client_profiles');
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
        });
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
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

    protected function tearDown(): void
    {
        Schema::dropIfExists('client_profiles');
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_a_client_can_have_a_separate_profile_record_for_each_booking(): void
    {
        DB::table('users')->insert(['id' => 1]);
        DB::table('booking_payments')->insert(['id' => 10, 'user_id' => 1]);
        DB::table('client_profiles')->insert([
            'user_id' => 1,
            'full_name' => 'Mark Cruz',
            'birthdate' => '2004-05-12',
            'email' => 'mark@example.test',
            'mobile' => '09384756384',
            'emergency_name' => 'Emergency Contact',
            'emergency_relation' => 'Parent',
            'emergency_phone' => '09385734756',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_12_171600_allow_multiple_client_profiles_per_booking.php');
        $migration->up();

        $this->assertSame(
            10,
            ClientProfile::where('user_id', 1)->value('booking_payment_id')
        );

        foreach ([101, 102, 103] as $holdId) {
            DB::table('client_profiles')->insert([
                'user_id' => 1,
                'slot_hold_id' => $holdId,
                'full_name' => 'Mark Cruz',
                'birthdate' => '2004-05-12',
                'email' => 'mark@example.test',
                'mobile' => '09384756384',
                'emergency_name' => 'Emergency Contact',
                'emergency_relation' => 'Parent',
                'emergency_phone' => '09385734756',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $profiles = ClientProfile::where('user_id', 1)->orderBy('slot_hold_id')->get();

        $this->assertCount(4, $profiles);
        $this->assertSame([null, 101, 102, 103], $profiles->pluck('slot_hold_id')->all());
    }
}
