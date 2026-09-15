<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Один запуск парсинга: статус, прогресс для фронта, итоги и причина сбоя.
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            // Ожидания общей паузы после бана: считаются отдельно от попыток, см. SyncOrganization.
            $table->unsignedSmallInteger('blocked_waits')->default(0);
            $table->unsignedSmallInteger('pages_total')->nullable();
            $table->unsignedSmallInteger('pages_done')->default(0);
            $table->unsignedInteger('reviews_fetched')->default(0);
            $table->unsignedInteger('reviews_created')->default(0);
            $table->unsignedInteger('reviews_updated')->default(0);
            $table->unsignedInteger('reviews_removed')->default(0);
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
