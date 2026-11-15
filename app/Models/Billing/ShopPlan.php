<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Pterodactyl\Models\Model;

/**
 * STAND-IN for Task R (phase 3). Task R owns this model and the `shop_plans` table: on merge,
 * keep Task R's version. Task T only reads the id and name.
 *
 * @property int $id
 * @property string $name
 */
#[Guarded([])]
#[Table(name: 'shop_plans')]
class ShopPlan extends Model {}
