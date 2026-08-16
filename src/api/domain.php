<?php

$domain = "https://inventarioaxel.wuaze.com/api-sunat-basic";

function isLocal(): bool {
    return str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost')
        || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');
}
