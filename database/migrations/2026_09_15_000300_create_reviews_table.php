<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->text('author_name');
            $table->string('author_avatar_url', 1024)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('text');
            $table->text('business_reply')->nullable();
            $table->timestamp('published_at');
            $table->timestamp('first_seen_at');
            // Отзыв пропал из выдачи Яндекса (удалён автором или модерацией). Строку не удаляем, это история.
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
            // Под постраничную выдачу: WHERE organization_id = ? AND removed_at IS NULL ORDER BY published_at DESC.
            $table->index(['organization_id', 'removed_at', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
