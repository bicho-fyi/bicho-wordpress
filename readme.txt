=== Bicho ===
Contributors: freshjuice4dev, reatlat
Tags: error-tracking, debugging, monitoring, opentelemetry, errors
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Report PHP and browser errors to your self-hosted Bicho instance, with independent settings and configuration overrides for each channel.

== Description ==

Bicho connects WordPress to your own Bicho error tracker. Enable PHP reporting, browser reporting, or both under Tools > Bicho. Each channel has its own endpoint and public ingest key, so backend and frontend errors can go to different projects.

The plugin bundles the Bicho PHP and JavaScript SDKs. It does not load reporting scripts from a CDN, add public branding, or require a FreshJuice account.

Constants in wp-config.php override environment variables, which override dashboard settings. The settings page identifies externally configured fields and makes them read-only. A debug switch enables credential-free PHP reporting diagnostics without enabling WP_DEBUG or displaying errors to visitors.

PHP requires cURL. Capture begins after plugins load. Warnings and fatal errors are reported without replacing normal PHP/WordPress handling; caught exceptions, early bootstrap failures, notices, and deprecations are not automatically reported. Delivery is synchronous, bounded to one second per report and five reports per request.

Browser reporting captures uncaught errors and unhandled promise rejections on public frontend pages after the SDK loads. It does not run on wp-admin or login screens.

== External services ==

Both reporting channels are disabled by default. After you explicitly enable and configure a channel, it sends error messages, stack/file locations, SDK/application versions, and configured environment/release metadata to your chosen Bicho OTLP/HTTP endpoint. PHP may send an additional backend ingest secret; browser configuration contains only its own endpoint and public key.

Your endpoint operator controls that installation's storage, access, and data policy. The plugin does not send reports to bicho.fyi or a FreshJuice telemetry service by default. It does not automatically collect cookies, user identities, form inputs, request bodies, or environment variable values. Error text and paths may still contain private information; credential filtering is best effort. The site operator is responsible for disclosure and any necessary consent.

Bicho project information: https://bicho.fyi/
Self-hosted data handling: https://bicho.fyi/docs/privacy/
Documentation website privacy notice: https://bicho.fyi/privacy/
Documentation website terms: https://bicho.fyi/terms/

The website notices describe the documentation website, not the policy of your self-hosted collector. Consult your collector operator's policy before enabling reporting.

== Installation ==

1. Deploy Bicho and register backend and/or browser projects: https://bicho.fyi/docs/
2. Download bicho-error-tracking.zip from https://github.com/bicho-fyi/bicho-wordpress/releases, upload it through Plugins > Add Plugin > Upload Plugin, and activate Bicho.
3. Open Tools > Bicho.
4. Configure the exact /v1/logs endpoint and public ingest key for each channel you want to enable.
5. Use a public-key-only project for the browser. Put optional backend ingest secrets only in PHP configuration.
6. Enable the chosen channels and save. Use a dedicated test project for deliberate error checks.

== Frequently Asked Questions ==

= Can PHP and JavaScript use separate projects? =

Yes. They have separate enable switches, endpoints, and public keys. PHP also supports an optional backend ingest secret. Never use an admin token for capture.

= Can I configure this without the dashboard? =

Yes. Define BICHO_PHP_ENABLED, BICHO_PHP_ENDPOINT, BICHO_PHP_PUBLIC_KEY, BICHO_PHP_SECRET, BICHO_JS_ENABLED, BICHO_JS_ENDPOINT, BICHO_JS_PUBLIC_KEY, BICHO_DEBUG, BICHO_ENVIRONMENT, and BICHO_RELEASE in wp-config.php or environment variables. Set only the values you need to override. Constants take precedence. See the source README for examples.

= Does debug display errors to visitors? =

No. It enables fixed reporting diagnostics in the PHP error log. It does not change WP_DEBUG or capture notices and deprecations. External configuration sources are shown on the settings screen regardless of debug mode.

= Does uninstalling delete my Bicho events? =

No. Uninstall removes this plugin's saved WordPress settings. It does not modify your Bicho instance or remove constants and environment variables.

= Does the plugin capture every crash? =

No. PHP errors before plugin loading, caught exceptions, and some memory exhaustion failures cannot be captured. Browser errors before the script loads cannot be captured. SDK delivery and shutdown are best effort.

== Source code ==

Plugin source and build scripts: https://github.com/bicho-fyi/bicho-wordpress
PHP SDK source: https://github.com/bicho-fyi/bicho-php
JavaScript SDK source: https://github.com/bicho-fyi/bicho-javascript

Run npm ci, composer install, npm run build, and npm run package. Lockfiles select the SDK versions. dist/ includes readable SDK source, licenses, acknowledgements, and a version manifest. The PHP SDK is namespace-scoped during bundling; its transport behavior is unchanged.

== Changelog ==

= 1.0.0 =
* Separate opt-in PHP and browser error reporting.
* Native Tools settings with independent project configuration.
* Constant and environment overrides, plus safe debug diagnostics.
* Bundled Bicho SDKs, Docker development, and runtime-only ZIP packaging.
