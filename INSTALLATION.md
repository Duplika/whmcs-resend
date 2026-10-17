# Installation and configuration

Resend API for WHMCS, version 1.0.2. Target environment: WHMCS 9.0.9 with PHP 8.3.

## Requirements

- PHP 8.3 for both web requests and cron, with the cURL extension enabled.
- Current CA certificates and outbound HTTPS connectivity to `api.resend.com:443`.
- All sender domains verified in Resend, including domains used by support departments and administrator notifications.
- A dedicated Resend API key with **Sending access**. Restrict it to a domain if all senders use that domain.
- Sufficient transactional email capacity in your Resend plan. Newsletter contact limits are separate from transactional sending quotas.

No credentials or customer data are included in the package.

## 1. Install the files

1. Save your current mail-provider settings so you can restore them later.
2. Download `whmcs-resend.zip` from the release and extract it locally.
3. Upload **only the `modules` folder** to the root of your WHMCS installation:

```text
WHMCS/
└── modules/
    └── mail/
        └── Resend/
            ├── Resend.php
            └── lib/
                ├── ApiClient.php
                ├── MailError.php
                └── MessageMapper.php
```

Preserve the capitalization of `Resend`. Back up any existing folder before replacing it. PHP needs read access, not write access; use your installation's normal permissions, typically 755 for directories and 644 for files.

Configure this provider in **General Settings → Mail**, not in Addon Modules.

## 2. Configure and test

On a staging installation, open **Configuration → System Settings → General Settings → Mail → Configure Mail Provider** and select **Resend API**.

| Field | Description | Suggested initial value |
| --- | --- | --- |
| Resend API key | API key with Sending access permission for the sender domains. | Your dedicated sending key |
| Test sender | Email address from a domain verified in Resend. Used only when testing the configuration. | An address on your verified domain |
| Timeout per attempt | Seconds. All API attempts share a maximum time budget of 25 seconds. | 10 (options: 10, 15, 20) |
| Maximum attempts | Includes the initial attempt. Only temporary failures are retried, using the same idempotency key. | 2 (options: 1, 2, 3) |

**Test Configuration sends a real email to the currently logged-in WHMCS administrator**, using the Test sender address. Verify receipt and the corresponding entry in Resend before saving. If the test fails, WHMCS displays an error and the module raises `InvalidConfiguration`.

Normal emails use the sender, To, CC, BCC and Reply-To supplied by WHMCS. The Test sender field does not override normal senders. To test invoices and notifications, use accounts and recipients you control, including any globally configured copies.

## 3. Validate before production

| Test | Expected result |
| --- | --- |
| Test Configuration | Only the logged-in administrator receives the test email |
| Invoice with a PDF | Complete email with a readable PDF |
| Service notification in each supported template language | Original variables, language and formatting preserved |
| Password reset for a test account | Email received with a working link |
| Multiple To, CC and BCC recipients | All intended recipients preserved |
| Support ticket reply | Reply-To, In-Reply-To and References preserve the conversation |
| Invalid API key | Visible error, never a false success |
| Unverified sender domain | Visible Resend error |
| Unreadable attachment | Entire send fails; no incomplete invoice is sent |
| Staging cron with multiple emails | Results reconciled with Resend; temporary errors and limits reviewed |

After these checks, configure the provider in production, verify all senders and send a controlled email to an account you own. Verify the recipient and delivery in Resend, and check the next cron cycle.

## Upgrading from 1.0.1

Back up and replace `modules/mail/Resend/`, then reopen Configure Mail Provider. Existing API credentials and settings remain unchanged. Version 1.0.2 translates the module interface, test email, errors and documentation into English; it does not change the language of WHMCS templates or sending behavior.

If PHP OPcache does not check for changed files, ask your server administrator to reload the relevant PHP process.

## Upgrading from 1.0.0

Version 1.0.1 removed test-mode recipient redirection. Version 1.0.2 retains that behavior: normal emails use the original WHMCS recipients as soon as PHP loads the updated files. Legacy `test_mode` and `test_to` settings are ignored even if they remain stored.

Back up and replace `modules/mail/Resend/` and reopen Configure Mail Provider. The old test-mode and test-recipient controls should no longer appear. No database changes or clearing of old settings are necessary. Confirm recipients with an email to your own account. **Test Configuration** remains available and targets only the logged-in administrator.

## Logs and troubleshooting

Search for `Resend API` in **System Logs → Activity Log**. An accepted request records:

