<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
$GLOBALS['saved'] = [];
$GLOBALS['errors'] = [];
function get_option($name, $default = false) { return $GLOBALS['saved']; }
function add_settings_error(...$args): void { $GLOBALS['errors'][] = $args; }
function __($text, $domain = '') { return $text; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function checked($actual, $expected, $echo = true) { return $actual == $expected ? ' checked="checked"' : ''; }
function add_action(...$args): void { $GLOBALS['hooks'][] = $args; }
function add_option(...$args): void { $GLOBALS['option_args'] = $args; }
function wp_set_option_autoload_values($args): void { $GLOBALS['autoload'] = $args; }
function register_setting(...$args): void { $GLOBALS['registration'] = $args; }
function add_settings_section(...$args): void {}
function add_settings_field(...$args): void { $GLOBALS['fields'][] = $args; }
function add_management_page(...$args): void { $GLOBALS['menu'] = $args; }
function current_user_can($cap): bool { return $GLOBALS['allowed'] ?? false; }
function wp_die($message): never { throw new RuntimeException($message); }
function settings_errors($group): void {}
function settings_fields($group): void { echo '<input name="_wpnonce" value="test-nonce">'; $GLOBALS['nonce_group'] = $group; }
function do_settings_sections($page): void { $GLOBALS['sections_page'] = $page; }
function submit_button(): void { echo '<button type="submit">Save</button>'; }
require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/settings.php';

use BichoWordPress\Config;
use BichoWordPress\Settings;

$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    ++$count;
    if (!$condition) { throw new RuntimeException($message); }
}

foreach (Config::constants() as $name) { putenv($name); }

if (($argv[1] ?? '') === 'constant') {
    putenv('BICHO_PHP_ENABLED=true');
    putenv('BICHO_ENVIRONMENT=staging');
    define('BICHO_PHP_ENABLED', false);
    define('BICHO_ENVIRONMENT', '');
    define('BICHO_PHP_SECRET', 'never-output-external-secret');
    $GLOBALS['saved'] = ['php_enabled' => true, 'php_secret' => 'saved-secret'];
    check(Config::source('php_enabled') === 'constant', 'False constant takes precedence');
    check(Config::get()['php_enabled'] === false, 'False constant remains false');
    check(Config::get()['environment'] === '', 'Empty constant takes precedence');
    check(Config::sanitize(['php_secret' => 'replacement', 'php_secret_clear' => '1'])['php_secret'] === 'saved-secret', 'External secret preserves saved value');
    ob_start();
    Settings::render_field(['field' => 'php_secret']);
    $html = ob_get_clean();
    check(!str_contains($html, 'never-output-external-secret') && !str_contains($html, 'saved-secret'), 'No secret in HTML');
    check(str_contains($html, ' disabled') && str_contains($html, 'BICHO_PHP_SECRET') && str_contains($html, 'Configured by constant'), 'Constant source shown and control disabled');
    echo "constant checks: $count passed\n";
    exit;
}

check(Config::get() === Config::defaults(), 'Disabled defaults');
check(count(Config::defaults()) === 10, 'Exact fields');
foreach (['1', 'true', 'TRUE', 'yes', 'on', 1, true] as $value) {
    check(Config::sanitize(['debug' => $value])['debug'] === true, 'Truthy boolean');
}
foreach (['0', 'false', 'off', 'no', '', 0, false] as $value) {
    check(Config::sanitize(['debug' => $value])['debug'] === false, 'False boolean');
}
foreach (['wat', ' true ', 2, [], null] as $value) {
    putenv('BICHO_DEBUG=' . (is_string($value) ? $value : 'invalid'));
    check(Config::get()['debug'] === false, 'Invalid override fails closed');
}
putenv('BICHO_DEBUG');
$GLOBALS['saved'] = ['environment' => 'dashboard', 'debug' => true];
putenv('BICHO_ENVIRONMENT=');
check(Config::source('environment') === 'environment' && Config::get()['environment'] === '', 'Empty environment overrides dashboard');
check(Config::sanitize(['environment' => 'changed'])['environment'] === 'dashboard', 'Disabled field preserves dashboard value');
ob_start();
Settings::render_field(['field' => 'environment']);
$html = ob_get_clean();
check(str_contains($html, ' disabled') && str_contains($html, 'BICHO_ENVIRONMENT') && str_contains($html, 'Configured by environment variable'), 'Environment source shown');
putenv('BICHO_ENVIRONMENT');

