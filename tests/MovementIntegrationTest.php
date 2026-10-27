<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;
use App\Simulation\DeterministicRandom;
use App\Simulation\MovementSystem;
use App\Simulation\SurvivalEngine;

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$db = Database::connect();
$worlds = [];
function fixture(PDO $db, array &$worlds): int
{
    $db->exec("INSERT INTO worlds(name,seed,rng_state,status) VALUES('Movement test',1337,1337,'running')");
    $world = (int)$db->lastInsertId();
    $worlds[] = $world;
    $tile = $db->prepare('INSERT INTO world_tiles(world_id,x,y,terrain,movement_cost) VALUES(?,?,?,?,?)');
    $food = $db->prepare('INSERT INTO tile_resources(world_id,tile_id,food,food_capacity) VALUES(?,?,30,30)');
    foreach ([[0,0,'grass',1],[1,0,'forest',1],[0,1,'water',1],[1,1,'mountain',1],[4,0,'grass',1],[5,0,'forest',200]] as [$x,$y,$terrain,$cost]) {
        $tile->execute([$world,$x,$y,$terrain,$cost]);
        if ($terrain === 'grass' || $terrain === 'forest') {
            $food->execute([$world,(int)$db->lastInsertId()]);
        }
    }
    return $world;
}
function agent(PDO $db, int $world, string $name, int $x, string $sex, int $age=7300, int $energy=100, int $alive=1): void
{
    $q = $db->prepare('INSERT INTO agents(world_id,name,x,y,sex,age_ticks,energy,alive,health,food_inventory) VALUES(?,?,?,0,?,?,?,?,?,2)');
    $q->execute([$world,$name,$x,$sex,$age,$energy,$alive,$alive ? 100 : 0]);
}
function snapshot(PDO $db, int $world): array
{
    // IDs differ between fixtures; compare simulation state in insertion order.
    $q = $db->prepare('SELECT x,y,sex,age_ticks,hunger,energy,health,alive,food_inventory FROM agents WHERE world_id=? ORDER BY id');
    $q->execute([$world]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
try {
    $world = fixture($db,$worlds);
    agent($db,$world,'Explorer',0,'female');
    agent($db,$world,'Infant',0,'male',0);
    agent($db,$world,'Dead',1,'male',7300,100,0);
    agent($db,$world,'Isolated',4,'male');
    agent($db,$world,'Tired',0,'male',7300,19);
    $movement = new MovementSystem($db);
    $rng = new DeterministicRandom(1337);
    $previous = snapshot($db,$world);
    $moves = 0;
    $db->beginTransaction();
    for ($i=0;$i<100;$i++) {
        $moves += $movement->advance($world,$rng);
        $next = snapshot($db,$world);
        check(abs((int)$next[0]['x']-(int)$previous[0]['x']) <= 1 && (int)$next[0]['y'] === 0 && in_array((int)$next[0]['x'],[0,1],true), 'Movement crossed terrain or skipped a tile');
        check($next[1] === $previous[1], 'Infant moved');
        check($next[2] === $previous[2], 'Dead agent moved');
        check($next[3] === $previous[3], 'Agent entered an unaffordable or disconnected tile');
        check($next[4] === $previous[4], 'Exhausted agent moved');
        $previous = $next;
    }
    check($moves > 0 && (int)$previous[0]['energy'] < 100, 'Explorer did not move or spend energy');
    $db->commit();

    $a = fixture($db,$worlds);
    $b = fixture($db,$worlds);
    foreach ([$a,$b] as $world) {
        agent($db,$world,'Mother',0,'female');
        agent($db,$world,'Father',1,'male');
    }
    $engine = new SurvivalEngine($db);
    $moves = 0;
    $met = false;
    for ($tick=1;$tick<=1000;$tick++) {
        $before = snapshot($db,$a);
        $left = $engine->tick($a);
        $right = $engine->tick($b);
        check($left === $right && snapshot($db,$a) === snapshot($db,$b), 'Identical worlds diverged');
        $after = snapshot($db,$a);
        foreach ($before as $i=>$old) {
            check(abs((int)$after[$i]['x']-(int)$old['x']) + abs((int)$after[$i]['y']-(int)$old['y']) <= 1, 'Agent moved more than one tile per tick');
        }
        foreach ($after as $row) {
            check((int)$row['y'] === 0 && in_array((int)$row['x'],[0,1],true), 'Agent entered blocked or disconnected terrain');
        }
        $met = $met || $after[0]['x'] === $after[1]['x'];
        $moves += $left['moved'];
    }
    $q = $db->prepare('SELECT COUNT(*) FROM pregnancies WHERE world_id=?');
    $q->execute([$a]);
    check($moves > 0 && $met && (int)$q->fetchColumn() > 0, 'Separated founders never met and conceived');
    $q = $db->prepare('SELECT COUNT(*) FROM agents WHERE world_id=? AND mother_id IS NOT NULL AND father_id IS NOT NULL');
    $q->execute([$a]);
    check((int)$q->fetchColumn() > 0, 'No births with recorded parents');
    $q = $db->prepare('SELECT rng_state FROM worlds WHERE id=?');
    $q->execute([$a]); $state = $q->fetchColumn();
    $q->execute([$b]);
    check($state === $q->fetchColumn(), 'Saved random states diverged');
    echo "PASS: bounded movement, terrain and energy restrictions, deterministic replay, encounters, pregnancies and births\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    // Remove only the fixtures created by this invocation.
    foreach ($worlds as $world) {
        foreach ([
            'DELETE FROM pregnancies WHERE world_id=?',
            'DELETE FROM world_events WHERE world_id=?',
            'UPDATE agents SET mother_id=NULL,father_id=NULL WHERE world_id=?',
            'DELETE FROM agents WHERE world_id=?',
            'DELETE FROM worlds WHERE id=?',
        ] as $sql) {
            $db->prepare($sql)->execute([$world]);
        }
    }
}
