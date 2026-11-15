<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Assistant\SecretRedactorTest;

use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;
use Pterodactyl\Services\Assistant\Tools\Server\EditFileTool;

const MASK = SecretRedactor::PLACEHOLDER;

test('values of secret-named keys are masked in common config formats', function (string $text, string $expected): void {
    expect(SecretRedactor::redact($text))->toBe($expected);
})->with([
    'env' => ["APP_NAME=Bot\nDISCORD_TOKEN=abc.def\nexport API_KEY=xyz", "APP_NAME=Bot\nDISCORD_TOKEN=".MASK."\nexport API_KEY=".MASK],
    'properties' => ["motd=Hi\nrcon.password=hunter2\nmax-players=20", "motd=Hi\nrcon.password=".MASK."\nmax-players=20"],
    'yaml' => ["database:\n  host: db\n  password: 'hunter2'\n  user: mc", "database:\n  host: db\n  password: ".MASK."\n  user: mc"],
    'json' => ['{"name": "bot", "token": "abc123", "count": 2}', '{"name": "bot", "token": "'.MASK.'", "count": 2}'],
    'php define' => ["define( 'DB_PASSWORD', 'hunter2' );", "define( 'DB_PASSWORD', '".MASK."' );"],
    'php array' => ["'secret' => 'abc',", "'secret' => '".MASK."',"],
    'camelCase' => ['rconPassword: x', 'rconPassword: '.MASK],
    'empty value' => ['DB_PASSWORD=', 'DB_PASSWORD='],
    'yaml block' => ["auth:\n  enabled: true", "auth:\n  enabled: true"],
    'block scalar marker' => ["token: |\n  abc", "token: |\n  abc"],
]);

test('keys are judged by whole segments, not substrings', function (string $text): void {
    expect(SecretRedactor::redact($text))->toBe($text);
})->with([
    'rcon.port=25575',
    'enable-rcon=true',
    'author: Steve',
    'authors: [Steve]',
    'api-version: 1.20',
    'bypass-permission: x.y',
    'hotkey: F5',
    'passive-mobs: true',
    'oauth-redirect: /cb',
    'keep-alive: 30',
]);

test('credentials in URLs, webhook tokens, key blocks and JWTs are masked anywhere', function (): void {
    $text = "db: mysql://root:hunter2@db/mc\nhook: https://discord.com/api/webhooks/123456/aBc-DeF_ghi\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----\njwt eyJhbGciOiJIUzI1.eyJzdWIiOiIxMjM0.SflKxwRJSMeKKF2QT4 end";

    expect(SecretRedactor::redact($text))->toBe('db: mysql://root:'.MASK."@db/mc\nhook: https://discord.com/api/webhooks/123456/".MASK."\n".MASK."\njwt ".MASK.' end')
        // A key block cut off before its END line is masked to the end.
        ->and(SecretRedactor::redact("a\n-----BEGIN OPENSSH PRIVATE KEY-----\nb3Blbn"))->toBe("a\n".MASK);
});

test('secret-looking variable names are recognised', function (string $name, bool $secret): void {
    expect(SecretRedactor::isSecretName($name))->toBe($secret);
})->with([
    ['RCON_PASSWORD', true],
    ['DISCORD_TOKEN', true],
    ['STEAM_API_KEY', true],
    ['client_secret', true],
    ['STEAM_ACC', true],
    ['DB_PW', true],
    ['ADMIN_PASS', true],
    ['SERVER_JARFILE', false],
    ['MAX_PLAYERS', false],
    ['ENABLE_RCON', false],
    ['RCON_PORT', false],
]);

test('file keys named like a secret startup variable are masked, and log lines are masked mid-line', function (): void {
    expect(SecretRedactor::redact('STEAM_ACC=ABCDEF'))->toBe('STEAM_ACC='.MASK)
        ->and(SecretRedactor::redactLog('[12:00:01 INFO]: RCON password: hunter22'))->toBe('[12:00:01 INFO]: RCON password: '.MASK)
        ->and(SecretRedactor::redactLog('[Bot] token=MTAxabc rest'))->toBe('[Bot] token='.MASK.' rest')
        ->and(SecretRedactor::redactLog('[12:00:01 INFO]: Done (3.2s)! port: 25565'))->toBe('[12:00:01 INFO]: Done (3.2s)! port: 25565');
});

