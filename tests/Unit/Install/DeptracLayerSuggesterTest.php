<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\DeptracLayerSuggester;
use Henryavila\Codeguard\Install\LayerSuggestion;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-deptrac-'.uniqid();
    mkdir($this->tempDir, 0o755, true);

    $this->appPath = $this->tempDir.DIRECTORY_SEPARATOR.'app';
    mkdir($this->appPath, 0o755, true);
});

afterEach(function (): void {
    deleteRecursive($this->tempDir);
});

function deleteRecursive(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    $entries = scandir($path) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        deleteRecursive($path.DIRECTORY_SEPARATOR.$entry);
    }
    rmdir($path);
}

function writePhpFile(string $dir, string $name): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents(
        $dir.DIRECTORY_SEPARATOR.$name,
        "<?php\n\nclass {$name}Class {}\n",
    );
}

it('returns an empty suggestion when the app directory does not exist', function (): void {
    $suggester = new DeptracLayerSuggester(new Filesystem);

    $suggestion = $suggester->suggest('/nonexistent/path/here');

    expect($suggestion)->toBeInstanceOf(LayerSuggestion::class)
        ->and($suggestion->isEmpty())->toBeTrue()
        ->and($suggestion->detectedNamespaces)->toBe([])
        ->and($suggestion->layers)->toBe([])
        ->and($suggestion->ruleset)->toBe([]);
});

