<?php
/** Fallback PSR-4 autoloader for CargoVelo\ when neither the root nor the package composer autoload is present. */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CargoVelo\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

require_once __DIR__ . '/src/Support/functions.php';
