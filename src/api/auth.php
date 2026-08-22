<?php

declare(strict_types=1);

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$validKey = $_ENV['API_KEY'] ?? '';

if ($validKey === '' || !hash_equals($validKey, $apiKey)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "No autorizado"]);
    exit();
}
