<?php
/**
 * Schlanker PSR-4-Autoloader für den Namensraum HugoCMS\FileManager.
 * Ohne Composer nutzbar; einfach in der eigenen index.php inkludieren.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'HugoCMS\\FileManager\\';
    $baseDir = __DIR__ . '/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
