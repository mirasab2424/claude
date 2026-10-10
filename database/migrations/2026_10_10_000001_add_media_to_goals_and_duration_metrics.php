<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('retrospective');
            $table->string('link')->nullable()->after('photo');
        });

        // Метрики-длительности (время бега и т.п.) хранятся в секундах и показываются как мм:сс.
        Schema::table('metrics', function (Blueprint $table) {
            $table->boolean('is_duration')->default(false)->after('direction');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram')->nullable()->after('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('goals', fn (Blueprint $table) => $table->dropColumn(['photo', 'link']));
        Schema::table('metrics', fn (Blueprint $table) => $table->dropColumn('is_duration'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('telegram'));
    }
};
