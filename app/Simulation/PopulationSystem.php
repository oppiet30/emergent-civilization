<?php
declare(strict_types=1);
namespace App\Simulation;
use PDO;

/** Abstract lifecycle model; one tick is one day. */
final class PopulationSystem
{
    public function __construct(private PDO $db) {}
    public function advance(int $worldId, int $tick, DeterministicRandom $rng): array
    {
        $this->db->prepare('UPDATE agents SET age_ticks=age_ticks+1 WHERE world_id=? AND alive=1')->execute([$worldId]);
        $q=$this->db->prepare('SELECT p.* FROM pregnancies p WHERE p.world_id=? AND p.resolved_tick IS NULL AND p.due_tick<=? ORDER BY p.id');
        $q->execute([$worldId,$tick]);
        $alive=$this->db->prepare('SELECT alive,x,y FROM agents WHERE id=? AND world_id=?');
        $insert=$this->db->prepare('INSERT INTO agents(world_id,name,x,y,sex,age_ticks,mother_id,father_id,food_inventory) VALUES(?,?,?,?,?,0,?,?,0)');
        $finish=$this->db->prepare('UPDATE pregnancies SET resolved_tick=?,child_id=? WHERE id=? AND resolved_tick IS NULL');
        $event=$this->db->prepare('INSERT INTO world_events(world_id,tick,agent_id,event_type,details) VALUES(?,?,?,?,?)');
        $births=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $alive->execute([$p['mother_id'],$worldId]); $mother=$alive->fetch(PDO::FETCH_ASSOC);
            if (!$mother || !(int)$mother['alive']) { $finish->execute([$tick,null,$p['id']]); continue; }
            // Infant mortality is an illustrative 2% probability at delivery.
            if ($rng->nextInt(100)<2) {
                $finish->execute([$tick,null,$p['id']]);
                $event->execute([$worldId,$tick,$p['mother_id'],'infant_death','Pregnancy ended in infant loss']);
                continue;
            }
            $sex=$rng->nextInt(2)===0?'female':'male';
            $insert->execute([$worldId,'Child-'.$worldId.'-'.$p['id'],$mother['x'],$mother['y'],$sex,$p['mother_id'],$p['father_id']]);
            $id=(int)$this->db->lastInsertId();
            $finish->execute([$tick,$id,$p['id']]);
            $event->execute([$worldId,$tick,$id,'birth','Child born']);
            $births++;
        }
        // Only adults may reproduce. Pregnancies take 280 ticks.
        $q=$this->db->prepare("SELECT id,sex,age_ticks,x,y FROM agents WHERE world_id=? AND alive=1 AND age_ticks BETWEEN 6570 AND 16425 AND sex IS NOT NULL ORDER BY id");
        $q->execute([$worldId]); $adults=$q->fetchAll(PDO::FETCH_ASSOC);
        $males=array_values(array_filter($adults,static fn($a)=>$a['sex']==='male'));
        $active=$this->db->prepare('SELECT COUNT(*) FROM pregnancies WHERE mother_id=? AND resolved_tick IS NULL');
        $last=$this->db->prepare('SELECT MAX(conceived_tick) FROM pregnancies WHERE mother_id=?');
        $conceive=$this->db->prepare('INSERT INTO pregnancies(world_id,mother_id,father_id,conceived_tick,due_tick) VALUES(?,?,?,?,?)');
        foreach($adults as $a) {
            if ($a['sex']!=='female') continue;
            $active->execute([$a['id']]); if ((int)$active->fetchColumn()>0) continue;
            $last->execute([$a['id']]); $previous=$last->fetchColumn();
            if ($previous!==null && $tick-(int)$previous<365) continue;
            $candidates=array_values(array_filter($males,static fn($m)=>$m['id']!==$a['id'] && $m['x']===$a['x'] && $m['y']===$a['y']));
            if (!$candidates || $rng->nextInt(100)!==0) continue;
            $father=$candidates[$rng->nextInt(count($candidates))];
            $conceive->execute([$worldId,$a['id'],$father['id'],$tick,$tick+280]);
            $event->execute([$worldId,$tick,$a['id'],'conception','Pregnancy began']);
        }
        return ['births'=>$births];
    }
}
