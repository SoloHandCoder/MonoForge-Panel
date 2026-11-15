<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Notifications\SubscriptionBillingNotification;
use Pterodactyl\Services\Notices\NoticeService;
use Throwable;

/**
 * Warns a shop server's owner in the panel (NoticeService) and by email. Each warning is sent
 * once per subscription and period, so the scheduler can run as often as it likes.
 */
class BillingNotifier
{
    public const string RENEWAL_SHORT = 'renewal_short';

    public const string SUSPENDED = 'suspended';

    public const string DELETION_SOON = 'deletion_soon';

    public const string DELETED = 'deleted';

    public const string RESTORED = 'restored';

    /** The client Wallet page (Task Q), where tokens are bought. */
    public const string WALLET_PATH = '/wallet';

    /** The client Store page (Task S). */
    public const string STORE_PATH = '/store';

    public function __construct(private readonly NoticeService $notices) {}

    /**
     * "6d 23h" until the moment, never negative.
     */
    public static function remaining(CarbonInterface $until): string
    {
        $seconds = max(0, $until->getTimestamp() - CarbonImmutable::now()->getTimestamp());
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    /**
     * Before a renewal: the balance does not cover it.
     */
    public function renewalShort(ServerSubscription $subscription, int $balance): bool
    {
        if (! $this->claim($subscription, self::RENEWAL_SHORT, $subscription->periodKey())) {
            return false;
        }

        $name = $subscription->server->name;
        $when = $this->date($subscription->next_renewal_at);
        $body = "{$name} renews on {$when} for {$subscription->price_tokens} tokens, but your balance is {$balance} tokens. Top up before then or the server is suspended.";

        $this->notifyUser($subscription->user, $subscription->id, self::RENEWAL_SHORT, 'warning', 'Balance too low for a renewal', $body, 'Top up', self::WALLET_PATH, "Your balance is too low to renew {$name}");

        return true;
    }

    /**
     * The renewal was not paid and the server is suspended until it is, or until `delete_at`.
     */
    public function suspended(ServerSubscription $subscription): bool
    {
        $deleteAt = $subscription->delete_at ?? CarbonImmutable::now();
        if (! $this->claim($subscription, self::SUSPENDED, $this->stamp($deleteAt))) {
            return false;
        }

        $name = $subscription->server->name;
        $body = "{$name} was suspended because its renewal of {$subscription->price_tokens} tokens could not be paid. "
            .'It will be deleted in '.self::remaining($deleteAt).' ('.$this->date($deleteAt).'). Top up and renew to keep it.';

        $this->notifyUser($subscription->user, $subscription->id, self::SUSPENDED, 'danger', 'Server suspended: renewal unpaid', $body, 'Renew', $this->serverPath($subscription), "{$name} is suspended");

        return true;
    }

    /**
     * About a day before a suspended server is deleted.
     */
    public function deletionSoon(ServerSubscription $subscription): bool
    {
        $deleteAt = $subscription->delete_at ?? CarbonImmutable::now();
        if (! $this->claim($subscription, self::DELETION_SOON, $this->stamp($deleteAt))) {
            return false;
        }

        $name = $subscription->server->name;
        $body = "{$name} is deleted in ".self::remaining($deleteAt).' ('.$this->date($deleteAt).'), with all of its files. Renew it now to keep it.';

        $this->notifyUser($subscription->user, $subscription->id, self::DELETION_SOON, 'danger', 'Server deleted tomorrow', $body, 'Renew', $this->serverPath($subscription), "{$name} is deleted tomorrow");

        return true;
    }

    /**
     * The server was deleted after its grace period. The subscription row is gone by now, so this
     * takes what the message needs.
     */
    public function deleted(User $user, int $subscriptionId, string $serverName): void
    {
        $body = "{$serverName} was deleted because its renewal was not paid.";

        $this->notifyUser($user, $subscriptionId, self::DELETED, 'danger', 'Server deleted', $body, 'Store', self::STORE_PATH, "{$serverName} was deleted");
    }

    /**
     * A suspended server's renewal was paid, so the server is back. Clears its billing warnings.
     */
    public function restored(ServerSubscription $subscription): void
    {
        $this->clear($subscription);

        $this->notices->notify(
            $subscription->user,
            'success',
            'Server restored',
            "{$subscription->server->name} is renewed until ".$this->date($subscription->next_renewal_at).'.',
            $this->kind($subscription->id, self::RESTORED),
            'Open server',
            $this->serverPath($subscription),
        );
    }

    /**
     * Marks the subscription's unread billing warnings read, once it is paid.
     */
    public function clear(ServerSubscription $subscription): void
    {
        $kinds = array_map(fn (string $event): string => $this->kind($subscription->id, $event), [self::RENEWAL_SHORT, self::SUSPENDED, self::DELETION_SOON]);

        UserNotice::query()
            ->where('user_id', $subscription->user_id)
            ->whereNull('read_at')
            ->whereIn('kind', $kinds)
            ->update(['read_at' => CarbonImmutable::now()]);
    }

    /**
     * Records the warning; true only for the first claim of a subscription, kind and period.
     */
    private function claim(ServerSubscription $subscription, string $kind, string $periodKey): bool
    {
        return DB::table('server_subscription_warnings')->insertOrIgnore([
            'server_subscription_id' => $subscription->id,
            'kind' => $kind,
            'period_key' => $periodKey,
            'created_at' => CarbonImmutable::now(),
        ]) === 1;
    }

    private function notifyUser(User $user, int $subscriptionId, string $event, string $level, string $title, string $body, string $actionLabel, string $actionPath, string $subject): void
    {
        $this->notices->notify($user, $level, $title, $body, $this->kind($subscriptionId, $event), $actionLabel, $actionPath);

        try {
            $user->notify(new SubscriptionBillingNotification($event, $user->username, $subject, [$body], $actionLabel, $actionPath));
        } catch (Throwable $throwable) {
            // The panel notice is already shown; a mail problem must not stop billing.
            report($throwable);
        }
    }

    private function kind(int $subscriptionId, string $event): string
    {
        return "billing.{$event}.{$subscriptionId}";
    }

    private function serverPath(ServerSubscription $subscription): string
    {
        return '/server/'.$subscription->server->uuidShort;
    }

    private function stamp(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function date(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->utc()->format('M j, Y H:i').' UTC';
    }
}
