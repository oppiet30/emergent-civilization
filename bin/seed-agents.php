<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Infrastructure\Database;
use App\Simulation\DeterministicRandom;

$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { fwrite(STDERR,"Usage: php bin/seed-agents.php <world-id>\n"); exit(2); }
$db = Database::connect();
$db->beginTransaction();
try {
    $q=$db->prepare('SELECT seed FROM worlds WHERE id=? FOR UPDATE');
    $q->execute([$id]); $seed=$q->fetchColumn();
    if ($seed === false) { throw new RuntimeException('World not found'); }
    $q=$db->prepare('SELECT COUNT(*) FROM agents WHERE world_id=?');
    $q->execute([$id]);
    if ((int)$q->fetchColumn() !== 0) { throw new RuntimeException('World already has agents'); }
    $rng=new DeterministicRandom((int)$seed);
    $q=$db->prepare("SELECT id,x,y,terrain FROM world_tiles WHERE world_id=? AND terrain IN ('grass','forest') ORDER BY id");
    $q->execute([$id]); $tiles=$q->fetchAll();
    if (!$tiles) { throw new RuntimeException('No habitable tiles'); }
    $ins=$db->prepare('INSERT INTO agents(world_id,name,x,y,food_inventory,sex,age_ticks) VALUES(?,?,?,?,?,?,?)');
    for ($i=1;$i<=30;$i++) {
        $tile=$tiles[$rng->nextInt(count($tiles))];
        $ins->execute([$id,sprintf('Settler %02d',$i),$tile['x'],$tile['y'],2,$i%2===0?'female':'male',7300]);
    }
    $res=$db->prepare('INSERT INTO tile_resources(world_id,tile_id,food,food_capacity) VALUES(?,?,?,?)');
    foreach ($tiles as $tile) {
        $capacity=$tile['terrain']==='forest'?30:15;
        $res->execute([$id,$tile['id'],$capacity,$capacity]);
    }
    $db->commit();
    echo "Seeded 30 agents and ".count($tiles)." resource tiles.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
