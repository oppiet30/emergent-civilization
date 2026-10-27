<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;
use App\Viewer\WorldViewer;

function ensure(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$db=Database::connect();$worlds=[];
function fixtureWorld(PDO $db,array &$worlds,string $name): int
{
    $db->prepare("INSERT INTO worlds(name,seed,rng_state,status,current_tick) VALUES(?,42,42,'running',123)")->execute([$name]);
    $id=(int)$db->lastInsertId();$worlds[]=$id;
    $db->prepare("INSERT INTO world_tiles(world_id,x,y,terrain) VALUES(?,0,0,'forest')")->execute([$id]);
    return $id;
}
function fixturePerson(PDO $db,int $world,string $name,int $age,int $alive=1): int
{
    $db->prepare("INSERT INTO agents(world_id,name,x,y,age_ticks,alive,health,sex) VALUES(?,?,0,0,?,?,?,'female')")->execute([$world,$name,$age,$alive,$alive?100:0]);
    return (int)$db->lastInsertId();
}
try {
    $world=fixtureWorld($db,$worlds,'<script>World & history</script>');
    $other=fixtureWorld($db,$worlds,'Other world');
    $mother=fixturePerson($db,$world,'Mother',7300);
    $child=fixturePerson($db,$world,'Child <img src=x>',100);
    $dead=fixturePerson($db,$world,'Ancestor',36500,0);
    $foreign=fixturePerson($db,$other,'Foreign person',8000);
    $db->prepare('UPDATE agents SET mother_id=?,father_id=? WHERE id=?')->execute([$mother,$foreign,$child]);
    $q=$db->prepare('INSERT INTO world_events(world_id,tick,agent_id,event_type,details) VALUES(?,?,?,?,?)');
    $ids=[];
    foreach ([['birth',$child,'Child born'],['death',$dead,'Died from old age'],['feeding',$child,'Food shared'],['conception',$mother,'Pregnancy began'],['infant_death',$mother,'Infant loss']] as [$kind,$person,$details]) {
        $q->execute([$world,123,$person,$kind,$details]);$ids[]=(int)$db->lastInsertId();
    }
    $q->execute([$other,123,$foreign,'death','Foreign event']);
    $before=$db->query('SELECT id,current_tick,rng_state FROM worlds ORDER BY id')->fetchAll();
    $viewer=new WorldViewer($db);
    [$status,$data]=$viewer->respond('GET',"/api/worlds/$world");
    ensure($status===200 && $data['world']['name']==='<script>World & history</script>','Snapshot or literal names failed');
    ensure((int)$data['summary']['population']===2 && (int)$data['summary']['adults']===1 && (int)$data['summary']['children']===1,'Population summary incorrect');
    ensure((int)$data['summary']['births']===1 && (int)$data['summary']['deaths']===1,'Vital counts leaked or included infant loss as living death');
    ensure(count($data['tiles'])===1 && count($data['people'])===2,'Map included other worlds or dead agents');
    [$status,$data]=$viewer->respond('GET',"/api/worlds/$world/people/$child");
    ensure($status===200 && (int)$data['person']['mother_id']===$mother && $data['person']['father_id']===null,'Parent links crossed world boundary');
    [$status,$data]=$viewer->respond('GET',"/api/worlds/$world/people/$mother");
    ensure(count($data['children'])===1 && (int)$data['children'][0]['id']===$child,'Children list failed');
    [$status,$data]=$viewer->respond('GET',"/api/worlds/$world/people/$dead");
    ensure($status===200 && $data['death']['details']==='Died from old age','Historical person or cause unavailable');
    [$status]=$viewer->respond('GET',"/api/worlds/$world/people/$foreign");ensure($status===404,'Cross-world person readable');
    [$status,$data]=$viewer->respond('GET',"/api/worlds/$world/events",['limit'=>'2']);
    ensure($status===200 && count($data['events'])===2 && (int)$data['next_before']===$ids[1],'Vital timeline order or cursor failed');
    [$status,$next]=$viewer->respond('GET',"/api/worlds/$world/events",['limit'=>'2','before'=>(string)$data['next_before']]);
    ensure(count($next['events'])===1 && (int)$next['events'][0]['id']===$ids[0] && $next['next_before']===null,'Cursor skipped or repeated events');
    [$status,$data]=$viewer->respond('GET',"/api/worlds/$world/events",['kind'=>'all']);ensure(count($data['events'])===5,'All-events filter failed');
    [$status,$data]=$viewer->respond('GET',"/api/worlds/$world/events",['kind'=>'death']);ensure(count($data['events'])===1,'Death filter leaked another world');
    foreach ([['limit'=>'0'],['limit'=>'101'],['before'=>'-1'],['before'=>['1']],['kind'=>['death']],['kind'=>"death' OR 1=1"]] as $query) {
        [$status]=$viewer->respond('GET',"/api/worlds/$world/events",$query);ensure($status===400,'Invalid filter was accepted');
    }
    foreach (['POST','PUT','DELETE','PATCH'] as $method) {
        [$status]=$viewer->respond($method,"/api/worlds/$world");ensure($status===405,'Mutation method accepted');
    }
    [$status]=$viewer->respond('GET','/api/worlds/99999999999999999999999999');ensure($status===400,'Overflow ID accepted');
    [$status]=$viewer->respond('GET','/api/worlds/0');ensure($status===404,'Invalid route accepted');
    [$status]=$viewer->respond('GET','/api/worlds/9223372036854775807');ensure($status===404,'Missing world not handled');
    [$status]=$viewer->respond('GET','/.env');ensure($status===404,'Configuration route accepted');
    [$status,$data]=$viewer->respond('GET','/api/worlds');ensure($status===200 && isset($data['worlds']),'World list failed');
    ensure(!$db->inTransaction(),'Read transaction left open');
    ensure($before===$db->query('SELECT id,current_tick,rng_state FROM worlds ORDER BY id')->fetchAll(),'Viewer advanced ticks or changed RNG state');
    echo "PASS: read-only snapshots, family/world isolation, historical people, event filters/cursors and input validation\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($worlds as $world) $db->prepare('UPDATE agents SET mother_id=NULL,father_id=NULL WHERE world_id=?')->execute([$world]);
    foreach ($worlds as $world) {
        foreach (['DELETE FROM world_events WHERE world_id=?','DELETE FROM agents WHERE world_id=?','DELETE FROM worlds WHERE id=?'] as $sql) $db->prepare($sql)->execute([$world]);
    }
}
