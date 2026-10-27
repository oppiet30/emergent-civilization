<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;
use App\Simulation\ChildcareSystem;
use App\Simulation\DeterministicRandom;
use App\Simulation\MovementSystem;
use App\Simulation\SurvivalEngine;

function expect(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
$db = Database::connect();
$worlds = [];
function careWorld(PDO $db, array &$worlds, bool $neighbors=false): int
{
    $db->exec("INSERT INTO worlds(name,seed,rng_state,status) VALUES('Childcare test',1337,1337,'running')");
    $id = (int)$db->lastInsertId();
    $worlds[] = $id;
    $q = $db->prepare("INSERT INTO world_tiles(world_id,x,y,terrain) VALUES(?,?,0,'grass')");
    $q->execute([$id,0]);
    if ($neighbors) { $q->execute([$id,1]); }
    return $id;
}
function person(PDO $db, int $world, string $name, int $age, int $food=0, int $hunger=0, int $health=100): int
{
    $q = $db->prepare("INSERT INTO agents(world_id,name,x,y,sex,age_ticks,food_inventory,hunger,health,alive) VALUES(?,?,0,0,'female',?,?,?,?,?)");
    $q->execute([$world,$name,$age,$food,$hunger,$health,$health>0 ? 1 : 0]);
    return (int)$db->lastInsertId();
}
function state(PDO $db, int $id): array
{
    $q = $db->prepare('SELECT * FROM agents WHERE id=?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
function parents(PDO $db, int $child, ?int $mother, ?int $father): void
{
    $db->prepare('UPDATE agents SET mother_id=?,father_id=? WHERE id=?')->execute([$mother,$father,$child]);
}
$engine = new SurvivalEngine($db);
try {
    // Child inserted first: adults must finish before sharing to avoid stale stock writes.
    $world = careWorld($db,$worlds,true);
    $child = person($db,$world,'Child',100,0,48);
    $mother = person($db,$world,'Mother',7300,4);
    $father = person($db,$world,'Father',7300,4);
    parents($db,$child,$mother,$father);
    $db->beginTransaction();
    $movement = new MovementSystem($db);
    $rng = new DeterministicRandom(1337);
    for ($i=0;$i<100;$i++) { $movement->advance($world,$rng); }
    expect((int)state($db,$mother)['x'] === 0 && (int)state($db,$child)['x'] === 0, 'Caregiver left a dependent behind');
    $db->rollBack();
    $result = $engine->tick($world);
    expect($result['feedings'] === 1 && (int)state($db,$child)['hunger'] === 6, 'Hungry child was not fed');
    expect((int)state($db,$mother)['food_inventory'] === 3, 'Mother donation lost or duplicated food');
    expect((int)state($db,$father)['food_inventory'] === 4, 'Father used before available mother');

    // Dead mother cannot feed; living father can.
    $world = careWorld($db,$worlds);
    $mother = person($db,$world,'Dead mother',7300,4,0,0);
    $father = person($db,$world,'Father',7300,4);
    $child = person($db,$world,'Child',0,0,48);
    parents($db,$child,$mother,$father);
    expect(isset((new ChildcareSystem($db))->caregiverIds($world)[$father]), 'Father did not take over care');
    $result = $engine->tick($world);
    expect($result['feedings'] === 1 && (int)state($db,$father)['food_inventory'] === 3, 'Father fallback failed');
    expect((int)state($db,$mother)['food_inventory'] === 4, 'Dead adult donated food');

    // Shared adult's one unit can feed only one child, in stable ID order.
    $world = careWorld($db,$worlds);
    $adult = person($db,$world,'Neighbor',7300,1);
    $first = person($db,$world,'First child',100,0,48);
    $second = person($db,$world,'Second child',100,0,48);
    $result = $engine->tick($world);
    expect($result['feedings'] === 1 && (int)state($db,$adult)['food_inventory'] === 0, 'Limited food was duplicated');
    expect((int)state($db,$first)['hunger'] === 6 && (int)state($db,$second)['hunger'] === 51, 'Sharing order or local non-parent care failed');

    // A distant parent and a parent in another world cannot donate.
    $other = careWorld($db,$worlds);
    $foreign = person($db,$other,'Foreign parent',7300,10);
    $world = careWorld($db,$worlds,true);
    $distant = person($db,$world,'Distant parent',7300,10);
    $db->prepare('UPDATE agents SET x=1,energy=0 WHERE id=?')->execute([$distant]);
    $child = person($db,$world,'Alone',100,0,48);
    parents($db,$child,$foreign,$distant);
    $result = $engine->tick($world);
    expect($result['feedings'] === 0 && (int)state($db,$child)['hunger'] === 51, 'Food crossed tile or world boundaries');

    // Abundant ground food cannot be gathered by a dependent; starvation logs once.
    $world = careWorld($db,$worlds);
    $db->prepare('INSERT INTO tile_resources(world_id,tile_id,food,food_capacity) SELECT world_id,id,30,30 FROM world_tiles WHERE world_id=?')->execute([$world]);
    $child = person($db,$world,'Unsupported',100,0,87,8);
    $result = $engine->tick($world);
    expect($result['child_deaths'] === 1 && $result['hunts'] === 0 && (int)state($db,$child)['alive'] === 0 && (int)state($db,$child)['food_inventory'] === 0, 'Unsupported child gathered, hunted or escaped starvation');
    $result = $engine->tick($world);
    $q = $db->prepare("SELECT COUNT(*) FROM world_events WHERE world_id=? AND event_type='death'");
    $q->execute([$world]);
    expect($result['child_deaths'] === 0 && (int)$q->fetchColumn() === 1, 'Child death counted repeatedly');

    // Becoming an adult releases the caregiver on the following tick.
    $world = careWorld($db,$worlds);
    $mother = person($db,$world,'Mother',7300,4);
    $child = person($db,$world,'Almost adult',6569,1);
    parents($db,$child,$mother,null);
    $care = new ChildcareSystem($db);
    expect(isset($care->caregiverIds($world)[$mother]), 'Care released too early');
    $engine->tick($world);
    expect((int)state($db,$child)['age_ticks'] === 6570 && $care->caregiverIds($world) === [], 'Adulthood did not release caregiver');

    // Parents return one tile per tick around blocked terrain; replay is identical.
    $replay = [];
    foreach ([0,1] as $copy) {
        $world = careWorld($db,$worlds);
        $q = $db->prepare('INSERT INTO world_tiles(world_id,x,y,terrain) VALUES(?,?,?,?)');
        foreach ([[1,0,'water'],[2,0,'grass'],[0,1,'forest'],[1,1,'grass'],[2,1,'grass'],[3,1,'mountain']] as [$x,$y,$terrain]) {
            $q->execute([$world,$x,$y,$terrain]);
        }
        $mother = person($db,$world,'Returning mother',7300,10);
        $child = person($db,$world,'Separated child',100,0,48);
        $db->prepare('UPDATE agents SET x=2 WHERE id=?')->execute([$child]);
        parents($db,$child,$mother,null);
        $replay[] = [$world,$mother,$child];
    }
    foreach ([[0,1],[1,1],[2,1],[2,0]] as [$x,$y]) {
        $results = [];
        $snapshots = [];
        foreach ($replay as [$world,$mother,$child]) {
            $results[] = $engine->tick($world);
            $p = state($db,$mother);
            expect((int)$p['x'] === $x && (int)$p['y'] === $y, 'Returning parent skipped a tile or crossed a barrier');
            $q = $db->prepare('SELECT x,y,energy,hunger,health,food_inventory,age_ticks FROM agents WHERE world_id=? ORDER BY id');
            $q->execute([$world]);
            $snapshots[] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        expect($results[0] === $results[1] && $snapshots[0] === $snapshots[1], 'Reunion replay diverged');
    }
    foreach ($replay as [$world,$mother,$child]) {
        expect((int)state($db,$child)['hunger'] === 15 && (int)state($db,$mother)['food_inventory'] === 9, 'Reunited parent did not feed child on arrival');
        $engine->tick($world);
        expect((int)state($db,$mother)['x'] === 2 && (int)state($db,$mother)['y'] === 0, 'Reunited caregiver wandered away');
    }

    // A parent caring for another child must stay; the other parent returns.
    $world = careWorld($db,$worlds,true);
    $db->prepare("INSERT INTO world_tiles(world_id,x,y,terrain) VALUES(?,2,0,'forest')")->execute([$world]);
    $mother = person($db,$world,'Busy mother',7300,10);
    $father = person($db,$world,'Free father',7300,10);
    $db->prepare('UPDATE agents SET x=1 WHERE id=?')->execute([$father]);
    $local = person($db,$world,'Local child',100);
    parents($db,$local,$mother,null);
    $remote = person($db,$world,'Remote child',100,0,48);
    $db->prepare('UPDATE agents SET x=2 WHERE id=?')->execute([$remote]);
    parents($db,$remote,$mother,$father);
    $engine->tick($world);
    expect((int)state($db,$mother)['x'] === 0 && (int)state($db,$father)['x'] === 2, 'Reunion abandoned an existing dependent or missed father fallback');
    expect((int)state($db,$remote)['hunger'] === 6, 'Returning father failed to feed child');

    // Unreachable mother falls back to a reachable father; siblings reserve one parent.
    $world = careWorld($db,$worlds,true);
    $q = $db->prepare("INSERT INTO world_tiles(world_id,x,y,terrain) VALUES(?,?,0,'grass')");
    $q->execute([$world,2]); $q->execute([$world,5]);
    $mother = person($db,$world,'Isolated mother',7300,10);
    $db->prepare('UPDATE agents SET x=5 WHERE id=?')->execute([$mother]);
    $father = person($db,$world,'Reachable father',7300,10);
    foreach (['Sibling one','Sibling two'] as $name) {
        $child = person($db,$world,$name,100);
        $db->prepare('UPDATE agents SET x=2 WHERE id=?')->execute([$child]);
        parents($db,$child,$mother,$father);
    }
    $tiles = [];
    $q = $db->prepare('SELECT x,y,movement_cost FROM world_tiles WHERE world_id=? ORDER BY y,x');
    $q->execute([$world]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $tile) { $tiles[$tile['x'].','.$tile['y']] = $tile; }
    $care = new ChildcareSystem($db);
    $steps = $care->reunionSteps($world,$tiles,$care->caregiverIds($world));
    expect(count($steps) === 1 && isset($steps[$father]) && (int)$steps[$father][0] === 1, 'Unreachable-parent fallback or sibling assignment failed');
    $db->prepare('UPDATE agents SET energy=0 WHERE id=?')->execute([$father]);
    expect($care->reunionSteps($world,$tiles,$care->caregiverIds($world)) === [], 'Exhausted parent was assigned a reunion step');

    echo "PASS: caregiver stays, parent preference, fallback care, food conservation, locality, childhood starvation, adulthood and deterministic parent reunion\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    // All references are removed before deleting these fixtures (including cross-world test parents).
    foreach ($worlds as $world) {
        $db->prepare('UPDATE agents SET mother_id=NULL,father_id=NULL WHERE world_id=?')->execute([$world]);
    }
    foreach ($worlds as $world) {
        foreach (['DELETE FROM pregnancies WHERE world_id=?','DELETE FROM world_events WHERE world_id=?','DELETE FROM agents WHERE world_id=?','DELETE FROM worlds WHERE id=?'] as $sql) {
            $db->prepare($sql)->execute([$world]);
        }
    }
}
