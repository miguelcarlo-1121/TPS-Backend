<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // slug — the frontend id: psychotherapy | aba | assessment
            $table->string('slug', 50)->nullable()->unique()->after('code');
            $table->string('title', 150)->nullable()->after('name');
            $table->string('meta', 200)->nullable()->after('title');
            $table->text('description')->nullable()->after('meta');
            $table->json('good_for')->nullable()->after('description');
            $table->json('steps')->nullable()->after('good_for');
            // 'enabled' mirrors is_active for the frontend; we'll keep both
            $table->boolean('enabled')->default(true)->after('is_active');
            $table->timestamps();
        });

        // Backfill slugs for the 3 seeded services
        DB::table('services')->where('code', 'PC')
            ->update(['slug' => 'psychotherapy', 'enabled' => true]);
        DB::table('services')->where('code', 'ABA')
            ->update(['slug' => 'aba', 'enabled' => true]);
        DB::table('services')->where('code', 'AC')
            ->update(['slug' => 'assessment', 'enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['slug', 'title', 'meta', 'description', 'good_for', 'steps', 'enabled', 'created_at', 'updated_at']);
        });
    }
};

