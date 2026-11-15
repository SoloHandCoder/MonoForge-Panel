<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_addons', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('resource', ['backups', 'databases', 'allocations', 'memory', 'disk', 'cpu']);
            // How much of the resource one unit adds (MB for memory and disk, percent for cpu).
            $table->unsignedInteger('amount');
            // Tokens per 30 days for one unit.
            $table->unsignedInteger('price_tokens');
            $table->unsignedInteger('max_units')->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_addons');
    }
};
