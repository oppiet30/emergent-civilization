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
