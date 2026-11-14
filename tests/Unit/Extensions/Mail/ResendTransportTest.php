<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Extensions\Mail\ResendTransportTest;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Pterodactyl\Extensions\Mail\ResendTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

function message(): Email
{
    return (new Email)
        ->from(new Address('panel@example.com', 'MonoForge'))
        ->to('player@example.com')
        ->cc('staff@example.com')
        ->replyTo('support@example.com')
        ->subject('Hello')
        ->text('Plain body')
        ->html('<p>HTML body</p>')
        ->attach('file contents', 'notes.txt', 'text/plain');
}

test('posts the message to the Resend API with the key as a bearer token', function (): void {
    $http = new Factory;
    $http->fake(['api.resend.com/*' => $http->response(['id' => 're_123'])]);

    $sent = (new ResendTransport($http, 're_test_key'))->send(message());

    $http->assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === ResendTransport::ENDPOINT
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer re_test_key')
            && $data['from'] === '"MonoForge" <panel@example.com>'
            && $data['to'] === ['player@example.com']
            && $data['cc'] === ['staff@example.com']
            && $data['reply_to'] === ['support@example.com']
            && $data['subject'] === 'Hello'
            && $data['text'] === 'Plain body'
            && $data['html'] === '<p>HTML body</p>'
            && $data['attachments'][0]['filename'] === 'notes.txt'
            && base64_decode($data['attachments'][0]['content'], true) === 'file contents'
            && ! isset($data['bcc']);
    });

    expect($sent?->getMessageId())->toBe('re_123');
});

test('surfaces API errors as transport exceptions without the key', function (): void {
    $http = new Factory;
    $http->fake(['api.resend.com/*' => $http->response(['statusCode' => 403, 'message' => 'The domain is not verified.', 'name' => 'validation_error'], 403)]);

    try {
        (new ResendTransport($http, 're_secret_key'))->send(message());
        $this->fail('Expected a transport exception.');
    } catch (TransportException $transportException) {
        expect($transportException->getMessage())
            ->toContain('HTTP 403')
            ->toContain('The domain is not verified.')
            ->not->toContain('re_secret_key');
    }
});

test('refuses to send without an API key', function (): void {
    $http = new Factory;
    $http->fake();

    expect(fn (): ?SentMessage => (new ResendTransport($http, ''))->send(message()))->toThrow(TransportException::class);
    $http->assertNothingSent();
});
