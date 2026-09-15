<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Площадка: пока только yandex, но 2ГИС ляжет в эту же таблицу.
            $table->string('source', 20)->default('yandex');
            $table->string('external_id', 32);
            $table->string('url', 2048);
            $table->text('name')->nullable();
            $table->text('address')->nullable();
            // Текущие значения дублируют последний снимок, чтобы не джойнить снимки на каждый запрос.
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Повторное подключение той же карточки не плодит дубли.
            $table->unique(['user_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
