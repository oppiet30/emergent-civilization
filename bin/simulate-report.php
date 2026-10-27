<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;
use App\Simulation\SurvivalEngine;

$worldId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$ticks = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
$interval = filter_var($argv[3] ?? '100', FILTER_VALIDATE_INT);
// UTC and microseconds keep reports sortable and distinguish quick successive runs.
$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d_H-i-s-u');
$output = $argv[4] ?? "storage/simulation-report-world-{$worldId}-{$timestamp}Z.csv";

if (!$worldId || $worldId < 1 || !$ticks || $ticks < 1 || $ticks > 10000 ||
    !$interval || $interval < 1) {
    fwrite(STDERR, "Usage: php bin/simulate-report.php <world-id> <ticks> [interval=100] [csv-path]\n");
    exit(2);
}

$db = Database::connect();
$engine = new SurvivalEngine($db);
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException("Cannot create report directory: $dir");
}
$file = fopen($output, 'xb');
if ($file === false) {
    throw new RuntimeException("Cannot create report: $output (choose a new filename if it already exists)");
}
fputcsv($file, ['tick','population','deaths','food_inventory','tile_food','average_hunger','average_health','death_events','deer','rabbits','hunts_interval','meat_interval'], ',', '"', '');
$summary = $db->prepare(
    'SELECT COUNT(*) AS population, COALESCE(SUM(food_inventory),0) AS food_inventory, ' .
    'COALESCE(AVG(hunger),0) AS average_hunger, COALESCE(AVG(health),0) AS average_health ' .
    'FROM agents WHERE world_id=? AND alive=1'
);
$tileFood = $db->prepare('SELECT COALESCE(SUM(food),0) FROM tile_resources WHERE world_id=?');
$wildlifeCounts = $db->prepare("SELECT species,COALESCE(SUM(population),0) AS total FROM wildlife_populations WHERE world_id=? GROUP BY species");
$intervalHunts = 0;
$intervalMeat = 0;
$deathEvents = $db->prepare("SELECT COUNT(*) FROM world_events WHERE world_id=? AND event_type='death'");
try {
    for ($i = 1; $i <= $ticks; $i++) {
        $result = $engine->tick($worldId);
        $intervalHunts += $result['hunts'];
        $intervalMeat += $result['meat'];
        if ($i % $interval !== 0 && $i !== $ticks) {
            continue;
        }
        $summary->execute([$worldId]);
        $agents = $summary->fetch(PDO::FETCH_ASSOC);
        $tileFood->execute([$worldId]);
        $deathEvents->execute([$worldId]);
        $wildlifeCounts->execute([$worldId]);
        $animals = ['deer'=>0,'rabbit'=>0];
        foreach ($wildlifeCounts->fetchAll(PDO::FETCH_ASSOC) as $animal) {
            $animals[$animal['species']] = (int)$animal['total'];
        }
        $row = [
            $result['tick'],
            (int)$agents['population'],
            $result['deaths'],
            (int)$agents['food_inventory'],
            (int)$tileFood->fetchColumn(),
            round((float)$agents['average_hunger'], 2),
            round((float)$agents['average_health'], 2),
            (int)$deathEvents->fetchColumn(),
            $animals['deer'],
            $animals['rabbit'],
            $intervalHunts,
            $intervalMeat,
        ];
        fputcsv($file, $row, ',', '"', '');
        $intervalHunts = 0;
        $intervalMeat = 0;
        echo sprintf(
            "Tick %d: population=%d, deaths_this_tick=%d, tile_food=%d, average_hunger=%.2f, total_deaths=%d\n",
            $row[0], $row[1], $row[2], $row[4], $row[5], $row[7], $row[8], $row[9], $row[10], $row[11]
        );
    }
} finally {
    fclose($file);
}
echo "Report saved: $output\n";
