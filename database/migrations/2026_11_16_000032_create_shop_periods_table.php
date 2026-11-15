<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_periods', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('days');
            // -20 is a 20% discount, +10 a 10% surcharge.
            $table->integer('price_modifier_percent')->default(0);
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_periods');
    }
};
