<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Assistant\CrashDoctorToolsTest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ConversationContext;
use Pterodactyl\Services\Assistant\SystemPrompt;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\Server\GetStartupTool;
use Pterodactyl\Services\Assistant\Tools\Server\ReadConsoleTool;
use Pterodactyl\Services\Assistant\Tools\Server\ReadFileTool;
use Pterodactyl\Services\Assistant\Tools\Server\SetDockerImageTool;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonFile;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

function context(User $user, Server $server): ToolContext
{
    return new ToolContext($user, 'server', $server);
}

/** @return array{0: User, 1: Server, 2: Egg} */
function serverWithImages(object $test, string $image): array
{
    [$user, $server] = $test->generateTestAccount();
    $egg = Egg::query()->findOrFail($server->egg_id);
    $egg->forceFill(['docker_images' => ['Java 17' => 'ghcr.io/pterodactyl/yolks:java_17', 'Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']])->save();
    $server->forceFill(['image' => $image])->save();

    return [$user, $server->refresh(), $egg];
}

test('tail keeps the last lines within the byte budget', function (): void {
    $text = implode("\n", array_map(fn (int $i): string => "line {$i}", range(1, 10)))."\n";

    expect(ReadFileTool::tail($text, 3))->toBe("[... 7 earlier lines left out]\nline 8\nline 9\nline 10")
        ->and(ReadFileTool::tail($text, 50))->toBe(mb_rtrim($text, "\n"))
        ->and(ReadFileTool::tail($text, 50, 16))->toBe("[... 8 earlier lines left out]\nline 9\nline 10");
});

test('read_file with tail_lines reads the end of a log up to 1 MB', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $files = new FakeDaemonFile;
    $files->directory = [['name' => 'latest.log', 'file' => true, 'symlink' => false, 'size' => 600_000]];
    $files->content = implode("\n", array_map(fn (int $i): string => "\e[32m[12:00:00 INFO]: line {$i}\e[0m", range(1, 400)));

    $tool = resolve(ReadFileTool::class);

    $arguments = $tool->prepareArguments(['path' => 'logs/latest.log', 'tail_lines' => '50'], context($user, $server));
    expect($arguments)->toBe(['path' => '/logs/latest.log', 'tail_lines' => 50])
        ->and($tool->describeCall($arguments))->toBe('Read the end of /logs/latest.log')
        ->and($tool->needsConfirmation($arguments, context($user, $server)))->toBeFalse();

    $result = $tool->execute($arguments, context($user, $server));
    expect($result->content)->toStartWith("[... 350 earlier lines left out]\n[12:00:00 INFO]: line 351")
        ->and($result->content)->toEndWith('line 400')
        ->and($result->content)->not->toContain("\e[");

    // Without tail_lines the 256 KB limit stands; with it, 1 MB.
    expect(fn (): ToolResult => $tool->execute(['path' => '/logs/latest.log'], context($user, $server)))->toThrow(ToolException::class, 'larger than 256 KB');
    $files->directory = [['name' => 'latest.log', 'file' => true, 'symlink' => false, 'size' => ReadFileTool::TAIL_MAX_BYTES + 1]];
    expect(fn (): ToolResult => $tool->execute($arguments, context($user, $server)))->toThrow(ToolException::class, 'larger than 1 MB');
});

test('read_console keeps the newest lines when the output is long', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $wings = new FakeDaemonServer;
    $wings->logs = array_map(fn (int $i): string => '[12:00:00 INFO]: '.str_repeat('x', 200)." {$i}", range(1, 100));

    $result = resolve(ReadConsoleTool::class)->execute(['lines' => 100], context($user, $server));
    expect(mb_strlen($result->content, '8bit'))->toBeLessThanOrEqual(ReadFileTool::TAIL_RESULT_BYTES + 64)
        ->and($result->content)->toStartWith('[... ')
        ->and($result->content)->toEndWith(' 100');
});

