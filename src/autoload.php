<?php

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Jelite\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Jelite\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
