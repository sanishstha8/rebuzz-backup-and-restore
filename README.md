# Rebuzz Backup & Restore

Complete WordPress Backup & Restore Solution.

- **Requires:** WordPress 5.8+, PHP 7.4+ (zip extension required)
- **License:** GPLv2 or later

## Layout

| Path | What it is |
| --- | --- |
| `rebuzz-backup-and-restore/` | Plugin source. This folder is what ships. |
| `_dev-tools/wpcb-diagnose.php` | Standalone restore diagnostic. Not shipped. |

## Branches

- `main` — 1.4.0 baseline, imported unchanged from the release zip.
- `restore-diagnostics` — 1.4.2: extractor failure reporting and restore-path fixes.

## Releases

The distributable zip is built from `rebuzz-backup-and-restore/` and is not
tracked in git (see `.gitignore`); the archived `.zip` / `.bak` files stay on
disk alongside the repo.
