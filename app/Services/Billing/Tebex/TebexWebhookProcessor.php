<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing\Tebex;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Billing\TebexCheckout;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\ReferralService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Notices\NoticeService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Applies a verified Tebex payment webhook to the token ledger. Every event is recorded on the
 * transaction's `tebex_payments` row; a payment is credited at most once and a refund or lost
 * dispute takes back at most what was credited.
 *
 * Tokens come from the token pack whose Tebex package ID matches the paid product, never from
 * anything the player sent. The panel user comes from the `custom` data the panel put on the basket.
 * A payment that paid less than the package's price (a coupon, a sale, a different currency) is
 * not credited: it is flagged as underpaid and root administrators get a notice.
 *
 * A dispute takes the tokens back as soon as it is opened (into a negative balance if need be),
 * and a dispute won by the seller gives them back, once each.
 *
 * A referred user's first credited payment starts their referral's hold; a refund, lost dispute
 * or opened dispute of that payment cancels the referral (or takes its rewards back).
 */
readonly class TebexWebhookProcessor
{
    /** Where administrators review Tebex payments. */
    public const string ADMIN_PAYMENTS_PATH = '/panel/shop/token-packs';

    /** Tebex payment status IDs, per the webhook documentation. */
    private const int STATUS_COMPLETE = 1;

    /** Money is compared to the cent. */
    private const float CENT = 0.005;

    public function __construct(private TokenWallet $wallet, private NoticeService $notices, private ReferralService $referrals) {}

    /**
     * @param  array<array-key, mixed>  $event  the decoded, signature-checked webhook body
     */
    public function handle(array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $webhookId = is_string($event['id'] ?? null) ? $event['id'] : '';
        $subject = is_array($event['subject'] ?? null) ? $event['subject'] : [];
        $transactionId = is_string($subject['transaction_id'] ?? null) ? mb_trim($subject['transaction_id']) : '';

        if (! str_starts_with($type, 'payment.') || $transactionId === '' || mb_strlen($transactionId) > 191) {
            // Recurring payments are not sold, and anything else carries no payment to record.
            return;
        }

        $row = TebexPayment::query()->createOrFirst(
            ['transaction_id' => $transactionId],
            ['status' => TebexPayment::STATUS_PENDING, 'raw' => []],
        );

        DB::transaction(function () use ($row, $type, $webhookId, $subject, $event): void {
            /** @var TebexPayment $payment */
            $payment = TebexPayment::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($webhookId !== '' && $payment->hasEvent($webhookId)) {
                return;
            }

            $payment->raw = [...$payment->raw, $event];
            // A new full body is kept until it is old enough to prune again.
            $payment->raw_pruned_at = null;
            $this->recordPrice($payment, $subject);

            match ($type) {
                'payment.completed' => $this->complete($payment, $subject),
                'payment.refunded' => $this->reverse($payment, TebexPayment::STATUS_REFUNDED),
                'payment.dispute.lost' => $this->reverse($payment, TebexPayment::STATUS_CHARGEBACK),
                'payment.dispute.opened' => $this->disputeOpened($payment, $subject),
                'payment.dispute.won' => $this->disputeWon($payment),
                'payment.declined' => $this->declined($payment, $subject),
                default => null,
            };

            $payment->save();
            $this->linkCheckout($payment, $subject);
        });
    }

    /**
     * An administrator's "Approve and credit" for a flagged (underpaid or unmatched) payment: it
     * credits the tokens of its completed webhook through the normal credit path, so the
     * payment's `tokens` are set and a later refund, dispute or chargeback takes them back.
     *
     * - The tokens go to the user the payment matched. `$recipient` is accepted only for a
     *   payment that matched no user, and never the approving administrator.
     * - A payment that does not cover its package price is credited only with `$acceptUnderpaid`.
     * - Approving a payment that is already credited changes nothing and logs nothing; the check
     *   and the activity log run under the payment's row lock, so two admins log once.
     *
     * @return bool whether this call credited the payment
     *
     * @throws ConflictHttpException|UnprocessableEntityHttpException
     */
    public function approve(TebexPayment $payment, User $admin, ?User $recipient = null, bool $acceptUnderpaid = false): bool
    {
        return DB::transaction(function () use ($payment, $admin, $recipient, $acceptUnderpaid): bool {
            /** @var TebexPayment $locked */
            $locked = TebexPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payment->setRawAttributes($locked->getAttributes(), true);
            if ($locked->processed_at !== null) {
                return false;
            }

            throw_unless(in_array($locked->status, [TebexPayment::STATUS_UNDERPAID, TebexPayment::STATUS_UNMATCHED], true), ConflictHttpException::class, 'Only an underpaid or unmatched payment can be approved.');

            $subject = $this->completedSubject($locked);
            throw_if($subject === null, ConflictHttpException::class, 'The details of this payment were pruned; adjust the tokens by hand instead.');

            $matched = $this->user($subject) ?? ($locked->user_id === null ? null : User::query()->find($locked->user_id));
            if ($recipient instanceof User) {
                if ($matched instanceof User) {
                    throw new UnprocessableEntityHttpException(sprintf('This payment belongs to user #%d; its tokens can only be credited to them.', $matched->id));
                }

                throw_if($recipient->id === $admin->id, UnprocessableEntityHttpException::class, 'You cannot credit a payment to yourself.');
            }

            $user = $matched ?? $recipient;
            [$tokens, $names] = $this->tokens($subject);
            throw_unless($user instanceof User, ConflictHttpException::class, 'The payment matched no panel user. Choose the user to credit.');
            throw_if($tokens < 1, ConflictHttpException::class, 'The payment matched no token pack, so there are no tokens to credit.');

            $underpaid = $this->underpaid($subject);
            throw_if($underpaid !== null && ! $acceptUnderpaid, UnprocessableEntityHttpException::class, "This payment does not cover its package price: {$underpaid}. Confirm that you accept it to credit it anyway.");

            $locked->user_id = $user->id;
            $this->credit($locked, $user, $tokens, $names);
            $locked->save();
            $payment->setRawAttributes($locked->getAttributes(), true);

            Activity::event('admin:tebex-payment.approve')
                ->actor($admin)
                ->property([
                    'transaction_id' => $locked->transaction_id,
                    'tokens' => $tokens,
                    'user_id' => $user->id,
                    'chosen_by_admin' => $recipient instanceof User,
                    'flag_reason' => $locked->flag_reason,
                    'accepted_underpaid' => $underpaid,
                ])
                ->log();

            return true;
        });
    }

    /**
     * @param  array<array-key, mixed>  $subject
     */
    private function complete(TebexPayment $payment, array $subject): void
    {
        $status = is_array($subject['status'] ?? null) ? ($subject['status']['id'] ?? null) : null;
        if ($status !== self::STATUS_COMPLETE && $status !== (string) self::STATUS_COMPLETE) {
            return;
        }

        // Already credited, refunded before the completion arrived, or already flagged as underpaid.
        if ($payment->processed_at !== null || in_array($payment->status, [TebexPayment::STATUS_REFUNDED, TebexPayment::STATUS_CHARGEBACK, TebexPayment::STATUS_UNDERPAID], true)) {
            return;
        }

        $user = $this->user($subject);
        [$tokens, $names] = $this->tokens($subject);
        $payment->user_id = $user?->id;

        if (! $user instanceof User || $tokens < 1) {
            $payment->status = TebexPayment::STATUS_UNMATCHED;
            $payment->flagged_at ??= now()->toImmutable();
            $payment->flag_reason = $user instanceof User ? 'it matched no token pack' : 'it matched no panel user';
            // An unmatched payment may also be underpaid; say so, so the admin sees it before approving.
            $underpaid = $this->underpaid($subject);
            if ($underpaid !== null) {
                $payment->flag_reason = mb_substr($payment->flag_reason.'; also '.$underpaid, 0, 255);
            }

            Log::warning('A Tebex payment matched no panel user or token pack; nothing was credited.', ['payment' => $payment->id]);

            return;
        }

        $underpaid = $this->underpaid($subject);
        if ($underpaid !== null) {
            $payment->status = TebexPayment::STATUS_UNDERPAID;
            $payment->flagged_at ??= now()->toImmutable();
            $payment->flag_reason = mb_substr($underpaid, 0, 255);
            Log::warning('A Tebex payment paid less than its package price; nothing was credited.', ['payment' => $payment->id, 'reason' => $underpaid]);
            $this->notifyAdmins(
                'Tebex payment needs a look',
                sprintf('Tebex payment %s was not credited: %s. If it is fine, use "Approve and credit" on it in Shop > Token packs & Tebex, so a later refund or chargeback can take the tokens back.', $payment->transaction_id, $underpaid),
            );

            return;
        }

        $this->credit($payment, $user, $tokens, $names);
    }

    /**
     * The subject of the payment's stored completion webhook, unless it was pruned.
     *
     * @return array<array-key, mixed>|null
     */
    private function completedSubject(TebexPayment $payment): ?array
    {
        foreach (array_reverse($payment->raw) as $event) {
            $subject = $event['subject'] ?? null;
            if (($event['type'] ?? null) !== 'payment.completed' || ! is_array($subject)) {
                continue;
            }

            // Like complete(), only a completion with the "complete" status counts.
            $status = is_array($subject['status'] ?? null) ? ($subject['status']['id'] ?? null) : null;
            if ($status === self::STATUS_COMPLETE || $status === (string) self::STATUS_COMPLETE) {
                return $subject;
            }
        }

        return null;
    }

    /**
     * Credits the payment's tokens once and marks it completed.
     *
     * @param  list<string>  $names
     */
    private function credit(TebexPayment $payment, User $user, int $tokens, array $names): void
    {
        if (! $this->wallet->hasTransaction(TebexPayment::LEDGER_REFERENCE, $payment->transaction_id, 'purchase')) {
            $this->wallet->credit(
                $user,
                $tokens,
                'purchase',
                'Bought '.implode(', ', $names).' on Tebex',
                TebexPayment::LEDGER_REFERENCE,
                $payment->transaction_id,
                ['amount' => $payment->amount, 'currency' => $payment->currency],
            );
        }

        $payment->status = TebexPayment::STATUS_COMPLETED;
        $payment->tokens = $tokens;
        $payment->processed_at = now()->toImmutable();

        $this->referrals->paymentCredited($payment, $user);

        $this->notices->notify(
            $user,
            'success',
            'Tokens added',
            number_format($tokens).' tokens were added to your wallet. Thank you for your purchase!',
        );
    }

    private function reverse(TebexPayment $payment, string $status): void
    {
        $payment->status = $status;
        $payment->flagged_at ??= now()->toImmutable();
        $this->referrals->paymentReversed($payment, $status === TebexPayment::STATUS_REFUNDED ? 'refunded' : 'charged back');

        $owed = $payment->tokens - $payment->tokens_reversed;
        $user = $payment->user_id === null ? null : User::query()->find($payment->user_id);
        if ($owed < 1 || ! $user instanceof User) {
            return;
        }

        $type = $status === TebexPayment::STATUS_REFUNDED ? 'refund' : 'chargeback';
        if (! $this->wallet->hasTransaction(TebexPayment::LEDGER_REFERENCE, $payment->transaction_id, $type)) {
            $this->wallet->debit(
                $user,
                $owed,
                $type,
                $type === 'refund' ? 'Tebex payment refunded' : 'Tebex payment charged back',
                TebexPayment::LEDGER_REFERENCE,
                $payment->transaction_id,
                [],
                true,
            );
        }

        $payment->tokens_reversed += $owed;

        $this->notices->notify(
            $user,
            'danger',
            $type === 'refund' ? 'Payment refunded' : 'Payment charged back',
            number_format($owed).' tokens were removed from your wallet because a Tebex payment was '.($type === 'refund' ? 'refunded' : 'charged back').'.',
            null,
            'Open wallet',
            TebexSettings::WALLET_PATH,
        );
    }

    /**
     * @param  array<array-key, mixed>  $subject
     */
    private function disputeOpened(TebexPayment $payment, array $subject): void
    {
        $payment->user_id ??= $this->user($subject)?->id;
        $payment->flagged_at ??= now()->toImmutable();
        if ($payment->status === TebexPayment::STATUS_REFUNDED || $payment->status === TebexPayment::STATUS_CHARGEBACK) {
            return;
        }

        $payment->status = TebexPayment::STATUS_DISPUTED;
        $this->referrals->paymentReversed($payment, 'disputed');

        // The tokens are held back while the dispute is open, like a refund.
        $owed = $payment->tokens - $payment->tokens_reversed;
        $user = $payment->user_id === null ? null : User::query()->find($payment->user_id);
        $reference = $this->disputeReference($payment);
        if ($owed < 1 || ! $user instanceof User || $this->wallet->hasTransaction(TebexPayment::LEDGER_REFERENCE, $reference, TokenTransaction::TYPE_CHARGEBACK)) {
            return;
        }

        $this->wallet->debit($user, $owed, TokenTransaction::TYPE_CHARGEBACK, 'Tebex payment disputed: tokens held until the dispute is settled', TebexPayment::LEDGER_REFERENCE, $reference, [], true);
        $payment->tokens_reversed += $owed;

        $this->notices->notify(
            $user,
            'danger',
            'Payment disputed',
            number_format($owed).' tokens were held back from your wallet because a Tebex payment is disputed. They come back if the dispute is settled in our favour.',
            null,
            'Open wallet',
            TebexSettings::WALLET_PATH,
        );
    }

    private function disputeWon(TebexPayment $payment): void
    {
        if ($payment->status !== TebexPayment::STATUS_DISPUTED) {
            return;
        }

        $payment->status = $payment->processed_at === null ? TebexPayment::STATUS_PENDING : TebexPayment::STATUS_COMPLETED;

        // Give back what the opened dispute held, once.
        $reference = $this->disputeReference($payment);
        $held = TokenTransaction::query()
            ->where('reference_type', TebexPayment::LEDGER_REFERENCE)
            ->where('reference_id', $reference)
            ->where('type', TokenTransaction::TYPE_CHARGEBACK)
            ->value('amount');
        $tokens = is_numeric($held) ? -(int) $held : 0;
        $user = $payment->user_id === null ? null : User::query()->find($payment->user_id);
        if ($tokens < 1 || ! $user instanceof User || $this->wallet->hasTransaction(TebexPayment::LEDGER_REFERENCE, $reference, TokenTransaction::TYPE_CORRECTION)) {
            return;
        }

        $this->wallet->credit($user, $tokens, TokenTransaction::TYPE_CORRECTION, 'Tebex dispute settled in our favour: held tokens returned', TebexPayment::LEDGER_REFERENCE, $reference);
        $payment->tokens_reversed = max(0, $payment->tokens_reversed - $tokens);

        $this->notices->notify(
            $user,
            'success',
            'Dispute settled',
            number_format($tokens).' held tokens were returned to your wallet.',
            null,
            'Open wallet',
            TebexSettings::WALLET_PATH,
        );
    }

    /** The ledger reference of a dispute's hold and its return, kept apart from a refund or chargeback. */
    private function disputeReference(TebexPayment $payment): string
    {
        return $payment->transaction_id.'#dispute';
    }

    /**
     * Why the payment does not cover its packages' price, or null when it does.
     *
     * `base_price` is the package price for one unit. Whether a product's `paid_price` is per
     * unit or the line total is not documented, so a line passes outright only when it covers
     * `base_price × quantity` read as the line total, and fails outright when even the per-unit
     * reading falls short. In between it is ambiguous, and the payment's own total
     * (`price_paid`, the money taken) decides: it must always cover `base_price × quantity` of
     * every product. Every amount must be in the payment's currency.
     *
     * @param  array<array-key, mixed>  $subject
     */
    private function underpaid(array $subject): ?string
    {
        $paid = $this->money($subject['price_paid'] ?? null);
        if ($paid === null) {
            return 'Tebex did not report the amount paid';
        }

        $required = 0.0;
        $ambiguous = false;
        foreach (is_array($subject['products'] ?? null) ? $subject['products'] : [] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $base = $this->money($product['base_price'] ?? null);
            $productPaid = $this->money($product['paid_price'] ?? null);
            $quantity = $product['quantity'] ?? 1;
            if ($base === null || $productPaid === null || ! is_int($quantity) || $quantity < 1) {
                return 'Tebex did not report the package price or quantity';
            }

            if ($productPaid[1] !== $base[1] || $paid[1] !== $base[1]) {
                return sprintf('it was paid in %s, but the package is priced in %s', $paid[1] !== $base[1] ? $paid[1] : $productPaid[1], $base[1]);
            }

            $line = $base[0] * $quantity;
            if ($line > $productPaid[0] * $quantity + self::CENT) {
                return sprintf('%s %s was paid for %d × a package priced %s %s', number_format($productPaid[0], 2), $productPaid[1], $quantity, number_format($base[0], 2), $base[1]);
            }

            $ambiguous = $ambiguous || $line > $productPaid[0] + self::CENT;
            $required += $line;
        }

        if ($required > $paid[0] + self::CENT) {
            return sprintf('%s %s was paid in total for packages priced %s %s%s', number_format($paid[0], 2), $paid[1], number_format($required, 2), $paid[1], $ambiguous ? ' (whether the paid price is per package or per line is ambiguous)' : '');
        }

        return null;
    }

    /**
     * An `{amount, currency}` object of the webhook.
     *
     * @return array{float, string}|null
     */
    private function money(mixed $price): ?array
    {
        if (! is_array($price)) {
            return null;
        }

        $amount = $price['amount'] ?? null;
        $currency = $price['currency'] ?? null;
        if (! (is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount))) || ! is_string($currency) || $currency === '') {
            return null;
        }

        return [(float) $amount, mb_strtoupper($currency)];
    }

    private function notifyAdmins(string $title, string $body): void
    {
        User::query()->where('root_admin', true)->each(function (User $admin) use ($title, $body): void {
            $this->notices->notify($admin, 'warning', $title, $body, null, 'Open Tebex payments', self::ADMIN_PAYMENTS_PATH);
        });
    }

    /**
     * @param  array<array-key, mixed>  $subject
     */
    private function declined(TebexPayment $payment, array $subject): void
    {
        if ($payment->status === TebexPayment::STATUS_PENDING) {
            $payment->status = TebexPayment::STATUS_DECLINED;
            $payment->user_id ??= $this->user($subject)?->id;
        }
    }

    /**
     * @param  array<array-key, mixed>  $subject
     */
    private function recordPrice(TebexPayment $payment, array $subject): void
    {
        $price = is_array($subject['price_paid'] ?? null) ? $subject['price_paid'] : (is_array($subject['price'] ?? null) ? $subject['price'] : []);
        $amount = $price['amount'] ?? null;
        $currency = $price['currency'] ?? null;

        if (is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount))) {
            $payment->amount = number_format((float) $amount, 2, '.', '');
        }

        if (is_string($currency) && $currency !== '') {
            $payment->currency = mb_substr($currency, 0, 8);
        }
    }

    /**
     * Links the payment to the panel checkout named in the basket's custom data, so the
     * player's return page can follow it. Only a checkout of the user the custom data names,
     * and not yet linked to another payment, is linked.
     *
     * @param  array<array-key, mixed>  $subject
     */
    private function linkCheckout(TebexPayment $payment, array $subject): void
    {
        foreach ($this->customs($subject) as $custom) {
            $reference = $custom[TebexCheckout::CUSTOM_KEY] ?? null;
            $userId = $custom['user_id'] ?? null;
            if (! is_string($reference) || ! Str::isUuid($reference) || ! is_int($userId) && (! is_string($userId) || ! ctype_digit($userId))) {
                continue;
            }

            TebexCheckout::query()
                ->where('uuid', $reference)
                ->where('user_id', (int) $userId)
                ->whereNull('tebex_payment_id')
                ->update(['tebex_payment_id' => $payment->id, 'updated_at' => now()]);

            return;
        }
    }

    /**
     * The basket's custom data, from the products first, then from the payment itself.
     *
     * @param  array<array-key, mixed>  $subject
     * @return list<array<array-key, mixed>>
     */
    private function customs(array $subject): array
    {
        $customs = [];
        foreach (is_array($subject['products'] ?? null) ? $subject['products'] : [] as $product) {
            if (is_array($product) && is_array($product['custom'] ?? null)) {
                $customs[] = $product['custom'];
            }
        }

        if (is_array($subject['custom'] ?? null)) {
            $customs[] = $subject['custom'];
        }

        return $customs;
    }

    /**
     * The panel user from the basket's custom data, on the products or on the payment itself.
     *
     * @param  array<array-key, mixed>  $subject
     */
    private function user(array $subject): ?User
    {
        foreach ($this->customs($subject) as $custom) {
            $id = $custom['user_id'] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                return User::query()->find((int) $id);
            }
        }

        return null;
    }

    /**
     * The tokens bought: each product matched to a token pack by its Tebex package ID, times its quantity.
     *
     * @param  array<array-key, mixed>  $subject
     * @return array{int, list<string>}
     */
    private function tokens(array $subject): array
    {
        $tokens = 0;
        $names = [];

        foreach (is_array($subject['products'] ?? null) ? $subject['products'] : [] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $id = $product['id'] ?? null;
            $quantity = $product['quantity'] ?? 1;
            if (! is_int($id) && (! is_string($id) || $id === '') || ! is_int($quantity) || $quantity < 1) {
                continue;
            }

            $pack = TokenPack::query()->where('tebex_package_id', (string) $id)->orderBy('id')->first();
            if ($pack instanceof TokenPack) {
                $tokens += $pack->totalTokens() * $quantity;
                $names[] = ($quantity > 1 ? $quantity.' × ' : '').$pack->name;
            }
        }

        return [$tokens, $names];
    }
}
