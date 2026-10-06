# Rebuzz Backup & Restore

Complete WordPress Backup & Restore Solution.

- **Requires:** WordPress 5.8+, PHP 7.4+ (zip extension required)
- **License:** GPLv2 or later

## Layout

| Path | What it is |
| `rebuzz-backup-and-restore/` | Plugin source. This folder is what ships. |
| `_dev-tools/wpcb-diagnose.php` | Standalone restore diagnostic. Not shipped. |

## Branches

- `main`- released versions, updated by merging pull requests from `restore-diagnostics`.
- `restore-diagnostics` - development. The current version is the `Stable tag` in
  `rebuzz-backup-and-restore/readme.txt`.
- google drive integration is in next release

## Releases

The distributable zip is built from `rebuzz-backup-and-restore/` and is not
tracked in git (see `.gitignore`); the archived `.zip` / `.bak` files stay on
disk alongside the repo.
