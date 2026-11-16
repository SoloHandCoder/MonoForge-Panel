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

test('only .toml files in the playit locations are accepted as the secret file', function (): void {
    $out = playitScript(<<<'BASH'
        home_of() { printf '/home/playit'; }
        for p in /etc/playit/playit.toml /var/lib/playit/agent.toml /home/playit/.config/playit_gg/playit.toml \
            /etc/playit/sub/playit.toml /etc/passwd /etc/playit/playit.conf /etc/playit/../shadow.toml /tmp/playit.toml; do
            secret_path_allowed "$p" playit && echo "ok $p" || echo "no $p"
        done
        BASH);

    expect(explode("\n", $out))->toBe([
        'ok /etc/playit/playit.toml',
        'ok /var/lib/playit/agent.toml',
        'ok /home/playit/.config/playit_gg/playit.toml',
        'no /etc/playit/sub/playit.toml',
        'no /etc/passwd',
        'no /etc/playit/playit.conf',
        'no /etc/playit/../shadow.toml',
        'no /tmp/playit.toml',
    ]);
});

test('a directory writable by group or others is refused', function (): void {
    if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        // As a normal user the owner check refuses first; check the mode rule on its own.
        expect(playitScript('mkdir -p "$3/open"; chmod 777 "$3/open"; mode=$(stat -c %a "$3/open"); (( (8#$mode & 8#022) != 0 )) && echo writable'))
            ->toBe('writable');

        return;
    }

    expect(playitScript('mkdir -p "$3/open"; chmod 1777 "$3/open"; path_unsafe "$3/open/playit.toml" && echo unsafe'))
        ->toContain('writable by group or others')->toEndWith('unsafe');
});

test('only the exact secret_key line is replaced, and files without one are refused', function (): void {
    $out = playitScript(<<<'BASH'
        old=$'# secret_key = "commented"\nsecret_key_backup = "x"\n  secret_key = "aaaa"\nother = 1'
        new=$(build_secret_content "$old" "bbbb")
        printf '%s\n--\n' "$new"
        echo "lines=$(secret_key_lines "$new")"
        build_secret_content $'name = "x"\nsecret_key = "a"\nsecret_key = "b"' "cccc" >/dev/null && echo dup-ok || echo dup-refused
        echo "none=$(secret_key_lines $'root:x:0:0::/root:/bin/bash')"
        build_secret_content "" "dddd"
        BASH);

    expect(explode("\n", $out))->toBe([
        '# secret_key = "commented"',
        'secret_key_backup = "x"',
        'secret_key = "bbbb"',
        'other = 1',
        '--',
        'lines=1',
        'dup-refused',
        'none=0',
        'secret_key = "dddd"',
    ]);
});

test('tokens and secrets are checked as a whole value, not line by line', function (): void {
    $out = playitScript(<<<'BASH'
        valid_token "$(printf 'a%.0s' {1..48})" && echo token-ok
        valid_token "$(printf 'a%.0s' {1..48})"$'\nx' || echo token-multiline-refused
        valid_token 'ABCDEF' || echo token-short-refused
        valid_secret 'deadbeefdeadbeef' && echo secret-ok
        valid_secret "$(printf 'ab%.0s' {1..200})" && echo secret-400-ok
        valid_secret $'deadbeefdeadbeef\n"; rm' || echo secret-multiline-refused
        BASH);

    expect(explode("\n", $out))->toBe(['token-ok', 'token-multiline-refused', 'token-short-refused', 'secret-ok', 'secret-400-ok', 'secret-multiline-refused']);
});

test('the new secret file is 600, or 640 only for a root file in the playit group', function (): void {
    expect(playitScript('touch "$3/f"; secret_mode "$3/f"; echo; secret_mode "$3/missing"'))->toBe("600\n600");
});

test('processes are compared with pid 1 by namespace and root, and start time', function (): void {
    $out = playitScript(<<<'BASH'
        in_host_namespaces $$ && echo self-host
        in_host_namespaces 999999999 || echo missing-refused
        t=$(proc_start_time $$); [ -n "$t" ] && [ "$t" = "$(proc_start_time $$)" ] && echo start-stable
        [ -z "$(proc_start_time 999999999)" ] && echo start-missing
        BASH);

    expect(explode("\n", $out))->toContain('missing-refused', 'start-stable', 'start-missing');
});

test('the script never lets curl read a .curlrc, nor follows the panel elsewhere without asking', function (): void {
    $script = (string) file_get_contents(PlayitAgentSwitchService::scriptFile());

    expect(mb_substr_count($script, 'curl -q -sS'))->toBe(2)
        ->and($script)->toContain('confirm_yes "The node\'s daemon token will be sent to $PANEL."')
        ->toContain("trap 'exit 129' HUP")
        ->toContain('restore "$dir" || die')
        ->not->toContain('restore "$dir" || true');
});
