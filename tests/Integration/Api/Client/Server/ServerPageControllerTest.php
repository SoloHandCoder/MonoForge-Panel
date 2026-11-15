<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\ServerPageControllerTest;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\Share\ServerPageSlugHold;
use Pterodactyl\Models\User;
use Pterodactyl\Services\ServerPages\ServerPageBanners;
use Pterodactyl\Services\ServerPages\ServerPageSlug;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/** A real image of the given type, made with GD. */
function bannerImage(string $type = 'png', int $width = 64, int $height = 32): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 139, 92, 246));
    ob_start();
    match ($type) {
        'png' => imagepng($image),
        'jpg' => imagejpeg($image),
        'webp' => imagewebp($image),
        'gif' => imagegif($image),
    };

    return UploadedFile::fake()->createWithContent("banner.{$type}", (string) ob_get_clean());
}

/** @return array<string, mixed> */
function pageInput(array $overrides = []): array
{
    return [
        'slug' => 'skyblock',
        'published' => true,
        'noindex' => false,
        'description' => 'A **friendly** server',
        'banner_color' => '#8B5CF6',
        ...$overrides,
    ];
}

function pageUrl(Server $server, string $path = ''): string
{
    return "/api/client/servers/{$server->uuid}/page{$path}";
}

test('a server without a page returns the defaults', function (): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->getJson(pageUrl($server))
        ->assertOk()
        ->assertExactJson([
            'exists' => false,
            'slug' => null,
            'url' => null,
            'published' => false,
            'noindex' => false,
            'description' => '',
            'banner_color' => null,
            'banner_image_url' => null,
            'votes' => 0,
            'has_address' => false,
            'allow_wake' => false,
        ]);
});

test('managing the page needs share.manage', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson(pageUrl($server))->assertForbidden();
    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertForbidden();
    $this->actingAs($user)->postJson(pageUrl($server, '/banner'), ['banner' => bannerImage()])->assertForbidden();
    $this->actingAs($user)->deleteJson(pageUrl($server, '/banner'))->assertForbidden();

    expect(ServerPage::query()->count())->toBe(0);
});

test('a subuser with share.manage publishes the page and it is logged', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ShareManage->value]);

    $this->actingAs($user)->putJson(pageUrl($server), pageInput())
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('slug', 'skyblock')
        ->assertJsonPath('url', mb_rtrim(config()->string('app.url'), '/').'/s/skyblock')
        ->assertJsonPath('published', true)
        ->assertJsonPath('banner_color', '#8b5cf6')
        ->assertJsonPath('description', 'A **friendly** server');

    $page = ServerPage::query()->sole();
    expect($page->server_id)->toBe($server->id)->and($page->published)->toBeTrue();

    $events = ActivityLog::query()->pluck('event')->all();
    expect($events)->toContain('server:page.update')->toContain('server:page.publish');

    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['published' => false, 'description' => '  ']))
        ->assertOk()
        ->assertJsonPath('published', false)
        ->assertJsonPath('description', '');

    expect(ActivityLog::query()->pluck('event')->all())->toContain('server:page.unpublish')
        ->and(ServerPage::query()->count())->toBe(1);
});

test('the slug must be well formed, not reserved and not taken', function (string $slug): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['slug' => $slug]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'slug');
})->with([
    'too short' => 'ab',
    'too long' => str_repeat('a', 33),
    'uppercase' => 'SkyBlock',
    'a double hyphen' => 'sky--block',
    'a leading hyphen' => '-sky',
    'a trailing hyphen' => 'sky-',
    'a dot' => 'sky.block',
    'a slash' => 'sky/block',
    'reserved: admin' => 'admin',
    'reserved: status' => 'status',
    'reserved: monoforge' => 'monoforge',
]);

test('a slug another server uses is taken, but a server keeps its own', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $other = $this->createServerModel();
    ServerPage::query()->create(['server_id' => $other->id, 'slug' => 'skyblock']);

    $this->actingAs($user)->putJson(pageUrl($server), pageInput())
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'That address is already taken.');

    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['slug' => 'my-island']))->assertOk();
    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['slug' => 'my-island', 'noindex' => true]))
        ->assertOk()
        ->assertJsonPath('noindex', true);
});

