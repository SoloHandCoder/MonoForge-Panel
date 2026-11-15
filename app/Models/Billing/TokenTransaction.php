<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Pterodactyl\Models\Model;

/**
 * STAND-IN for Task Q (phase 3). Task Q owns this model and its `token_transactions` table:
 * on merge, keep Task Q's version. The stand-in is never saved.
 *
 * @property int $user_id
 * @property int $amount
 * @property int $balance_after
 * @property string $type
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string $description
 */
#[Fillable(['user_id', 'amount', 'balance_after', 'type', 'reference_type', 'reference_id', 'description', 'meta'])]
class TokenTransaction extends Model {}
