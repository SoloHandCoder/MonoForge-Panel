<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing\Tebex;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Notices\NoticeService;

/**
 * Applies a verified Tebex payment webhook to the token ledger. Every event is recorded on the
 * transaction's `tebex_payments` row; a payment is credited at most once and a refund or lost
 * dispute takes back at most what was credited.
 *
 * Tokens come from the token pack whose Tebex package ID matches the paid product, never from
 * anything the player sent. The panel user comes from the `custom` data the panel put on the basket.
 */
readonly class TebexWebhookProcessor
{
    /** Tebex payment status IDs, per the webhook documentation. */
    private const int STATUS_COMPLETE = 1;

    public function __construct(private TokenWallet $wallet, private NoticeService $notices) {}

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

        // Already credited, or refunded before the completion arrived.
        if ($payment->processed_at !== null || in_array($payment->status, [TebexPayment::STATUS_REFUNDED, TebexPayment::STATUS_CHARGEBACK], true)) {
            return;
        }

        $user = $this->user($subject);
        [$tokens, $names] = $this->tokens($subject);
        $payment->user_id = $user?->id;

        if (! $user instanceof User || $tokens < 1) {
            $payment->status = TebexPayment::STATUS_UNMATCHED;
            $payment->flagged_at ??= now()->toImmutable();
            Log::warning('A Tebex payment matched no panel user or token pack; nothing was credited.', ['payment' => $payment->id]);

            return;
        }

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
        if ($payment->status !== TebexPayment::STATUS_REFUNDED && $payment->status !== TebexPayment::STATUS_CHARGEBACK) {
            $payment->status = TebexPayment::STATUS_DISPUTED;
        }

        $payment->flagged_at ??= now()->toImmutable();
    }

    private function disputeWon(TebexPayment $payment): void
    {
        if ($payment->status === TebexPayment::STATUS_DISPUTED) {
            $payment->status = $payment->processed_at === null ? TebexPayment::STATUS_PENDING : TebexPayment::STATUS_COMPLETED;
        }
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
     * The panel user from the basket's custom data, on the products or on the payment itself.
     *
     * @param  array<array-key, mixed>  $subject
     */
    private function user(array $subject): ?User
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

        foreach ($customs as $custom) {
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
