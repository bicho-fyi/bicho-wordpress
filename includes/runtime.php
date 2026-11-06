<?php
declare(strict_types=1);

namespace BichoWordPress;

use BichoWordPress\SDK\Client;
use BichoWordPress\SDK\Config as SDKConfig;
use BichoWordPress\SDK\Event;

if (!defined('ABSPATH')) {
    exit;
}

final class Runtime
{
    private static ?self $instance = null;
    private ?Client $client = null;
    private mixed $previous = null;
    private bool $reporting = false;
    private int $reports = 0;
    private ?array $lastFatal = null;

    private function __construct(private readonly array $config)
    {
    }

    public static function boot(): void
    {
        if (self::$instance !== null) {
            return;
        }
        $runtime = self::$instance = new self(Config::get());
        add_action('wp_enqueue_scripts', [$runtime, 'enqueue']);
        if (!$runtime->config['php_enabled']) {
            return;
        }
        try {
            $runtime->client = new Client(new SDKConfig(
                endpoint: $runtime->config['php_endpoint'],
                key: $runtime->config['php_key'],
                serviceName: 'wordpress/php',
                environment: $runtime->config['environment'],
                release: $runtime->config['release'] ?: null,
                secret: $runtime->config['php_secret'] ?: null,
                timeout: 1.0,
            ));
            $runtime->previous = set_error_handler([$runtime, 'handleError']);
            register_shutdown_function([$runtime, 'shutdown']);
        } catch (\Throwable) {
            $runtime->diagnostic();
        }
    }

    public function handleError(int $severity, string $message, string $file, int $line): mixed
    {
        if ((error_reporting() & $severity) !== 0
            && ($severity & (E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR | E_USER_ERROR)) !== 0) {
            if ($severity === E_USER_ERROR) {
                $this->lastFatal = [$severity, $message, $file, $line];
            }
            $this->report($severity, $message, $file, $line);
        }
        return $this->previous !== null ? ($this->previous)($severity, $message, $file, $line) : false;
    }

    public function shutdown(): void
    {
        $error = error_get_last();
        if ($error === null || ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) === 0
            || (error_reporting() & $error['type']) === 0
            || $this->lastFatal === [$error['type'], $error['message'], $error['file'], $error['line']]) {
            return;
        }
        $this->lastFatal = [$error['type'], $error['message'], $error['file'], $error['line']];
        $this->report($error['type'], $error['message'], $error['file'], $error['line']);
    }

    private function report(int $severity, string $message, string $file, int $line): void
    {
        if ($this->client === null || $this->reporting || $this->reports >= 5) {
            return;
        }
        $this->reporting = true;
        $this->reports++;
        try {
            $event = Event::exception(new \ErrorException($message, 0, $severity, $file, $line))
                ->withStacktrace('#0 ' . $file . '(' . $line . '): PHP error')
                ->withLevel(($severity & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) !== 0 ? 'fatal' : 'error')
                ->withTag('php.error_type', $severity)
                ->withTag('php.version', PHP_VERSION)
                ->withTag('wordpress.version', (string) ($GLOBALS['wp_version'] ?? ''))
                ->withTag('plugin.version', BICHO_WORDPRESS_VERSION);
            $this->client->send($event);
        } catch (\Throwable) {
            $this->diagnostic();
        } finally {
            $this->reporting = false;
        }
    }

    private function diagnostic(): void
    {
        if ($this->config['debug']) {
            try {
                error_log('Bicho WordPress: reporting unavailable.');
            } catch (\Throwable) {
            }
        }
    }

    public function enqueue(): void
    {
        if (!$this->config['js_enabled'] || is_admin() || !is_file(BICHO_WORDPRESS_DIR . 'dist/bicho.min.js')) {
            return;
        }
        try {
            new SDKConfig(
                endpoint: $this->config['js_endpoint'],
                key: $this->config['js_key'],
                serviceName: 'wordpress/browser',
                environment: $this->config['environment'],
                release: $this->config['release'] ?: null,
            );
            if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $this->config['js_key'])) {
                return;
            }
            $host = wp_parse_url($this->config['js_endpoint'], PHP_URL_HOST);
            if (str_starts_with($this->config['js_endpoint'], 'http:') && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
                return;
            }
            $options = [
                'endpoint' => $this->config['js_endpoint'],
                'key' => $this->config['js_key'],
                'serviceName' => 'wordpress/browser',
                'environment' => $this->config['environment'],
                'release' => $this->config['release'],
            ];
            $json = wp_json_encode($options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if ($json === false) {
                return;
            }
            wp_enqueue_script('bicho-wordpress', BICHO_WORDPRESS_URL . 'dist/bicho.min.js', [], BICHO_WORDPRESS_VERSION, false);
            wp_add_inline_script('bicho-wordpress', 'window.Bicho.init(' . $json . ');', 'after');
        } catch (\Throwable) {
            $this->diagnostic();
        }
    }
}
