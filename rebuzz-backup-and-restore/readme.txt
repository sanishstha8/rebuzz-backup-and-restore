=== ReBuzz Backup and Restore ===
Contributors: rebuzz
Tags: backup, restore, migration, multisite, database
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full-site backup and restore for WordPress, built to work within the tight PHP time and memory limits of shared hosting.

== Description ==

ReBuzz Backup and Restore copies your whole site, every file and the entire database, into a single archive you can download, move, or restore later.

Most backup plugins assume the server will let them finish. On shared hosting it often won't: PHP gets cut off after thirty seconds, memory caps out well below what a large site needs, and a half-written backup is worse than no backup at all. This plugin is built the other way round. Nothing it does happens in one long request. File scanning, zipping, the database export and import, extraction, URL rewriting, all of it runs in small batches that pick up where the last one stopped.

= What it does =

* **Chunked and resumable.** No single request has to finish the whole job, so execution-time and memory limits stop being the thing that decides whether your backup completes.
* Files and the database dump are **SHA-256 checksummed** when the backup is written, then verified again before a restore is allowed near your live site. A damaged archive gets rejected instead of half-applied.
* **Domain-safe restores.** Moving to a different URL rewrites hardcoded references in post content, GUIDs and serialized data. Your admin session and the site URL survive the process.
* Backup too large to push through the browser? Drop the ZIP straight into `wp-content/uploads/rebuzz-backup-and-restore/backups/` over FTP. It turns up in the restore list on its own, with no size ceiling.
* **Archives are not left guessable.** A backup is a complete copy of your site, database included, so every archive filename ends in 32 random characters - the file can't be downloaded without knowing that name. Web-server rules that deny direct access are written alongside them for Apache and IIS.
* **Multisite aware.** A network administrator can back up the network; an individual site admin gets their own site and nothing else. The restore side enforces the same boundary, so nobody can drop another site's data onto theirs.
* Free disk space and directory permissions are checked before a restore begins, so it fails early and safely rather than halfway through.
* Leftover storage from All-in-One WP Migration and UpdraftPlus is detected, and you can clear it from the dashboard.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/rebuzz-backup-and-restore`, or install it through the Plugins screen in WordPress.
2. Activate it from the Plugins screen.
3. Open **ReBuzz Backup > Dashboard** to make your first backup, or **ReBuzz Backup > Restore** to bring one back.

== Frequently Asked Questions ==

= Will it work on shared hosting with strict PHP limits? =

That is what it was built for. Every step is chunked and resumable, so the plugin works inside short execution-time and memory limits instead of asking you to raise them.

= My restore stopped before it started, saying the account is out of space or files. What now? =

Before a restore begins the plugin writes a few megabytes and creates some test files in its own folder. If that fails, your hosting account has hit its storage quota or its file-count (inode) limit, and the restore would have failed part-way through anyway - so it stops first and says so. Both figures are in your hosting control panel; cPanel shows the second as "File Usage" or "Inodes". The test is deliberately smaller than the first batch of a real extraction, so it will not refuse a restore that would have worked, but if it ever misfires you can switch it off with `define('WPCB_RESTORE_PROBE_FILES', 0);` in `wp-config.php`.

= Where are my backups stored, and can anyone download them? =

By default in `wp-content/uploads/rebuzz-backup-and-restore/backups/`. The plugin writes `.htaccess` and `web.config` rules there, but those only apply on Apache and IIS - on Nginx they are ignored and the archive is served as an ordinary static file to anyone who knows its name.

Because of that the plugin actively tests the folder before every backup: it requests a temporary file from it over HTTP with no login. If the server hands that file over, the backup is refused rather than created and left downloadable - a backup contains your whole database, so the plugin will not produce one it cannot keep private.

Some hosts block a site from making requests to itself, so the test cannot run. Backups are refused then too: the server's name cannot settle it, because many hosts put Nginx, which ignores `.htaccess`, in front of Apache.

To fix it, move backups out of the web root by adding this to `wp-config.php`:

`define( 'WPCB_BACKUP_DIR', '/full/path/outside/public_html/rebuzz-backups' );`

The path must be absolute and writable by PHP, and should sit outside the folder your web server serves. Archives already in the old location are not moved automatically - the Dashboard will tell you they are there so you can move them yourself.

= My backup is too big to upload. Now what? =

Put the ZIP in `wp-content/uploads/rebuzz-backup-and-restore/backups/` using FTP or your host's file manager. It appears in the restore list automatically and skips the browser upload limit entirely. The exact path is also shown on **ReBuzz Backup > Dashboard**, under the backups list.

= Does it support Multisite? =

Yes. A network administrator can back up the whole network, while an individual site admin gets only their own site. Restores enforce the same boundary.

= Is the backup checked before it gets restored? =

Every file and the database dump is hashed with SHA-256 when the backup is made, and those hashes are re-checked before the restore writes anything. If one doesn't match, the restore stops rather than continuing with a corrupt archive.

== Changelog ==

= 1.5.4 =
* Security: on hosts whose open_basedir setting keeps PHP out of the web root, a WPCB_BACKUP_DIR inside the web root was accepted as private, so backups saved there could be downloaded by anyone. PHP could not look up the web root there, and the check left it out instead of comparing against its path as written. It now compares against the path, so such a folder is refused and backups fall back to the uploads folder and its privacy test.
* Fixed: on those hosts the same check wrote "open_basedir restriction in effect" warnings to the PHP error log during every backup and restore.

= 1.5.3 =
* Security: when the plugin cannot test whether the backups folder is private - some hosts block a site from making requests to itself - backups are now refused, as they already were on Nginx. It used to trust the server's name instead, but many hosts put Nginx in front of Apache: PHP reports Apache while Nginx serves the archive and ignores the .htaccess rule. Setting WPCB_BACKUP_DIR to a folder outside the web root fixes it, as before.
* Changed: a privacy test that could not run is retried after five minutes instead of a day, so a passing network error does not turn backups off for long.
* Fixed: a finished backup is discarded only when the backups folder is proven public, not when the test repeated after the backup cannot run.
* Fixed: a deleted backups folder is recreated, instead of blocking new backups and uploads until the plugin was reactivated.

= 1.5.2 =
* Fixed: on hosts that disable PHP's disk_free_space(), uploading a backup or starting a restore crashed on PHP 8 with "Call to undefined function", and on PHP 7.4 was refused for having "0 B" free. The free-space check is now skipped there, as it already was when the host could not measure the space; the storage test that runs before every restore still catches a full account.
* Fixed: on hosts that disable PHP's ini_set(), downloading a backup crashed on PHP 8 when the server had output compression turned on.

= 1.5.1 =
* Fixed: restoring a backup made on a different WordPress version could send wp-admin to the "Database Update Required" screen. WordPress core used to be copied file by file across many requests, so the site ran a mix of old and new core, and new core against the old database. Core is now swapped in by folder renames in one request, together with the database; if the database swap fails, the previous core is put back.
* Fixed: before any plugin, theme or upload is overwritten, the restore now checks that WordPress core can actually be swapped on this server, and stops with the site unchanged if it can't.
* Fixed: on Windows servers, the core swap could not rename wp-admin while the restore request was running from it. It now swaps that folder's contents one by one instead.
* Fixed: the new core is swapped in straight from the extracted backup, and the old one is moved into the restore's own folder, so no copy of core sits in the website's root folder during a restore - where a server without .htaccess support, such as Nginx, could serve it.
* Fixed: a PHP crash while WordPress was still loading, part-way through a restore, reported the error but left the restore marked as running and locked. It now marks the restore failed, puts plugins back and releases the lock.
* Fixed: a restore killed outright by the host no longer blocks new restores until its record expires; one with no progress for 10 minutes is treated as stopped, as backups already were.
* Fixed: database export and URL rewrite batches are sized from the real size of the rows about to be read, not from the table's average row length, so a run of small rows followed by very large ones can no longer exhaust PHP's memory limit.
* Changed: crash messages only suggest raising the time or memory limit when that is what the error actually was.
* Added: the restore screen warns before a restore when the backup was made on a different WordPress version from this site.

= 1.5.0 =
* Fixed: a failed restore no longer leaves a half-replaced database. The backup is now imported into temporary staging tables while your live site stays untouched, and only swapped in - all tables at once - after every file is in place. If the import fails, the site is exactly as it was.
* Fixed: the database is now imported before any file is overwritten, so a database error can no longer leave new files running on the old database.
* Fixed: database views were backed up as tables, rows included, which duplicated data or failed the restore. Views are now saved as definitions only and recreated after the tables. Older backups containing views restore correctly too.
* Fixed: DEFINER clauses on views, triggers and routines are removed on backup and on restore, so a backup no longer fails on a server where the original database user doesn't exist.
* Fixed: backups from MySQL 8 now restore onto older MySQL and MariaDB servers - the utf8mb4_0900_* collations and the utf8mb3 name are translated to ones the server knows.
* Fixed: a PHP crash during a restore now reactivates your plugins, instead of leaving all of them switched off.
* Fixed: restored PHP files are cleared from OPcache, preventing fatal errors from stale cached code right after a restore.
* Fixed: the database export no longer runs in a single request, where a large database could hit the server's time limit and fail the backup. It now runs in short chunks like the other steps, and resumes by primary key, so rows added or deleted while the backup runs are never written twice.
* Fixed: restoring a large database was very slow - every row was saved as its own transaction, waiting for its own disk flush, so a table with a million rows could take hours. Each chunk is now saved in one transaction, about 20 times faster in testing.
* Fixed: on hosts that disable set_time_limit(), every backup and restore step crashed on PHP 8 with "Call to undefined function". The plugin now checks for it first; its chunked steps already stay inside the default time limit.
* Fixed: an error reading a table's rows during the export now fails the backup with the table named in the log, instead of silently leaving the rest of that table out.
* Fixed: the database export and URL rewrite size their batches by bytes, not a fixed row count, so tables with large rows no longer exhaust PHP's memory limit.
* Fixed: a PHP fatal error now shows its real cause on the dashboard (for example the memory limit) instead of "AJAX request failed", and a failed job is no longer retried into the same crash.
* Fixed: uploading a backup larger than the server's post_max_size now shows the "file too large" message instead of a blank page.
* Fixed: a crashed backup no longer blocks new backups for an hour; a backup with no progress for 10 minutes is treated as stopped.
* Changed: text files and the database dump are now compressed in the backup archive, making archives much smaller - which matters on shared hosting, where a restore needs room for both the archive and its extracted copy.
* Fixed: protocol-relative URLs (//old-site/...) stay protocol-relative when the domain is rewritten.
* Changed: the notice about leftover plugins-old/themes-old/uploads-old folders now warns that they may be publicly reachable.

= 1.4.3 =
* Fixed: a restore on shared hosting could run for thousands of files and then fail part-way through because the account was out of storage or had reached its file-count (inode) limit. Before a restore starts, the plugin now actually writes a few megabytes and creates a small number of test files in its own folder, and refuses to start if that fails - naming which limit was reached instead of stopping at an arbitrary file. The old check only asked the server how much space was free, which on shared hosting reports the whole disk rather than what your account is allowed to use, and cannot see inode limits at all.
* Fixed: the free-space check measured the wrong folder when `WPCB_BACKUP_DIR` was set, reporting space for a filesystem the restore never writes to.
* Added: the restore log now records what the pre-flight checks saw on every restore, not only on failure.
* Note: the new test is deliberately smaller than the first batch of a real extraction, so it cannot refuse a restore that would have worked. If it ever misfires on an unusual host, `define('WPCB_RESTORE_PROBE_FILES', 0);` in `wp-config.php` turns it off.

= 1.4.2 =
* Fixed: a failed extraction could report only "Extraction failed for: <file>" with no cause. The plugin now identifies why - a full disk or exceeded hosting quota, a folder PHP cannot write to, a damaged or incompletely uploaded archive, a path the filesystem rejects, or the server running out of file handles - so the message says what to fix. It also now recognises the "Not a directory" error Linux reports where Windows reports something different.
* Fixed: a file listed by the scan but missing from the extracted workspace was skipped silently, so a partial extraction could still finish reporting a clean restore. Missing files are now logged by name and counted, which triggers the existing end-of-restore warning.
* Fixed: during URL rewriting, a database write that failed was still counted as a successful change.

= 1.4.0 =
* Fixed: downloading a backup from the admin panel is much faster. It was reading the archive 8KB at a time and forcing a flush after every read - roughly 77,000 of them for a 600MB file. It now reads 1MB at a time and lets the server flush, which measured about twice as fast locally. A long download is also no longer cut short by `max_execution_time`, which could leave a truncated ZIP that looked complete.
* Security: the plugin now refuses to create a backup it cannot store privately, instead of creating one anybody could download. Before a backup starts it checks whether the backup folder is reachable over HTTP - by requesting a short-lived file from it with no login - and stops with instructions if it is. A backup archive contains your entire database, including every user account and password hash, so an unguessable filename is not enough on its own.
* Security: backup archives can be stored outside the public web root. Define `WPCB_BACKUP_DIR` in `wp-config.php` with an absolute path outside `public_html` and archives are kept there, where no URL maps to them at all. On Nginx, which ignores the `.htaccess` rule the plugin writes, this is the arrangement that prevents a direct download.
* Note: on a server that serves the backup folder - typically Nginx without extra configuration - creating new backups now stops until `WPCB_BACKUP_DIR` is set. Restoring, listing, downloading and deleting existing backups are unaffected.
* Security: finished archives are written with restrictive file permissions, so on hosts where the web server and PHP run as different users the web server cannot read them.
* Fixed: backups no longer sweep in other backup plugins' archives. Storage used by BackWPup, BackupBuddy, Duplicator, WPvivid, WP Migrate DB and All In One WP Security is now excluded alongside the folders already recognised, including the ones that live under `uploads` with a per-install name. Left in, those archives were copied into every new backup, so each one contained the one before it.
* Added: the Dashboard now reports leftover backup storage found under `uploads`, not just under `wp-content`, so an administrator without FTP can see and reclaim it.
* Changed: the plugin no longer writes a generated PHP file into `wp-content/mu-plugins`. WordPress's background auto-updater is held off during a restore with an ordinary filter instead, which does the same job without putting code anywhere plugins are not meant to write.
* Security: backup archive filenames now end in 32 random characters instead of 6 hexadecimal ones. Archives contain a full database dump, and on servers that ignore the `.htaccess` and `web.config` rules the plugin writes - Nginx among them - an unguessable filename is what actually prevents one being downloaded directly. The storage folder itself is unchanged, so existing backups stay where they are.
* Fixed: mu-plugins that a restore temporarily renames aside are now recorded outside the job, and put back automatically on the next page load if the restore is killed mid-way. Previously a fatal error at the wrong moment could leave them disabled indefinitely.
* Fixed: failed writes during the database export are now detected instead of ignored. A backup that runs out of disk space part-way now fails with a clear message rather than finishing "successfully" with a truncated dump that would restore an incomplete site.
* Fixed: no output buffer is left open across the request. Buffering is now opened and closed inside the function that needs it, so it can no longer interfere with output from WordPress or other plugins.
* Changed: `DOING_CRON` is no longer defined during backup and restore polling. Cron spawning is suppressed for that one request through a filter instead, so other plugins no longer see an ordinary admin request as a cron run.

= 1.3.5 =
* Fixed: percent signs in database content were being replaced with an internal placeholder token while the database was exported, corrupting values such as `width: 50%` and leaving restored pages with broken layouts. Backups created with earlier versions contain this corruption and should be re-created.
* Fixed: URL rewriting now covers every scheme form - `https://`, `http://` and protocol-relative `//` - so media referenced with a different scheme than the destination site's own no longer points at an unreachable address after a restore.
* Changed: uploaded backup archives are now handled by WordPress's own upload API, which applies stricter file-type and destination validation.

