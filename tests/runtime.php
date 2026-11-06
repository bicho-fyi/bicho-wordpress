<?php
declare(strict_types=1);

namespace BichoWordPress\SDK {
    final class Client
    {
        public static array $payloads = [];
        public static ?Config $config = null;
        public static mixed $duringSend = null;
        public static bool $fail = false;

        public function __construct(Config $config)
        {
            self::$config = $config;
        }

        public function send(Event $event): string
        {
            self::$payloads[] = json_decode($event->payload(self::$config), true, 512, JSON_THROW_ON_ERROR);
            if (self::$duringSend !== null) {
                (self::$duringSend)();
            }
            if (self::$fail) {
                throw new \RuntimeException('private delivery detail');
            }
            return $event->uid();
        }
    }
}

namespace BichoWordPress {
    final class Config
    {
        public static array $value;
        public static function get(): array { return self::$value; }
    }
}

namespace {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    use BichoWordPress\Config;
    use BichoWordPress\Runtime;
    use BichoWordPress\SDK\Client;

    function check(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    }

    $sdk = getenv('BICHO_TEST_SDK_DIR') ?: dirname(__DIR__) . '/dist/vendor/bicho-php';
    spl_autoload_register(static function (string $class) use ($sdk): void {
        $prefix = 'BichoWordPress\\SDK\\';
        if (str_starts_with($class, 'Bicho\\')) {
            require_once $sdk . '/' . substr($class, strlen('Bicho\\')) . '.php';
            return;
        }
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $name = substr($class, strlen($prefix));
        $path = $sdk . '/' . $name . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Build the bundled PHP SDK first, or set BICHO_TEST_SDK_DIR to the PHP SDK src directory.');
        }
        require_once $path;
        if (!class_exists($class, false) && class_exists('Bicho\\' . $name)) {
            class_alias('Bicho\\' . $name, $class);
        }
    });

    $mode = $argv[1] ?? '';
    if ($mode === '') {
        foreach (['disabled', 'browser', 'admin', 'invalid-php', 'invalid-js', 'invalid-key', 'invalid-loopback', 'capture', 'capture-debug', 'chain', 'chain-null', 'chain-throw', 'limit', 'recursion', 'delivery-failure', 'fatal', 'user-fatal', 'masked-fatal'] as $case) {
            $process = proc_open([PHP_BINARY, __FILE__, $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            check(is_resource($process), 'Could not start PHP test');
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if (in_array($case, ['fatal', 'user-fatal', 'masked-fatal'], true)) {
                check($status !== 0 && str_contains($out, 'shutdown-ok'), $case . ': native fatal semantics or capture failed: ' . $out . $err);
            } else {
                check($status === 0 && str_contains($out, 'ok'), $case . ': ' . $out . $err);
            }
            print($case . ": ok\n");
        }
        exit;
    }

    define('ABSPATH', __DIR__ . '/');
    $fixture = tempnam(getenv('TMPDIR') ?: sys_get_temp_dir(), 'bicho-runtime-');
    check($fixture !== false, 'Could not create asset fixture');
    unlink($fixture);
    mkdir($fixture . '/dist', 0700, true);
    file_put_contents($fixture . '/dist/bicho.min.js', '');
    register_shutdown_function(static function () use ($fixture): void {
        unlink($fixture . '/dist/bicho.min.js');
        rmdir($fixture . '/dist');
        rmdir($fixture);
    });
    define('BICHO_WORDPRESS_DIR', $fixture . '/');
    define('BICHO_WORDPRESS_URL', 'https://wordpress.example/plugins/bicho/');
    define('BICHO_WORDPRESS_VERSION', '1.0.0');
    $GLOBALS['wp_version'] = '6.9';
    $hooks = $scripts = $inline = [];
    function add_action(string $hook, callable $callback): void { $GLOBALS['hooks'][$hook][] = $callback; }
    function is_admin(): bool { return $GLOBALS['mode'] === 'admin'; }
    function wp_enqueue_script(...$args): void { $GLOBALS['scripts'][] = $args; }
    function wp_add_inline_script(...$args): void { $GLOBALS['inline'][] = $args; }
    function wp_json_encode(mixed $data, int $flags): string|false { return json_encode($data, $flags); }
    require dirname(__DIR__) . '/includes/runtime.php';

    Config::$value = [
        'php_enabled' => !in_array($mode, ['disabled', 'browser', 'admin', 'invalid-js', 'invalid-key', 'invalid-loopback'], true),
        'js_enabled' => in_array($mode, ['browser', 'admin', 'invalid-js', 'invalid-key', 'invalid-loopback'], true),
        'php_endpoint' => $mode === 'invalid-php' ? 'not-an-endpoint' : 'https://collector.example/v1/logs',
        'php_key' => 'backend-key', 'php_secret' => 'NEVER-IN-BROWSER',
        'js_endpoint' => $mode === 'invalid-js' ? 'https://collector.example/nope' : ($mode === 'invalid-loopback' ? 'http://127.2.3.4/v1/logs' : 'https://browser.example/v1/logs'),
        'js_key' => $mode === 'invalid-key' ? 'bad key' : 'browser-key',
        'debug' => $mode === 'capture-debug', 'environment' => 'testing', 'release' => '</script><script>alert(1)</script>',
    ];
    $chained = 0;
    if (str_starts_with($mode, 'chain')) {
        set_error_handler(static function () use (&$chained, $mode): mixed {
            $chained++;
            if ($mode === 'chain-throw') { throw new LogicException('original handler'); }
            return $mode === 'chain-null' ? null : true;
        });
    }
    Runtime::boot();
    Runtime::boot();
    check(count($hooks['wp_enqueue_scripts']) === 1, 'Boot must be idempotent');
    $runtime = $hooks['wp_enqueue_scripts'][0][0];
    $runtime->enqueue();
    if (in_array($mode, ['browser', 'admin', 'invalid-js', 'invalid-key', 'invalid-loopback'], true)) {
        if ($mode === 'browser') {
            check(count($scripts) === 1, 'Browser must enqueue independently of PHP');
            check($scripts[0] === ['bicho-wordpress', BICHO_WORDPRESS_URL . 'dist/bicho.min.js', [], '1.0.0', false], 'Expected local head script');
            check($inline[0][2] === 'after' && str_starts_with($inline[0][1], 'window.Bicho.init('), 'Expected actual global SDK init');
            check(!str_contains($inline[0][1], '</script>') && !str_contains($inline[0][1], 'NEVER-IN-BROWSER') && !str_contains($inline[0][1], 'backend-key'), 'Unsafe browser config');
            $options = json_decode(substr($inline[0][1], strlen('window.Bicho.init('), -2), true, 512, JSON_THROW_ON_ERROR);
            check($options === ['endpoint' => Config::$value['js_endpoint'], 'key' => 'browser-key', 'serviceName' => 'wordpress/browser', 'environment' => 'testing', 'release' => Config::$value['release']], 'Wrong public options');
        } else {
            check($scripts === [] && $inline === [], 'Invalid/admin browser script must not enqueue');
        }
        check(Client::$config === null, 'Browser must not enable PHP');
    } elseif (in_array($mode, ['disabled', 'invalid-php'], true)) {
        check(Client::$config === null, 'Disabled or invalid PHP must not create a client');
        check($runtime->handleError(E_USER_WARNING, 'ignored', __FILE__, 1) === false, 'Preserve default handling');
        check(Client::$payloads === [], 'Disabled PHP must not report');
    } else {
        check(Client::$config->timeout === 1.0 && Client::$config->serviceName === 'wordpress/php', 'Bounded real SDK config');
        error_reporting(E_ALL);
        if (in_array($mode, ['fatal', 'user-fatal', 'masked-fatal'], true)) {
            register_shutdown_function(static function () use ($mode): void {
                $expected = $mode === 'masked-fatal' ? 0 : 1;
                check(count(Client::$payloads) === $expected, 'Fatal must report exactly once, unless masked');
                print("shutdown-ok\n");
            });
            if ($mode === 'user-fatal') { trigger_error('fatal fixture', E_USER_ERROR); }
            if ($mode === 'masked-fatal') { error_reporting(0); }
            undefined_bicho_test_function();
        } elseif ($mode === 'chain-throw') {
            try { $runtime->handleError(E_USER_WARNING, 'warning', __FILE__, 10); check(false, 'Previous exception lost'); }
            catch (LogicException $e) { check($e->getMessage() === 'original handler', 'Previous exception changed'); }
        } elseif ($mode === 'chain' || $mode === 'chain-null') {
            check($runtime->handleError(E_USER_WARNING, 'warning', __FILE__, 10) === ($mode === 'chain' ? true : null), 'Previous return changed');
            @$runtime->handleError(E_USER_WARNING, 'suppressed', __FILE__, 11);
            check($chained === 2 && count(Client::$payloads) === 1, 'Suppression must preserve handler chaining');
            trigger_error('installed warning', E_USER_WARNING);
            @trigger_error('installed suppressed warning', E_USER_WARNING);
            check($chained === 4 && count(Client::$payloads) === 2, 'Installed hook must chain and respect suppression');
        } elseif ($mode === 'limit') {
            for ($i = 0; $i < 8; $i++) { $runtime->handleError(E_WARNING, 'warning', __FILE__, $i); }
            check(count(Client::$payloads) === 5, 'Five reports maximum');
        } elseif ($mode === 'recursion') {
            Client::$duringSend = static fn () => $runtime->handleError(E_WARNING, 'recursive', __FILE__, 8);
            $runtime->handleError(E_WARNING, 'outer', __FILE__, 7);
            check(count(Client::$payloads) === 1, 'Recursive report');
        } elseif ($mode === 'delivery-failure') {
            Client::$fail = true;
            check($runtime->handleError(E_WARNING, 'original', __FILE__, 5) === false, 'Delivery changed original behavior');
            $runtime->handleError(E_WARNING, 'second', __FILE__, 6);
            check(count(Client::$payloads) === 2, 'Guard not released after failure');
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $_REQUEST['private'] = $_COOKIE['private'] = 'DO-NOT-CAPTURE';
            $runtime->handleError(E_NOTICE, 'notice', __FILE__, 1);
            $runtime->handleError(E_DEPRECATED, 'deprecated', __FILE__, 2);
            $runtime->handleError(E_USER_DEPRECATED, 'deprecated', __FILE__, 2);
            @$runtime->handleError(E_WARNING, 'suppressed', __FILE__, 3);
            error_reporting(E_ERROR);
            $runtime->handleError(E_WARNING, 'masked', __FILE__, 4);
            error_reporting(E_ALL);
            check(Client::$payloads === [], 'Notice, deprecation or suppressed report');
            check($runtime->handleError(E_WARNING, 'actual warning', '/plugins/example.php', 42) === false, 'Default handler changed');
            check(count(Client::$payloads) === 1, 'Missing warning');
            $record = Client::$payloads[0]['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
            $attrs = array_column($record['attributes'], 'value', 'key');
            check($attrs['exception.type']['stringValue'] === 'ErrorException', 'Missing exception type');
            check($attrs['exception.message']['stringValue'] === 'actual warning', 'Wrong exception message');
            check($attrs['exception.stacktrace']['stringValue'] === '#0 /plugins/example.php(42): PHP error', 'Wrong original location');
            check($attrs['wordpress.version']['stringValue'] === '6.9' && $attrs['plugin.version']['stringValue'] === '1.0.0', 'Missing safe tags');
            check(!str_contains(json_encode(Client::$payloads), 'DO-NOT-CAPTURE') && !isset($attrs['bicho.contexts']), 'Request data captured');
        }
    }
    print("ok\n");
}
