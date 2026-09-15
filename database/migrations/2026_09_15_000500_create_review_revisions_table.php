<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал изменений отзыва: автор поправил текст или оценку, организация ответила,
        // отзыв пропал из выдачи или вернулся. changes = {поле: {old, new}}.
        Schema::create('review_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            // Дубль organization_id: лента истории и подсчёты идут по организации,
            // а через отзывы это перебор всей таблицы ревизий.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 20);
            $table->json('changes');
            $table->timestamp('created_at');

            $table->index(['review_id', 'id']);
            $table->index(['organization_id', 'id']);
            // Итоги запуска считаются по его ревизиям.
            $table->index(['sync_run_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_revisions');
    }
};