```text
Resend API: accepted; request=whmcs-...; email_id=...
```

Use the email identifier to find the request in Resend. The module also adds `source=whmcs`, `module_version=1_0_2`, `mode=live` and a request-reference tag. Its Activity Log entries omit API keys, email bodies, subjects, recipients and attachments. WHMCS email history and Resend's own logs retain their usual behavior.

**Accepted means Resend accepted the API request; it does not confirm delivery to the recipient's server.** Check delivery status in Resend. This version does not receive webhooks or add a delivery dashboard to WHMCS.

| Error | What to check |
| --- | --- |
| HTTP 401 | API key and permissions |
| HTTP 403 | Permissions and all sender domains |
| HTTP 400/422 | Addresses, headers, attachments and request details in Resend |
| HTTP 409 | Conflicting request or request still processing; check Resend before resending |
| HTTP 429 | Account-wide sending rate and cron or campaign activity |
| Quota reached | Resend transactional quota and plan |
| cURL 6/7/28 | Server DNS, connectivity or timeout |
| cURL 60 | Server CA certificates; keep TLS verification enabled |

The module does not fall back to SMTP automatically. After attempts are exhausted, it raises `SendFailure`. There is no persistent queue, and the module does not assume WHMCS automatically retries every email type.

## Retries and duplicate prevention

- One to three total attempts, including the initial attempt; the default is two.
- Temporary network errors, HTTP 408, 429 and 5xx, concurrent requests still processing, and successful responses without a valid identifier can be retried.
- Credential, validation, quota, certificate and conflicting-content idempotency errors are not retried.
- `Retry-After` is honored. If the required wait exceeds the remaining budget, the module returns an error rather than retrying too early.
- API attempts share a 25-second budget. Connection establishment has a maximum of 5 seconds; each attempt is limited to the configured timeout or remaining budget, whichever is smaller.
- Internal retries reuse the same JSON body and `Idempotency-Key`.
- When WHMCS supplies a stable `Message-ID`, the key is derived from that identifier and the sender. Reusing that identity with different content causes an idempotency conflict in Resend.
- Without a `Message-ID`, the key is retained only while the same message object and content are reused within the PHP process. Deduplication is not guaranteed if WHMCS reconstructs the message or starts another process.
- Resend's documented idempotency window is 24 hours. Later or manual resends can produce another email. Check Resend before resending an uncertain request. Identical content alone does not suppress separate legitimate emails.

## Scope and limits

- Preserves HTML and plain text. When only HTML is supplied, Resend can generate the text version.
- Supports in-memory binary attachments and local files, with conservative local limits of **20 MiB total before Base64** and **35 MiB for the complete JSON request**. Missing or oversized attachments stop the entire send.
- Does not download URL attachments or accept stream wrappers such as `php://` or `file://`.
- Preserves attachment `content_type` and `content_id` when supplied. Validate inline images with your actual templates.
- Preserves conversation, automatic-response, list-preference, priority and `X-WHMCS-*` headers. Ignores transport overrides and unrecognized headers. Additional custom headers require review.
- Validates addresses using PHP; use ASCII addresses or international domain names encoded as punycode.
- Does not synchronize newsletter contacts, languages or segments, apply marketing filters to operational messages, or modify Resend suppressions.
- Does not change incoming email, piping or support-reply imports.

## Restore the previous provider

Select your previous provider in **General Settings → Mail**, restore its saved settings and test it. Once switched, you can remove `modules/mail/Resend/`. The module does not modify core files or database tables.

Before resending any failed messages, check whether Resend accepted them. A connection failure does not prove that an email was not delivered.

## Validation and references

The included offline tests cover mapping, validation, attachments, original recipients with legacy settings, idempotency, errors, retry limits and cURL options. They use test doubles for WHMCS and HTTP. A licensed WHMCS 9.0.9 installation, live TLS connection and real Resend delivery were not tested during release preparation. Complete the staging checks above in your environment.

- [WHMCS mail providers](https://developers.whmcs.com/mail-providers/getting-started/)
- [WHMCS provider settings](https://developers.whmcs.com/mail-providers/provider-settings)
- [WHMCS sender interface](https://developers.whmcs.com/classes/whmcs/module/contracts/sendermoduleinterface)
- [WHMCS message class](https://developers.whmcs.com/classes/whmcs/mail/message)
- [Resend send-email API](https://resend.com/docs/api-reference/emails/send-email)
- [Resend idempotency keys](https://resend.com/docs/dashboard/emails/idempotency-keys)
