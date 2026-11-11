<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('services', 'meta')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('services', 'meta')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('meta', 200)->nullable()->after('title');
            });
        }
    }
};
