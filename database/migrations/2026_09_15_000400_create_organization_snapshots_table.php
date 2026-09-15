<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Снимок карточки после каждого успешного парсинга. По разнице соседних снимков видно, что было и что стало.
        Schema::create('organization_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('address')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count');
            $table->unsignedInteger('reviews_count');
            $table->timestamp('captured_at');

            $table->index(['organization_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
    }
};
