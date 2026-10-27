<?php
declare(strict_types=1);
namespace App\Viewer;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Bounded observer queries. Every request uses a database-enforced read-only snapshot. */
final class WorldViewer
{
    public function __construct(private PDO $db) {}

    public function respond(string $method, string $path, array $query = []): array
    {
        if ($method !== 'GET') { return [405, ['error'=>'Only GET is supported']]; }
        if ($path !== '/api/worlds' && !preg_match('~^/api/worlds/([1-9][0-9]*)(?:/(events|people/([1-9][0-9]*)))?$~D', $path, $match)) {
            return [404, ['error'=>'Endpoint not found']];
        }
        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->db->beginTransaction();
            if ($path === '/api/worlds') {
                $data = ['worlds'=>$this->rows('SELECT id,name,current_tick,status FROM worlds ORDER BY id LIMIT 100')];
            } else {
                $worldId = self::integer($match[1]);
                $world = $this->rows('SELECT id,name,current_tick,status FROM worlds WHERE id=?', [$worldId])[0] ?? null;
                if ($world === null) { throw new RuntimeException('not-found'); }
                $resource = $match[2] ?? '';
                $data = match (true) {
                    $resource === '' => $this->snapshot($world),
                    $resource === 'events' => $this->events($world, $query),
                    default => $this->person($world, self::integer($match[3])),
                };
            }
            $this->db->commit();
            return [200, $data];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($error instanceof InvalidArgumentException) { return [400, ['error'=>$error->getMessage()]]; }
            if ($error instanceof RuntimeException && $error->getMessage() === 'not-found') { return [404, ['error'=>'World or person not found']]; }
            error_log('World viewer request failed: ' . get_class($error));
            return [503, ['error'=>'World data is temporarily unavailable']];
        }
    }

    private static function integer(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('Expected a positive integer'); }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($number === false) { throw new InvalidArgumentException('Expected a positive integer'); }
        return $number;
    }

    private function rows(string $sql, array $values = []): array
    {
        $statement = $this->db->prepare($sql);
        foreach ($values as $i=>$value) {
            $statement->bindValue($i+1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function snapshot(array $world): array
    {
        $id = (int)$world['id'];
        $summary = $this->rows('SELECT COUNT(*) AS population, COALESCE(SUM(age_ticks<6570),0) AS children, COALESCE(SUM(age_ticks>=6570),0) AS adults FROM agents WHERE world_id=? AND alive=1', [$id])[0];
        $vital = $this->rows("SELECT COALESCE(SUM(event_type='birth'),0) AS births, COALESCE(SUM(event_type='death'),0) AS deaths FROM world_events WHERE world_id=?", [$id])[0];
        $people = $this->rows('SELECT id,name,x,y,sex,age_ticks,health,hunger,food_inventory FROM agents WHERE world_id=? AND alive=1 ORDER BY id LIMIT 5001', [$id]);
        $truncated = count($people)>5000;
        $tiles = $this->rows('SELECT t.x,t.y,t.terrain,COALESCE(r.food,0) AS food,COALESCE(r.food_capacity,0) AS food_capacity FROM world_tiles t LEFT JOIN tile_resources r ON r.tile_id=t.id AND r.world_id=t.world_id WHERE t.world_id=? ORDER BY t.y,t.x LIMIT 10001', [$id]);
        return [
            'world'=>$world, 'summary'=>array_merge($summary,$vital),
            'tiles'=>array_slice($tiles,0,10000), 'tiles_truncated'=>count($tiles)>10000,
            'people'=>array_slice($people,0,5000), 'people_truncated'=>$truncated,
        ];
    }

    private function person(array $world, int $personId): array
    {
        $id = (int)$world['id'];
        $person = $this->rows('SELECT a.id,a.name,a.x,a.y,a.sex,a.age_ticks,a.health,a.hunger,a.energy,a.food_inventory,a.alive,m.id AS mother_id,m.name AS mother_name,f.id AS father_id,f.name AS father_name FROM agents a LEFT JOIN agents m ON m.id=a.mother_id AND m.world_id=a.world_id LEFT JOIN agents f ON f.id=a.father_id AND f.world_id=a.world_id WHERE a.world_id=? AND a.id=?', [$id,$personId])[0] ?? null;
        if ($person === null) { throw new RuntimeException('not-found'); }
        $children = $this->rows('SELECT id,name,sex,age_ticks,alive FROM agents WHERE world_id=? AND (mother_id=? OR father_id=?) ORDER BY id LIMIT 201', [$id,$personId,$personId]);
        $death = $this->rows("SELECT tick,details FROM world_events WHERE world_id=? AND agent_id=? AND event_type='death' ORDER BY id DESC LIMIT 1", [$id,$personId])[0] ?? null;
        return ['world'=>$world,'person'=>$person,'children'=>array_slice($children,0,200),'children_truncated'=>count($children)>200,'death'=>$death];
    }

    private function events(array $world, array $query): array
    {
        $limit = isset($query['limit']) ? self::integer($query['limit']) : 30;
        if ($limit>100) { throw new InvalidArgumentException('Limit must be between 1 and 100'); }
        $kind = $query['kind'] ?? 'vital';
        if (!is_string($kind) || !in_array($kind,['vital','birth','death','conception','infant_death','all'],true)) { throw new InvalidArgumentException('Unknown event filter'); }
        $where = 'e.world_id=?';
        $values = [(int)$world['id']];
        if ($kind === 'vital') { $where .= " AND e.event_type IN ('birth','death','infant_death')"; }
        elseif ($kind !== 'all') { $where .= ' AND e.event_type=?'; $values[] = $kind; }
        if (isset($query['before'])) { $where .= ' AND e.id<?'; $values[] = self::integer($query['before']); }
        $values[] = $limit+1;
        $rows = $this->rows('SELECT e.id,e.tick,e.agent_id,a.name AS agent_name,e.event_type,e.details FROM world_events e LEFT JOIN agents a ON a.id=e.agent_id AND a.world_id=e.world_id WHERE ' . $where . ' ORDER BY e.id DESC LIMIT ?', $values);
        $more = count($rows)>$limit;
        $rows = array_slice($rows,0,$limit);
        return ['world'=>$world,'events'=>$rows,'next_before'=>$more ? $rows[count($rows)-1]['id'] : null];
    }
}
