<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/composer/InstalledVersions.php';

function scopeSdk(string $source): string
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    $changed = 0;
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_NAMESPACE) {
            continue;
        }
        $name = $i + 1;
        while (is_array($tokens[$name]) && $tokens[$name][0] === T_WHITESPACE) {
            $name++;
        }
        $end = $name + 1;
        while (is_array($tokens[$end]) && $tokens[$end][0] === T_WHITESPACE) {
            $end++;
        }
        if (!is_array($tokens[$name]) || $tokens[$name][1] !== 'Bicho' || $tokens[$end] !== ';') {
            throw new RuntimeException('Unexpected SDK namespace');
        }
        $tokens[$name][1] = 'BichoWordPress\\SDK';
        $changed++;
    }
    if ($changed !== 1) {
        throw new RuntimeException('Expected exactly one SDK namespace');
    }
    return implode('', array_map(static fn ($token) => is_array($token) ? $token[1] : $token, $tokens));
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

try {
    $phpVersion = Composer\InstalledVersions::getPrettyVersion('bicho-fyi/php');
    $jsDir = $root . '/node_modules/@bicho-fyi/javascript';
    $js = json_decode(file_get_contents($jsDir . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($phpVersion !== '1.0.0' || $js['version'] !== '1.1.0') {
        throw new RuntimeException('Expected installed PHP SDK 1.0.0 and JavaScript SDK 1.1.0');
    }
    $probe = "<?php namespace Bicho; \$value = 'namespace Bicho;';";
    if (scopeSdk($probe) !== "<?php namespace BichoWordPress\\SDK; \$value = 'namespace Bicho;';") {
        throw new RuntimeException('Namespace scoping check failed');
    }
    $stage = $root . '/build/sdk-stage';
    removeTree($stage);
    mkdir($stage . '/vendor/bicho-php', 0755, true);
    foreach (glob($jsDir . '/dist/*') as $source) {
        if (!is_file($source) || is_link($source) || !preg_match('/\.(?:js|map|ts)$/D', $source)) {
            throw new RuntimeException('Unexpected JavaScript distribution file: ' . basename($source));
        }
        if (!copy($source, $stage . '/' . basename($source))) {
            throw new RuntimeException('Cannot copy JavaScript SDK');
        }
    }
    foreach (['bicho.min.js', 'browser.js', 'index.js', 'browser.d.ts', 'index.d.ts'] as $required) {
        if (!is_file($stage . '/' . $required)) {
            throw new RuntimeException('Missing JavaScript artifact: ' . $required);
        }
    }
    $phpDir = Composer\InstalledVersions::getInstallPath('bicho-fyi/php');
    $sources = glob($phpDir . '/src/*.php');
    if (count($sources) !== 5) {
        throw new RuntimeException('Expected five PHP SDK source files');
    }
    foreach ($sources as $source) {
        $target = $stage . '/vendor/bicho-php/' . basename($source);
        $scoped = scopeSdk(file_get_contents($source));
        file_put_contents($target, "<?php\n/* Namespace scoped for Bicho for WordPress; original SDK behavior is unchanged. */\n" . substr($scoped, 5));
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($target), $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Scoped SDK syntax check failed');
        }
    }
    foreach (['PHP' => $phpDir, 'JS' => $jsDir] as $prefix => $dir) {
        foreach (['LICENSE' => 'LICENSE', 'ACKNOWLEDGEMENTS.md' => 'ACKNOWLEDGEMENTS'] as $source => $target) {
            if (!copy($dir . '/' . $source, $stage . '/' . $prefix . '-' . $target)) {
                throw new RuntimeException('Cannot copy SDK license or acknowledgements');
            }
        }
    }
    file_put_contents($stage . '/manifest.json', json_encode([
        'php' => ['package' => 'bicho-fyi/php', 'version' => $phpVersion, 'source' => 'https://github.com/bicho-fyi/bicho-php/tree/v1.0.0', 'namespace' => 'BichoWordPress\\SDK'],
        'javascript' => ['package' => '@bicho-fyi/javascript', 'version' => $js['version'], 'source' => 'https://github.com/bicho-fyi/bicho-javascript/tree/v1.1.0'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    removeTree($root . '/build/sdk-previous');
    if (is_dir($root . '/dist') && !rename($root . '/dist', $root . '/build/sdk-previous')) {
        throw new RuntimeException('Cannot preserve previous dist');
    }
    if (!rename($stage, $root . '/dist')) {
        if (is_dir($root . '/build/sdk-previous')) {
            rename($root . '/build/sdk-previous', $root . '/dist');
        }
        throw new RuntimeException('Cannot replace dist');
    }
    removeTree($root . '/build/sdk-previous');
    fwrite(STDOUT, "Bundled PHP SDK 1.0.0 (scoped) and JavaScript SDK 1.1.0.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
