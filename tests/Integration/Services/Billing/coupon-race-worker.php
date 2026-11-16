<?php

declare(strict_types=1);

/*
 * A separate PHP process for CouponConcurrencyTest: boots the panel from this checkout and
 * redeems a free-tokens coupon once, printing the outcome as JSON.
 * Usage: php coupon-race-worker.php <user id> <code> <ip>
 */

use Illuminate\Contracts\Console\Kernel;
use Pterodactyl\Exceptions\Billing\CouponException;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\CouponService;

$root = str_replace('\\', '/', dirname(__DIR__, 4));
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $root;

$loader = require $root.'/vendor/autoload.php';
// vendor/ may be shared with another checkout, so load this checkout's app/ first.
$classes = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
        $relative = mb_substr(str_replace('\\', '/', $file->getPathname()), mb_strlen($root.'/app/'), -4);
        $classes['Pterodactyl\\'.str_replace('/', '\\', $relative)] = $file->getPathname();
    }
}

$loader->addClassMap($classes);
$loader->register(true);

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->findOrFail((int) ($argv[1] ?? 0));

try {
    $redemption = resolve(CouponService::class)->redeemTokens($user, $argv[2] ?? '', $argv[3] ?? '');
    echo json_encode(['ok' => true, 'tokens' => $redemption->tokens]);
} catch (CouponException $couponException) {
    echo json_encode(['ok' => false, 'message' => $couponException->getMessage()]);
}
