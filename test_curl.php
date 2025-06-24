<?php
header('Content-Type: application/json');
echo json_encode([
    'curl_enabled' => function_exists('curl_init'),
    'loaded_extensions' => get_loaded_extensions()
]);
