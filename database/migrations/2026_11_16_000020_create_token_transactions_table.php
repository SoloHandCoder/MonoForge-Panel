<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Denormalised from token_transactions; only TokenWallet changes it, in the same transaction as the ledger row.
            $table->bigInteger('token_balance')->default(0)->after('gravatar');
        });

        Schema::create('token_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->enum('type', ['purchase', 'refund', 'chargeback', 'server_charge', 'addon_charge', 'ai_usage', 'admin_adjust', 'correction']);
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'id']);
            $table->index(['reference_type', 'reference_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_transactions');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('token_balance');
        });
    }
};
