#!/usr/bin/env bash
# Reproduces the two lab results in the iLogr article
# "WordPress Plugin Patching with WP-CLI: Closing the Exploitation Window, and Checking AI-Written Plugin Code".
#
#   Part A: Plugin Check 2.1.0 on two AI-written plugins, before and after a one-line access-control change.
#   Part B: WP-CLI 2.12.0 update, checksum and rollback behaviour on a WordPress.org plugin.
#
# Everything runs in Docker on this machine. Nothing here connects to a live site.
# Usage: ./bin/run-lab.sh
#   The lab containers are stopped when the script ends. KEEP_LAB_RUNNING=1 leaves them running.
#   Remove the containers and their data afterwards: docker compose down -v
set -euo pipefail
cd "$(dirname "$0")/.."

PORT="${LAB_PORT:-8126}"
URL="http://127.0.0.1:${PORT}"
fails=0
pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; fails=$((fails + 1)); }
check() { if [ "$2" = "$3" ]; then pass "$1 ($2)"; else fail "$1 (expected $3, got $2)"; fi; }
wpc() { docker compose exec -T cli wp "$@"; }
inside() { docker compose exec -T cli "$@"; }

die() { printf 'STOP  %s\n' "$1" >&2; exit 2; }
for tool in docker curl openssl; do command -v "$tool" >/dev/null 2>&1 || die "$tool is not installed."; done
docker info >/dev/null 2>&1 || die "Docker is not running. Start Docker and run the script again."
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is required (the 'docker compose' command)."

# Stop the lab when the script ends, including after an error or Ctrl+C, unless asked to keep it.
# shellcheck disable=SC2317  # called by the EXIT trap below
stop_lab() { [ "${KEEP_LAB_RUNNING:-0}" = "1" ] || docker compose stop >/dev/null 2>&1 || true; }
trap stop_lab EXIT

echo "== Starting the lab (WordPress 7.1.3, PHP 8.3, MariaDB 11.4, WP-CLI 2.12.0)"
echo "   The first run downloads the Docker images, which can take a few minutes."
if ! up_log=$(docker compose up -d --quiet-pull 2>&1); then
	printf '%s\n' "$up_log" >&2
	die "The lab did not start. If port ${PORT} is in use, run again with LAB_PORT set to a free port, for example LAB_PORT=8127 ./bin/run-lab.sh"
fi
ready=0
for _ in $(seq 1 90); do
	if inside test -f wp-config.php >/dev/null 2>&1; then ready=1; break; fi
	sleep 2
done
[ "$ready" = "1" ] || die "WordPress did not finish starting within 3 minutes. Check 'docker compose logs wordpress'."
if ! wpc core is-installed 2>/dev/null; then
	# A random admin password, never printed: nobody needs to log in to run the lab.
	wpc core install --url="$URL" --title="Plugin patching lab" --admin_user=labadmin \
		--admin_password="$(openssl rand -hex 16)" --admin_email=lab@example.test --skip-email >/dev/null
fi
wpc plugin install plugin-check --version=2.1.0 --activate --force >/dev/null 2>&1
check "Plugin Check version" "$(wpc plugin get plugin-check --field=version)" "2.1.0"

# Errors and warnings from Plugin Check, leaving out the missing readme and text-domain items,
# which come from the lab's folder names rather than from the code.
counts() {
	# The braces keep a run with no findings from stopping the script (grep exits 1 when nothing matches).
	wpc plugin check "$1" --format=csv --fields=type,code 2>/dev/null \
		| { grep -E '^(ERROR|WARNING),' || true; } | { grep -v -i -e textdomain -e no_plugin_readme || true; } \
		| awk -F, '{ if ($1 == "ERROR") e++; else w++ } END { printf "%d errors, %d warnings", e, w }'
}
fresh() { inside rm -rf "wp-content/plugins/$1"; }

echo
echo "== Part A: what Plugin Check sees in two AI-written plugins"
for p in quick-quote-requests-sonnet quick-quote-requests-haiku; do
	fresh "$p"; inside cp -r "/specimens/$p" wp-content/plugins/
done
inside php /lab-bin/mutate.php public-rest \
	/specimens/quick-quote-requests-haiku/quick-quote-requests.php \
	wp-content/plugins/quick-quote-requests-haiku-public/quick-quote-requests.php
inside php /lab-bin/mutate.php no-capability-check \
	/specimens/quick-quote-requests-sonnet/quick-quote-requests.php \
	wp-content/plugins/quick-quote-requests-sonnet-nocap/quick-quote-requests.php

