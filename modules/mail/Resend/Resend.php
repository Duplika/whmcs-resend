<?php
declare(strict_types=1);

namespace WHMCS\Module\Mail;

use WHMCS\Authentication\CurrentUser;
use WHMCS\Exception\Mail\SendFailure;
use WHMCS\Exception\Module\InvalidConfiguration;
use WHMCS\Mail\Message;
use WHMCS\Module\Contracts\SenderModuleInterface;
use WHMCS\Module\MailSender\DescriptionTrait;
use Duplika\WhmcsResend\ApiClient;
use Duplika\WhmcsResend\MailError;
use Duplika\WhmcsResend\MessageMapper;

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/lib/MailError.php';
require_once __DIR__ . '/lib/MessageMapper.php';
require_once __DIR__ . '/lib/ApiClient.php';

/** WHMCS mail provider. No hooks, Composer changes or database migrations. */
class Resend implements SenderModuleInterface
{
    use DescriptionTrait;

    public const VERSION = '1.0.2';
    private static ?\WeakMap $requests = null;

    public function getName() { return 'Resend'; }
    public function getDisplayName() { return 'Resend API'; }

    public function settings()
    {
        return [
            'api_key' => [
                'FriendlyName' => 'Resend API key', 'Type' => 'password',
                'Description' => 'API key with Sending access permission for the sender domains.',
            ],
            'test_from' => [
                'FriendlyName' => 'Test sender', 'Type' => 'text',
                'Description' => 'Email address from a domain verified in Resend. Used only when testing the configuration.',
            ],
            'timeout' => [
                'FriendlyName' => 'Timeout per attempt', 'Type' => 'dropdown',
                'Options' => '10,15,20', 'Default' => '10',
                'Description' => 'Seconds. All API attempts share a maximum time budget of 25 seconds.',
            ],
            'max_attempts' => [
                'FriendlyName' => 'Maximum attempts', 'Type' => 'dropdown',
                'Options' => '1,2,3', 'Default' => '2',
                'Description' => 'Includes the initial attempt. Only temporary failures are retried, using the same idempotency key.',
            ],
        ];
    }

    public function testConnection(array $settings)
    {
        try {
            $this->validateSettings($settings);
            $admin = (new CurrentUser())->admin();
            if (!$admin || empty($admin->email)) {
                throw new MailError('Could not determine the email address of the currently logged-in administrator.');
            }
            $message = new Message();
            foreach (['to', 'cc', 'bcc'] as $kind) { $message->clearRecipients($kind); }
            $message->setFromEmail(MessageMapper::email((string) ($settings['test_from'] ?? '')));
            $message->setFromName('WHMCS - Resend');
            $message->addRecipient('to', MessageMapper::email((string) $admin->email));
            $message->setSubject('WHMCS: Resend API configuration test');
            $message->setPlainText('Your Resend API configuration successfully sent this email from WHMCS.');
            // Always targets the logged-in administrator; never a customer.
            $this->send($settings, $message);
        } catch (\Throwable $error) {
            $safe = ($error instanceof MailError || $error instanceof SendFailure)
                ? $error->getMessage() : 'Could not run the configuration test. Check PHP and the module installation.';
            throw new InvalidConfiguration($safe);
        }
    }

    public function send(array $settings, Message $message)
    {
        $request = 'not-created';
        try {
            $this->validateSettings($settings);
            $payload = (new MessageMapper())->map($message);
            $request = $this->requestKey($message, $payload);
            $payload['tags'] = [
                ['name' => 'source', 'value' => 'whmcs'],
                ['name' => 'module_version', 'value' => self::VERSION],
                ['name' => 'mode', 'value' => 'live'],
                ['name' => 'request', 'value' => substr(hash('sha256', $request), 0, 32)],
            ];
            // Tags permit letters, numbers, underscores and dashes only.
            $payload['tags'][1]['value'] = str_replace('.', '_', self::VERSION);
            $id = $this->client($settings)->send($payload, $request);
            $this->record('accepted', $request, $id);
        } catch (\Throwable $error) {
            $safe = $error instanceof MailError ? $error->getMessage()
                : 'Internal Resend module error. Check the requirements and installation; check Resend before resending.';
            $this->record('failed', $request, '', $safe);
            // Do not chain an exception that might carry a key, message body or local filename.
            throw new SendFailure($safe . ' Reference: ' . $request);
        }
    }

    protected function client(array $settings): ApiClient
    {
        return new ApiClient(trim((string) $settings['api_key']),
            (int) ($settings['timeout'] ?? 10), (int) ($settings['max_attempts'] ?? 2));
    }

    protected function validateSettings(array $settings): void
    {
        if (PHP_VERSION_ID < 80300) {
            throw new MailError('This module requires PHP 8.3 or later.');
        }
        if (!extension_loaded('curl')) {
            throw new MailError('Enable the PHP cURL extension.');
        }
        ApiClient::validateKey(trim((string) ($settings['api_key'] ?? '')));
        if (!in_array((string) ($settings['timeout'] ?? '10'), ['10', '15', '20'], true)
            || !in_array((string) ($settings['max_attempts'] ?? '2'), ['1', '2', '3'], true)) {
            throw new MailError('The timeout or maximum attempts setting is invalid.');
        }
    }

    private function requestKey(Message $message, array $payload): string
    {
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        // A WHMCS/custom integration may provide a stable Message-ID. Its value is hashed, not logged.
        foreach (($payload['headers'] ?? []) as $name => $value) {
            if (strtolower($name) === 'message-id' && $value !== '') {
                return 'whmcs-message-' . hash('sha256', $payload['from'] . "\0" . $value);
            }
        }
        // No content-only deduplication: two legitimate identical emails must both be sent.
        self::$requests ??= new \WeakMap();
        $existing = self::$requests[$message] ?? null;
        if (!$existing || $existing['fingerprint'] !== $fingerprint) {
            self::$requests[$message] = [
                'fingerprint' => $fingerprint, 'key' => 'whmcs-' . bin2hex(random_bytes(16)),
            ];
        }
        return self::$requests[$message]['key'];
    }

    private function record(string $status, string $request, string $id = '', string $error = ''): void
    {
        try {
            if (function_exists('logActivity')) {
                \logActivity('Resend API: ' . $status . '; request=' . $request
                    . ($id !== '' ? '; email_id=' . $id : '') . ($error !== '' ? '; ' . $error : ''));
            }
        } catch (\Throwable $ignored) {
            // A logging failure after acceptance must never trigger another delivery.
        }
    }
}
