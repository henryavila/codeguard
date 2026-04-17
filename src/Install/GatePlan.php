<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class GatePlan
{
    public function __construct(
        public string $gateName,
        public string $description,
        public int $configMinutes,
        public int $ciCostSeconds,
    ) {}

    public function configTimeLabel(): string
    {
        if ($this->configMinutes === 0) {
            return '0';
        }

        if ($this->configMinutes < 60) {
            return "{$this->configMinutes}min";
        }

        $hours = intdiv($this->configMinutes, 60);
        $minutes = $this->configMinutes % 60;

        return $minutes === 0
            ? "{$hours}h"
            : "{$hours}h {$minutes}min";
    }

    public function ciCostLabel(): string
    {
        if ($this->ciCostSeconds === 0) {
            return '0';
        }

        if ($this->ciCostSeconds < 60) {
            return "~{$this->ciCostSeconds}s";
        }

        $minutes = intdiv($this->ciCostSeconds, 60);
        $seconds = $this->ciCostSeconds % 60;

        return $seconds === 0
            ? "+{$minutes}min"
            : "+{$minutes}min {$seconds}s";
    }
}
