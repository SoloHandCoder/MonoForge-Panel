<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Support\Billing;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the phase 3 classes and tables the store tests need available while the tasks that
 * own them are not merged yet. With the real migrations and classes present it does nothing.
 *
 * The tables are created on a second connection: DDL commits implicitly, and on the test's
 * own connection it would end the transaction every test runs in.
 */
final class BillingStandIns
{
    public static function ensure(): void
    {
        require_once __DIR__.'/phase3-stand-ins.php';

        $connection = config('database.default');
        if (! is_string($connection)) {
            return;
        }

        config(['database.connections.stand_in_ddl' => config("database.connections.$connection")]);
        $schema = Schema::connection('stand_in_ddl');

        if (! $schema->hasColumn('users', 'token_balance')) {
            $schema->table('users', fn (Blueprint $table) => $table->bigInteger('token_balance')->default(0));
        }

        self::create($schema, 'token_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('type');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        self::create($schema, 'shop_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('visible')->default(true);
            $table->integer('sort')->default(0);
            $table->integer('price_tokens');
            foreach (['memory', 'swap', 'disk', 'cpu', 'databases', 'backups', 'allocations'] as $column) {
                $table->integer($column)->default(0);
            }

            $table->integer('io')->default(500);
            $table->json('egg_ids');
            $table->json('node_ids')->nullable();
            $table->timestamps();
        });
        self::create($schema, 'shop_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('days');
            $table->integer('price_modifier_percent')->default(0);
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
        });
        self::create($schema, 'shop_multipliers', function (Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->unsignedInteger('scope_id');
            $table->decimal('multiplier', 5, 2);
            $table->string('label')->nullable();
        });
        self::create($schema, 'shop_addons', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('resource');
            $table->integer('amount');
            $table->integer('price_tokens');
            $table->integer('max_units')->default(0);
            $table->boolean('enabled')->default(true);
        });
        self::create($schema, 'server_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('period_id')->nullable();
            $table->integer('period_days');
            $table->integer('price_tokens');
            $table->json('addons')->nullable();
            $table->string('status');
            $table->dateTime('next_renewal_at');
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('delete_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamps();
        });

        DB::purge('stand_in_ddl');
    }

    private static function create(Builder $schema, string $table, Closure $definition): void
    {
        if (! $schema->hasTable($table)) {
            $schema->create($table, $definition);
        }
    }
}
