<?php
declare(strict_types=1);
namespace App\Simulation;

use PDO;
use RuntimeException;
use Throwable;

/** One atomic, ordered world tick. No wall-clock or global randomness. */
final class SurvivalEngine
{
    public function __construct(private PDO $db) {}

    public function tick(int $worldId): array
    {
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT * FROM worlds WHERE id = ? FOR UPDATE');
            $q->execute([$worldId]);
            $world = $q->fetch(PDO::FETCH_ASSOC);
            if (!$world || $world['status'] !== 'running') {
                throw new RuntimeException('World missing or not running');
            }
            $tick = (int)$world['current_tick'] + 1;
            $rng = new DeterministicRandom((int)$world['rng_state']);
            $wildlife = new WildlifeSystem($this->db);
            $q = $this->db->prepare('SELECT a.*, t.id AS tile_id, t.terrain FROM agents a JOIN world_tiles t ON t.world_id=a.world_id AND t.x=a.x AND t.y=a.y WHERE a.world_id=? AND a.alive=1 ORDER BY a.id FOR UPDATE');
            $q->execute([$worldId]);
            $agents = $q->fetchAll();
            $resource = $this->db->prepare('SELECT food FROM tile_resources WHERE tile_id=? FOR UPDATE');
            $take = $this->db->prepare('UPDATE tile_resources SET food=food-1 WHERE tile_id=? AND food>0');
            $update = $this->db->prepare('UPDATE agents SET hunger=?,energy=?,health=?,food_inventory=?,alive=? WHERE id=?');
            $event = $this->db->prepare('INSERT INTO world_events(world_id,tick,agent_id,event_type,details) VALUES (?,?,?,?,?)');
            $deaths = 0;
            $hunts = 0;
            $meat = 0;
            foreach ($agents as $a) {
                $hunger = min(100, (int)$a['hunger'] + 3);
                $energy = max(0, (int)$a['energy'] - 2);
                $health = (int)$a['health'];
                $food = (int)$a['food_inventory'];
                if ($hunger >= 50 && $food > 0) {
                    $food--;
                    $hunger = max(0, $hunger - 45);
                } elseif ($food < 3 && $a['terrain'] !== 'water' && $a['terrain'] !== 'mountain') {
                    // Alternate gathering and hunting so wildlife is a genuine food source.
                    if ($rng->nextInt(100) < 35) {
                        $yield = $wildlife->hunt($worldId, (int)$a['tile_id'], $rng);
                        if ($yield > 0) {
                            $food += $yield;
                            $hunts++;
                            $meat += $yield;
                        }
                    } else {
                        $resource->execute([(int)$a['tile_id']]);
                        $available = (int)($resource->fetchColumn() ?: 0);
                        if ($available > 0 && $rng->nextInt(100) < 65) {
                            $take->execute([(int)$a['tile_id']]);
                            if ($take->rowCount() === 1) { $food++; }
                        }
                    }
                }
                if ($hunger >= 90) { $health = max(0, $health - 8); }
                if ($energy < 20) { $energy = min(100, $energy + 12); }
                $alive = $health > 0 ? 1 : 0;
                $update->execute([$hunger,$energy,$health,$food,$alive,(int)$a['id']]);
                if (!$alive) {
                    $deaths++;
                    $event->execute([$worldId,$tick,(int)$a['id'],'death','Died from prolonged starvation']);
                }
            }
            if ($tick % 10 === 0) { $wildlife->advance($worldId, $rng); }
            $q = $this->db->prepare('UPDATE tile_resources SET food=LEAST(food_capacity,food+1) WHERE world_id=? AND food<food_capacity');
            $q->execute([$worldId]);
            $q = $this->db->prepare('UPDATE worlds SET current_tick=?,rng_state=? WHERE id=?');
            $q->execute([$tick,$rng->state(),$worldId]);
            $this->db->commit();
            return ['tick'=>$tick,'agents'=>count($agents),'deaths'=>$deaths,'hunts'=>$hunts,'meat'=>$meat];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
