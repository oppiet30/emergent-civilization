<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;
use App\Simulation\AgingSystem;
use App\Simulation\DeterministicRandom;
use App\Simulation\SurvivalEngine;

function verify(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$db = Database::connect();
$worlds = [];
$report = null;
function agingWorld(PDO $db, array &$worlds): int
{
    $db->exec("INSERT INTO worlds(name,seed,rng_state,status) VALUES('Aging test',0,0,'running')");
    $id = (int)$db->lastInsertId();
    $worlds[] = $id;
    $db->prepare("INSERT INTO world_tiles(world_id,x,y,terrain) VALUES(?,0,0,'grass')")->execute([$id]);
    return $id;
}
function agingAgent(PDO $db, int $world, int $age, string $sex='female', int $food=10): int
{
    $q = $db->prepare("INSERT INTO agents(world_id,name,x,y,age_ticks,sex,food_inventory) VALUES(?,'Test agent',0,0,?,?,?)");
    $q->execute([$world,$age,$sex,$food]);
    return (int)$db->lastInsertId();
}
function agingSnapshot(PDO $db, int $world): array
{
    $q = $db->prepare('SELECT age_ticks,alive,health,hunger,energy,food_inventory,x,y FROM agents WHERE world_id=? ORDER BY id');
    $q->execute([$world]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function deathCount(PDO $db, int $world): int
{
    $q = $db->prepare("SELECT COUNT(*) FROM world_events WHERE world_id=? AND event_type='death'");
    $q->execute([$world]);
    return (int)$q->fetchColumn();
}
try {
    foreach ([[60,5],[70,15],[80,40],[90,100],[100,250],[110,100000]] as [$years,$risk]) {
        verify(AgingSystem::dailyRisk($years*365) === $risk, 'Wrong age-band risk');
        verify(AgingSystem::dailyRisk($years*365-1) < $risk, 'Risk changed before birthday');
    }
    verify(AgingSystem::dailyRisk(20*365) === 0, 'Young adult has old-age risk');
    $rng = new DeterministicRandom(0);
    verify($rng->nextInt(100000) === 48, 'Seeded mortality fixture draw changed');

    // Equal initial worlds replay equal deaths; no duplicate events or aging after death.
    $copies = [];
    foreach ([0,1] as $copy) {
        $world = agingWorld($db,$worlds);
        agingAgent($db,$world,90*365); // Seed zero's first draw is below the 90-year risk.
        agingAgent($db,$world,AgingSystem::MAX_AGE_DAYS-1);
        agingAgent($db,$world,100); // Child must survive and age once.
        $copies[] = $world;
    }
    $engine = new SurvivalEngine($db);
    $left = $engine->tick($copies[0]);
    $right = $engine->tick($copies[1]);
    verify($left === $right && agingSnapshot($db,$copies[0]) === agingSnapshot($db,$copies[1]), 'Aging replay diverged');
    verify($left['deaths'] === 2 && $left['old_age_deaths'] === 2 && $left['child_deaths'] === 0, 'Death causes miscounted');
    $before = agingSnapshot($db,$copies[0]);
    verify((int)$before[0]['alive'] === 0 && (int)$before[0]['health'] === 0 && (int)$before[0]['age_ticks'] === 90*365+1, 'Probabilistic old-age death not persisted correctly');
    verify((int)$before[1]['age_ticks'] === AgingSystem::MAX_AGE_DAYS && (int)$before[1]['alive'] === 0, 'Maximum lifespan boundary failed');
    verify((int)$before[2]['age_ticks'] === 101 && (int)$before[2]['alive'] === 1, 'Survivor age changed more than once');
    $result = $engine->tick($copies[0]);
    $after = agingSnapshot($db,$copies[0]);
    verify($result['old_age_deaths'] === 0 && deathCount($db,$copies[0]) === 2, 'Death counted twice');
    verify($before[0] === $after[0] && $before[1] === $after[1] && (int)$after[2]['age_ticks'] === 102, 'Dead agent acted or aged again');

    // Surviving older adult ages once; no extra mortality draw below age 60.
    $world = agingWorld($db,$worlds);
    agingAgent($db,$world,60*365-1);
    $db->beginTransaction();
    $rng = new DeterministicRandom(0);
    verify((new AgingSystem($db))->advance($world,1,$rng) === 0, 'Nonfatal draw killed a 60-year-old');
    verify((int)agingSnapshot($db,$world)[0]['age_ticks'] === 60*365-1, 'Mortality check double-aged survivor');
    $db->rollBack();
    $engine->tick($world);
    verify((int)agingSnapshot($db,$world)[0]['age_ticks'] === 60*365, 'Survivor did not reach birthday');
    $world = agingWorld($db,$worlds);
    agingAgent($db,$world,59*365);
    $db->beginTransaction();
    $rng = new DeterministicRandom(42);
    (new AgingSystem($db))->advance($world,1,$rng);
    verify($rng->state() === 42, 'Mortality consumed randomness for a young agent');
    $db->rollBack();

    // Death rolls back with the tick transaction.
    $world = agingWorld($db,$worlds);
    agingAgent($db,$world,AgingSystem::MAX_AGE_DAYS-1);
    $before = agingSnapshot($db,$world);
    $db->beginTransaction();
    (new AgingSystem($db))->advance($world,1,new DeterministicRandom(0));
    $db->rollBack();
    verify(agingSnapshot($db,$world) === $before && deathCount($db,$world) === 0, 'Rolled-back death persisted');

    // Dead mother cannot act, feed or give birth; living father takes over care.
    $world = agingWorld($db,$worlds);
    $mother = agingAgent($db,$world,AgingSystem::MAX_AGE_DAYS-1);
    $father = agingAgent($db,$world,20*365,'male',4);
    $child = agingAgent($db,$world,100,'female',0);
    $db->prepare('UPDATE agents SET mother_id=?,father_id=?,hunger=48 WHERE id=?')->execute([$mother,$father,$child]);
    $db->prepare('INSERT INTO pregnancies(world_id,mother_id,father_id,conceived_tick,due_tick) VALUES(?,?,?,0,1)')->execute([$world,$mother,$father]);
    $result = $engine->tick($world);
    verify($result['old_age_deaths'] === 1 && $result['births'] === 0 && $result['feedings'] === 1, 'Death did not precede birth and care');
    $rows = agingSnapshot($db,$world);
    verify((int)$rows[0]['food_inventory'] === 10 && (int)$rows[1]['food_inventory'] === 3 && (int)$rows[2]['hunger'] === 6, 'Dead mother donated or father failed to feed');
    $q = $db->prepare('SELECT resolved_tick,child_id FROM pregnancies WHERE world_id=?');
    $q->execute([$world]); $pregnancy = $q->fetch(PDO::FETCH_ASSOC);
    verify((int)$pregnancy['resolved_tick'] === 1 && $pregnancy['child_id'] === null, 'Dead mother pregnancy not resolved');

    // Report must include deaths earlier in the interval, even if final tick has none.
    $world = agingWorld($db,$worlds);
    agingAgent($db,$world,AgingSystem::MAX_AGE_DAYS-1);
    agingAgent($db,$world,20*365);
    $report = sys_get_temp_dir() . '/aging-report-' . bin2hex(random_bytes(8)) . '.csv';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__).'/bin/simulate-report.php') . ' ' . $world . ' 2 2 ' . escapeshellarg($report);
    exec($command,$output,$exit);
    verify($exit === 0, 'Report command failed');
    $file = fopen($report,'rb');
    $header = fgetcsv($file,0,',','"','');
    $values = fgetcsv($file,0,',','"','');
    fclose($file);
    verify(count($header) === count($values), 'Report column counts differ');
    $row = array_combine($header,$values);
    verify((int)$row['old_age_deaths_interval'] === 1 && (int)$row['death_events'] === 1 && (int)$row['deaths'] === 0, 'Report lost interval mortality counts');
    echo "PASS: mortality age bands, birthdays, deterministic replay, single death events, transactional rollback, caregiver fallback and report counts\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    if ($report !== null && is_file($report)) { unlink($report); }
    foreach ($worlds as $world) {
        foreach (['DELETE FROM pregnancies WHERE world_id=?','DELETE FROM world_events WHERE world_id=?','UPDATE agents SET mother_id=NULL,father_id=NULL WHERE world_id=?','DELETE FROM agents WHERE world_id=?','DELETE FROM worlds WHERE id=?'] as $sql) {
            $db->prepare($sql)->execute([$world]);
        }
    }
}
