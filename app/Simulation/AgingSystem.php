<?php
declare(strict_types=1);
namespace App\Simulation;

use PDO;

/** Illustrative game mortality, not a calibrated demographic model. */
final class AgingSystem
{
    public const RISK_SCALE = 100000;
    public const MAX_AGE_DAYS = 110 * 365;

    public function __construct(private PDO $db) {}

    /** Daily chance in hundred-thousandths, based on age reached this tick. */
    public static function dailyRisk(int $ageDays): int
    {
        return match (true) {
            $ageDays >= self::MAX_AGE_DAYS => self::RISK_SCALE,
            $ageDays >= 100 * 365 => 250,
            $ageDays >= 90 * 365 => 100,
            $ageDays >= 80 * 365 => 40,
            $ageDays >= 70 * 365 => 15,
            $ageDays >= 60 * 365 => 5,
            default => 0,
        };
    }

    /** Resolve before other actions, inside the world's tick transaction. */
    public function advance(int $worldId, int $tick, DeterministicRandom $rng): int
    {
        $q = $this->db->prepare('SELECT id,age_ticks FROM agents WHERE world_id=? AND alive=1 AND age_ticks>=? ORDER BY id FOR UPDATE');
        $q->execute([$worldId,60 * 365 - 1]);
        $death = $this->db->prepare('UPDATE agents SET alive=0,health=0,age_ticks=age_ticks+1 WHERE id=? AND world_id=? AND alive=1');
        $event = $this->db->prepare("INSERT INTO world_events(world_id,tick,agent_id,event_type,details) VALUES(?,?,?,'death','Died from old age')");
        $deaths = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $agent) {
            $risk = self::dailyRisk((int)$agent['age_ticks'] + 1);
            if ($risk < self::RISK_SCALE && $rng->nextInt(self::RISK_SCALE) >= $risk) { continue; }
            $death->execute([$agent['id'],$worldId]);
            if ($death->rowCount() !== 1) { continue; }
            $event->execute([$worldId,$tick,$agent['id']]);
            $deaths++;
        }
        // PopulationSystem ages survivors once later in this same tick.
        return $deaths;
    }
}