test('address placeholders are still never written back', function (): void {
    expect(OutputRedactor::containsPlaceholder('ip='.OutputRedactor::IP))->toBeTrue()
        ->and(OutputRedactor::containsPlaceholder('password: '.MASK))->toBeFalse()
        ->and(SecretRedactor::containsPlaceholder('password: '.MASK))->toBeTrue()
        ->and(SecretRedactor::containsPlaceholder(SecretRedactor::UNCHECKED))->toBeTrue();
});

test('masking stays linear: long values never switch it off', function (int $length): void {
    $cases = [
        '{"favicon": "'.str_repeat('A', $length).'", "password": "S3cretPw"}',
        '{"password": "S3cretPw", "motd": "'.str_repeat('B', $length).'"}',
        '{"desc": "'.str_repeat('a\\"', intdiv($length, 3)).'", "password": "S3cretPw"}',
        "<?php return ['logo' => '".str_repeat('B', $length)."', 'db' => ['password' => 'S3cretPw']];",
        "password=S3cretPw\nmotd=".str_repeat('x ', intdiv($length, 2)),
        "a\n-----BEGIN RSA PRIVATE KEY-----\n".str_repeat("MIIEow-x\n", intdiv($length, 9))."-----END RSA PRIVATE KEY-----\npassword: S3cretPw\n",
    ];

    foreach ($cases as $text) {
        $masked = SecretRedactor::redact($text);
        expect($masked)->not->toContain('S3cretPw')->not->toBe(SecretRedactor::UNCHECKED);
    }
})->with([10_000, 200_000]);

test('masking fails closed when PCRE reports an error', function (): void {
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $text = 'password: hunter2 and more text '.str_repeat('y ', 100);
        expect(SecretRedactor::redact($text))->toBe(SecretRedactor::UNCHECKED)
            ->and(SecretRedactor::redactLog($text))->toBe(SecretRedactor::UNCHECKED)
            ->and(SecretRedactor::hidesSomething('motd=hi'))->toBeTrue()
            ->and(SecretRedactor::hiddenValues($text))->toBeNull();
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
        ini_set('pcre.jit', (string) $jit);
    }
});

test('secrets in YAML list items are masked', function (): void {
    expect(SecretRedactor::redact("tokens:\n  - token: abcdef123\n  - password: hunter2\n  - name: lobby\n"))
        ->toBe("tokens:\n  - token: ".MASK."\n  - password: ".MASK."\n  - name: lobby\n");
});

test('a carriage return stays outside a masked value', function (): void {
    expect(SecretRedactor::redact("password=hunter2\r\nmotd=hi\r\n"))->toBe('password='.MASK."\r\nmotd=hi\r\n");
});

test('the hidden values of a text are listed in order', function (): void {
    expect(SecretRedactor::hiddenValues("motd=hi\nrcon.password=A1\ndb: mysql://root:B2@db/mc\n"))->toBe(['A1', 'B2'])
        ->and(SecretRedactor::hiddenValues('motd=hi'))->toBe([])
        // Too many to line up quickly: the file counts as unchecked, so it is not edited.
        ->and(SecretRedactor::hiddenValues(str_repeat("password=x\n", SecretRedactor::MAX_HIDDEN_VALUES + 1)))->toBeNull();
});

const PROPERTIES = "motd=A Minecraft Server\nrcon.password=hunter22\nrcon.port=25575\nmax-players=20\n";

test('edit_file changes the MOTD line of a file that holds rcon.password, leaving the password untouched', function (): void {
    $edited = EditFileTool::apply(PROPERTIES, [
        ['find' => 'motd=A Minecraft Server', 'replace' => 'motd=Welcome!'],
        ['find' => 'max-players=20', 'replace' => 'max-players=50'],
    ]);

    expect($edited)->toBe("motd=Welcome!\nrcon.password=hunter22\nrcon.port=25575\nmax-players=50\n");
});

