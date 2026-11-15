<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table): void {
            // Every argument in full, as the confirmation card shows it.
            $table->json('details')->nullable()->after('arguments');
            // The full result of a secret read, for the user only; encrypted by the model.
            $table->longText('private_result')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table): void {
            $table->dropColumn(['details', 'private_result']);
        });
    }
};
