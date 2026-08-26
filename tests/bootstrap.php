<?php

declare(strict_types=1);

// Mirrors bu/quickbase-bundle/tests/bootstrap.php: the mono root autoloader does
// not map every bundle namespace, so map this package (and the sibling bundles it
// depends on) explicitly. Works whether the package has its own vendor/ or not.
$packageRoot = dirname(__DIR__);
$monoRoot = dirname($packageRoot, 2);
$autoload = $packageRoot.'/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = $monoRoot.'/vendor/autoload.php';
}
require $autoload;

spl_autoload_register(static function (string $class) use ($packageRoot, $monoRoot): void {
    $prefixes = [
        'Survos\\GristBundle\\Tests\\' => $packageRoot.'/tests/',
        'Survos\\GristBundle\\' => $packageRoot.'/src/',
        'Survos\\RecordStoreBundle\\' => $monoRoot.'/bu/record-store-bundle/src/',
        'Survos\\Kit\\' => $monoRoot.'/bu/kit-bundle/src/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
