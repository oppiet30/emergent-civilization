<?php
declare(strict_types=1);
namespace App\Simulation;

use PDO;

/** Local exploration, called inside the world's tick transaction. */
final class MovementSystem
{
    public function __construct(private PDO $db) {}

    public function advance(int $worldId, DeterministicRandom $rng): int
    {
        $q = $this->db->prepare("SELECT x,y,movement_cost FROM world_tiles WHERE world_id=? AND terrain IN ('grass','forest') ORDER BY y,x");
        $q->execute([$worldId]);
        $tiles = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $tile) {
            $tiles[$tile['x'] . ',' . $tile['y']] = $tile;
        }
        $q = $this->db->prepare('SELECT id,x,y,energy FROM agents WHERE world_id=? AND alive=1 AND age_ticks>=6570 AND energy>=20 ORDER BY id FOR UPDATE');
        $q->execute([$worldId]);
        $move = $this->db->prepare('UPDATE agents SET x=?,y=?,energy=energy-? WHERE id=? AND world_id=?');
        $moved = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $agent) {
            // A 25% daily exploration chance; staying makes encounters last longer.
            if ($rng->nextInt(100) >= 25) { continue; }
            $neighbors = [];
            foreach ([[0,-1],[1,0],[0,1],[-1,0]] as [$dx,$dy]) {
                $key = ((int)$agent['x'] + $dx) . ',' . ((int)$agent['y'] + $dy);
                if (!isset($tiles[$key])) { continue; }
                $tile = $tiles[$key];
                $cost = max(1, (int)$tile['movement_cost']);
                if ($cost <= (int)$agent['energy']) {
                    $neighbors[] = [$tile['x'],$tile['y'],$cost];
                }
            }
            if (!$neighbors) { continue; }
            [$x,$y,$cost] = $neighbors[$rng->nextInt(count($neighbors))];
            $move->execute([$x,$y,$cost,$agent['id'],$worldId]);
            $moved++;
        }
        return $moved;
    }
}