test('set_docker_image switches to another image of the egg', function (): void {
    [$user, $server] = serverWithImages($this, 'ghcr.io/pterodactyl/yolks:java_17');
    $tool = resolve(SetDockerImageTool::class);

    expect($tool->describeCall(['image' => 'ghcr.io/pterodactyl/yolks:java_21']))->toBe('Switch the Docker image to ghcr.io/pterodactyl/yolks:java_21');

    $result = $tool->execute(['image' => 'ghcr.io/pterodactyl/yolks:java_21'], context($user, $server));
    expect($result->content)->toContain('Switched the Docker image to ghcr.io/pterodactyl/yolks:java_21')
        ->and($server->refresh()->image)->toBe('ghcr.io/pterodactyl/yolks:java_21');
    $this->assertActivityActor('server:startup.image', $user);

    expect(fn (): ToolResult => $tool->execute(['image' => 'evil/image:latest'], context($user, $server)))->toThrow(ToolException::class, 'is not one of the images');
});

test('set_docker_image leaves an image an administrator set by hand', function (): void {
    [$user, $server] = serverWithImages($this, 'custom/admin-image:1');

    expect(fn (): ToolResult => resolve(SetDockerImageTool::class)->execute(['image' => 'ghcr.io/pterodactyl/yolks:java_21'], context($user, $server)))
        ->toThrow(ToolException::class, "set this server's Docker image by hand");
    expect($server->refresh()->image)->toBe('custom/admin-image:1');
});

test('get_startup lists the images the server can switch to', function (): void {
    [$user, $server] = serverWithImages($this, 'ghcr.io/pterodactyl/yolks:java_17');

    $data = json_decode(resolve(GetStartupTool::class)->execute([], context($user, $server))->content, true);
    expect($data['docker_image'])->toBe('ghcr.io/pterodactyl/yolks:java_17')
        ->and($data['docker_images'])->toBe(['Java 17' => 'ghcr.io/pterodactyl/yolks:java_17', 'Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']);
});

test('the crash doctor has its own prompt and reads more of each result, keeping the end of logs', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $conversation = AiConversation::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-000000000001', 'user_id' => $user->id, 'scope' => 'server', 'server_id' => $server->id, 'title' => 'Crash diagnosis', 'mode' => 'crash_doctor']);
    $chat = AiConversation::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-000000000002', 'user_id' => $user->id, 'scope' => 'server', 'server_id' => $server->id, 'title' => 'Chat']);

    expect(SystemPrompt::for($conversation))->toContain('crash doctor')->toContain('### What happened')->toContain('set_docker_image')
        ->and(SystemPrompt::for($chat))->not->toContain('crash doctor')
        ->and(ConversationContext::toolResultBytes($conversation))->toBe(ConversationContext::CRASH_DOCTOR_TOOL_RESULT_BYTES)
        ->and(ConversationContext::toolResultBytes($chat))->toBe(ConversationContext::TOOL_RESULT_BYTES);

    $log = implode("\n", array_map(fn (int $i): string => "log line {$i}", range(1, 2000)));
    $assistant = new AiMessage(['role' => 'assistant', 'content' => null, 'tool_calls' => [
        ['id' => 'a', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"/logs/latest.log","tail_lines":200}']],
        ['id' => 'b', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"/config.yml"}']],
    ]]);
    $history = new Collection([
        $assistant,
        new AiMessage(['role' => 'tool', 'tool_call_id' => 'a', 'content' => $log]),
        new AiMessage(['role' => 'tool', 'tool_call_id' => 'b', 'content' => $log]),
    ]);

    $messages = resolve(ConversationContext::class)->format($history, ConversationContext::CRASH_DOCTOR_TOOL_RESULT_BYTES);
    expect($messages[1]['content'])->toStartWith('[... truncated')->toEndWith('log line 2000')
        ->and(mb_strlen($messages[1]['content'], '8bit'))->toBeLessThanOrEqual(ConversationContext::CRASH_DOCTOR_TOOL_RESULT_BYTES)
        ->and($messages[2]['content'])->toStartWith('log line 1')->toContain('[... truncated');
});