sonnet=$(counts quick-quote-requests-sonnet); haiku=$(counts quick-quote-requests-haiku)
check "Sonnet plugin as written" "$sonnet" "0 errors, 11 warnings"
check "Haiku plugin as written" "$haiku" "2 errors, 21 warnings"
check "Sonnet plugin without its capability check: same findings" "$(counts quick-quote-requests-sonnet-nocap)" "$sonnet"
check "Haiku plugin with a public REST route: same findings" "$(counts quick-quote-requests-haiku-public)" "$haiku"
perm=$(wpc plugin check quick-quote-requests-haiku-public --format=csv --fields=code,message 2>/dev/null \
	| grep -c -i -E 'permission|capabilit|__return_true' || true)
check "Findings that mention permissions" "$perm" "0"

route="$URL/?rest_route=/quick-quote-requests/v1/requests"
status() { curl -s -o /dev/null -w '%{http_code}' "$route"; }
wpc plugin deactivate --all --exclude=plugin-check >/dev/null 2>&1 || true
wpc plugin activate quick-quote-requests-haiku >/dev/null
wpc db query "INSERT INTO wp_qqr_requests (name,email,message,created_at) VALUES ('Test Person','test@example.test','Synthetic lab row',UTC_TIMESTAMP())" >/dev/null
check "Anonymous request, plugin as written" "$(status)" "401"
wpc plugin deactivate quick-quote-requests-haiku >/dev/null
wpc plugin activate quick-quote-requests-haiku-public >/dev/null
check "Anonymous request, route made public" "$(status)" "200"
if curl -s "$route" | grep -q 'Synthetic lab row'; then pass "The public route returns the stored submission"; else fail "The public route returns the stored submission"; fi
wpc plugin deactivate quick-quote-requests-haiku-public >/dev/null

echo
echo "== Part B: WP-CLI update, checksums and rollback (Classic Editor from WordPress.org)"
wpc plugin install classic-editor --version=1.6.5 --force >/dev/null 2>&1
if wpc plugin list --update=available --field=name | grep -qx classic-editor; then pass "An update is listed for Classic Editor 1.6.5"; else fail "An update is listed for Classic Editor 1.6.5"; fi
if wpc plugin update --all --dry-run | grep -q classic-editor; then pass "The dry-run lists it and changes nothing"; else fail "The dry-run lists it"; fi
check "Still 1.6.5 after the dry-run" "$(wpc plugin get classic-editor --field=version)" "1.6.5"
wpc plugin update classic-editor >/dev/null
updated=$(wpc plugin get classic-editor --field=version)
if [ "$updated" != "1.6.5" ]; then pass "Updated to $updated"; else fail "Update did not change the version"; fi
if wpc plugin verify-checksums classic-editor >/dev/null 2>&1; then pass "Checksums verify after the update"; else fail "Checksums verify after the update"; fi
inside sh -c 'echo "// lab change" >> wp-content/plugins/classic-editor/classic-editor.php'
if wpc plugin verify-checksums classic-editor >/dev/null 2>&1; then fail "A changed line is caught"; else pass "A changed line is caught"; fi
wpc plugin install classic-editor --force >/dev/null 2>&1
inside rm -f wp-content/plugins/classic-editor/js/block-editor-plugin.js
if wpc plugin verify-checksums classic-editor >/dev/null 2>&1; then
	pass "A deleted file is NOT caught: checksum verification still passes (the gap the article describes)"
else
	fail "Expected verify-checksums to pass with a deleted file; WP-CLI may have changed, so recheck the article"
fi
wpc plugin install classic-editor --version=1.6.5 --force >/dev/null 2>&1
check "Rollback reinstalls the older files" "$(wpc plugin get classic-editor --field=version)" "1.6.5"

echo
echo "== Information only: what wporg_status reports today (the article explains why custom plugins can show 'closed')"
wpc plugin list --fields=name,wporg_status --format=table 2>/dev/null || true

echo
if [ "$fails" -eq 0 ]; then echo "All checks passed."; else echo "$fails check(s) failed."; fi
if [ "${KEEP_LAB_RUNNING:-0}" = "1" ]; then echo "The lab is still running at ${URL}."; else echo "The lab containers have been stopped."; fi
echo "Remove the containers and their data with: docker compose down -v"
exit $(( fails > 0 ? 1 : 0 ))