test('the description and banner colour are validated', function (array $input, string $field): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->putJson(pageUrl($server), pageInput($input))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', $field);
})->with([
    'a long description' => [['description' => str_repeat('a', 2001)], 'description'],
    'a named colour' => [['banner_color' => 'red'], 'banner_color'],
    'a short colour' => [['banner_color' => '#fff'], 'banner_color'],
    'a colour with css' => [['banner_color' => '#ffffff;background:url(x)'], 'banner_color'],
]);

test('a page of another server cannot be changed', function (): void {
    [$user] = $this->generateTestAccount();
    $other = $this->createServerModel();

    $this->actingAs($user)->getJson(pageUrl($other))->assertNotFound();
    $this->actingAs($user)->putJson(pageUrl($other), pageInput())->assertNotFound();
});

test('a banner can only be added once the page is saved', function (): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->postJson(pageUrl($server, '/banner'), ['banner' => bannerImage()])->assertStatus(409);
});

test('png, jpeg and webp banners are stored re-encoded under a random name, and replacing one deletes the old file', function (string $type, string $extension): void {
    [$user, $server] = $this->generateTestAccount();
    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertOk();

    $first = $this->actingAs($user)->postJson(pageUrl($server, '/banner'), ['banner' => bannerImage($type)])
        ->assertOk()
        ->json('banner_image_url');

    expect($first)->toMatch('#\A/s/skyblock/banner/[a-f0-9]{40}\.'.$extension.'\z#');
    $firstName = basename((string) $first);
    Storage::disk('local')->assertExists('server-pages/'.$firstName);
    expect(ActivityLog::query()->pluck('event')->all())->toContain('server:page.banner');

    $second = $this->actingAs($user)->postJson(pageUrl($server, '/banner'), ['banner' => bannerImage($type)])->assertOk()->json('banner_image_url');
    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing('server-pages/'.$firstName);

    $this->actingAs($user)->deleteJson(pageUrl($server, '/banner'))->assertOk()->assertJsonPath('banner_image_url', null);
    Storage::disk('local')->assertMissing('server-pages/'.basename((string) $second));
    expect(ActivityLog::query()->pluck('event')->all())->toContain('server:page.banner-delete');
})->with([
    'png' => ['png', 'png'],
    'jpeg' => ['jpg', 'jpg'],
    'webp' => ['webp', 'webp'],
]);

test('metadata and bytes after the image are dropped by re-encoding', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertOk();
    $image = bannerImage();
    $polyglot = UploadedFile::fake()->createWithContent('banner.png', $image->getContent().'<script>alert(1)</script>');

    $url = $this->actingAs($user)->postJson(pageUrl($server, '/banner'), ['banner' => $polyglot])->assertOk()->json('banner_image_url');

    expect(Storage::disk('local')->get('server-pages/'.basename((string) $url)))->not->toContain('<script>');
});

