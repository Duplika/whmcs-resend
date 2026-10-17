# Changelog

## 1.0.3

- Remove redundant `Resend API: accepted` Activity Log entries. Successful sends rely on WHMCS's normal records, avoiding an extra log write per email.
- Keep sanitized failure logging and request references; simplify the logger to handle failures only.
- Preserve API response validation, retry behavior, idempotency, recipients and existing configuration.
- Update offline regression coverage for silent success, failure diagnostics and logging failures.

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
