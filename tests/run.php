<?php
declare(strict_types=1);
// php tests/run.php (PHP 8.3). Never upload tests to the WHMCS web root.
error_reporting(E_ALL);
set_error_handler(static function ($level, $message, $file, $line) {
    if (!(error_reporting() & $level)) { return false; }
    throw new ErrorException($message, 0, $level, $file, $line);
});
define('WHMCS', true);
require __DIR__ . '/stubs.php';
require dirname(__DIR__) . '/modules/mail/Resend/Resend.php';

use Duplika\WhmcsResend\ApiClient;
use Duplika\WhmcsResend\MailError;
use Duplika\WhmcsResend\MessageMapper;
use WHMCS\Mail\Message;
use WHMCS\Module\Mail\Resend;
use WHMCS\Exception\Mail\SendFailure;
use WHMCS\Exception\Module\InvalidConfiguration;

const EMAIL_ID = '11111111-1111-4111-8111-111111111111';
const TEST_KEY = 're_local_test_only_not_a_real_secret';
$GLOBALS['test_admin'] = (object) ['email' => 'admin@example.com'];
$GLOBALS['test_curl_available'] = true;
$GLOBALS['test_log_failure'] = false;
$GLOBALS['test_logs'] = [];
$passed = [];

function check($condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function fails(callable $call, string $class = MailError::class, string $contains = ''): Throwable {
    try { $call(); } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected exception: ' . get_class($error) . ': ' . $error->getMessage());
        check($contains === '' || str_contains($error->getMessage(), $contains), 'Unexpected message: ' . $error->getMessage());
        return $error;
    }
    throw new RuntimeException('Expected failure did not happen');
}
function test(string $name, callable $call): void { $call(); $GLOBALS['passed'][] = $name; }
function response(int $status = 200, $body = null, int $errno = 0, array $headers = []): array {
    return ['status' => $status, 'body' => $body ?? json_encode(['id' => EMAIL_ID]), 'errno' => $errno, 'headers' => $headers];
}
class FakeClient extends ApiClient {
    public array $calls = [];
    public array $responses = [];
    public array $delays = [];
    public float $clock = 0;
    public float $duration = 0;
    protected function request(string $json, string $key, int $timeoutMs): array {
        $this->calls[] = compact('json', 'key', 'timeoutMs');
        $this->clock += min($this->duration, $timeoutMs / 1000);
        return array_shift($this->responses) ?? response();
    }
    protected function now(): float { return $this->clock; }
    protected function pause(float $seconds): void { $this->delays[] = $seconds; $this->clock += $seconds; }
    protected function jitter(): float { return 0.0; }
}
class TestProvider extends Resend {
    public FakeClient $fake;
    public function __construct() { $this->fake = new FakeClient(TEST_KEY); }
    protected function client(array $settings): ApiClient { return $this->fake; }
}
function settings(array $extra = []): array {
    return array_replace(['api_key' => TEST_KEY, 'test_from' => 'billing@example.com',
        'timeout' => '10', 'max_attempts' => '2'], $extra);
}
function payload(): array { return (new MessageMapper())->map(new Message()); }

