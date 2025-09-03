<?php
// Prova entrambe le possibili vendor
$autoloads = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/libs/vendor/autoload.php',
];

$loaded = false;
foreach ($autoloads as $a) {
    if (is_file($a)) {
        require_once $a;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die('Autoloader Composer non trovato (né vendor/autoload.php né libs/vendor/autoload.php).');
}

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!class_exists(IOFactory::class)) {
    // Debug utile: stampa dove punta il namespace PhpSpreadsheet
    $mapFile = __DIR__ . '/vendor/composer/autoload_psr4.php';
    $mapFile2 = __DIR__ . '/libs/vendor/composer/autoload_psr4.php';
    $hint = '';
    if (is_file($mapFile)) {
        $psr4 = include $mapFile;
        $hint = ' (vendor) mapping: ' . (isset($psr4['PhpOffice\\PhpSpreadsheet\\']) ? implode(',', (array)$psr4['PhpOffice\\PhpSpreadsheet\\']) : 'nessuno');
    } elseif (is_file($mapFile2)) {
        $psr4 = include $mapFile2;
        $hint = ' (libs/vendor) mapping: ' . (isset($psr4['PhpOffice\\PhpSpreadsheet\\']) ? implode(',', (array)$psr4['PhpOffice\\PhpSpreadsheet\\']) : 'nessuno');
    }
    die('Classe IOFactory non trovata.'.$hint);
}

// Se arrivi qui, la classe c’è
echo "OK: PhpSpreadsheet è caricato.";