= 1.3.4 =
* Initial submission to the WordPress Plugin Directory.

== Upgrade Notice ==

= 1.5.4 =
On hosts that use open_basedir, a WPCB_BACKUP_DIR inside the web root could be accepted as private, leaving backups downloadable. Recommended for anyone who has set WPCB_BACKUP_DIR.

= 1.5.3 =
Backups are now refused when the plugin cannot test that the backup folder is private, instead of guessing from the server's name - on hosts that put Nginx in front of Apache, that guess could leave an archive downloadable. If backups stop, the Dashboard shows the one line to add to wp-config.php.

= 1.5.2 =
Fixes crashes when uploading, restoring or downloading a backup on hosts that disable some PHP functions. Recommended for anyone on shared hosting.

= 1.5.1 =
Restoring a backup from a different WordPress version no longer sends wp-admin to the database update screen, and a restore that cannot replace WordPress core now stops before changing anything. Also fixes restores on Windows servers and memory errors on tables with very large rows. Recommended for everyone.

= 1.5.0 =
Restores are now all-or-nothing for the database: a failed import leaves your site untouched. Also fixes restores involving database views, MySQL 8 collations and DEFINER clauses, and a crash that left all plugins deactivated. Recommended for everyone.

= 1.4.3 =
A restore that would run out of storage or hit the account's file-count limit part-way through is now stopped before it starts, with a message naming which limit was reached. Recommended for anyone on shared hosting whose restore has failed mid-way.

= 1.4.2 =
A failed extraction now reports why it failed instead of only which file it stopped at, and a partial extraction can no longer finish reporting a clean restore.

= 1.4.0 =
Removes a generated file from wp-content/mu-plugins, hardens backup filenames against direct download, and fixes several failure cases where a full disk could produce a backup that looked complete but could not be restored. Existing backups and their location are unchanged.

= 1.3.5 =
Fixes two restore bugs that could corrupt page layouts and break media URLs. Re-create any backups made with an earlier version - existing archives carry the corruption and restoring one will reproduce it.