test('PHP 8.3 and module syntax', function () {
    check(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400, PHP_VERSION);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/modules'));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') { token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE); }
    }
    check((new Resend()) instanceof \WHMCS\Module\Contracts\SenderModuleInterface);
    check((new Resend())->getName() === 'Resend');
});
test('HTML, plaintext, UTF-8 and all recipient groups', function () {
    $message = new Message();
    $message->recipients['to'][] = ['second@example.net', 'Second'];
    $message->recipients['cc'] = [['copy1@example.net', 'Copy'], ['copy2@example.net', 'Copy 2']];
    $message->recipients['bcc'] = [['blind@example.net', 'Blind']];
    $message->reply = ['email' => 'support@example.com', 'name' => 'Support'];
    $result = (new MessageMapper())->map($message);
    check(count($result['to']) === 2 && count($result['cc']) === 2 && count($result['bcc']) === 1);
    check($result['reply_to'] === '"Support" <support@example.com>');
    check($result['text'] === 'Hello, José' && $result['html'] === '<p>Hello, José</p>');
});
test('Plaintext-only and HTML-only messages', function () {
    $message = new Message(); $message->body = '';
    check(!isset((new MessageMapper())->map($message)['html']));
    $message->body = '<p>Only HTML</p>'; $message->text = '';
    check(!isset((new MessageMapper())->map($message)['text']));
    $message->body = '';
    fails(fn() => (new MessageMapper())->map($message));
});
test('Mailbox quoting and duplicate recipients', function () {
    $message = new Message(); $message->fromName = 'Duplika, "Hosting"';
    $message->recipients['to'][] = ['client@example.net', 'Same'];
    $result = (new MessageMapper())->map($message);
    check($result['from'] === '"Duplika, \\"Hosting\\"" <billing@example.com>');
    check(count($result['to']) === 1);
});
test('Recipient validation and 50-recipient boundary', function () {
    $message = new Message(); $message->recipients['to'] = [];
    fails(fn() => (new MessageMapper())->map($message));
    for ($i = 0; $i < 50; $i++) { $message->recipients['to'][] = ["person$i@example.net", '']; }
    check(count((new MessageMapper())->map($message)['to']) === 50);
    $message->recipients['to'][] = ['overflow@example.net', ''];
    fails(fn() => (new MessageMapper())->map($message));
    fails(fn() => MessageMapper::email('bad-address'));
});
test('Header injection rejected in addresses, subject and names', function () {
    foreach (['fromEmail', 'fromName', 'subject'] as $field) {
        $message = new Message(); $message->$field = "ok\r\nBcc: other@example.net";
        fails(fn() => (new MessageMapper())->map($message));
    }
});
test('Support threading and safe headers preserved; transport overrides excluded', function () {
    $message = new Message();
    $message->headers = ['In-Reply-To' => '<ticket@example.com>', 'References' => "<one@example.com>\r\n <two@example.com>",
        'Auto-Submitted' => 'auto-generated', 'Bcc' => 'exposed@example.net', 'Authorization' => 'secret',
        'Content-Type' => 'bad', 'List-Unsubscribe' => '<https://example.com/unsubscribe>', 'X-WHMCS-Test' => '123'];
    $headers = (new MessageMapper())->map($message)['headers'];
    check(count($headers) === 5 && $headers['references'] === '<one@example.com> <two@example.com>');
    check($headers['x-whmcs-test'] === '123');
    $message->headers = ['References' => "evil\nBcc: injected@example.net"];
    fails(fn() => (new MessageMapper())->map($message));
});
test('Binary and local-file attachments are Base64 encoded', function () {
    $data = "%PDF-1.7\n\0\xFFinvoice";
    $path = tempnam(sys_get_temp_dir(), 'invoice'); file_put_contents($path, $data);
    try {
        $message = new Message();
        $message->attachments = [['filename' => 'invoice.pdf', 'data' => $data], ['filename' => 'local.pdf', 'filepath' => $path]];
        $items = (new MessageMapper())->map($message)['attachments'];
        check(base64_decode($items[0]['content'], true) === $data && base64_decode($items[1]['content'], true) === $data);
        check(!isset($items[1]['path']));
    } finally { unlink($path); }
});
test('Missing, remote and stream attachments fail before transmission', function () {
    foreach (['/missing-private-invoice.pdf', 'https://example.com/file', 'php://filter', 'file:///etc/passwd'] as $path) {
        $message = new Message(); $message->attachments = [['filename' => 'invoice.pdf', 'filepath' => $path]];
        fails(fn() => (new MessageMapper())->map($message));
    }
});
test('Total attachment size bound and optional inline attachment metadata', function () {
    $message = new Message();
    $message->attachments = [['filename' => 'logo.png', 'data' => 'image', 'content_type' => 'image/png', 'content_id' => 'logo']];
    check((new MessageMapper())->map($message)['attachments'][0]['content_id'] === 'logo');
    $message->attachments = [['filename' => 'huge.pdf', 'data' => str_repeat('x', MessageMapper::MAX_RAW_ATTACHMENT_BYTES + 1)]];
    fails(fn() => (new MessageMapper())->map($message), MailError::class, '20 MiB');
});
test('Successful API response and JSON wire field names', function () {
    $client = new FakeClient(TEST_KEY);
    check($client->send(payload(), 'whmcs-test') === EMAIL_ID);
    check(json_decode($client->calls[0]['json'], true)['subject'] === 'Invoice');
});
test('Retry 429 honors Retry-After with identical payload and key', function () {
    $client = new FakeClient(TEST_KEY, 10, 3);
    $client->responses = [response(429, '{}', 0, ['retry-after' => '3']), response()];
    $client->send(payload(), 'same-key');
    check($client->delays === [3.0]);
    check($client->calls[0] === $client->calls[1]);
});
test('Timeout and server error retries reuse idempotency', function () {
    $client = new FakeClient(TEST_KEY, 10, 3);
    $client->responses = [response(0, '', 28), response(503, '{}'), response()];
    check($client->send(payload(), 'stable-key') === EMAIL_ID);
    check(count($client->calls) === 3 && count(array_unique(array_column($client->calls, 'key'))) === 1);
});
test('Permanent failures and quotas are not retried', function () {
    foreach ([response(400, '{}'), response(401, '{}'), response(403, '{}'), response(422, '{}'),
        response(409, '{"name":"invalid_idempotent_request"}'), response(0, '', 60),
        response(429, '{"name":"daily_quota_exceeded"}')] as $error) {
        $client = new FakeClient(TEST_KEY, 10, 3); $client->responses = [$error];
        fails(fn() => $client->send(payload(), 'failure-key'));
        check(count($client->calls) === 1);
    }
});
test('Concurrent idempotency conflict is retried', function () {
    $client = new FakeClient(TEST_KEY); $client->responses = [response(409, '{"name":"concurrent_idempotent_requests"}'), response()];
    check($client->send(payload(), 'concurrent-key') === EMAIL_ID && count($client->calls) === 2);
});
test('Retry limits and total deadline are bounded', function () {
    $client = new FakeClient(TEST_KEY, 10, 3); $client->duration = 10;
    $client->responses = [response(503, '{}'), response(503, '{}'), response(503, '{}')];
    fails(fn() => $client->send(payload(), 'deadline-key'));
    check(count($client->calls) === 3 && $client->calls[2]['timeoutMs'] === 2000 && $client->clock <= 25);
    $client = new FakeClient(TEST_KEY, 10, 3); $client->responses = [response(429, '{}', 0, ['retry-after' => '60'])];
    fails(fn() => $client->send(payload(), 'long-delay'));
    check(count($client->calls) === 1 && $client->delays === []);
});
test('Malformed success, missing ID and unsafe IDs never count as success', function () {
    foreach (['bad-json', '{}', '{"id":"private-or-untrusted-content"}'] as $body) {
        $client = new FakeClient(TEST_KEY, 10, 1); $client->responses = [response(200, $body)];
        fails(fn() => $client->send(payload(), 'bad-response'));
    }
});
test('Invalid UTF-8, excessive size and invalid keys fail before HTTP', function () {
    $client = new FakeClient(TEST_KEY);
    fails(fn() => $client->send(['html' => "\xFF"], 'bad-utf8'));
    fails(fn() => $client->send(['html' => str_repeat('x', ApiClient::MAX_JSON_BYTES + 1)], 'big-message'));
    fails(fn() => $client->send(payload(), "key\nInjected"));
    check($client->calls === []);
    fails(fn() => new FakeClient("re_secret\r\nOther: injected"));
});
test('No API error body or credentials leaked in exceptions', function () {
    $client = new FakeClient(TEST_KEY, 10, 1);
    $client->responses = [response(422, json_encode(['name' => TEST_KEY, 'message' => 'PRIVATE_BODY ' . TEST_KEY]))];
    $error = fails(fn() => $client->send(payload(), 'redaction'));
    check(!str_contains($error->getMessage(), TEST_KEY) && !str_contains($error->getMessage(), 'PRIVATE_BODY'));
});
test('Provider preserves normal deliveries without adding an Activity Log entry', function () {
    $logs = $GLOBALS['test_logs'];
    $provider = new TestProvider(); $provider->send(settings(), new Message());
    $sent = json_decode($provider->fake->calls[0]['json'], true);
    check(str_contains($sent['to'][0], 'client@example.net'));
    check($sent['tags'][2]['value'] === 'live');
    check(count($provider->fake->calls) === 1);
    check($GLOBALS['test_logs'] === $logs);
});
test('Legacy enabled test mode cannot redirect recipients or strip copies and threading', function () {
    $provider = new TestProvider(); $message = new Message();
    $message->recipients['bcc'] = [['blind@example.net', '']];
    $message->recipients['cc'] = [['copy@example.net', '']];
    $message->headers = ['References' => '<real-ticket@example.com>'];
    $message->reply = ['email' => 'real-support@example.com'];
    $provider->send(settings(['test_mode' => 'on', 'test_to' => 'qa@example.com']), $message);
    $sent = json_decode($provider->fake->calls[0]['json'], true);
    $expected = (new MessageMapper())->map($message);
    foreach (['to', 'cc', 'bcc', 'headers', 'reply_to', 'subject', 'html', 'text'] as $key) {
        check($sent[$key] === $expected[$key], 'Legacy settings altered ' . $key);
    }
    check($sent['tags'][2]['value'] === 'live');
});
test('Missing or invalid legacy test recipient does not block normal delivery', function () {
    foreach ([[], ['test_mode' => 'on'], ['test_mode' => 'on', 'test_to' => ''],
        ['test_mode' => 'on', 'test_to' => 'not-an-email']] as $legacy) {
        $provider = new TestProvider(); $provider->send(settings($legacy), new Message());
        check(count($provider->fake->calls) === 1);
        $sent = json_decode($provider->fake->calls[0]['json'], true);
        check(str_contains($sent['to'][0], 'client@example.net'));
        check($sent['subject'] === 'Invoice');
    }
});
test('Provider settings no longer expose the redirection controls', function () {
    $fields = (new Resend())->settings();
    check(!array_key_exists('test_mode', $fields) && !array_key_exists('test_to', $fields));
    check(isset($fields['api_key'], $fields['test_from'], $fields['timeout'], $fields['max_attempts']));
});
test('Same message retries reuse key; independent identical emails do not', function () {
    $provider = new TestProvider(); $message = new Message();
    $provider->send(settings(), $message); $provider->send(settings(), $message);
    $provider->send(settings(), new Message());
    $keys = array_column($provider->fake->calls, 'key');
    check($keys[0] === $keys[1] && $keys[0] !== $keys[2]);
});
test('Stable Message-ID survives reconstructed messages', function () {
    $provider = new TestProvider();
    for ($i = 0; $i < 2; $i++) {
        $message = new Message(); $message->headers = ['Message-ID' => '<logical-message-123@example.com>'];
        $provider->send(settings(), $message);
    }
    check($provider->fake->calls[0]['key'] === $provider->fake->calls[1]['key']);
});
test('Changing payload without Message-ID generates a fresh logical request', function () {
    $provider = new TestProvider(); $message = new Message();
    $provider->send(settings(), $message); $message->subject = 'New event'; $provider->send(settings(), $message);
    check($provider->fake->calls[0]['key'] !== $provider->fake->calls[1]['key']);
});
test('Log failure does not mask a send failure or trigger another delivery', function () {
    $provider = new TestProvider(); $GLOBALS['test_log_failure'] = true;
    $provider->fake->responses = [response(401, '{}')];
    try { fails(fn() => $provider->send(settings(), new Message()), SendFailure::class, '401'); }
    finally { $GLOBALS['test_log_failure'] = false; }
    check(count($provider->fake->calls) === 1);
});
test('Provider surfaces SendFailure and preflight configuration errors', function () {
    $logCount = count($GLOBALS['test_logs']);
    $provider = new TestProvider();
    $provider->fake->responses = [response(401, json_encode(['message' => 'PRIVATE_BODY ' . TEST_KEY]))];
    $error = fails(fn() => $provider->send(settings(), new Message()), SendFailure::class, '401');
    $log = end($GLOBALS['test_logs']);
    $request = $provider->fake->calls[0]['key'];
    check(count($GLOBALS['test_logs']) === $logCount + 1);
    check(str_contains($log, 'Resend API: failed; request=' . $request));
    check(str_contains($log, '401') && str_contains($error->getMessage(), $request));
    foreach ([TEST_KEY, 'PRIVATE_BODY', 'client@example.net', 'Invoice'] as $private) {
        check(!str_contains($log, $private));
    }
    fails(fn() => $provider->send(settings(['timeout' => '99']), new Message()), SendFailure::class);
    $GLOBALS['test_curl_available'] = false;
    try { fails(fn() => $provider->send(settings(), new Message()), SendFailure::class, 'cURL'); }
    finally { $GLOBALS['test_curl_available'] = true; }
});
test('Configuration test targets only the authenticated administrator', function () {
    $provider = new TestProvider();
    $provider->testConnection(settings(['test_mode' => 'on', 'test_to' => 'not-an-email']));
    $sent = json_decode($provider->fake->calls[0]['json'], true);
    check($sent['to'] === ['admin@example.com']);
    check($sent['text'] !== '' && !isset($sent['cc'], $sent['bcc']));
    $GLOBALS['test_admin'] = null;
    try { fails(fn() => $provider->testConnection(settings()), InvalidConfiguration::class); }
    finally { $GLOBALS['test_admin'] = (object) ['email' => 'admin@example.com']; }
});
test('Production HTTP adapter fixes endpoint, TLS, redirects and sensitive headers', function () {
    // Constants are supplied by native cURL. A stripped-down WASM runtime may lack them.
    foreach (['CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER', 'CURLOPT_USERAGENT',
        'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS', 'CURLOPT_PROTOCOLS', 'CURLOPT_SSL_VERIFYPEER',
        'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_CONNECTTIMEOUT_MS', 'CURLOPT_TIMEOUT_MS', 'CURLOPT_NOSIGNAL',
        'CURLOPT_HEADERFUNCTION', 'CURLOPT_WRITEFUNCTION', 'CURLINFO_HTTP_CODE', 'CURLPROTO_HTTPS'] as $i => $constant) {
        if (!defined($constant)) { define($constant, 1000 + $i); }
    }
    $client = new ApiClient(TEST_KEY);
    check($client->send(payload(), 'wire-key') === EMAIL_ID);
    check($GLOBALS['curl_url'] === 'https://api.resend.com/emails');
    $options = $GLOBALS['curl_options'];
    check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2);
    check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS);
    check(in_array('Idempotency-Key: wire-key', $options[CURLOPT_HTTPHEADER], true));
    check(in_array('Authorization: Bearer ' . TEST_KEY, $options[CURLOPT_HTTPHEADER], true));
    check($GLOBALS['curl_closed'] === true);
});

echo json_encode([
    'php_version' => PHP_VERSION, 'passed_count' => count($passed), 'passed' => $passed,
    'limitations' => 'Offline PHP 8.3 runtime. WHMCS classes and HTTP are test doubles. No licensed WHMCS 9.0.9 installation, real TLS connection or live Resend delivery was tested.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
