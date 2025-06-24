<?php
header('Content-Type: application/json');

$extensions = get_loaded_extensions();
$pdoPgsqlLoaded = extension_loaded('pdo_pgsql');

echo json_encode([
    'loaded_extensions' => $extensions,
    'pdo_pgsql_loaded' => $pdoPgsqlLoaded,
    'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : []
]);
