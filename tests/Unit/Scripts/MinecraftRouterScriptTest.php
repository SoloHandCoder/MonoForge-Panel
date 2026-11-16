<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Scripts\MinecraftRouterScriptTest;

/** scripts/monoforge/mc-router.sh, read without booting the app. */
function routerScript(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/scripts/monoforge/mc-router.sh');
}

test('the router script keeps the daemon token off command lines and out of its output', function (): void {
    $script = routerScript();

    // The only use of the token is curl's config, fed through a pipe.
    expect(mb_substr_count($script, '$DAEMON_TOKEN'))->toBe(2)
        ->and($script)->toContain('-K <(printf')->toContain('"$TOKEN_ID" "$DAEMON_TOKEN")')
        ->and($script)->not->toContain('echo "$DAEMON_TOKEN')
        ->and($script)->not->toContain('set -x')
        // The config is replaced atomically and Gate reads it from the same directory.
        ->and($script)->toContain('mv -f "$tmp" "$CONFIG"')
        ->and($script)->toContain('/api/remote/minecraft-routes');
});

test('the router script verifies the Gate download', function (): void {
    $script = routerScript();

    expect($script)->toContain('checksums.txt')->toContain('sha256sum -c -');
});
