<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row a minute for the panel and for each node on the public status page.
        // `target` is "panel" or "node:{id}"; rows go after 90 days.
        Schema::create('status_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('target', 32);
            $table->boolean('ok');
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('checked_at');

            $table->index(['target', 'checked_at']);
            $table->index('checked_at');
        });

        // The same checks summed per target and day, so the 90-day bars read 90 rows, not 130 000.
        Schema::create('status_days', function (Blueprint $table): void {
            $table->id();
            $table->string('target', 32);
            $table->date('date');
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedBigInteger('response_ms_total')->default(0);
            $table->unsignedInteger('response_samples')->default(0);

            $table->unique(['target', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_days');
        Schema::dropIfExists('status_checks');
    }
};
