<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;
use App\Simulation\DeterministicRandom;

$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    fwrite(STDERR, "Usage: php bin/seed-wildlife.php <world-id>\n");
    exit(2);
}
$db = Database::connect();
$db->beginTransaction();
try {
    $q = $db->prepare('SELECT seed, current_tick FROM worlds WHERE id=? FOR UPDATE');
    $q->execute([$id]);
    $world = $q->fetch(PDO::FETCH_ASSOC);
    if (!$world) { throw new RuntimeException('World not found'); }
    if ((int)$world['current_tick'] !== 0) {
        throw new RuntimeException('Seed wildlife before the first simulation tick');
    }
    $q = $db->prepare('SELECT COUNT(*) FROM wildlife_populations WHERE world_id=?');
    $q->execute([$id]);
    if ((int)$q->fetchColumn() !== 0) {
        throw new RuntimeException('Wildlife already seeded');
    }
    $rng = new DeterministicRandom((int)$world['seed'] ^ 0x13579);
    $q = $db->prepare("SELECT id, terrain FROM world_tiles WHERE world_id=? AND terrain IN ('grass','forest') ORDER BY id");
    $q->execute([$id]);
    $insert = $db->prepare('INSERT INTO wildlife_populations(world_id,tile_id,species,population,capacity) VALUES (?,?,?,?,?)');
    $count = 0;
    foreach ($q->fetchAll() as $tile) {
        foreach (['deer','rabbit'] as $species) {
            $capacity = $species === 'deer'
                ? ($tile['terrain'] === 'forest' ? 8 : 4)
                : ($tile['terrain'] === 'grass' ? 18 : 10);
            $population = 1 + $rng->nextInt($capacity);
            $insert->execute([$id,$tile['id'],$species,$population,$capacity]);
            $count++;
        }
    }
    $db->commit();
    echo "Seeded $count wildlife populations.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    throw $e;
}
