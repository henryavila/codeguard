<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\PhpFileInspector;

it('extracts a simple use import', function (): void {
    $src = "<?php\nnamespace App\\Http;\n\nuse App\\Services\\OrderService;\n\nclass C {}\n";

    expect(PhpFileInspector::imports($src))->toBe(['App\\Services\\OrderService']);
});

it('resolves aliases to the FQCN, ignoring the alias', function (): void {
    $src = "<?php\nuse App\\Services\\OrderService as OS;\nclass C {}\n";

    expect(PhpFileInspector::imports($src))->toBe(['App\\Services\\OrderService']);
});

it('expands group use into individual FQCNs', function (): void {
    $src = "<?php\nuse App\\Services\\{OrderService, Billing\\InvoiceService};\nclass C {}\n";

    expect(PhpFileInspector::imports($src))->toBe([
        'App\\Services\\OrderService',
        'App\\Services\\Billing\\InvoiceService',
    ]);
});

it('ignores use function / use const', function (): void {
    $src = "<?php\nuse function App\\helpers\\tap;\nuse const App\\X\\Y;\nuse App\\Real\\Klass;\nclass C {}\n";

    expect(PhpFileInspector::imports($src))->toBe(['App\\Real\\Klass']);
});

it('ignores trait use inside a class body', function (): void {
    $src = "<?php\nuse App\\Real\\Imported;\n\nclass C\n{\n    use SomeTrait;\n}\n";

    expect(PhpFileInspector::imports($src))->toBe(['App\\Real\\Imported']);
});

it('ignores closure use', function (): void {
    $src = "<?php\nuse App\\Real\\Imported;\n\$fn = function () use (\$x) { return \$x; };\n";

    expect(PhpFileInspector::imports($src))->toBe(['App\\Real\\Imported']);
});

it('detects class-like declarations for the structure guard', function (): void {
    expect(PhpFileInspector::declaresClass("<?php\nfinal class Foo {}"))->toBeTrue()
        ->and(PhpFileInspector::declaresClass("<?php\nenum Status {}"))->toBeTrue()
        ->and(PhpFileInspector::declaresClass("<?php\nreturn ['a' => 1];"))->toBeFalse()
        ->and(PhpFileInspector::declaresClass("<?php\nfunction helper() {}"))->toBeFalse();
});
