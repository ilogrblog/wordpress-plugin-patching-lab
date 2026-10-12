# WordPress plugin patching lab: WP-CLI checks and AI-written plugin code

Companion to the [iLogr](https://ilogr.com/) article **[WordPress Plugin Patching with WP-CLI: Closing the Exploitation Window, and Checking AI-Written Plugin Code](https://ilogr.com/wordpress-plugin-patching-wp-cli/)**.

One script reproduces the article's two lab results on your own machine, in Docker, in about a minute after the first image download:

- **Part A, AI-written plugin code.** Two AI models wrote the same small WordPress plugin. [Plugin Check](https://wordpress.org/plugins/plugin-check/) scans both, then scans copies with the access control removed by a one-line change. The findings stay the same, while the REST route goes from HTTP 401 to HTTP 200 with the stored data.
- **Part B, WP-CLI patching checks.** An older Classic Editor is listed for update, dry-run, updated and verified. `wp plugin verify-checksums` catches a changed line but still passes with a shipped file deleted, and reinstalling the older version restores its files.

> **Before you run anything:** this lab installs WordPress, MariaDB and WP-CLI in Docker on your machine and downloads two plugins from WordPress.org. Run it on a development machine, never on a live server, and at your own risk. iLogr cannot see your machine and accepts no responsibility for damage, data loss or downtime.

## Requirements

- Docker with Compose v2
- `bash`, `curl` and `openssl` (standard on macOS and Linux)
- Port 8126 free on 127.0.0.1. Set `LAB_PORT` to use another port.

## Run it

```bash
./bin/run-lab.sh
```

Each check prints `PASS` or `FAIL`, and the script exits non-zero if any check fails. If Docker is not running, the port is taken or Docker is out of disk space, it stops straight away and says what to change. The lab containers are stopped when the script ends; set `KEEP_LAB_RUNNING=1` to leave them running and look around.

Expected results on the tested versions:

| Check | Expected |
| --- | --- |
| Sonnet plugin as written | 0 errors, 11 warnings |
| Haiku plugin as written | 2 errors, 21 warnings |
| Same plugins with access control removed | the same counts; no finding mentions permissions |
| Anonymous request to the REST route | 401 as written, 200 with the stored row once public |
| Classic Editor 1.6.5 | update listed, dry-run changes nothing, update succeeds |
| `verify-checksums` | passes after the update, fails after a changed line, **still passes with a deleted file** |
| Rollback | reinstalls 1.6.5 |

The counts leave out a missing `readme.txt` and text-domain notices, which come from the lab's folder names rather than from the code. The last section prints `wporg_status` for information: the article explains why custom plugins can show "closed".

Remove everything afterwards (the containers and their data):

```bash
docker compose down -v
```

## What is in this repository

| Path | What it is |
| --- | --- |
| `bin/run-lab.sh` | The lab script. |
| `bin/mutate.php` | Makes the one-line access-control changes in a copy, inside the container. The repository keeps the plugins exactly as written. |
| `compose.yaml` | WordPress 7.1.3 (PHP 8.3), MariaDB 11.4 and WP-CLI 2.12.0, bound to 127.0.0.1 only. The database passwords are local test values. |
| `plugins/` | The two specimen plugins, unedited. |

## About the specimen plugins

Both were written on 11 October 2026 by Claude Code subagents, one using Claude Sonnet 5.5 and one Claude Haiku 5.5, from the same request with no security instructions:

> Write a WordPress plugin called "Quick Quote Requests". It should add a shortcode [quote_request_form] that shows a form (name, email, message) on the front end, save each submission to a custom database table, show the submissions in an admin page under Tools, and add a REST API route that returns the submissions as JSON so our dashboard app can read them.

They are lab specimens for reading and testing, not plugins to install on a real site. The article's review also found concerns no scan reported: a rate limit keyed on the address PHP sees, which behaves differently behind a proxy; a nonce in a public form that a page cache can serve after it expires; and personal data kept with no retention rule.

## Tested

12 October 2026 on macOS with Docker Desktop: WordPress 7.1.3, PHP 8.3, MariaDB 11.4, WP-CLI 2.12.0, Plugin Check 2.1.0 (bundling WordPress Coding Standards 3.4.1), Classic Editor 1.6.5 updated to 1.7.0. Later versions may report different counts; the article explains what each result means.

## Licence

GPL-2.0-or-later, the licence of the WordPress code it builds on. See `LICENSE`.

More code that accompanies iLogr guides: [github.com/ilogrblog](https://github.com/ilogrblog).
