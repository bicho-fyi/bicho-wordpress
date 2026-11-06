# Bicho for WordPress

Report PHP and browser errors from WordPress to your own [Bicho](https://bicho.fyi) installation. PHP and browser reporting have separate switches, endpoints, and public ingest keys, so each can use a different Bicho project. Both are off until you enable them.

Requires WordPress 6.8+ and PHP 8.2+. PHP reporting also requires cURL. The plugin bundles the native [PHP SDK](https://github.com/bicho-fyi/bicho-php) and [JavaScript SDK](https://github.com/bicho-fyi/bicho-javascript); visitors do not download executable code from a CDN.

## Install

Download `bicho-error-tracking.zip` from the latest [GitHub release](https://github.com/bicho-fyi/bicho-wordpress/releases) (or build `build/bicho-error-tracking.zip` from source), then upload it through Plugins → Add Plugin → Upload Plugin. Activate Bicho and open Tools → Bicho. Deploy Bicho and register your projects separately using the [setup guide](https://bicho.fyi/docs/).

For each reporting channel, enter the exact generated OTLP endpoint ending in `/v1/logs` and its public ingest key, then enable the channel. The PHP channel optionally accepts the generated backend ingest secret. The browser channel must use a public-key-only project. Never enter your Bicho admin token here.

## Configuration overrides

Constants override environment variables, which override saved dashboard settings. Each overridden field shows its source and is read-only in Tools → Bicho. An explicit `false`, `0`, or empty environment value still overrides the dashboard. Invalid configuration disables the affected reporting channel rather than falling back to saved credentials.

```php
define('BICHO_PHP_ENABLED', true);
define('BICHO_PHP_ENDPOINT', 'https://collector.example/v1/logs');
define('BICHO_PHP_PUBLIC_KEY', 'YOUR_PHP_PUBLIC_KEY');
define('BICHO_PHP_SECRET', 'YOUR_BACKEND_INGEST_SECRET');

define('BICHO_JS_ENABLED', true);
define('BICHO_JS_ENDPOINT', 'https://collector.example/v1/logs');
define('BICHO_JS_PUBLIC_KEY', 'YOUR_BROWSER_PUBLIC_KEY');

define('BICHO_DEBUG', false);
define('BICHO_ENVIRONMENT', 'production');
define('BICHO_RELEASE', 'my-site@1.0.0');
```

Put constants in `wp-config.php` before WordPress loads plugins. Environment variables use the same names; boolean values accept `true`/`false`, `1`/`0`, `yes`/`no`, or `on`/`off`. Omit `BICHO_PHP_SECRET` for a public-key-only backend project. An empty secret disables the extra header.

`BICHO_DEBUG` enables fixed, credential-free reporting diagnostics in the PHP error log. It does not turn on `WP_DEBUG`, expose errors to visitors, or change which PHP severities are captured. The dashboard identifies constant/environment overrides even when debug is off.

## Capture and privacy

PHP reporting handles unsuppressed warnings, user warnings, recoverable errors, and fatal errors after the plugin starts. It chains existing error handlers and leaves normal WordPress/PHP error handling in place. It cannot capture failures before plugin loading, every out-of-memory crash, or exceptions caught by your application. Notices and deprecations are not reported automatically.

Reporting uses synchronous PHP SDK delivery with a one-second deadline per report and a maximum of five reports per request. It adds request latency and is best effort. A collector failure must not replace your application's error handling. The plugin does not keep an offline queue or delete errors already stored in Bicho.

Browser reporting runs on the public frontend, not wp-admin or login screens. It captures uncaught JavaScript errors and unhandled promise rejections after the SDK loads. It does not collect sessions, replay, console output, cookies, form inputs, logged-in user details, request bodies, or server environment variables. Backend secrets never appear in browser configuration.

Error messages, stack traces, and file paths can still contain private information. The SDKs filter recognized credential patterns, not every secret or personal detail. Review what your site sends and your collector's access controls. Enabling a channel authorizes reports to the endpoint you configure; there is no FreshJuice telemetry endpoint. Your site's notice and consent requirements remain your responsibility.

## Development

```sh
npm ci
composer install
npm run build
npm test
npm run package
```

The build scopes the PHP SDK to the plugin's own namespace so another plugin's Composer dependencies cannot replace it. It copies pinned SDK code and notices into `dist/`, with third-party PHP source under `dist/vendor/bicho-php/`. The package script stages only runtime files in `build/bicho-error-tracking/` and creates the installable ZIP from that same directory. Users installing the ZIP do not need Node.js or Composer.

The Docker setup provides a local WordPress site, database, and WP-CLI. Use separate Compose project names for the default, dev, and compatibility environments. See [DEVELOPMENT.md](DEVELOPMENT.md) for startup and runtime checks. No release or directory upload runs automatically.

## License

Copyright 2026 [Alex Zappa](https://alex.zappa.dev/) ([FreshJuice](https://freshjuice.dev/)). Licensed under Apache-2.0. See [LICENSE](LICENSE) and [ACKNOWLEDGEMENTS.md](ACKNOWLEDGEMENTS.md). WordPress and its bundled components retain their own licenses.
