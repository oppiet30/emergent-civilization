<?php
declare(strict_types=1);
namespace App\Simulation;

final class WorldGenerator
{
    public function generate(int $seed, int $width = 32, int $height = 32): array
    {
        if ($width < 4 || $height < 4) { throw new \InvalidArgumentException('World dimensions must be at least 4'); }
        $rng = new DeterministicRandom($seed);
        $tiles = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $roll = $rng->nextInt(100);
                $terrain = $x === intdiv($width, 2) ? 'water'
                    : ($roll < 12 ? 'mountain' : ($roll < 35 ? 'forest' : 'grass'));
                $tiles[] = ['x' => $x, 'y' => $y, 'terrain' => $terrain];
            }
        }
        return $tiles;
    }
}
