# Development

## Build and package

Use Node.js 24+, Composer, PHP 8.2+ with cURL, Python 3, and Docker Compose.

```sh
npm ci
composer install
npm run build
npm test
npm run package
```

`dist/` contains the installed SDK versions, namespaced PHP source, JavaScript, licenses, and acknowledgement files. `build/bicho-error-tracking.zip` contains only installable runtime files. Both directories are generated and ignored. Do not install the repository root on a public server or include development files in a release ZIP.

## WordPress runtime checks

```sh
npm run test:wordpress
```

This starts a dedicated `bicho-wordpress-test` Compose project on loopback ports 8896 and 8899, generates a local administrator password, installs/activates the plugin, and runs real WordPress/browser/SDK checks against a local collector. It does not send reports to a hosted Bicho project. The test removes its own containers and volumes on exit; it never stops other Compose projects.

Set `BICHO_KEEP_TEST_SITE=1` to keep the throwaway site for inspection. Reports and a settings screenshot are written under ignored `_temp/wordpress/`. The browser check uses the system Chrome installation on macOS; set `CHROME_PATH` for another installation. It obtains a temporary local session through WP-CLI without storing or displaying an administrator password.

The runtime test covers the Tools settings form, WordPress nonce-protected saving, separate project delivery, constant overrides, secret exclusion from frontend HTML, independent enable switches, fatal-error behavior, uninstall cleanup, and the official Plugin Check scanner. `npm test` separately covers configuration validation, overrides, suppression, handler chaining, recursion, rate bounds, and safe failure paths without WordPress.

Run the same checks against the other environments with `BICHO_WP_VARIANT=compat npm run test:wordpress` and `BICHO_WP_VARIANT=dev npm run test:wordpress`. Use a fresh test project between runs; the default cleanup removes its volumes. The dev and compatibility logs are separate from packaging validation.

Plugin Check reports six warnings in the error-reporting adapter: installing the error handler, reading the current error mask, and the explicitly enabled diagnostic log. The mask calls have no arguments and do not change PHP reporting settings. These warnings remain visible for review rather than being suppressed. Bundled standalone SDK source lives in the conventional vendor directory and is covered separately by SDK and real-delivery tests. A zero-error scan is not directory approval.

## Manual environments

Set `WP_ADMIN_PASSWORD`, `WP_DB_PASSWORD`, and `WP_DB_ROOT_PASSWORD` in your local shell before startup; do not commit them. The local administrator username is `bicho`.

```sh
docker compose up -d

docker compose -p bicho-wordpress-dev -f docker-compose.yml -f docker-compose.dev.yml up -d

docker compose -p bicho-wordpress-compat -f docker-compose.yml -f docker-compose.compat.yml up -d
```

Use a separate project name for each environment to keep its WordPress/database volumes independent. Wait for the WP-CLI initialization log to confirm installation, not a fixed startup delay. The development bind mounts include runtime files only; they do not expose `.git`, tests, scripts, or local configuration inside the plugin directory.

## Release boundaries

A local ZIP is not a WordPress.org approval or release. Source pushes, GitHub releases, tags, WordPress.org submission, and SVN publication require separate approval. Check current WordPress versions, regenerate translations, inspect the actual ZIP, and run Plugin Check before submission. Apache-2.0 is the selected license; WordPress.org requires GPL-compatible licensing, not that every plugin use the same license text as Zest.
