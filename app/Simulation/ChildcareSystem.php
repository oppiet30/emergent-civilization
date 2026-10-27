<?php
declare(strict_types=1);
namespace App\Simulation;

use PDO;

/** Basic local care; called inside the world's tick transaction. */
final class ChildcareSystem
{
    public const ADULT_AGE = 6570;

    public function __construct(private PDO $db) {}

    /** Pin one available adult per dependent's tile, preferring a local parent. */
    public function caregiverIds(int $worldId): array
    {
        $q = $this->db->prepare('SELECT id,x,y,age_ticks,mother_id,father_id FROM agents WHERE world_id=? AND alive=1 ORDER BY id FOR UPDATE');
        $q->execute([$worldId]);
        $agents = $q->fetchAll(PDO::FETCH_ASSOC);
        $adults = [];
        foreach ($agents as $agent) {
            if ((int)$agent['age_ticks'] >= self::ADULT_AGE) {
                $adults[$agent['x'] . ',' . $agent['y']][(int)$agent['id']] = true;
            }
        }
        $caregivers = [];
        foreach ($agents as $child) {
            if ((int)$child['age_ticks'] >= self::ADULT_AGE) { continue; }
            $local = $adults[$child['x'] . ',' . $child['y']] ?? [];
            if (!$local) { continue; }
            $chosen = (int)array_key_first($local);
            foreach ([$child['mother_id'],$child['father_id']] as $parent) {
                if ($parent !== null && isset($local[(int)$parent])) {
                    $chosen = (int)$parent;
                    break;
                }
            }
            $caregivers[$chosen] = true;
        }
        return $caregivers;
    }

    /**
     * One cardinal step for a free parent returning to an unsupported child.
     * Hungriest children first, then ID; mothers before fathers. Never reassign
     * an adult already caring for children on their current tile.
     */
    public function reunionSteps(int $worldId, array $tiles, array $caregivers): array
    {
        $q = $this->db->prepare('SELECT id,x,y,age_ticks,mother_id,father_id,hunger,energy FROM agents WHERE world_id=? AND alive=1 ORDER BY hunger DESC,id FOR UPDATE');
        $q->execute([$worldId]);
        $agents = $q->fetchAll(PDO::FETCH_ASSOC);
        $adults = [];
        $occupied = [];
        foreach ($agents as $agent) {
            if ((int)$agent['age_ticks'] >= self::ADULT_AGE) {
                $adults[(int)$agent['id']] = $agent;
                $occupied[$agent['x'] . ',' . $agent['y']] = true;
            }
        }
        $steps = [];
        $targets = [];
        foreach ($agents as $child) {
            if ((int)$child['age_ticks'] >= self::ADULT_AGE) { continue; }
            $target = $child['x'] . ',' . $child['y'];
            if (isset($occupied[$target]) || isset($targets[$target])) { continue; }
            foreach ([$child['mother_id'],$child['father_id']] as $id) {
                $id = (int)$id;
                if (!isset($adults[$id]) || isset($caregivers[$id]) || isset($steps[$id])) { continue; }
                $parent = $adults[$id];
                if ((int)$parent['energy'] < 20) { continue; }
                $step = $this->firstStep($parent, $target, $tiles);
                if ($step === null || $step[2] > (int)$parent['energy']) { continue; }
                $steps[$id] = $step;
                $targets[$target] = true;
                break;
            }
        }
        return $steps;
    }

    /** Breadth-first search: minimum tile hops with a stable N/E/S/W tie-break. */
    private function firstStep(array $parent, string $target, array $tiles): ?array
    {
        $start = $parent['x'] . ',' . $parent['y'];
        if (!isset($tiles[$start],$tiles[$target]) || $start === $target) { return null; }
        $queue = [$start];
        $seen = [$start=>true];
        $first = [];
        for ($head=0;$head<count($queue);$head++) {
            $key = $queue[$head];
            $tile = $tiles[$key];
            foreach ([[0,-1],[1,0],[0,1],[-1,0]] as [$dx,$dy]) {
                $next = ((int)$tile['x']+$dx) . ',' . ((int)$tile['y']+$dy);
                if (isset($seen[$next]) || !isset($tiles[$next])) { continue; }
                $cost = max(1,(int)$tiles[$next]['movement_cost']);
                // Energy is capped at 100 by the simulation.
                if ($cost > 100) { continue; }
                $seen[$next] = true;
                $first[$next] = $key === $start
                    ? [$tiles[$next]['x'],$tiles[$next]['y'],$cost]
                    : $first[$key];
                if ($next === $target) { return $first[$next]; }
                $queue[] = $next;
            }
        }
        return null;
    }

    /** Consume exactly one unit from a living local adult, if any has food. */
    public function takeFood(int $worldId, array $child): bool
    {
        $q = $this->db->prepare(
            'SELECT id FROM agents WHERE world_id=? AND alive=1 AND age_ticks>=? AND x=? AND y=? AND food_inventory>0 ' .
            'ORDER BY CASE WHEN id=? THEN 0 WHEN id=? THEN 1 ELSE 2 END,id LIMIT 1 FOR UPDATE'
        );
        $q->execute([$worldId,self::ADULT_AGE,$child['x'],$child['y'],$child['mother_id'],$child['father_id']]);
        $id = $q->fetchColumn();
        if ($id === false) { return false; }
        $q = $this->db->prepare('UPDATE agents SET food_inventory=food_inventory-1 WHERE id=? AND world_id=? AND alive=1 AND food_inventory>0');
        $q->execute([$id,$worldId]);
        return $q->rowCount() === 1;
    }
}
