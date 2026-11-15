<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\CrashDoctor\CrashDoctorApiTest;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerCrash;
use Pterodactyl\Services\Assistant\AssistantBilling;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashRecorder;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

uses(ClientApiIntegrationTestCase::class);

/**
 * Wings and the AI provider for a diagnosis: the console, /crash-reports with two reports, and
 * a latest.log of 300 lines. Records every request; answers the model calls in order.
 */
final class DoctorWings
{
    /** @var list<array<string, mixed>> */
    public array $answers = [];

    /** @var list<Request> */
    public array $requests = [];

    public bool $crashReports = true;

    public function __construct()
    {
        Http::fake(function (Request $request) {
            $this->requests[] = $request;
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($request->url() === FakeAi::URL) {
                return Http::response(array_shift($this->answers) ?? FakeAi::text('(no more answers)'));
            }

            if (str_ends_with($path, '/logs')) {
                return Http::response(['data' => [
                    '[12:00:00 INFO]: Starting minecraft server version 1.21.4',
                    "\e[31m[12:00:02 ERROR]: Could not load 'plugins/BrokenPlugin.jar'\e[0m",
                    'java.lang.UnsupportedClassVersionError: compiled by a more recent version of the Java Runtime (class file version 65.0)',
                    'Database password=hunter2secret from 198.51.100.23',
                ]]);
            }

            if (str_ends_with($path, '/files/list-directory')) {
                return match ($query['directory'] ?? null) {
                    '/crash-reports' => $this->crashReports ? Http::response([
                        ['name' => 'crash-2026-11-14_10.00.00-server.txt', 'file' => true, 'symlink' => false, 'size' => 500, 'modified' => '2026-11-14T10:00:00Z'],
                        ['name' => 'crash-2026-11-15_11.59.00-server.txt', 'file' => true, 'symlink' => false, 'size' => 500, 'modified' => '2026-11-15T11:59:00Z'],
                        ['name' => 'old', 'file' => false, 'symlink' => false, 'size' => 0, 'modified' => '2026-11-15T12:00:00Z'],
                    ]) : Http::response(['error' => 'The requested resource was not found on the system.'], 404),
                    '/logs' => Http::response([
                        ['name' => 'latest.log', 'file' => true, 'symlink' => false, 'size' => 20000, 'modified' => '2026-11-15T12:00:00Z'],
                    ]),
                    default => Http::response([]),
                };
            }

            if (str_ends_with($path, '/files/contents')) {
                return match ($query['file'] ?? null) {
                    '/crash-reports/crash-2026-11-15_11.59.00-server.txt' => Http::response("---- Minecraft Crash Report ----\nDescription: Exception in server tick loop\njava.lang.NullPointerException: newest report"),
                    '/logs/latest.log' => Http::response(implode("\n", array_map(fn (int $i): string => "[12:00:00 INFO]: log line {$i}", range(1, 300)))),
                    default => Http::response(['error' => 'not found'], 404),
                };
            }

            return Http::response([]);
        });
    }

    /** @return list<Request> */
    public function aiRequests(): array
    {
        return array_values(array_filter($this->requests, fn (Request $request): bool => $request->url() === FakeAi::URL));
    }

    public function sent(string $suffix): bool
    {
        foreach ($this->requests as $request) {
            if (str_contains($request->url(), $suffix)) {
                return true;
            }
        }

        return false;
    }
}

beforeEach(function (): void {
    FakeAi::enable();
    config()->set('monoforge.ai.crash_doctor_enabled', true);
});

function crash(Server $server, array $attributes = []): ServerCrash
{
    return ServerCrash::query()->create([
        'server_id' => $server->id,
        'kind' => 'crashed',
        'source' => 'scheduler',
        'detected_at' => CarbonImmutable::now(),
        'console' => 'masked lines',
        ...$attributes,
    ]);
}

test('the status shows the newest crash of the last day that was not dismissed', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $base = "/api/client/servers/{$server->uuid}/crash-doctor";

    $this->actingAs($user)->getJson($base)->assertOk()->assertExactJson(['enabled' => true, 'crash' => null]);

    crash($server, ['detected_at' => CarbonImmutable::now()->subDays(2)]);
    $this->actingAs($user)->getJson($base)->assertOk()->assertJsonPath('crash', null);

    $crash = crash($server, ['kind' => 'start_failed']);
    $this->actingAs($user)->getJson($base)->assertOk()->assertExactJson(['enabled' => true, 'crash' => [
        'id' => $crash->id,
        'kind' => 'start_failed',
        'status' => 'open',
        'detected_at' => $crash->detected_at->toAtomString(),
        'conversation_uuid' => null,
    ]]);

    $this->actingAs($user)->postJson("{$base}/crashes/{$crash->id}/dismiss")->assertNoContent();
    expect($crash->refresh()->status)->toBe('dismissed');
    $this->actingAs($user)->getJson($base)->assertOk()->assertJsonPath('crash', null);
});

