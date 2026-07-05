<?php
spl_autoload_register(function (string $class): void {
    $base = dirname(__DIR__); // modules/chat/app
    $dirs = [
        $base . '/helpers/',
        $base . '/models/',
        $base . '/controllers/',
    ];
    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
