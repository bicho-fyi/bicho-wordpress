<?php

declare(strict_types=1);

namespace BichoWordPress;

if (!defined('ABSPATH')) {
    exit;
}

final class Config
{
    public static function defaults(): array
    {
        return [
            'php_enabled' => false, 'js_enabled' => false,
            'php_endpoint' => '', 'php_key' => '', 'php_secret' => '',
            'js_endpoint' => '', 'js_key' => '', 'debug' => false,
            'environment' => 'production', 'release' => '',
        ];
    }

    public static function constants(): array
    {
        return [
            'php_enabled' => 'BICHO_PHP_ENABLED', 'js_enabled' => 'BICHO_JS_ENABLED',
            'php_endpoint' => 'BICHO_PHP_ENDPOINT', 'php_key' => 'BICHO_PHP_PUBLIC_KEY',
            'php_secret' => 'BICHO_PHP_SECRET', 'js_endpoint' => 'BICHO_JS_ENDPOINT',
            'js_key' => 'BICHO_JS_PUBLIC_KEY', 'debug' => 'BICHO_DEBUG',
            'environment' => 'BICHO_ENVIRONMENT', 'release' => 'BICHO_RELEASE',
        ];
    }

    public static function source(string $field): string
    {
        $name = self::constants()[$field] ?? null;
        if ($name !== null && defined($name)) {
            return 'constant';
        }
        return $name !== null && getenv($name) !== false ? 'environment' : 'dashboard';
    }

    public static function get(): array
    {
        $saved = get_option('bicho_settings', []);
        $saved = is_array($saved) ? $saved : [];
        $out = self::defaults();
        $invalid = [];
        foreach (self::constants() as $field => $name) {
            $source = self::source($field);
            $raw = match ($source) {
                'constant' => constant($name),
                'environment' => getenv($name),
                default => $saved[$field] ?? $out[$field],
            };
            $value = self::validated($field, $raw);
            if ($value === null) {
                $invalid[explode('_', $field, 2)[0]] = true;
            }
            $out[$field] = $value ?? $out[$field];
        }
        foreach (['php', 'js'] as $channel) {
            if ($out[$channel . '_endpoint'] === '' || $out[$channel . '_key'] === '' || isset($invalid[$channel])) {
                $out[$channel . '_enabled'] = false;
            }
        }
        return $out;
    }

    public static function sanitize($input): array
    {
        $saved = get_option('bicho_settings', []);
        $saved = is_array($saved) ? $saved : [];
        $out = self::defaults();
        foreach ($out as $field => $default) {
            $out[$field] = self::validated($field, $saved[$field] ?? $default) ?? $default;
        }
        if (!is_array($input)) {
            self::error('settings');
            return $out;
        }
        foreach ($out as $field => $old) {
            if (self::source($field) !== 'dashboard') {
                continue;
            }
            if ($field === 'php_secret') {
                if (self::validated('debug', $input['php_secret_clear'] ?? false) === true) {
                    $out[$field] = '';
                    continue;
                }
                if (($input[$field] ?? '') === '') {
                    continue;
                }
            }
            $raw = $input[$field] ?? (is_bool(self::defaults()[$field]) ? false : $old);
            $value = self::validated($field, $raw);
            if ($value === null) {
                self::error($field);
            } else {
                $out[$field] = $value;
            }
        }
        return $out;
    }

    private static function validated(string $field, mixed $value): bool|string|null
    {
        if (is_bool(self::defaults()[$field])) {
            if (is_bool($value)) {
                return $value;
            }
            if (!is_string($value) && !is_int($value)) {
                return null;
            }
            return match (strtolower((string) $value)) {
                '1', 'true', 'yes', 'on' => true,
                '', '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }
        if (!is_string($value)) {
            return null;
        }
        if (str_ends_with($field, '_endpoint')) {
            if ($value === '') {
                return '';
            }
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                return null;
            }
            $url = wp_parse_url($value);
            if ($url === false || ($url['path'] ?? '') !== '/v1/logs' ||
                isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                return null;
            }
            $scheme = strtolower($url['scheme'] ?? '');
            $loopback = in_array(strtolower($url['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
            return $scheme === 'https' || ($scheme === 'http' && $loopback) ? $value : null;
        }
        if ($field === 'js_key') {
            return $value === '' || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) === 1 ? $value : null;
        }
        if (in_array($field, ['php_key', 'php_secret'], true)) {
            return $value === '' || preg_match('/\A[\x21-\x7e]{1,512}\z/D', $value) === 1 ? $value : null;
        }
        return strlen($value) <= 200 && !preg_match('/[\x00-\x1f\x7f<>]/', $value) ? $value : null;
    }

    private static function error(string $field): void
    {
        add_settings_error('bicho_settings', 'bicho_invalid_' . $field,
            __('Invalid Bicho setting. The previous safe value was kept.', 'bicho-error-tracking'), 'error');
    }
}