it('detects namespaces and groups them into four Laravel layers', function (): void {
    writePhpFile($this->appPath.'/Domain', 'User.php');
    writePhpFile($this->appPath.'/Domain', 'Order.php');
    writePhpFile($this->appPath.'/Services', 'CheckoutService.php');
    writePhpFile($this->appPath.'/Models', 'UserModel.php');
    writePhpFile($this->appPath.'/Livewire', 'Dashboard.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    expect($suggestion->isEmpty())->toBeFalse()
        ->and($suggestion->detectedNamespaces)->toHaveCount(4);

    $namespaces = array_map(
        static fn ($n) => $n->namespace,
        $suggestion->detectedNamespaces,
    );
    expect($namespaces)->toContain('App\\Domain', 'App\\Services', 'App\\Models', 'App\\Livewire');

    expect(array_keys($suggestion->layers))
        ->toContain('Domain', 'Application', 'Infrastructure', 'Presentation');
});

it('auto-suggests Skip for bootstrap/cross-cutting namespaces', function (): void {
    writePhpFile($this->appPath.'/Providers', 'AppServiceProvider.php');
    writePhpFile($this->appPath.'/Exceptions', 'Handler.php');
    writePhpFile($this->appPath.'/Traits', 'HasUuid.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    $suggestedLayers = array_map(
        static fn ($n) => $n->suggestedLayer,
        $suggestion->detectedNamespaces,
    );

    expect($suggestedLayers)->each->toBe('__skip__');

    // Skip namespaces must NOT appear in any layer regex.
    foreach ($suggestion->layers as $patterns) {
        foreach ($patterns as $pattern) {
            expect($pattern)->not->toContain('Providers')
                ->and($pattern)->not->toContain('Exceptions')
                ->and($pattern)->not->toContain('Traits');
        }
    }
});

it('classifies UI namespaces (Livewire, Filament) as Presentation', function (): void {
    writePhpFile($this->appPath.'/Livewire', 'OrderForm.php');
    writePhpFile($this->appPath.'/Filament', 'UserResource.php');
    writePhpFile($this->appPath.'/Http', 'Controller.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    foreach ($suggestion->detectedNamespaces as $ns) {
        expect($ns->suggestedLayer)->toBe('Presentation');
    }
});

it('classifies Notifications and Listeners as Application', function (): void {
    writePhpFile($this->appPath.'/Notifications', 'OrderShipped.php');
    writePhpFile($this->appPath.'/Listeners', 'SendInvoice.php');
    writePhpFile($this->appPath.'/Observers', 'UserObserver.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    foreach ($suggestion->detectedNamespaces as $ns) {
        expect($ns->suggestedLayer)->toBe('Application');
    }
});

it('classifies ValueObjects and Policies as Domain', function (): void {
    writePhpFile($this->appPath.'/ValueObjects', 'Money.php');
    writePhpFile($this->appPath.'/Policies', 'ArticlePolicy.php');
    writePhpFile($this->appPath.'/Contracts', 'Repository.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    foreach ($suggestion->detectedNamespaces as $ns) {
        expect($ns->suggestedLayer)->toBe('Domain');
    }
});

it('classifies project-specific Infrastructure namespaces via heuristic', function (): void {
    writePhpFile($this->appPath.'/ExternalApi', 'StripeClient.php');
    writePhpFile($this->appPath.'/Configurators', 'QueueConfigurator.php');
    writePhpFile($this->appPath.'/Upgrades', 'Upgrade_2026.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    foreach ($suggestion->detectedNamespaces as $ns) {
        expect($ns->suggestedLayer)->toBe('Infrastructure');
    }
});

it('sorts detected namespaces by file count descending', function (): void {
    writePhpFile($this->appPath.'/Domain', 'A.php');
    writePhpFile($this->appPath.'/Services', 'B.php');
    writePhpFile($this->appPath.'/Services', 'C.php');
    writePhpFile($this->appPath.'/Services', 'D.php');
    writePhpFile($this->appPath.'/Models', 'E.php');
    writePhpFile($this->appPath.'/Models', 'F.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    $counts = array_map(
        static fn ($n) => $n->fileCount,
        $suggestion->detectedNamespaces,
    );
    expect($counts)->toBe([3, 2, 1]);
});

it('skips empty directories that contain no PHP files', function (): void {
    mkdir($this->appPath.'/Empty', 0o755, true);
    writePhpFile($this->appPath.'/Domain', 'User.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    expect($suggestion->detectedNamespaces)->toHaveCount(1)
        ->and($suggestion->detectedNamespaces[0]->namespace)->toBe('App\\Domain');
});

it('builds a ruleset that only references detected layers', function (): void {
    writePhpFile($this->appPath.'/Domain', 'A.php');
    writePhpFile($this->appPath.'/Services', 'B.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    expect($suggestion->ruleset)->toHaveKeys(['Domain', 'Application'])
        ->and($suggestion->ruleset)->not->toHaveKey('Infrastructure')
        ->and($suggestion->ruleset)->not->toHaveKey('Presentation')
        ->and($suggestion->ruleset['Application'])->toBe(['Domain'])
        ->and($suggestion->ruleset['Domain'])->toBe([]);
});

it('builds Presentation ruleset allowing Application and Domain when all present', function (): void {
    writePhpFile($this->appPath.'/Domain', 'A.php');
    writePhpFile($this->appPath.'/Services', 'B.php');
    writePhpFile($this->appPath.'/Http', 'C.php');
    writePhpFile($this->appPath.'/Models', 'D.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem))->suggest($this->appPath);

    expect($suggestion->ruleset)->toHaveKeys(['Domain', 'Application', 'Presentation', 'Infrastructure'])
        ->and($suggestion->ruleset['Presentation'])->toBe(['Application', 'Domain'])
        ->and($suggestion->ruleset['Infrastructure'])->toBe(['Domain'])
        ->and($suggestion->ruleset['Application'])->toBe(['Domain'])
        ->and($suggestion->ruleset['Domain'])->toBe([]);
});

it('toYaml produces an empty scaffold when no namespaces are detected', function (): void {
    $suggester = new DeptracLayerSuggester(new Filesystem);
    $empty = new LayerSuggestion(detectedNamespaces: [], layers: [], ruleset: []);

    $yaml = $suggester->toYaml($empty);

    expect($yaml)->toContain('deptrac:')
        ->and($yaml)->toContain('paths:')
        ->and($yaml)->toContain('./app')
        ->and($yaml)->toContain('layers: []')
        ->and($yaml)->toContain('ruleset: []');
});

it('toYaml emits deptrac layers, collectors and ruleset for detected namespaces', function (): void {
    writePhpFile($this->appPath.'/Domain', 'User.php');
    writePhpFile($this->appPath.'/Services', 'CheckoutService.php');

    $suggester = new DeptracLayerSuggester(new Filesystem);
    $yaml = $suggester->toYaml($suggester->suggest($this->appPath));

    expect($yaml)->toContain('deptrac:')
        ->and($yaml)->toContain('name: Domain')
        ->and($yaml)->toContain('name: Application')
        ->and($yaml)->toContain('classLike')
        ->and($yaml)->toContain('value:')  // Deptrac 2.x classLike key (NOT 'regex:'; misleading error message)
        ->and($yaml)->toContain('ruleset:');
});
