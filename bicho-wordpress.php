<?php
/**
 * Plugin Name: Bicho
 * Plugin URI: https://bicho.fyi
 * Description: Report PHP and browser errors to your own Bicho instance with independent settings for each reporting channel.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: FreshJuice
 * Author URI: https://freshjuice.dev
 * License: Apache-2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain: bicho-error-tracking
 */

declare(strict_types=1);

namespace BichoWordPress;

if (!defined('ABSPATH')) {
    exit;
}

define('BICHO_WORDPRESS_VERSION', '1.0.0');
define('BICHO_WORDPRESS_DIR', plugin_dir_path(__FILE__));
define('BICHO_WORDPRESS_URL', plugin_dir_url(__FILE__));

require_once BICHO_WORDPRESS_DIR . 'includes/config.php';
require_once BICHO_WORDPRESS_DIR . 'includes/settings.php';

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\SDK\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (preg_match('/\A[A-Za-z]+\z/D', $name)) {
        $file = BICHO_WORDPRESS_DIR . 'dist/vendor/bicho-php/' . $name . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

add_action('plugins_loaded', static function (): void {
    Settings::boot();
    if (!is_file(BICHO_WORDPRESS_DIR . 'dist/vendor/bicho-php/Client.php') || !is_file(BICHO_WORDPRESS_DIR . 'dist/bicho.min.js')) {
        add_action('admin_notices', static function (): void {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Bicho requires its bundled SDK files. Install the complete plugin ZIP or run the build script.', 'bicho-error-tracking') . '</p></div>';
            }
        });
        return;
    }
    require_once BICHO_WORDPRESS_DIR . 'includes/runtime.php';
    Runtime::boot();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
    array_unshift($links, '<a href="' . esc_url(admin_url('tools.php?page=bicho-error-tracking')) . '">' . esc_html__('Settings', 'bicho-error-tracking') . '</a>');
    return $links;
});

register_activation_hook(__FILE__, static function (): void {
    add_option('bicho_settings', Config::defaults(), '', false);
});
