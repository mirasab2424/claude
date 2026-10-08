<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('is_public')->default(true)->after('is_admin');
            $table->string('avatar')->nullable();
            $table->string('city')->nullable();
            $table->string('tagline')->nullable();
            $table->text('bio')->nullable();
        });

        // Типы задач: спорт, знание, навык и т.д.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('color', 7)->default('#22c55e');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Цели и задачи (структура из TO_DO.xlsx)
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->string('title');
            $table->string('importance')->default('need');   // must | need | want
            $table->string('horizon')->default('month');     // year | month | week | day
            $table->string('status')->default('planned');    // planned | in_progress | done | failed | dropped
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();          // заметки во время выполнения
            $table->text('retrospective')->nullable();  // комментарии для анализа после
            $table->boolean('is_public')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        // Метрики: вес, шаги, часы чтения... для отслеживания прогресса/регресса
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('unit')->nullable();
            $table->string('direction')->default('up'); // up — больше лучше, down — меньше лучше
            $table->decimal('target', 12, 2)->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('metric_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metric_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('value', 12, 2);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['metric_id', 'date']);
        });

        // Карта посещённых мест (идея из прошлого проекта на October)
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->date('visited_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('description')->nullable();
            $table->string('photo')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
        Schema::dropIfExists('metric_entries');
        Schema::dropIfExists('metrics');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('categories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'is_admin', 'is_public', 'avatar', 'city', 'tagline', 'bio']);
        });
    }
};