test('edit_file refuses edits that touch, move, copy or add a hidden value', function (string $original, array $edits, string $message): void {
    expect(fn (): string => EditFileTool::apply($original, $edits))->toThrow(ToolException::class, $message);
})->with([
    'find on the rcon.password line' => [PROPERTIES, [['find' => 'rcon.password=', 'replace' => 'rcon.password=new']], 'touches a line with a hidden value'],
    'find ending next to the secret line' => [PROPERTIES, [['find' => "Server\nrcon", 'replace' => "Server\n#rcon"]], 'stay within one line'],
    'moving the secret line down' => [PROPERTIES, [['find' => "rcon.password=hunter22\nrcon.port=25575", 'replace' => "rcon.port=25575\nrcon.password=hunter22"]], 'stay within one line'],
    'a new secret' => [PROPERTIES, [['find' => 'max-players=20', 'replace' => "max-players=20\nquery.password=mine"]], 'stay within one line'],
    'a new secret on the same line' => [PROPERTIES, [['find' => 'max-players=20', 'replace' => 'query.password=mine']], 'moves, copies or changes a hidden value'],
    'YAML key turned into a block scalar (I-B probe)' => ["mysql:\n  password: A1secret\nmotd: hi\n", [['find' => 'mysql:', 'replace' => 'motd: |']], 'moves, copies or changes a hidden value'],
    'YAML key turned into an open quote (I-C probe)' => ["mysql:\n  password: A1secret\nmotd: hi\n", [['find' => 'mysql:', 'replace' => 'motd: "Welcome']], 'moves, copies or changes a hidden value'],
    'a secret written into a JSON string (I-C probe)' => ["{\n  \"password\": \"A1secret\",\n  \"join message\": \"Welcome!\"\n}\n", [['find' => '"Welcome!"', 'replace' => "\"Welcome! 'password': 'x'\""]], 'moves, copies or changes a hidden value'],
    'PHP heredoc' => ["<?php return [\n  'motd' => 'hi',\n  'password' => 'A1secret',\n];\n", [['find' => "'motd' => 'hi',", 'replace' => "'motd' => <<<EOT"]], 'moves, copies or changes a hidden value'],
    'text that occurs twice' => ["a=1\na=1\npassword=x\n", [['find' => 'a=1', 'replace' => 'a=2']], 'exactly once'],
]);

const VELOCITY = "motd = \"&3A Server\"\n[servers]\nlobby = \"127.0.0.1:30066\"\n[forced-hosts]\n# forwarding-secret\nsecret = \"MY-PROXY-SECRET\"\nping-passthrough = \"DISABLED\"\n";

test('edit_file refuses the round-4 multi-line string attacks structurally', function (string $path, string $original, array $edits): void {
    expect(fn (): string => EditFileTool::apply($original, $edits, $path))->toThrow(ToolException::class);
})->with([
    // The reviewer's velocity.toml probe: two edits that wrap the secret in a TOML """ string.
    'velocity.toml two-edit """ attack' => ['/velocity.toml', VELOCITY, [
        ['find' => 'motd = "&3A Server"', 'replace' => 'motd = """'],
        ['find' => 'ping-passthrough = "DISABLED"', 'replace' => '"""'."\n".'ping-passthrough = "DISABLED"'],
    ]],
    'velocity.toml one-edit """ opener' => ['/velocity.toml', VELOCITY, [['find' => 'motd = "&3A Server"', 'replace' => 'motd = """']]],
    'TOML """intro' => ['/velocity.toml', VELOCITY, [['find' => 'motd = "&3A Server"', 'replace' => 'motd = """Welcome']]],
    "TOML '''" => ['/velocity.toml', VELOCITY, [['find' => 'motd = "&3A Server"', 'replace' => "motd = '''"]]],
    'Lua [[ long string' => ['/config.lua', "motd = \"hi\"\npassword = \"S3cretPw\"\n", [['find' => 'motd = "hi"', 'replace' => 'motd = [[']]],
    'Lua [==[ long string' => ['/config.lua', "motd = \"hi\"\npassword = \"S3cretPw\"\n", [['find' => 'motd = "hi"', 'replace' => 'motd = [==[']]],
    'YAML key: |' => ['/config.yml', "motd: hi\nmysql:\n  password: A1secret\n", [['find' => 'mysql:', 'replace' => 'motd2: |']]],
    'a continuation at the end' => ['/config.ini', "motd = hi\npassword = S3cretPw\n", [['find' => 'motd = hi', 'replace' => 'motd = hi \\']]],
    'an odd quote' => ['/config.ini', "motd = hi\npassword = S3cretPw\n", [['find' => 'motd = hi', 'replace' => 'motd = "hi']]],
]);

