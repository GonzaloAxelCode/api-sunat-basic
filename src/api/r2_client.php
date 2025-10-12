<?php

use Aws\S3\S3Client;

require __DIR__ . '/../../vendor/autoload.php';

function r2Client(): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region'  => 'auto',

        'endpoint' => 'https://b6a94d41168d5138e642c8ee64103a44.r2.cloudflarestorage.com', // ✅ Tu endpoint R2
        'credentials' => [
            'key'    => '958a456a17ce4c33a5a430b3f4789dd7',
            'secret' => '82c2b02d7244fc847a4cc96339b167ae3e6a6bb2769c3c9491bef807ab92183e',
        ],
    ]);
}


const R2_BUCKET = 'axelmovilcomprobantes';

const R2_BASE_URL = 'https://pub-6b79c76579594222bdd6f486ae49157e.r2.dev';
