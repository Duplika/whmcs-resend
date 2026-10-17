"""Build and verify a deterministic installation ZIP from an explicit allowlist."""
from pathlib import Path
import hashlib
import json
import re
import zipfile

ROOT = Path(__file__).resolve().parent
MODULES = [
    "modules/mail/Resend/Resend.php",
    "modules/mail/Resend/lib/ApiClient.php",
    "modules/mail/Resend/lib/MailError.php",
    "modules/mail/Resend/lib/MessageMapper.php",
]
FILES = MODULES + ["README.md", "INSTALLATION.md", "CHANGELOG.md", "LICENSE"]


def main():
    report = json.loads((ROOT / "tests/validation.json").read_text())
    if report["passed_count"] != len(report["passed"]) or report["passed_count"] != 30:
        raise RuntimeError("Run the complete test suite before building.")
    if not report["php_version"].startswith("8.3."):
        raise RuntimeError("Run tests on the target PHP 8.3 runtime.")
    test_time = (ROOT / "tests/validation.json").stat().st_mtime_ns
    tested_files = [ROOT / name for name in MODULES] + list((ROOT / "tests").glob("*.php"))
    if any(path.stat().st_mtime_ns > test_time for path in tested_files):
        raise RuntimeError("Source changed after validation. Run the tests again.")
    version = re.search(r"VERSION = '([^']+)'", (ROOT / MODULES[0]).read_text()).group(1)
    if f"Duplika-WHMCS-Resend/{version}" not in (ROOT / MODULES[1]).read_text():
        raise RuntimeError("Provider and transport versions differ.")
    out = ROOT / "dist"
    out.mkdir(exist_ok=True)
    archive_path = out / "whmcs-resend.zip"
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name in sorted(FILES):
            entry = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, (ROOT / name).read_bytes())
    with zipfile.ZipFile(archive_path) as archive:
        if archive.testzip() is not None or sorted(archive.namelist()) != sorted(FILES):
            raise RuntimeError("Archive validation failed.")
        for name in FILES:
            if archive.read(name) != (ROOT / name).read_bytes():
                raise RuntimeError(f"Archive content mismatch: {name}")
    digest = hashlib.sha256(archive_path.read_bytes()).hexdigest()
    (out / "SHA256SUMS.txt").write_text(f"{digest}  {archive_path.name}\n")
    print(f"Verified version {version}: {archive_path.name}, {len(FILES)} files, {archive_path.stat().st_size} bytes")
    print(f"SHA-256: {digest}")


if __name__ == "__main__":
    main()
