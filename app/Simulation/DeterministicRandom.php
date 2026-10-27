<?php
declare(strict_types=1);
namespace App\Simulation;

final class DeterministicRandom
{
    private int $state;
    public function __construct(int $seed) { $this->state = $seed & 0x7fffffff; }
    public function nextInt(int $maxExclusive): int
    {
        if ($maxExclusive <= 0) { throw new \InvalidArgumentException('maxExclusive must be positive'); }
        $this->state = (int) (($this->state * 1103515245 + 12345) & 0x7fffffff);
        // Avoid the short low-bit cycles of this linear congruential generator.
        return ($this->state >> 8) % $maxExclusive;
    }
    public function state(): int { return $this->state; }
}
