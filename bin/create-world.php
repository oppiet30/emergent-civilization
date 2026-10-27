<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;
use App\Simulation\WorldGenerator;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/create-world.php <name> <seed>\n");
    exit(2);
}
$name = trim($argv[1]);
if ($name === '' || strlen($name) > 120 || !ctype_digit($argv[2])) {
    fwrite(STDERR, "Name must be 1-120 bytes; seed must be a nonnegative integer.\n");
    exit(2);
}
$seed = filter_var($argv[2], FILTER_VALIDATE_INT);
if ($seed === false || $seed < 0 || $seed > 2147483647) {
    fwrite(STDERR, "Seed must be between 0 and 2147483647.\n");
    exit(2);
}
$db = Database::connect();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO worlds (name, seed, rng_state) VALUES (?, ?, ?)');
    $stmt->execute([$name, $seed, $seed]);
    $worldId = (int) $db->lastInsertId();
    $stmt = $db->prepare('INSERT INTO world_tiles (world_id,x,y,terrain,movement_cost) VALUES (?,?,?,?,?)');
    foreach ((new WorldGenerator())->generate($seed) as $tile) {
        $stmt->execute([$worldId, $tile['x'], $tile['y'], $tile['terrain'], $tile['terrain'] === 'mountain' ? 3 : 1]);
    }
    $db->commit();
    echo "Created world #$worldId ($name) with 1024 tiles.\n";
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
