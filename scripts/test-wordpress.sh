#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-$(openssl rand -hex 24)}"
export WP_DB_PASSWORD="${WP_DB_PASSWORD:-$(openssl rand -hex 24)}"
export WP_DB_ROOT_PASSWORD="${WP_DB_ROOT_PASSWORD:-$(openssl rand -hex 24)}"
compose=(docker compose -p bicho-wordpress-test -f docker-compose.yml)
case "${BICHO_WP_VARIANT:-default}" in
  default) ;;
  dev|compat) compose+=(-f "docker-compose.${BICHO_WP_VARIANT}.yml") ;;
  *) printf 'Unknown WordPress variant\n' >&2; exit 1 ;;
esac
compose+=(-f tests/docker-compose.test.yml)
cleanup() {
  if [[ "${BICHO_KEEP_TEST_SITE:-0}" != 1 ]]; then
    "${compose[@]}" down -v --remove-orphans
  fi
}
trap cleanup EXIT
mkdir -p _temp/wordpress
chmod 777 _temp/wordpress
npm run build
"${compose[@]}" up -d --wait
ready=0
for attempt in {1..60}; do
  if "${compose[@]}" exec -T wp-cli wp --allow-root plugin is-active bicho-error-tracking >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
if [[ "$ready" != 1 ]]; then
  "${compose[@]}" logs wp-cli wordpress
  exit 1
fi
"${compose[@]}" exec -d wordpress php -S 0.0.0.0:8899 /var/bicho-tests/collector.php
"${compose[@]}" exec -d wp-cli php -S 127.0.0.1:8899 /var/bicho-tests/collector.php
ready=0
for attempt in {1..30}; do
  if curl -s -o /dev/null http://127.0.0.1:8899/; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" == 1 ]]
"${compose[@]}" exec -T wp-cli wp --allow-root eval 'delete_option("bicho_settings");'
npm run test:browser
"${compose[@]}" exec -T wp-cli wp --allow-root eval 'update_option("bicho_settings", ["php_enabled"=>true,"php_endpoint"=>"http://127.0.0.1:8899/v1/logs","php_key"=>"wordpress-php-fixture","php_secret"=>"wordpress-secret-fixture"], false);'
if "${compose[@]}" exec -T wp-cli wp --allow-root eval 'trigger_error("Bicho WordPress integration fatal", E_USER_ERROR);' >_temp/wordpress/fatal.log 2>&1; then
  printf 'Fatal fixture unexpectedly exited successfully\n' >&2
  exit 1
fi
python3 -c 'import json,pathlib; events=[json.loads(line) for line in pathlib.Path("_temp/wordpress/events.jsonl").read_text().splitlines() if line]; found=[event for event in events if "Bicho WordPress integration fatal" in json.dumps(event["payload"])]; assert len(found)==1, len(found); print("PASS: fatal fixture preserves failure exit and reports once")'
"${compose[@]}" exec -T wp-cli wp --allow-root eval 'define("WP_UNINSTALL_PLUGIN",true);require WP_PLUGIN_DIR."/bicho-error-tracking/uninstall.php";if(get_option("bicho_settings",null)!==null){throw new RuntimeException("Uninstall did not remove settings");}'
"${compose[@]}" exec -T wp-cli wp --allow-root plugin install plugin-check --activate
"${compose[@]}" exec -T wp-cli wp --allow-root plugin check bicho-error-tracking --format=json >_temp/wordpress/plugin-check.json
python3 -c 'import json,pathlib; findings=[item for line in pathlib.Path("_temp/wordpress/plugin-check.json").read_text().splitlines() if line.startswith("[") for item in json.loads(line)]; errors=[item for item in findings if item["type"]=="ERROR"]; assert not errors, errors; print("Plugin Check: 0 errors; %d warnings for review" % len(findings))'
printf 'PASS: WordPress runtime and plugin check completed; receipts in _temp/wordpress/\n'
