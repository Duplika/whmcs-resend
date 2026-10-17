<?php
declare(strict_types=1);
// Documented WHMCS contracts only. These are NOT a WHMCS installation.
namespace WHMCS\Module\Contracts {
    interface SenderModuleInterface {
        public function settings();
        public function getName();
        public function getDisplayName();
        public function testConnection(array $params);
        public function send(array $params, \WHMCS\Mail\Message $message);
    }
}
namespace WHMCS\Module\MailSender {
    trait DescriptionTrait { public function setDisplayName($name) { return $this; } }
}
namespace WHMCS\Exception\Mail { class SendFailure extends \RuntimeException {} }
namespace WHMCS\Exception\Module { class InvalidConfiguration extends \RuntimeException {} }
namespace WHMCS\Authentication {
    class CurrentUser { public function admin() { return $GLOBALS['test_admin']; } }
}
namespace WHMCS\Module\Mail {
    function extension_loaded($name) { return $GLOBALS['test_curl_available']; }
}
namespace WHMCS\Mail {
    class Message {
        public string $fromEmail = 'billing@example.com';
        public string $fromName = 'Duplika';
        public string $subject = 'Invoice';
        public string $body = '<p>Hello, José</p>';
        public string $text = 'Hello, José';
        public array $recipients = ['to' => [['client@example.net', 'José Núñez']], 'cc' => [], 'bcc' => []];
        public array $reply = [];
        public array $headers = [];
        public array $attachments = [];
        public function getFromEmail() { return $this->fromEmail; }
        public function getFromName() { return $this->fromName; }
        public function getSubject() { return $this->subject; }
        public function getBody() { return $this->body; }
        public function getPlainText() { return $this->text; }
        public function getRecipients($kind) { return $this->recipients[$kind]; }
        public function clearRecipients($kind) { $this->recipients[$kind] = []; return $this; }
        public function getReplyTo() { return $this->reply; }
        public function getHeaders() { return $this->headers; }
        public function getAttachments() { return $this->attachments; }
        public function setFromEmail($value) { $this->fromEmail = $value; return $this; }
        public function setFromName($value) { $this->fromName = $value; return $this; }
        public function setSubject($value) { $this->subject = $value; return $this; }
        public function setPlainText($value) { $this->text = $value; $this->body = ''; return $this; }
        public function addRecipient($kind, $email, $name = '') { $this->recipients[$kind][] = [$email, $name]; return $this; }
    }
}
namespace Duplika\WhmcsResend {
    // Intercept the cURL boundary. No external requests can be issued by the suite.
    function curl_init($url) { $GLOBALS['curl_url'] = $url; return new \stdClass(); }
    function curl_setopt_array($handle, $options) { $GLOBALS['curl_options'] = $options; return true; }
    function curl_exec($handle) {
        $options = $GLOBALS['curl_options'];
        $options[CURLOPT_HEADERFUNCTION]($handle, "HTTP/2 200\r\n");
        $options[CURLOPT_HEADERFUNCTION]($handle, "Retry-After: 2\r\n");
        $options[CURLOPT_WRITEFUNCTION]($handle, '{"id":"11111111-1111-4111-8111-111111111111"}');
        return true;
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_errno($handle) { return 0; }
    function curl_close($handle) { $GLOBALS['curl_closed'] = true; }
}
namespace {
    function logActivity($message) {
        if ($GLOBALS['test_log_failure']) { throw new \RuntimeException('Simulated database failure'); }
        $GLOBALS['test_logs'][] = $message;
    }
}
