<?php

use Aws\S3\S3Client;
use Dotenv\Dotenv;

require __DIR__ . '/../../vendor/autoload.php';

$envPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($envPath)) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();
}

function r2Client(): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region'  => 'auto',
        'endpoint' => $_ENV['R2_ENDPOINT'],
        'credentials' => [
            'key'    => $_ENV['R2_KEY'],
            'secret' => $_ENV['R2_SECRET'],
        ],
    ]);
}

$r2_bucket = $_ENV['R2_BUCKET'];
$r2_base_url = $_ENV['R2_BASE_URL'];