test('anything but a real png, jpeg or webp image is refused', function (UploadedFile $file): void {
    [$user, $server] = $this->generateTestAccount();
    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertOk();

    $this->actingAs($user)->postJson(pageUrl($server, '/banner'), ['banner' => $file])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'banner');

    expect(Storage::disk('local')->allFiles('server-pages'))->toBe([])
        ->and(ServerPage::query()->sole()->banner_image)->toBeNull();
})->with([
    'svg' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('banner.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'),
    'svg named png' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('banner.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
    'gif' => fn (): UploadedFile => bannerImage('gif'),
    'html' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('banner.png', '<html><script>alert(1)</script></html>'),
    'a png signature with garbage' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('banner.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200)),
    'too large' => fn (): UploadedFile => UploadedFile::fake()->create('banner.png', 1025, 'image/png'),
    'too wide' => fn (): UploadedFile => bannerImage('png', 4097, 1),
    'more than 8 megapixels' => fn (): UploadedFile => bannerImage('png', 4000, 2100),
]);

test('a slug containing a brand or role word is refused, except for a root admin', function (string $slug): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['slug' => $slug]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', ServerPageSlug::RESERVED_PART_MESSAGE);

    $admin = User::factory()->create(['root_admin' => true]);
    $this->actingAs($admin)->putJson(pageUrl($server), pageInput(['slug' => $slug]))->assertOk()->assertJsonPath('slug', $slug);
})->with(['monoforge-support', 'official-staff', 'admin-login', 'support-team', 'mypanel', 'billing-help', 'status-page', 'storefront']);

test('a renamed slug is held for its server for 30 days', function (): void {
    [$user, $server] = $this->generateTestAccount();
    [$other, $otherServer] = $this->generateTestAccount();

    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['slug' => 'popular-page']))->assertOk();
    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['slug' => 'new-name']))->assertOk();

    // Somebody else cannot take the old address while links to it are still around...
    $this->actingAs($other)->putJson(pageUrl($otherServer), pageInput(['slug' => 'popular-page']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', ServerPageSlug::HELD_MESSAGE);

    // ...but the same server can take it back.
    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['slug' => 'popular-page']))->assertOk();
    expect(ServerPageSlugHold::query()->where('slug', 'popular-page')->exists())->toBeFalse()
        ->and(ServerPageSlugHold::query()->where('slug', 'new-name')->value('server_id'))->toBe($server->id);

    // After 30 days the hold is gone.
    $this->travel(ServerPageSlugHold::HOLD_DAYS + 1)->days();
    $this->actingAs($other)->putJson(pageUrl($otherServer), pageInput(['slug' => 'new-name']))->assertOk();
    $this->artisan('model:prune', ['--model' => [ServerPageSlugHold::class]])->assertSuccessful();
    expect(ServerPageSlugHold::query()->count())->toBe(0);
});

test('the owner lets visitors wake the server; it is off by default', function (): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertOk()->assertJsonPath('allow_wake', false);
    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['allow_wake' => true]))->assertOk()->assertJsonPath('allow_wake', true);
    // Left out, it keeps its value.
    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertOk()->assertJsonPath('allow_wake', true);
    $this->actingAs($user)->putJson(pageUrl($server), pageInput(['allow_wake' => 'maybe']))->assertUnprocessable();
});

test('unpublishing gives the banner a new name, so the old address stops working', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertOk();
    $old = (string) $this->actingAs($user)->postJson(pageUrl($server, '/banner'), ['banner' => bannerImage()])->json('banner_image_url');

    $new = (string) $this->actingAs($user)->putJson(pageUrl($server), pageInput(['published' => false]))->assertOk()->json('banner_image_url');

    expect($new)->not->toBe($old);
    Storage::disk('local')->assertMissing('server-pages/'.basename($old));
    Storage::disk('local')->assertExists('server-pages/'.basename($new));

    // Published again, the old address does not come back either.
    $this->actingAs($user)->putJson(pageUrl($server), pageInput())->assertOk();
    $this->get($old)->assertNotFound();
    $this->get($new)->assertOk()->assertHeader('Cache-Control', 'max-age=300, public');
});

test('without gd a jpeg carrying exif is refused, and other images are only checked', function (): void {
    $banners = new class extends ServerPageBanners
    {
        protected function canReencode(): bool
        {
            return false;
        }
    };
    $jpeg = (string) bannerImage('jpg')->getContent();
    $exif = "\xFF\xD8\xFF\xE1\x00\x10Exif\x00\x00II*\x00\x08\x00\x00\x00".mb_substr($jpeg, 2);

    expect(fn (): string => $banners->store(UploadedFile::fake()->createWithContent('photo.jpg', $exif)))->toThrow(ValidationException::class);
    expect($banners->store(UploadedFile::fake()->createWithContent('plain.jpg', $jpeg)))->toMatch('/\A[a-f0-9]{40}\.jpg\z/');
});
