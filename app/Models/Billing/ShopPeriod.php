<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Pterodactyl\Models\Model;

/**
 * STAND-IN for Task R (phase 3). Task R owns this model and the `shop_periods` table: on merge,
 * keep Task R's version. Task T only reads the id, name and days.
 *
 * @property int $id
 * @property string $name
 * @property int $days
 */
#[Guarded([])]
#[Table(name: 'shop_periods')]
class ShopPeriod extends Model {}
