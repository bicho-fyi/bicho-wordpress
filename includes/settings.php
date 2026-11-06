<?php

declare(strict_types=1);

namespace BichoWordPress;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public static function boot(): void
    {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_menu', [self::class, 'add_menu_page']);
    }

    public static function register_settings(): void
    {
        add_option('bicho_settings', [], '', false);
        if (function_exists('wp_set_option_autoload_values')) {
            wp_set_option_autoload_values(['bicho_settings' => false]);
        }
        register_setting('bicho_settings', 'bicho_settings', [
            'type' => 'array', 'sanitize_callback' => [Config::class, 'sanitize'],
            'show_in_rest' => false, 'default' => Config::defaults(),
        ]);
        add_settings_section('bicho_configuration', __('Reporting', 'bicho-error-tracking'), '__return_false', 'bicho-error-tracking');
        foreach (self::fields() as $field => [$label]) {
            add_settings_field($field, $label, [self::class, 'render_field'], 'bicho-error-tracking', 'bicho_configuration',
                ['field' => $field, 'label_for' => 'bicho_' . $field]);
        }
    }

    public static function add_menu_page(): void
    {
        add_management_page(__('Bicho', 'bicho-error-tracking'), __('Bicho', 'bicho-error-tracking'),
            'manage_options', 'bicho-error-tracking', [self::class, 'render_page']);
    }

    private static function fields(): array
    {
        return [
            'php_enabled' => [__('PHP reporting', 'bicho-error-tracking'), __('Enable server-side error reporting. Requires an endpoint and public key; a backend ingest secret is optional.', 'bicho-error-tracking')],
            'php_endpoint' => [__('PHP endpoint', 'bicho-error-tracking'), __('HTTPS URL ending exactly in /v1/logs. HTTP is allowed only for localhost, 127.0.0.1 or [::1] testing.', 'bicho-error-tracking')],
            'php_key' => [__('PHP public key', 'bicho-error-tracking'), __('The public key for server-side reporting.', 'bicho-error-tracking')],
            'php_secret' => [__('PHP secret', 'bicho-error-tracking'), __('Leave blank to keep the saved secret. The secret is never displayed.', 'bicho-error-tracking')],
            'js_enabled' => [__('JavaScript reporting', 'bicho-error-tracking'), __('Enable browser error reporting. Requires an endpoint and public key.', 'bicho-error-tracking')],
            'js_endpoint' => [__('JavaScript endpoint', 'bicho-error-tracking'), __('HTTPS URL ending exactly in /v1/logs. HTTP is allowed only for loopback testing.', 'bicho-error-tracking')],
            'js_key' => [__('JavaScript public key', 'bicho-error-tracking'), __('The public key sent to browsers. Never enter a secret here.', 'bicho-error-tracking')],
            'debug' => [__('Reporting diagnostics', 'bicho-error-tracking'), __('Log safe reporting diagnostics only, never credentials or captured event contents.', 'bicho-error-tracking')],
            'environment' => [__('Environment', 'bicho-error-tracking'), __('For example: production or staging.', 'bicho-error-tracking')],
            'release' => [__('Release', 'bicho-error-tracking'), __('Optional application release identifier.', 'bicho-error-tracking')],
        ];
    }

    public static function render_field(array $args): void
    {
        $field = $args['field'];
        $defaults = Config::defaults();
        if (!array_key_exists($field, $defaults)) {
            return;
        }
        $source = Config::source($field);
        $external = $source !== 'dashboard';
        $config = Config::get();
        $saved = get_option('bicho_settings', []);
        $value = $external ? $config[$field] : (is_array($saved) ? ($saved[$field] ?? $defaults[$field]) : $defaults[$field]);
        $value = is_scalar($value) ? $value : $defaults[$field];
        $id = 'bicho_' . $field;
        $disabled = $external ? ' disabled' : '';
        $name = 'bicho_settings[' . $field . ']';
        $description = $id . '_description';
        if (is_bool($defaults[$field])) {
            echo '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" aria-describedby="' . esc_attr($description) . '"' . checked($value, true, false) . esc_attr($disabled) . '>';
        } else {
            $secret = $field === 'php_secret';
            $placeholder = $secret && !$external && $value !== '' ? __('Saved secret (unchanged)', 'bicho-error-tracking') : '';
            echo '<input class="regular-text" type="' . ($secret ? 'password' : 'text') . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($secret ? '' : (string) $value) . '" placeholder="' . esc_attr($placeholder) . '" aria-describedby="' . esc_attr($description) . '" autocomplete="off"' . esc_attr($disabled) . '>';
            if ($secret && !$external) {
                echo '<p><label><input type="checkbox" name="bicho_settings[php_secret_clear]" value="1"> ' . esc_html__('Clear saved secret', 'bicho-error-tracking') . '</label></p>';
            }
        }
        echo '<p class="description" id="' . esc_attr($description) . '">' . esc_html(self::fields()[$field][1]) . '</p>';
        if ($external) {
            $message = $source === 'constant'
                ? __('Configured by constant (for example, in wp-config.php):', 'bicho-error-tracking')
                : __('Configured by environment variable:', 'bicho-error-tracking');
            echo '<p class="description">' . esc_html($message) . ' <code>' . esc_html(Config::constants()[$field]) . '</code></p>';
        } else {
            echo '<p class="description">' . esc_html__('Configured by dashboard', 'bicho-error-tracking') . '</p>';
        }
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'bicho-error-tracking'));
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Bicho', 'bicho-error-tracking') . '</h1>';
        settings_errors('bicho_settings');
        echo '<form action="options.php" method="post">';
        settings_fields('bicho_settings');
        do_settings_sections('bicho-error-tracking');
        submit_button();
        echo '</form></div>';
    }
}
