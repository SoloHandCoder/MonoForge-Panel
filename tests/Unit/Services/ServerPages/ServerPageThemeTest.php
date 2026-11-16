<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\ServerPages\ServerPageThemeTest;

use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\ServerPages\ServerPageLinks;
use Pterodactyl\Services\ServerPages\ServerPageText;
use Pterodactyl\Services\ServerPages\ServerPageTheme;

/** @param array<string, mixed> $attributes */
function themePage(array $attributes = []): ServerPage
{
    return (new ServerPage)->forceFill(['slug' => 'skyblock', ...$attributes]);
}

test('the contrast ratio follows WCAG', function (): void {
    expect(ServerPageTheme::contrast('#000000', '#ffffff'))->toEqualWithDelta(21.0, 0.001)
        ->and(ServerPageTheme::contrast('#ffffff', '#ffffff'))->toEqualWithDelta(1.0, 0.001)
        // The brand violet with white text: 4.23:1.
        ->and(ServerPageTheme::contrast('#ffffff', '#8b5cf6'))->toEqualWithDelta(4.23, 0.01);
});

test('the text on any colour reaches AA', function (): void {
    // Every 17th step of each channel, mid greys included.
    for ($r = 0; $r <= 255; $r += 51) {
        for ($g = 0; $g <= 255; $g += 51) {
            for ($b = 0; $b <= 255; $b += 51) {
                $color = sprintf('#%02x%02x%02x', $r, $g, $b);
                expect(ServerPageTheme::contrast(ServerPageTheme::textOn($color), $color))->toBeGreaterThanOrEqual(ServerPageTheme::MIN_CONTRAST);
            }
        }
    }

    expect(ServerPageTheme::textOn('#0e0d15'))->toBe('#ffffff')
        ->and(ServerPageTheme::textOn('#ffd166'))->toBe('#000000')
        ->and(ServerPageTheme::textOn('#777777'))->toBe('#000000');
});

test('a gradient gets a text colour that reads on both ends, or none', function (): void {
    expect(ServerPageTheme::textFor('#0e0d15', '#5b21b6'))->toBe('#ffffff')
        ->and(ServerPageTheme::textFor('#fef3c7', '#bae6fd'))->toBe('#000000')
        ->and(ServerPageTheme::textFor('#000000', '#ffffff'))->toBeNull()
        ->and(ServerPageTheme::textFor('#ffd166', null))->toBe('#000000');
});

test('the theme of a page falls back to readable defaults', function (): void {
    expect(ServerPageTheme::for(themePage()))->toBe([
        'accent' => '#8b5cf6',
        'accent_text' => '#000000',
        'background' => ['style' => 'solid', 'from' => '#241a44', 'to' => null, 'image_url' => null],
        'text' => '#ffffff',
        'button_style' => 'rounded',
    ]);
});

test('a light background gets dark text, and a gradient its shared text colour', function (): void {
    expect(ServerPageTheme::for(themePage(['background_from' => '#FEF3C7']))['text'])->toBe('#000000');

    $theme = ServerPageTheme::for(themePage(['background_style' => 'gradient', 'background_from' => '#0e0d15', 'background_to' => '#5B21B6', 'button_style' => 'square']));
    expect($theme['background'])->toBe(['style' => 'gradient', 'from' => '#0e0d15', 'to' => '#5b21b6', 'image_url' => null])
        ->and($theme['text'])->toBe('#ffffff')
        ->and($theme['button_style'])->toBe('square');
});

test('an image background without an image falls back to the colours, and with one has white text', function (): void {
    expect(ServerPageTheme::for(themePage(['background_style' => 'image', 'background_from' => '#fef3c7']))['background']['style'])->toBe('solid');

    $theme = ServerPageTheme::for(themePage(['background_style' => 'image', 'background_from' => '#fef3c7', 'banner_image' => str_repeat('a', 40).'.png']));
    expect($theme['background'])->toBe(['style' => 'image', 'from' => '#fef3c7', 'to' => null, 'image_url' => '/s/skyblock/banner/'.str_repeat('a', 40).'.png'])
        ->and($theme['text'])->toBe('#ffffff');
});

test('stored values that are not colours or known choices are never passed on', function (): void {
    $theme = ServerPageTheme::for(themePage(['banner_color' => 'red;}', 'background_from' => 'url(x)', 'background_style' => 'video', 'button_style' => 'blob']));

    expect($theme['accent'])->toBe('#8b5cf6')
        ->and($theme['background']['from'])->toBe('#241a44')
        ->and($theme['background']['style'])->toBe('solid')
        ->and($theme['button_style'])->toBe('rounded');
});

test('links are only passed on when they are https and, for discord, an invite', function (): void {
    expect(ServerPageLinks::discord('https://discord.gg/abc123'))->toBe('https://discord.gg/abc123')
        ->and(ServerPageLinks::discord('https://www.discord.com/invite/abc123/'))->toBe('https://www.discord.com/invite/abc123/')
        ->and(ServerPageLinks::discord('https://discord.gg/abc123?x=1#y'))->toBe('https://discord.gg/abc123?x=1#y')
        ->and(ServerPageLinks::discord('https://evil.example/discord.gg/abc'))->toBeNull()
        ->and(ServerPageLinks::discord('https://discord.gg@evil.example/abc'))->toBeNull()
        ->and(ServerPageLinks::discord(null))->toBeNull()
        ->and(ServerPageLinks::website('https://example.com/a?b=c'))->toBe('https://example.com/a?b=c')
        ->and(ServerPageLinks::website('https://localhost'))->toBeNull()
        ->and(ServerPageLinks::website('https://exa mple.com'))->toBeNull()
        ->and(ServerPageLinks::website('//example.com'))->toBeNull()
        ->and(ServerPageLinks::website('HTTPS://example.com'))->toBeNull();
});

test('owner text becomes one clamped line of plain text', function (): void {
    expect(ServerPageText::line("  Hello\n\tworld \u{00A7}cRed\u{202E}  ", 80))->toBe('Hello world Red')
        ->and(ServerPageText::line(str_repeat('a', 90), 80))->toBe(str_repeat('a', 79).'…')
        ->and(mb_strlen(ServerPageText::line(str_repeat('a', 90), 80)))->toBe(80)
        ->and(ServerPageText::line(null, 80))->toBe('')
        ->and(ServerPageText::excerpt("# Title\n\nA **bold** [link](https://x.y) and ![img](https://x.y/i.png)<script>x</script>"))->toBe('Title A bold link and x');
});
