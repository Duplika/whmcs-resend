# Release procedure

1. Update the version in `Resend.php`, the API client's user agent, documentation and changelog.
2. Run the offline suite using PHP 8.3 or the documented WebAssembly runner.
3. Run `python3 build.py` and inspect the installation ZIP and checksum file.
4. Complete staging tests on a licensed WHMCS installation, including a real Resend delivery, before recommending production use. Record which versions and scenarios were tested.
5. Commit the source changes and push to GitHub.
6. Create a draft release for the exact tested commit and attach `dist/whmcs-resend.zip` and `dist/SHA256SUMS.txt`. Use the changelog and test results for release notes, including any unverified compatibility claims.
7. Review the draft and publish when ready. Mark a stable release as Latest only when it is intended to be the default download.

Always keep the installation asset name `whmcs-resend.zip`. The version is identified by its release tag. This supports the stable direct-download URL:

```text
https://github.com/Duplika/whmcs-resend/releases/latest/download/whmcs-resend.zip
```

A draft does not make that URL available to the public. The WHMCS Marketplace Download/Purchase URL should point to a published installation ZIP, and its listing should contain the module description and compatibility information.
