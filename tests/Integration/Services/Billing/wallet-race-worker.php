<?php

declare(strict_types=1);

/*
 * A separate PHP process for TokenWalletConcurrencyTest: boots the panel from this checkout and
 * debits a user once, printing the outcome as JSON. Usage: php wallet-race-worker.php <user id> <tokens>
 */

use Illuminate\Contracts\Console\Kernel;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;

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
    $transaction = resolve(TokenWallet::class)->debit($user, (int) ($argv[2] ?? 0), TokenTransaction::TYPE_SERVER_CHARGE, 'Race debit');
    echo json_encode(['ok' => true, 'balance_after' => $transaction->balance_after]);
} catch (InsufficientTokensException $insufficientTokensException) {
    echo json_encode(['ok' => false, 'balance' => $insufficientTokensException->balance]);
}
