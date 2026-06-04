<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\NamespaceGraph;

it('maps each class file to a node keyed by its FQCN', function (): void {
    $graph = (new NamespaceGraph)->fromSources([
        'app/Services/OrderService.php' => "<?php\nnamespace App\\Services;\nclass OrderService {}\n",
        'app/Repositories/OrderRepository.php' => "<?php\nnamespace App\\Repositories;\nclass OrderRepository {}\n",
    ]);

    $fqcns = array_map(static fn (array $n): string => $n['fqcn'], $graph['nodes']);

    expect($fqcns)->toContain('App\\Services\\OrderService', 'App\\Repositories\\OrderRepository');
});

it('records a first-party use edge between two scoped files', function (): void {
    $graph = (new NamespaceGraph)->fromSources([
        'app/Services/OrderService.php' => "<?php\nnamespace App\\Services;\nuse App\\Repositories\\OrderRepository;\nclass OrderService {}\n",
        'app/Repositories/OrderRepository.php' => "<?php\nnamespace App\\Repositories;\nclass OrderRepository {}\n",
    ]);

    expect($graph['edges'])->toContain([
        'from' => 'App\\Services\\OrderService',
        'to' => 'App\\Repositories\\OrderRepository',
    ]);
});

it('drops edges to third-party classes outside the scoped set', function (): void {
    $graph = (new NamespaceGraph)->fromSources([
        'app/Services/OrderService.php' => "<?php\nnamespace App\\Services;\nuse Illuminate\\Support\\Str;\nclass OrderService {}\n",
    ]);

    expect($graph['edges'])->toBe([]);
});

it('detects a two-file circular dependency', function (): void {
    $graph = (new NamespaceGraph)->fromSources([
        'app/Orders/OrderService.php' => "<?php\nnamespace App\\Orders;\nuse App\\Shipping\\ShippingService;\nclass OrderService {}\n",
        'app/Shipping/ShippingService.php' => "<?php\nnamespace App\\Shipping;\nuse App\\Orders\\OrderService;\nclass ShippingService {}\n",
    ]);

    expect($graph['cycles'])->toHaveCount(1);

    $cycle = $graph['cycles'][0];
    sort($cycle);
    expect($cycle)->toBe(['App\\Orders\\OrderService', 'App\\Shipping\\ShippingService']);
});

it('reports no cycle for an acyclic dependency chain', function (): void {
    $graph = (new NamespaceGraph)->fromSources([
        'a.php' => "<?php\nnamespace App;\nuse App\\B;\nclass A {}\n",
        'b.php' => "<?php\nnamespace App;\nuse App\\C;\nclass B {}\n",
        'c.php' => "<?php\nnamespace App;\nclass C {}\n",
    ]);

    expect($graph['cycles'])->toBe([]);
});
