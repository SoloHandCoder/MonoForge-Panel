<?php

declare(strict_types=1);

namespace Pterodactyl\Extensions\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through the Resend HTTP API (https://resend.com/docs/api-reference/emails/send-email)
 * with Laravel's HTTP client, so no Resend SDK is needed. The API key is only ever sent to
 * api.resend.com as a bearer token and never appears in exception messages.
 */
class ResendTransport extends AbstractTransport
{
    public const string ENDPOINT = 'https://api.resend.com/emails';

    /** Headers the API takes as dedicated fields rather than in "headers". */
    private const array BYPASSED_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type', 'mime-version', 'date', 'message-id'];

    public function __construct(private readonly HttpFactory $http, #[SensitiveParameter] private readonly string $key)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'resend';
    }

    /**
     * Build the JSON body for POST /emails.
     *
     * @return array<string, mixed>
     */
    public function payload(Email $email, Envelope $envelope): array
    {
        $headers = [];
        foreach ($email->getHeaders()->all() as $header) {
            if (! $header instanceof HeaderInterface || in_array(mb_strtolower($header->getName()), self::BYPASSED_HEADERS, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $prepared = $attachment->getPreparedHeaders();
            $filename = $prepared->getHeaderParameter('Content-Disposition', 'filename');

            $item = [
                'filename' => $filename,
                'content' => base64_encode($attachment->getBody()),
                'content_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
            ];

            if ($prepared->getHeaderBody('Content-Disposition') === 'inline') {
                $item['content_id'] = $attachment->hasContentId() ? $attachment->getContentId() : $filename;
            }

            $attachments[] = $item;
        }

        $from = $email->getFrom()[0] ?? $envelope->getSender();

        $payload = [
            'from' => $from->toString(),
            'to' => $this->addresses($this->recipients($email, $envelope)),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'reply_to' => $this->addresses($email->getReplyTo()),
            'subject' => (string) $email->getSubject(),
            'html' => $this->body($email->getHtmlBody()),
            'text' => $this->body($email->getTextBody()),
            'headers' => $headers,
            'attachments' => $attachments,
        ];

        // Resend rejects empty arrays and nulls for optional fields.
        return array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    protected function doSend(SentMessage $message): void
    {
        throw_if($this->key === '', TransportException::class, 'Resend is selected as the mail driver but no Resend API key is configured.');

        $original = $message->getOriginalMessage();
        throw_unless($original instanceof Message, TransportException::class, 'Resend can only send MIME messages.');

        $email = MessageConverter::toEmail($original);

        try {
            $response = $this->http
                ->withToken($this->key)
                ->acceptJson()
                ->timeout(30)
                ->post(self::ENDPOINT, $this->payload($email, $message->getEnvelope()));
        } catch (ConnectionException $connectionException) {
            throw new TransportException('Could not reach the Resend API: '.$connectionException->getMessage(), 0, $connectionException);
        }

        if ($response->failed()) {
            $reason = $response->json('message');

            throw new TransportException(sprintf(
                'Request to the Resend API failed (HTTP %d): %s',
                $response->status(),
                is_string($reason) && $reason !== '' ? $reason : 'no error message returned',
            ), $response->status());
        }

        $id = $response->json('id');
        if (is_string($id) && $id !== '') {
            $message->setMessageId($id);
            $original->getHeaders()->addTextHeader('X-Resend-Email-ID', $id);
        }
    }

    /**
     * The envelope recipients minus CC and BCC, which the API takes separately.
     *
     * @return list<Address>
     */
    private function recipients(Email $email, Envelope $envelope): array
    {
        $copies = array_map(fn (Address $address): string => $address->getAddress(), [...$email->getCc(), ...$email->getBcc()]);

        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address): bool => ! in_array($address->getAddress(), $copies, true),
        ));
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => $address->toString(), $addresses));
    }

    /**
     * @param  resource|string|null  $body
     */
    private function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return is_string($body) && $body !== '' ? $body : null;
    }
}