test('the status is off without a crash while the assistant or the crash doctor is off', function (string $key): void {
    [$user, $server] = $this->generateTestAccount();
    crash($server);
    config()->set($key, false);

    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/crash-doctor")->assertOk()->assertExactJson(['enabled' => false, 'crash' => null]);
    $this->actingAs($user)->getJson('/api/client/assistant')->assertOk()->assertJsonPath('crash_doctor_enabled', false);
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/diagnose", ['crash_id' => null])
        ->assertForbidden();
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/report", ['kind' => 'crashed'])->assertForbidden();
})->with(['monoforge.ai.crash_doctor_enabled', 'monoforge.ai.server_enabled', 'monoforge.ai.enabled']);

test('a subuser without assistant.use cannot use the crash doctor', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::WebsocketConnect->value, Permissions::FileRead->value]);
    $crash = crash($server);
    $base = "/api/client/servers/{$server->uuid}/crash-doctor";
    Http::fake();

    $this->actingAs($user)->getJson($base)->assertForbidden();
    $this->actingAs($user)->postJson("{$base}/report", ['kind' => 'crashed'])->assertForbidden();
    $this->actingAs($user)->postJson("{$base}/diagnose", ['crash_id' => $crash->id])->assertForbidden();
    $this->actingAs($user)->postJson("{$base}/crashes/{$crash->id}/dismiss")->assertForbidden();
    Http::assertNothingSent();
    expect($crash->refresh()->status)->toBe('open');
});

test('a crash of another server is not found', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $other = $this->createServerModel(['user_id' => $user->id]);
    $crash = crash($other);
    $base = "/api/client/servers/{$server->uuid}/crash-doctor";
    Http::fake();

    $this->actingAs($user)->postJson("{$base}/crashes/{$crash->id}/dismiss")->assertNotFound();
    $this->actingAs($user)->postJson("{$base}/diagnose", ['crash_id' => $crash->id])->assertNotFound();
    expect(AiConversation::query()->count())->toBe(0);
});

test('the console reports a crash, recorded with its masked console lines', function (): void {
    [$user, $server] = $this->generateTestAccount();
    new DoctorWings;

    $response = $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/report", ['kind' => 'start_failed'])
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('crash.kind', 'start_failed')
        ->assertJsonPath('crash.status', 'open');

    $crash = ServerCrash::query()->sole();
    expect($response->json('crash.id'))->toBe($crash->id)
        ->and($crash->source)->toBe('console')
        ->and($crash->console)->toContain('UnsupportedClassVersionError')
        ->and($crash->console)->not->toContain('hunter2secret')
        ->and($crash->console)->not->toContain('198.51.100.23');

    // A second report is the same incident.
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/report", ['kind' => 'crashed'])->assertOk()->assertJsonPath('crash.id', $crash->id);
    expect(ServerCrash::query()->count())->toBe(1);

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/report", ['kind' => 'exploded'])->assertUnprocessable();
});

test('a report is ignored when a stop was asked for through the panel', function (): void {
    [$user, $server] = $this->generateTestAccount();
    Http::fake();
    CrashRecorder::expectPowerChange($server, 'kill');

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/report", ['kind' => 'crashed'])
        ->assertOk()
        ->assertJsonPath('crash', null);
    expect(ServerCrash::query()->count())->toBe(0);
});

