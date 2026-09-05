<?php
putenv('NODE_PATH=' . getenv('APPDATA') . '\\npm\\node_modules');
echo "NODE_PATH=" . getenv('NODE_PATH') . PHP_EOL;
echo exec('node -e "console.log(require.resolve(' . "'puppeteer'" . '))"');
