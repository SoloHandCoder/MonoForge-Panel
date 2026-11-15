<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_plans', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('visible')->default(true);
            $table->integer('sort')->default(0);
            // Tokens per 30 days, before the period modifier and the node or location multiplier.
            $table->unsignedInteger('price_tokens');
            $table->unsignedInteger('memory');
            $table->unsignedInteger('swap')->default(0);
            $table->unsignedInteger('disk');
            // Percent of one thread: 100 = 1 thread.
            $table->unsignedInteger('cpu');
            $table->unsignedInteger('io')->default(500);
            $table->unsignedInteger('databases')->default(0);
            $table->unsignedInteger('backups')->default(0);
            // Extra ports on top of the server's primary allocation.
            $table->unsignedInteger('allocations')->default(0);
            $table->json('egg_ids');
            // Null allows any deployable node.
            $table->json('node_ids')->nullable();
            $table->timestamps();

            // Plans keep their category; the admin moves or deletes the plans first.
            $table->foreign('category_id')->references('id')->on('shop_categories')->restrictOnDelete();
            $table->index(['category_id', 'visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_plans');
    }
};