test('edit_file refuses a JSON or YAML edit that moves a hidden value to another parsed key path', function (): void {
    $json = "{\n  \"db\":\n    {\"password\": \"A1secret\"},\n  \"motd\": \"hi\"\n}\n";

    // One line, balanced quotes, the masked value unchanged: only parsing sees that the password
    // now sits under "public" instead of "db".
    expect(fn (): string => EditFileTool::apply($json, [['find' => '"db":', 'replace' => '"public":']], '/config.json'))
        ->toThrow(ToolException::class, 'moves, copies or changes a hidden value')
        // A JSON file that would no longer parse is refused too.
        ->and(fn (): string => EditFileTool::apply($json, [['find' => '"db":', 'replace' => '"db"']], '/config.json'))
        ->toThrow(ToolException::class, 'no longer parse');

    // An edit beside the secret that keeps it at its path is fine.
    expect(EditFileTool::apply($json, [['find' => '"motd": "hi"', 'replace' => '"motd": "Welcome"']], '/config.json'))
        ->toBe("{\n  \"db\":\n    {\"password\": \"A1secret\"},\n  \"motd\": \"Welcome\"\n}\n");

    // YAML: indenting the parent makes the password a top-level key.
    $yaml = "motd: hi\nmysql:\n  password: A1secret\n";
    expect(fn (): string => EditFileTool::apply($yaml, [['find' => 'mysql:', 'replace' => '  mysql:']], '/config.yml'))
        ->toThrow(ToolException::class);
});

test('edit_file keeps free editing in files without hidden values and the MOTD edit next to rcon.password', function (): void {
    expect(EditFileTool::apply(PROPERTIES, [['find' => 'motd=A Minecraft Server', 'replace' => 'motd=Welcome to the server']], '/server.properties'))
        ->toBe("motd=Welcome to the server\nrcon.password=hunter22\nrcon.port=25575\nmax-players=20\n")
        // No hidden values: multi-line edits and string openers are the user's business.
        ->and(EditFileTool::apply("motd = \"hi\"\nport = 1\n", [['find' => 'motd = "hi"', 'replace' => "motd = \"\"\"\nWelcome\n\"\"\""]], '/a.toml'))
        ->toBe("motd = \"\"\"\nWelcome\n\"\"\"\nport = 1\n");
});

test('a line is self-contained only with balanced quotes, no multi-line delimiters and no continuation', function (string $line, bool $expected): void {
    expect(EditFileTool::selfContained($line))->toBe($expected);
})->with([
    ['motd=Welcome', true],
    ['motd = "Welcome" # note', true],
    ['motd = "say \\"hi\\""', true],
    ["motd: 'it''s'", true],
    ['motd = """', false],
    ['motd = [[', false],
    ['motd = ]]', false],
    ['motd = [==[', false],
    ['motd: |', false],
    ['motd: >- # folded', false],
    ['motd = hi \\', false],
    ['motd = (', false],
    ['"motd": "hi",', false],
    ['x = <<<EOT', false],
    ["motd = 'open", false],
]);
