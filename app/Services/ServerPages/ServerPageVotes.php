<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\Share\ServerPageVote;

/**
 * One vote per visitor per page every 24 hours. A visitor is known by their address and by their
 * visitor cookie, and either one having voted in the window is enough to refuse: clearing the
 * cookie does not vote again from the same connection, and a new connection with the old cookie
 * does not either. Votes run one at a time per page, under a lock on its row.
 */
final readonly class ServerPageVotes
{
    /**
     * @return array{counted: bool, votes: int, next_vote_at: CarbonImmutable}
     */
    public function vote(ServerPage $page, ServerPageVisitor $visitor): array
    {
        $scope = (string) $page->id;
        $address = $visitor->addressKey('vote', $scope);
        $cookie = $visitor->cookieKey('vote', $scope);

        return DB::transaction(function () use ($page, $address, $cookie): array {
            $locked = ServerPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            $last = ServerPageVote::query()
                ->where('server_page_id', $locked->id)
                ->where('voted_at', '>', $now->subHours(ServerPageVote::COOLDOWN_HOURS))
                ->where(fn (Builder $query): Builder => $query->where('address_hash', $address)->orWhere('cookie_hash', $cookie))
                ->latest('voted_at')
                ->first();

            if ($last instanceof ServerPageVote) {
                return [
                    'counted' => false,
                    'votes' => $locked->votes,
                    'next_vote_at' => $last->voted_at->addHours(ServerPageVote::COOLDOWN_HOURS),
                ];
            }

            ServerPageVote::query()->create([
                'server_page_id' => $locked->id,
                'address_hash' => $address,
                'cookie_hash' => $cookie,
                'voted_at' => $now,
            ]);
            $locked->increment('votes');

            return ['counted' => true, 'votes' => $locked->votes, 'next_vote_at' => $now->addHours(ServerPageVote::COOLDOWN_HOURS)];
        });
    }
}
