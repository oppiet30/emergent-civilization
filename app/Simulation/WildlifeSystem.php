<?php
declare(strict_types=1);
namespace App\Simulation;

use PDO;

/**
 * Aggregate animal populations: deterministic births and deaths, no individual animal rows.
 * Called inside the world tick transaction, using the world's saved RNG.
 */
final class WildlifeSystem
{
    public function __construct(private PDO $db) {}

    public function hunt(int $worldId, int $tileId, DeterministicRandom $rng): int
    {
        // One hunt attempt; deer yield 3 food, rabbits yield 1.
        $q = $this->db->prepare(
            "SELECT species,population FROM wildlife_populations
             WHERE world_id=? AND tile_id=? AND population>0
             ORDER BY species FOR UPDATE"
        );
        $q->execute([$worldId,$tileId]);
        $animals = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$animals) { return 0; }
        $animal = $animals[$rng->nextInt(count($animals))];
        $chance = $animal['species'] === 'deer' ? 25 : 45;
        if ($rng->nextInt(100) >= $chance) { return 0; }
        $take = $this->db->prepare(
            'UPDATE wildlife_populations SET population=population-1
             WHERE world_id=? AND tile_id=? AND species=? AND population>0'
        );
        $take->execute([$worldId,$tileId,$animal['species']]);
        return $take->rowCount() === 1 ? ($animal['species'] === 'deer' ? 3 : 1) : 0;
    }

    public function advance(int $worldId, DeterministicRandom $rng): void
    {
        // Ecological turnover every ten ticks; populations may become locally extinct.
        $q = $this->db->prepare(
            'SELECT tile_id,species,population,capacity FROM wildlife_populations
             WHERE world_id=? ORDER BY tile_id,species FOR UPDATE'
        );
        $q->execute([$worldId]);
        $update = $this->db->prepare(
            'UPDATE wildlife_populations SET population=? WHERE tile_id=? AND species=?'
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $animal) {
            $population = (int)$animal['population'];
            $capacity = (int)$animal['capacity'];
            if ($population === 0) { continue; } // No spontaneous resurrection.
            // Births are density-limited; deaths can occur even below capacity.
            $births = ($population < $capacity && $rng->nextInt(100) < 40) ? 1 : 0;
            $deaths = ($rng->nextInt(100) < 12) ? 1 : 0;
            $next = max(0, min($capacity, $population + $births - $deaths));
            if ($next !== $population) {
                $update->execute([$next,$animal['tile_id'],$animal['species']]);
            }
        }
    }
}
