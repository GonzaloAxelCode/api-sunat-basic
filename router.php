<?php
// Router para servidor PHP integrado
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Servir archivos estáticos directamente
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    // Detectar el tipo MIME correcto
    $extension = pathinfo($uri, PATHINFO_EXTENSION);

    $mimeTypes = [
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'json' => 'application/json',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];

    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

    header('Content-Type: ' . $mimeType);
    readfile(__DIR__ . $uri);
    return true;
}

// Si no es un archivo estático, dejar que PHP lo maneje normalmente
return false;
