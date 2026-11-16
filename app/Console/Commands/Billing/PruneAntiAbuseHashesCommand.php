<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The anti-abuse hashes (keyed hashes of a network or a linked Discord/Google account) are
 * personal data: they are kept for RETENTION_MONTHS, as the Privacy Policy says, then cleared.
 * The rows themselves stay (a trial, a redemption, a referral), only the hashes go.
 */
#[Description('Clears the network and linked-account hashes kept against free trial, coupon and referral abuse once they are 12 months old.')]
#[Signature('p:billing:prune-abuse-hashes')]
class PruneAntiAbuseHashesCommand extends Command
{
    public const int RETENTION_MONTHS = 12;

    /** @var array<string, list<string>> the hash columns of each table, cleared by the row's created_at */
    public const array COLUMNS = [
        'users' => ['signup_subnet_hash'],
        'shop_trials' => ['subnet_hash', 'signup_subnet_hash', 'discord_hash', 'google_hash'],
        'coupon_redemptions' => ['network_hash', 'discord_hash', 'google_hash'],
        'referrals' => ['network_hash', 'discord_hash', 'google_hash'],
    ];

    public function handle(): int
    {
        $before = CarbonImmutable::now()->subMonths(self::RETENTION_MONTHS);

        foreach (self::COLUMNS as $table => $columns) {
            $cleared = DB::table($table)
                ->where('created_at', '<', $before)
                ->where(function (Builder $query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->update(array_fill_keys($columns, null));

            $this->components->twoColumnDetail($table, (string) $cleared);
        }

        return self::SUCCESS;
    }
}
