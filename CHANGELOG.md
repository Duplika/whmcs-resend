# Changelog

## 1.0.2

- Translate every module setting, help text, error message and configuration-test email into English.
- Add English installation, upgrade, troubleshooting and release instructions.
- Prepare a public repository and reproducible `whmcs-resend.zip` installation package under the MIT license.
- Preserve existing configuration keys, option values, defaults, email content and delivery behavior.

## 1.0.1

- Remove test-mode recipient redirection from normal deliveries.
- Preserve To, CC, BCC, Reply-To and threading when legacy test settings remain stored.
- Keep configuration testing limited to the logged-in administrator.

## 1.0.0

- Initial internal integration with Resend's HTTPS API, attachment handling, bounded retries and idempotency support.
