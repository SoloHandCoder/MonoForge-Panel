<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/**
 * Approving a flagged referral lets it mint its rewards, so like approving a Tebex payment it
 * is limited to root administrators (no delegated permission grants it).
 */
class ReviewReferralRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [];
    }
}
