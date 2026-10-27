<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Infrastructure\Database;
use App\Simulation\SurvivalEngine;
$id=filter_var($argv[1] ?? null,FILTER_VALIDATE_INT);
$count=filter_var($argv[2] ?? '1',FILTER_VALIDATE_INT);
if (!$id || $id<1 || !$count || $count<1 || $count>10000) {
    fwrite(STDERR,"Usage: php bin/simulate.php <world-id> [ticks:1-10000]\n"); exit(2);
}
$engine=new SurvivalEngine(Database::connect());
for ($i=0;$i<$count;$i++) {
    $result=$engine->tick($id);
}
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
