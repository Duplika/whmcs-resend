<?php
declare(strict_types=1);
namespace Duplika\WhmcsResend;

class ApiClient
{
    public const ENDPOINT = 'https://api.resend.com/emails';
    public const MAX_JSON_BYTES = 35 * 1024 * 1024;
    private const TOTAL_SECONDS = 25.0;

    public function __construct(
        private string $apiKey,
        private int $timeout = 10,
        private int $maxAttempts = 2
    ) {
        self::validateKey($apiKey);
        if ($timeout < 1 || $timeout > 20 || $maxAttempts < 1 || $maxAttempts > 3) {
            throw new MailError('Invalid transport configuration.');
        }
    }

    public static function validateKey(string $key): void
    {
        if (!preg_match('/^re_[A-Za-z0-9_-]{8,200}$/D', $key)) {
            throw new MailError('The Resend API key is empty or has an invalid format.');
        }
    }

    public function send(array $payload, string $key): string
    {
        if (!preg_match('/^[A-Za-z0-9_\/-]{1,256}$/D', $key)) {
            throw new MailError('The idempotency key is invalid.');
        }
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $error) {
            throw new MailError('The email contains invalid UTF-8 text or data that cannot be serialized.');
        }
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new MailError('The encoded message exceeds the local 35 MiB limit.');
        }
        $deadline = $this->now() + self::TOTAL_SECONDS;
        $failure = 'Could not confirm that Resend accepted the email.';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $remaining = $deadline - $this->now();
            if ($remaining < 1) { break; }
            $response = $this->request($json, $key, (int) floor(min($this->timeout, $remaining) * 1000));
            $status = (int) $response['status'];
            $errno = (int) $response['errno'];
            $body = json_decode((string) $response['body'], true);
            $name = is_array($body) && is_string($body['name'] ?? null) ? $body['name'] : '';
            if ($errno === 0 && $status >= 200 && $status < 300
                && is_array($body) && is_string($body['id'] ?? null)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $body['id'])) {
                return $body['id'];
            }
            $failure = $this->failure($status, $errno, $name);
            $retryable = $errno !== 0
                ? in_array($errno, [5, 6, 7, 18, 28, 35, 52, 55, 56, 92], true)
                : ($status === 408 || $status === 429 || $status >= 500
                    || ($status === 409 && $name === 'concurrent_idempotent_requests')
                    || ($status >= 200 && $status < 300));
            if (in_array($name, ['daily_quota_exceeded', 'monthly_quota_exceeded'], true)) {
                $retryable = false;
            }
            if (!$retryable || $attempt === $this->maxAttempts) { break; }
            $delay = max((float) (2 ** ($attempt - 1)), $this->retryAfter($response['headers'] ?? []))
                + $this->jitter();
            // Never retry earlier than Retry-After, or extend the total request budget.
            if ($this->now() + $delay + 1 >= $deadline) { break; }
            $this->pause($delay);
        }
        throw new MailError($failure);
    }

    /** Transport seam for offline tests. The production endpoint is deliberately fixed. */
    protected function request(string $json, string $key, int $timeoutMs): array
    {
        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) { throw new MailError('Could not initialize cURL.'); }
        $body = '';
        $headers = [];
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Idempotency-Key: ' . $key,
                    'Expect:',
                ],
                CURLOPT_USERAGENT => 'Duplika-WHMCS-Resend/1.0.2',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (stripos($line, 'HTTP/') === 0) { $headers = []; }
                    if (stripos($line, 'Retry-After:') === 0) {
                        $headers['retry-after'] = trim(substr($line, strlen('Retry-After:')));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) { return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_exec($handle);
            return [
                'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'errno' => curl_errno($handle), 'body' => $body, 'headers' => $headers,
            ];
        } finally {
            curl_close($handle);
        }
    }

    private function retryAfter(array $headers): float
    {
        $value = $headers['retry-after'] ?? '';
        if (is_numeric($value)) { return max(0.0, (float) $value); }
        $date = is_string($value) && $value !== '' ? strtotime($value) : false;
        return $date === false ? 0.0 : max(0.0, $date - time());
    }

    private function failure(int $status, int $errno, string $name): string
    {
        if ($errno !== 0) {
            return 'Connection to Resend failed (cURL ' . $errno
                . '). Delivery is unconfirmed; check Resend before manually resending.';
        }
        if (in_array($name, ['daily_quota_exceeded', 'monthly_quota_exceeded'], true)) {
            return 'The Resend email quota has been reached. Check your transactional email plan.';
        }
        return match ($status) {
            401 => 'Resend rejected the API key (HTTP 401). Check the key and its permissions.',
            403 => 'Resend did not authorize the email (HTTP 403). Check the sender domain and API key permissions.',
            400, 422 => 'Resend rejected the message (HTTP ' . $status . '). Check addresses, headers, attachments and limits in the Resend dashboard.',
            409 => $name === 'concurrent_idempotent_requests'
                ? 'Resend is still processing this request (HTTP 409). Check the result before resending.'
                : 'Resend idempotency conflict (HTTP 409). The request was not retried with a different key.',
            429 => 'Resend rate limit reached (HTTP 429). Check the sending rate of WHMCS and your Resend account.',
            default => 'Resend did not return a valid email identifier (HTTP ' . $status
                . '). Check Resend before manually resending.',
        };
    }

    protected function now(): float { return hrtime(true) / 1_000_000_000; }
    protected function pause(float $seconds): void { usleep((int) ($seconds * 1_000_000)); }
    protected function jitter(): float { return random_int(0, 250) / 1000; }
}
