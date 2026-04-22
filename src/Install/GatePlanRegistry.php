<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Henryavila\Codeguard\Testing\Preset;

final class GatePlanRegistry
{
    /**
     * @return list<GatePlan>
     */
    public function plansFor(Preset $preset): array
    {
        $default = [
            new GatePlan('Pint', 'auto-format', configMinutes: 0, ciCostSeconds: 5),
            new GatePlan('PHPStan', 'type safety', configMinutes: 15, ciCostSeconds: 30),
            new GatePlan('Deptrac', 'architecture', configMinutes: 30, ciCostSeconds: 15),
            new GatePlan('Infection', 'test quality', configMinutes: 20, ciCostSeconds: 180),
            new GatePlan('CaptainHook', 'pre-commit enforce', configMinutes: 10, ciCostSeconds: 0),
        ];

        if ($preset === Preset::Default) {
            return $default;
        }

        return [
            ...$default,
            new GatePlan('jscpd', 'duplication detection', configMinutes: 5, ciCostSeconds: 10),
            new GatePlan('Insights', 'metrics dashboard', configMinutes: 0, ciCostSeconds: 20),
            new GatePlan('TestQualityTest', 'meta-quality arch', configMinutes: 15, ciCostSeconds: 5),
        ];
    }

    /**
     * @param  list<GatePlan>  $plans
     */
    public function totalConfigMinutes(array $plans): int
    {
        $total = 0;

        foreach ($plans as $plan) {
            $total += $plan->configMinutes;
        }

        return $total;
    }

    public function formatMinutes(int $minutes): string
    {
        if ($minutes === 0) {
            return '0 min';
        }

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $remaining === 0
            ? "{$hours}h"
            : "{$hours}h {$remaining}min";
    }
}
