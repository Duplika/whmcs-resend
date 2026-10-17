# Resend API for WHMCS

![Resend API for WHMCS — free and open source, developed by Duplika](https://raw.githubusercontent.com/Duplika/whmcs-resend/main/docs/images/whmcs-resend-hero.jpg)

Send WHMCS transactional emails through the Resend HTTPS API. Developed by [Duplika](https://duplika.com/) and distributed under the MIT license.

**Version 1.0.3. Target environment: WHMCS 9.0.9 with PHP 8.3.**

This is a WHMCS **mail provider**, configured under **General Settings → Mail**. It is not activated under Addon Modules. No Composer installation, core changes, hooks or database migrations are required.

## Features

- Preserves the templates, variables, language, sender and recipients supplied by WHMCS.
- Supports HTML, plain text, To, CC, BCC, Reply-To, support-thread headers and attachments.
- Retries temporary API failures within a shared 25-second time budget, using the same idempotency key.
- Provides an English configuration screen, test email and error messages.
- Logs failures without email bodies or credentials. Successful sends use WHMCS's existing email history and Activity Log entries, with no duplicate module entry.

The module interface is in English. Customer emails continue to use the language and content of your WHMCS templates.

## Download and install

Download the latest **[whmcs-resend.zip](https://github.com/Duplika/whmcs-resend/releases/latest/download/whmcs-resend.zip)** or browse the [release history](https://github.com/Duplika/whmcs-resend/releases). Extract it and upload only its `modules` folder to your WHMCS root directory. Follow [INSTALLATION.md](INSTALLATION.md) to configure and test the provider.

Use the attached installation ZIP, which contains the module and its documentation. GitHub's automatically generated source archives also contain development files and tests; do not upload those entire archives to your WHMCS web root.

## Screenshots

### Select the mail provider

Choose **Resend API** in **General Settings → Mail**.

![WHMCS Mail settings with Resend API selected as the outgoing mail provider](https://raw.githubusercontent.com/Duplika/whmcs-resend/main/docs/images/mail-provider.png)

### Configure the API key and test sender

Enter a dedicated sending API key and a test sender address on a domain verified in Resend.

![Resend API key and test sender configuration fields](https://raw.githubusercontent.com/Duplika/whmcs-resend/main/docs/images/api-key-and-sender.png)

### Set retry limits and test the configuration

Adjust the timeout and maximum attempts, then use **Test Configuration** to send a test email to the logged-in administrator.

![Timeout, maximum attempts and configuration-test controls](https://raw.githubusercontent.com/Duplika/whmcs-resend/main/docs/images/retries-and-test.png)

## Requirements and validation status

- Target: WHMCS 9.0.9 and PHP 8.3, including the cron environment.
- PHP cURL, current CA certificates and outbound HTTPS access to `api.resend.com:443`.
- Verified sender domains in Resend and a dedicated API key with **Sending access**.

The included offline suite uses WHMCS class and HTTP test doubles. It does **not** establish compatibility with every WHMCS/PHP version or replace testing on a licensed installation. Duplika has confirmed live email sending with an earlier module version on WHMCS 9.0.9 with PHP 8.3. Version 1.0.3 has been validated offline; its logging change still needs a live installation check. Other version combinations have not been verified.

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
