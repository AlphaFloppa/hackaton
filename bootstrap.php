<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mesh\\Routing\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/D', $name) === 1) {
        $file = __DIR__ . '/src/' . $name . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
