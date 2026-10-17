<?php
declare(strict_types=1);
namespace Duplika\WhmcsResend;

use WHMCS\Mail\Message;

final class MessageMapper
{
    // Conservative local ceiling, leaving room for Base64, MIME and the message body.
    public const MAX_RAW_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    public function map(Message $message): array
    {
        $payload = [
            'from' => self::mailbox((string) $message->getFromEmail(), (string) $message->getFromName()),
            'subject' => self::singleLine((string) $message->getSubject()),
        ];
        foreach (['to', 'cc', 'bcc'] as $kind) {
            $recipients = [];
            foreach ($message->getRecipients($kind) as $recipient) {
                if (!is_array($recipient) || !isset($recipient[0])) {
                    throw new MailError('The recipient format is not supported by this provider.');
                }
                $email = self::email((string) $recipient[0]);
                $recipients[strtolower($email)] = self::mailbox($email, (string) ($recipient[1] ?? ''));
            }
            if ($recipients) {
                $payload[$kind] = array_values($recipients);
            }
        }
        if (empty($payload['to'])) {
            throw new MailError('The email requires at least one To recipient.');
        }
        if (count($payload['to']) > 50) {
            throw new MailError('Resend supports a maximum of 50 To recipients per message.');
        }
        $reply = $message->getReplyTo();
        if ($reply) {
            $payload['reply_to'] = self::mailbox((string) ($reply['email'] ?? ''), (string) ($reply['name'] ?? ''));
        }
        $html = (string) $message->getBody();
        $text = (string) $message->getPlainText();
        if ($html === '' && $text === '') {
            throw new MailError('The email has no HTML or plain-text content.');
        }
        if ($html !== '') { $payload['html'] = $html; }
        if ($text !== '') { $payload['text'] = $text; }
        $headers = $this->headers($message->getHeaders());
        if ($headers) { $payload['headers'] = $headers; }
        $attachments = $this->attachments($message->getAttachments());
        if ($attachments) { $payload['attachments'] = $attachments; }
        return $payload;
    }

    public static function email(string $email): string
    {
        self::singleLine($email);
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MailError('An email address is empty or invalid. Check the sender, recipients and test configuration.');
        }
        return $email;
    }

    private static function mailbox(string $email, string $name): string
    {
        $email = self::email($email);
        $name = trim(self::singleLine($name));
        return $name === '' ? $email : '"' . addcslashes($name, '\\"') . '" <' . $email . '>';
    }

    private static function singleLine(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new MailError('A header field contains prohibited control characters.');
        }
        return $value;
    }

    private function headers(array $source): array
    {
        $allowed = ['message-id', 'in-reply-to', 'references', 'auto-submitted',
            'x-auto-response-suppress', 'list-unsubscribe', 'list-unsubscribe-post',
            'list-id', 'precedence', 'x-priority', 'importance'];
        $headers = [];
        foreach ($source as $name => $value) {
            // WHMCS exposes a name => value map. Accept explicit name/value pairs too.
            if (is_int($name) && is_array($value)) {
                [$name, $value] = [$value['name'] ?? $value[0] ?? '', $value['value'] ?? $value[1] ?? ''];
            }
            if (!is_string($name) || !is_scalar($value)) {
                throw new MailError('The header format is not supported by this provider.');
            }
            self::singleLine($name);
            $name = strtolower($name);
            if (in_array($name, $allowed, true) || preg_match('/^x-whmcs-[a-z0-9-]+$/D', $name)) {
                // Unfold legal RFC header continuations, then reject all other control characters.
                $value = preg_replace('/\r?\n[ \t]+/', ' ', (string) $value);
                $headers[$name] = self::singleLine($value);
            }
        }
        return $headers;
    }

    private function attachments(array $source): array
    {
        $result = [];
        $remaining = self::MAX_RAW_ATTACHMENT_BYTES;
        foreach ($source as $attachment) {
            if (!is_array($attachment) || empty($attachment['filename'])) {
                throw new MailError('An attachment is missing its filename.');
            }
            $filename = basename(str_replace('\\', '/', self::singleLine((string) $attachment['filename'])));
            if ($filename === '' || $filename === '.' || $filename === '..') {
                throw new MailError('Invalid attachment filename.');
            }
            if (array_key_exists('data', $attachment)) {
                if (!is_string($attachment['data'])) {
                    throw new MailError('Attachment content must be a binary string.');
                }
                $data = $attachment['data'];
            } else {
                $path = (string) ($attachment['filepath'] ?? '');
                // No remote fetching, PHP streams or file:// wrappers.
                if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')
                    || !is_file($path) || !is_readable($path)) {
                    throw new MailError('Could not read a local attachment. The email was not sent.');
                }
                $handle = @fopen($path, 'rb');
                if ($handle === false) { throw new MailError('Could not open a local attachment.'); }
                try { $data = stream_get_contents($handle, $remaining + 1); }
                finally { fclose($handle); }
                if ($data === false) { throw new MailError('Could not read the contents of a local attachment.'); }
            }
            $remaining -= strlen($data);
            if ($remaining < 0) {
                throw new MailError('Attachments exceed the local limit of 20 MiB per email.');
            }
            $item = ['filename' => $filename, 'content' => base64_encode($data)];
            if (!empty($attachment['content_type'])) {
                $mime = self::singleLine((string) $attachment['content_type']);
                if (!preg_match('~^[a-zA-Z0-9.+_-]+/[a-zA-Z0-9.+_-]+$~D', $mime)) {
                    throw new MailError('Invalid attachment content type.');
                }
                $item['content_type'] = $mime;
            }
            if (!empty($attachment['content_id'])) {
                $item['content_id'] = self::singleLine((string) $attachment['content_id']);
            }
            $result[] = $item;
        }
        return $result;
    }
}