test('diagnose pre-loads the console, the newest crash report and the end of latest.log, then answers in crash doctor mode', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $crash = crash($server);
    $wings = new DoctorWings;
    $wings->answers = [FakeAi::text("### What happened\nThe server stopped.\n### Likely cause\nA plugin needs Java 21.\n### Fix\nSwitch the image.")];

    $response = $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/diagnose", ['crash_id' => $crash->id])
        ->assertOk()
        ->assertJsonPath('mode', 'crash_doctor')
        ->assertJsonPath('title', 'Crash diagnosis')
        ->assertJsonPath('status', 'idle')
        ->assertJsonCount(3, 'messages')
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.1.role', 'assistant')
        ->assertJsonCount(3, 'messages.1.tool_calls')
        ->assertJsonPath('messages.1.tool_calls.0.name', 'read_console')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.1.tool_calls.1.name', 'read_file')
        ->assertJsonPath('messages.1.tool_calls.1.arguments.path', '/crash-reports/crash-2026-11-15_11.59.00-server.txt')
        ->assertJsonPath('messages.1.tool_calls.1.status', 'done')
        ->assertJsonPath('messages.1.tool_calls.2.arguments', ['path' => '/logs/latest.log', 'tail_lines' => 200])
        ->assertJsonPath('messages.1.tool_calls.2.label', 'Read the end of /logs/latest.log')
        ->assertJsonPath('messages.1.tool_calls.2.status', 'done')
        ->assertJsonPath('messages.2.content', "### What happened\nThe server stopped.\n### Likely cause\nA plugin needs Java 21.\n### Fix\nSwitch the image.");

    expect($response->json('messages.0.content'))->toStartWith('My server crashed (');

    // One model call, with the crash doctor's prompt, the three results and the fixing tools.
    $ai = $wings->aiRequests();
    expect($ai)->toHaveCount(1);
    $body = $ai[0]->data();
    $messages = collect($body['messages']);
    expect($messages[0]['role'])->toBe('system')
        ->and($messages[0]['content'])->toContain('crash doctor')
        ->and($messages[0]['content'])->toContain('### Likely cause');

    $results = $messages->where('role', 'tool')->keyBy('tool_call_id');
    expect($results['preload_console']['content'])->toContain('UnsupportedClassVersionError')
        ->and($results['preload_console']['content'])->not->toContain('hunter2secret')
        ->and($results['preload_console']['content'])->not->toContain('198.51.100.23')
        ->and($results['preload_crash_report']['content'])->toContain('newest report')
        ->and($results['preload_latest_log']['content'])->toContain('log line 300')
        ->and($results['preload_latest_log']['content'])->toContain('log line 101')
        ->and($results['preload_latest_log']['content'])->not->toContain('log line 100'."\n");

    expect(collect($body['tools'])->pluck('function.name')->all())->toContain('edit_file', 'update_startup_variable', 'set_docker_image', 'delete_files', 'power');

    // The crash is being diagnosed, and the status links the conversation.
    $conversation = AiConversation::query()->sole();
    expect($conversation->mode)->toBe('crash_doctor')
        ->and($crash->refresh()->status)->toBe('diagnosing')
        ->and($crash->conversation_id)->toBe($conversation->id);
    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/crash-doctor")
        ->assertJsonPath('crash.status', 'diagnosing')
        ->assertJsonPath('crash.conversation_uuid', $conversation->uuid);

    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/assistant/conversations")
        ->assertJsonPath('data.0.mode', 'crash_doctor');

    $this->assertActivityFor('server:crash-doctor.diagnose', $user, $server);
});

test('a manual check without crash reports reads the console and the log only', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $wings = new DoctorWings;
    $wings->crashReports = false;
    $wings->answers = [FakeAi::text('Nothing looks wrong.')];

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/diagnose", ['crash_id' => null])
        ->assertOk()
        ->assertJsonPath('messages.0.content', 'Please check my server: why does it crash or fail to start, and how do I fix it?')
        ->assertJsonCount(2, 'messages.1.tool_calls')
        ->assertJsonPath('messages.1.tool_calls.0.name', 'read_console')
        ->assertJsonPath('messages.1.tool_calls.1.arguments.path', '/logs/latest.log');

});

test('the pre-loaded readings follow the permissions of a subuser', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::AssistantUse->value]);
    $wings = new DoctorWings;
    $wings->answers = [FakeAi::text('I cannot read the logs.')];

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/diagnose", ['crash_id' => null])
        ->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.1.content', 'I cannot read the logs.');

    expect($wings->sent('/logs'))->toBeFalse()
        ->and($wings->sent('/files/'))->toBeFalse()
        ->and(AiToolCall::query()->count())->toBe(0)
        ->and(AiMessage::query()->where('role', 'tool')->count())->toBe(0);
});

test('a diagnosis is billed like any conversation and refused without free tokens or balance', function (): void {
    FakeAi::enable(freeTokens: 100);
    [$user, $server] = $this->generateTestAccount();
    resolve(AssistantBilling::class)->record($user, null, AiConversation::SCOPE_SERVER, 100);
    $crash = crash($server);
    Http::fake();

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/crash-doctor/diagnose", ['crash_id' => $crash->id])->assertStatus(402);
    Http::assertNothingSent();
    expect(AiConversation::query()->count())->toBe(0)
        ->and($crash->refresh()->status)->toBe('open');
});
