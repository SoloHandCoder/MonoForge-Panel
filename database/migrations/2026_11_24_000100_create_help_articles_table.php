<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The administrator's changes to the help center. A row whose slug is a shipped article
        // (resources/help/{slug}.md) overrides it: a null column keeps the shipped value, so
        // later updates of the starter text still show. Any other row is an article of its own.
        // Deleting a row resets a shipped article (or removes an added one).
        Schema::create('help_articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug', 64)->unique();
            $table->string('title', 120)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('summary', 300)->nullable();
            $table->mediumText('content')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
    }
};
