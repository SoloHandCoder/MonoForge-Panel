<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Scripts\PlayitSwitchScriptTest;

use Pterodactyl\Services\Tunnels\PlayitAgentSwitchService;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The node switch script's rules, run with bash: the script is sourced in library mode
 * (MONOFORGE_PLAYIT_LIB=1), which defines its functions and runs nothing.
 */
function playitScript(string $body, string $wingsConfig = ''): string
{
    $directory = sys_get_temp_dir().'/playit-script-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $config = $directory.'/config.yml';
    file_put_contents($config, $wingsConfig);

    $process = new Process(['bash', '-c', 'source "$1"; WINGS_CONFIG=$2; set +e; '.$body, '_', PlayitAgentSwitchService::scriptFile(), $config, $directory], null, ['MONOFORGE_PLAYIT_LIB' => '1']);
    $process->run();

    exec('rm -rf '.escapeshellarg($directory));

    return mb_trim($process->getOutput().$process->getErrorOutput());
}

beforeEach(function (): void {
    if (PHP_OS_FAMILY === 'Windows' || (new ExecutableFinder)->find('bash') === null) {
        $this->markTestSkipped('The switch script runs on Linux nodes; these checks need bash on Linux.');
    }
});

const WINGS_CONFIG = <<<'YAML'
    token_id: abc
    token: def
    system:
      root_directory: /srv/ptero
      data: "/srv/ptero/volumes"
      user:
        uid: 988
    YAML;

test('the script is valid bash and has no way to skip the confirmation', function (): void {
    $process = new Process(['bash', '-n', PlayitAgentSwitchService::scriptFile()]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and(file_get_contents(PlayitAgentSwitchService::scriptFile()))->toContain('Type yes to continue')
        ->not->toContain('ASSUME_YES')
        // It never runs a process's own command line.
        ->not->toContain('setsid nohup')->not->toContain('screen -dmS');
});

test('the Wings data directory, docker and container homes are never written', function (): void {
    $out = playitScript(<<<'BASH'
        for p in /srv/ptero/volumes/0b8d/playit.toml /var/lib/pterodactyl/volumes/0b8d/s.toml \
            /var/lib/docker/overlay2/x/playit.toml /home/container/.config/playit_gg/playit.toml \
            /srv/ptero/volumes/../volumes/x /etc/playit/playit.toml /root/.config/playit_gg/playit.toml; do
            path_forbidden "$p" && echo "no $p" || echo "ok $p"
        done
        BASH, WINGS_CONFIG);

    expect(explode("\n", $out))->toBe([
        'no /srv/ptero/volumes/0b8d/playit.toml',
        'no /var/lib/pterodactyl/volumes/0b8d/s.toml',
        'no /var/lib/docker/overlay2/x/playit.toml',
        'no /home/container/.config/playit_gg/playit.toml',
        'no /srv/ptero/volumes/../volumes/x',
        'ok /etc/playit/playit.toml',
        'ok /root/.config/playit_gg/playit.toml',
    ]);
});

test('only root and the playit user may own the agent, never the Wings user', function (): void {
    expect(playitScript('uid_allowed 0 && echo root-ok; uid_allowed 988 || echo wings-refused; uid_allowed 1000 || echo other-refused', WINGS_CONFIG))
        ->toBe("root-ok\nwings-refused\nother-refused");
});

test('playit binaries and systemd ExecStart lines are recognised', function (): void {
    $out = playitScript(<<<'BASH'
        for b in /usr/bin/playitd /opt/playit/playit-linux-amd64 /usr/local/bin/playit /bin/bash /usr/bin/playitx; do
            is_playit_binary "$b" && echo "yes $b" || echo "no $b"
        done
        parse_execstart '{ path=/usr/bin/playitd ; argv[]=/usr/bin/playitd --secret-path /etc/playit/playit.toml ; ignore_errors=no ; start_time=[n/a] }'
        echo "argv0=${ARGV[0]} secret=$(arg_value --secret-path)"
        BASH);

    expect(explode("\n", $out))->toBe([
        'yes /usr/bin/playitd',
        'yes /opt/playit/playit-linux-amd64',
        'yes /usr/local/bin/playit',
        'no /bin/bash',
        'no /usr/bin/playitx',
        'argv0=/usr/bin/playitd secret=/etc/playit/playit.toml',
    ]);
});

test('the Wings uid and data directory are read from the nested config', function (): void {
    expect(playitScript('echo "$(wings_uid) $(yaml_nested system root_directory "$WINGS_CONFIG") $(yaml_nested system data "$WINGS_CONFIG")"', WINGS_CONFIG))
        ->toBe('988 /srv/ptero /srv/ptero/volumes');
});

test('a secret path through a symlink is refused', function (): void {
    $out = playitScript(<<<'BASH'
        mkdir -p "$3/real" && ln -s "$3/real" "$3/link"
        path_unsafe "$3/link/playit.toml" && echo unsafe || echo safe
        BASH);

    expect($out)->toEndWith('is a symlinkunsafe');
});

test('a secret path owned by another user is refused', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Running as root, the temporary directory is owned by root.');
    }

    expect(playitScript('mkdir -p "$3/own"; path_unsafe "$3/own/playit.toml" && echo unsafe || echo safe'))
        ->toContain('is owned by uid')->toEndWith('unsafe');
});
