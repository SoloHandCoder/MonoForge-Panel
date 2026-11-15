<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_multipliers', function (Blueprint $table): void {
            $table->increments('id');
            $table->enum('scope', ['location', 'node']);
            // A location or node id; not a foreign key because it points at either table.
            $table->unsignedInteger('scope_id');
            $table->decimal('multiplier', 5, 2);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_multipliers');
    }
};
