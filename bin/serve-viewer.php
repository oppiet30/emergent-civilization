<?php
declare(strict_types=1);
// Loopback-only development server. Database values stay in the environment.
$port = filter_var($argv[1] ?? '8080',FILTER_VALIDATE_INT,['options'=>['min_range'=>1024,'max_range'=>65535]]);
if ($port === false) { fwrite(STDERR,"Usage: php bin/serve-viewer.php [port=8080]\n"); exit(2); }
$root = dirname(__DIR__);
if (is_file($root.'/.env')) {
    foreach (file($root.'/.env',FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!preg_match('/^\s*(?:export\s+)?(DB_(?:HOST|PORT|NAME|USER|PASSWORD))\s*=\s*(.*)$/',$line,$match) || getenv($match[1]) !== false) { continue; }
        $value = trim($match[2]);
        if (strlen($value)>=2 && (($value[0] === "'" && substr($value,-1) === "'") || ($value[0] === '"' && substr($value,-1) === '"'))) { $value = substr($value,1,-1); }
        putenv($match[1].'='.$value);
    }
}
echo "Observer: http://127.0.0.1:$port (Ctrl+C to stop)\n";
passthru(escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$port.' -t '.escapeshellarg($root.'/public').' '.escapeshellarg($root.'/public/router.php'),$status);
exit($status);
