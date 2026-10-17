# Resend API for WHMCS

Send WHMCS transactional emails through the Resend HTTPS API. Developed by [Duplika](https://duplika.com/) and distributed under the MIT license.

**Version 1.0.2. Target environment: WHMCS 9.0.9 with PHP 8.3.**

This is a WHMCS **mail provider**, configured under **General Settings → Mail**. It is not activated under Addon Modules. No Composer installation, core changes, hooks or database migrations are required.

## Features

- Preserves the templates, variables, language, sender and recipients supplied by WHMCS.
- Supports HTML, plain text, To, CC, BCC, Reply-To, support-thread headers and attachments.
- Retries temporary API failures within a shared 25-second time budget, using the same idempotency key.
- Provides an English configuration screen, test email and error messages.
- Records the Resend email identifier in the WHMCS Activity Log without logging email bodies or credentials.

The module interface is in English. Customer emails continue to use the language and content of your WHMCS templates.

## Download and install

Download **`whmcs-resend.zip`** from a [published GitHub Release](https://github.com/Duplika/whmcs-resend/releases). Extract it and upload only its `modules` folder to your WHMCS root directory. Follow [INSTALLATION.md](INSTALLATION.md) to configure and test the provider.

Use the attached installation ZIP, which contains the module and its documentation. GitHub's automatically generated source archives also contain development files and tests; do not upload those entire archives to your WHMCS web root.

Once a stable release is published, the direct download URL is:

```text
https://github.com/Duplika/whmcs-resend/releases/latest/download/whmcs-resend.zip
```

Draft releases are not publicly downloadable. Keep the asset name `whmcs-resend.zip` in each stable release so this URL continues to work.

## Requirements and validation status

- Target: WHMCS 9.0.9 and PHP 8.3, including the cron environment.
- PHP cURL, current CA certificates and outbound HTTPS access to `api.resend.com:443`.
- Verified sender domains in Resend and a dedicated API key with **Sending access**.

The included offline suite uses WHMCS class and HTTP test doubles. It does **not** establish compatibility with every WHMCS/PHP version or replace testing on a licensed installation. No live Resend delivery or licensed WHMCS integration test was performed as part of this release preparation.

## Development

Run the offline suite with PHP 8.3:

```sh
php tests/run.php > tests/validation.json
```

Alternatively, the optional WebAssembly runner uses `@php-wasm/universal@3.1.58` and `@php-wasm/node-8-3@3.1.58`, installed outside this repository:

```sh
node tests/run.mjs /absolute/path/to/node_modules
```

Tests intercept HTTP calls and use dummy credentials; they do not send email. After a successful test run, build the installation ZIP with Python 3:

```sh
python3 build.py
```

The builder writes `dist/whmcs-resend.zip` and `dist/SHA256SUMS.txt` using an explicit list of distributable files. Test runtimes, test fixtures and local configuration are excluded. See [RELEASING.md](RELEASING.md) for the release procedure.

## Support

Report bugs through [GitHub Issues](https://github.com/Duplika/whmcs-resend/issues). Include the module, WHMCS and PHP versions, a sanitized error message and steps to reproduce. Do not include API keys, customer information or email contents.

This is an independent community integration, not an official WHMCS or Resend product. See [CHANGELOG.md](CHANGELOG.md) and [LICENSE](LICENSE).
