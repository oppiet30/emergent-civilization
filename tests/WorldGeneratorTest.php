<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Simulation\WorldGenerator;

$gen = new WorldGenerator();
$a = $gen->generate(1337);
$b = $gen->generate(1337);
$c = $gen->generate(1338);
assert(count($a) === 1024);
if (count($a) !== 1024 || $a !== $b || $a === $c) {
    fwrite(STDERR, "World generation test failed\n");
    exit(1);
}
echo "PASS: 1024 tiles and deterministic seeds\n";