$valid = ['https://errors.example.com/v1/logs', 'http://localhost:8787/v1/logs', 'http://127.0.0.1/v1/logs', 'http://[::1]:8787/v1/logs'];
foreach ($valid as $url) {
    check(Config::sanitize(['php_endpoint' => $url])['php_endpoint'] === $url, 'Valid endpoint: ' . $url);
}
$GLOBALS['saved'] = ['php_endpoint' => $valid[0], 'php_secret' => 'saved-secret'];
$invalid = ['http://example.com/v1/logs', 'https://u:p@example.com/v1/logs', 'https://example.com/v1/logs?', 'https://example.com/v1/logs#', 'https://example.com/a/../v1/logs', 'https://example.com/%2e%2e/v1/logs', 'https://example.com/v1/logs/', 'javascript:alert(1)', "https://example.com/v1/logs\n", 'http://127.0.0.2/v1/logs', 'http://localhost.evil/v1/logs', 'https://example.com\\@evil/v1/logs'];
foreach ($invalid as $url) {
    $before = count($GLOBALS['errors']);
    check(Config::sanitize(['php_endpoint' => $url])['php_endpoint'] === $valid[0], 'Invalid endpoint keeps prior value');
    check(count($GLOBALS['errors']) > $before, 'Invalid endpoint reports an error');
}
check(Config::sanitize(['php_secret' => ''])['php_secret'] === 'saved-secret', 'Blank secret preserves');
check(Config::sanitize(['php_secret_clear' => '1'])['php_secret'] === '', 'Explicit clear secret');
foreach (["bad\nkey", "bad\x00key", str_repeat('a', 513), ['bad'], 'é'] as $key) {
    check(Config::sanitize(['php_secret' => $key])['php_secret'] === 'saved-secret', 'Invalid key is not repaired');
}
check(Config::sanitize(['php_key' => str_repeat('a', 512)])['php_key'] === str_repeat('a', 512), '512 ASCII key accepted');
check(Config::sanitize(['js_key' => 'browser-key_123'])['js_key'] === 'browser-key_123', 'Browser SDK key accepted');
check(Config::sanitize(['js_key' => 'invalid.browser.key'])['js_key'] === '', 'Invalid browser SDK key rejected before enqueue');
check(Config::sanitize('bad')['php_secret'] === 'saved-secret', 'Malformed top level preserves values');
check(Config::sanitize(['environment' => ['bad']])['environment'] === 'production', 'Malformed field safe');
check(Config::sanitize(['release' => '<script>alert(1)</script>'])['release'] === '', 'XSS metadata rejected');
$GLOBALS['saved'] = ['php_enabled' => true, 'php_endpoint' => $valid[0], 'php_key' => 'public', 'php_secret' => 'secret'];
check(Config::get()['php_enabled'] === true, 'Complete configuration enabled');
unset($GLOBALS['saved']['php_secret']);
check(Config::get()['php_enabled'] === true, 'Public-key-only PHP project enabled without a secret');
putenv('BICHO_PHP_SECRET=bad secret');
check(Config::get()['php_enabled'] === false, 'Invalid external secret cannot downgrade to public-key-only reporting');
putenv('BICHO_PHP_SECRET');
putenv('BICHO_PHP_ENDPOINT=not-a-url');
check(Config::get()['php_enabled'] === false, 'Invalid external endpoint disables reporting');
putenv('BICHO_PHP_ENDPOINT');
$GLOBALS['saved']['php_secret'] = "broken\nsecret";
check(Config::get()['php_enabled'] === false, 'Invalid saved secret disables reporting');
$GLOBALS['saved'] = ['php_key' => '\"><script>alert(1)</script>', 'php_secret' => 'saved-secret'];
ob_start();
Settings::render_field(['field' => 'php_key']);
Settings::render_field(['field' => 'php_secret']);
$html = ob_get_clean();
check(!str_contains($html, '<script>') && !str_contains($html, 'saved-secret'), 'Escaped output, no saved secret');
$GLOBALS['saved'] = ['php_endpoint' => 'invalid', 'php_secret' => []];
check(Config::sanitize([])['php_endpoint'] === '' && Config::sanitize([])['php_secret'] === '', 'Unsafe prior data not preserved');
Settings::boot();
Settings::register_settings();
Settings::add_menu_page();
check(count($GLOBALS['hooks']) === 2, 'Boot registers native admin hooks');
check($GLOBALS['option_args'][3] === false && $GLOBALS['autoload']['bicho_settings'] === false, 'Secret option not autoloaded');
check($GLOBALS['registration'][0] === 'bicho_settings' && $GLOBALS['registration'][2]['sanitize_callback'] === [Config::class, 'sanitize'], 'Settings API registration');
check(count($GLOBALS['fields']) === 10, 'All fields registered');
check($GLOBALS['menu'][2] === 'manage_options' && $GLOBALS['menu'][3] === 'bicho-error-tracking', 'Tools page capability and slug');
$denied = false;
try { Settings::render_page(); } catch (RuntimeException $error) { $denied = true; }
check($denied, 'Page denies unprivileged users');
$GLOBALS['allowed'] = true;
ob_start();
Settings::render_page();
$html = ob_get_clean();
check(str_contains($html, 'action="options.php"') && str_contains($html, '_wpnonce'), 'Native Settings API form');
check($GLOBALS['nonce_group'] === 'bicho_settings' && $GLOBALS['sections_page'] === 'bicho-error-tracking', 'Correct nonce group and sections');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' constant';
passthru($command, $status);
check($status === 0, 'Constant subprocess passed');
echo "config checks: $count passed\n";
