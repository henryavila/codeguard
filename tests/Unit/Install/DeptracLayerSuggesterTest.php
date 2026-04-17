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
    $suggester = new DeptracLayerSuggester(new Filesystem());

    $suggestion = $suggester->suggest('/nonexistent/path/here');

    expect($suggestion)->toBeInstanceOf(LayerSuggestion::class)
        ->and($suggestion->isEmpty())->toBeTrue()
        ->and($suggestion->detectedNamespaces)->toBe([])
        ->and($suggestion->layers)->toBe([])
        ->and($suggestion->ruleset)->toBe([]);
});

it('detects namespaces and groups them into Domain/Application/Persistence layers', function (): void {
    writePhpFile($this->appPath.'/Domain', 'User.php');
    writePhpFile($this->appPath.'/Domain', 'Order.php');
    writePhpFile($this->appPath.'/Services', 'CheckoutService.php');
    writePhpFile($this->appPath.'/Models', 'UserModel.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem()))->suggest($this->appPath);

    expect($suggestion->isEmpty())->toBeFalse()
        ->and($suggestion->detectedNamespaces)->toHaveCount(3);

    $namespaces = array_map(
        static fn ($n) => $n->namespace,
        $suggestion->detectedNamespaces,
    );
    expect($namespaces)->toContain('App\\Domain', 'App\\Services', 'App\\Models');

    expect(array_keys($suggestion->layers))
        ->toContain('Domain', 'Application', 'Persistence');
});

it('sorts detected namespaces by file count descending', function (): void {
    writePhpFile($this->appPath.'/Domain', 'A.php');
    writePhpFile($this->appPath.'/Services', 'B.php');
    writePhpFile($this->appPath.'/Services', 'C.php');
    writePhpFile($this->appPath.'/Services', 'D.php');
    writePhpFile($this->appPath.'/Models', 'E.php');
    writePhpFile($this->appPath.'/Models', 'F.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem()))->suggest($this->appPath);

    $counts = array_map(
        static fn ($n) => $n->fileCount,
        $suggestion->detectedNamespaces,
    );
    expect($counts)->toBe([3, 2, 1]);
});

it('skips empty directories that contain no PHP files', function (): void {
    mkdir($this->appPath.'/Empty', 0o755, true);
    writePhpFile($this->appPath.'/Domain', 'User.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem()))->suggest($this->appPath);

    expect($suggestion->detectedNamespaces)->toHaveCount(1)
        ->and($suggestion->detectedNamespaces[0]->namespace)->toBe('App\\Domain');
});

it('builds a ruleset that only references detected layers', function (): void {
    writePhpFile($this->appPath.'/Domain', 'A.php');
    writePhpFile($this->appPath.'/Services', 'B.php');

    $suggestion = (new DeptracLayerSuggester(new Filesystem()))->suggest($this->appPath);

    expect($suggestion->ruleset)->toHaveKeys(['Domain', 'Application'])
        ->and($suggestion->ruleset)->not->toHaveKey('Persistence')
        ->and($suggestion->ruleset['Application'])->toBe(['Domain'])
        ->and($suggestion->ruleset['Domain'])->toBe([]);
});

it('toYaml produces an empty scaffold when no namespaces are detected', function (): void {
    $suggester = new DeptracLayerSuggester(new Filesystem());
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

    $suggester = new DeptracLayerSuggester(new Filesystem());
    $yaml = $suggester->toYaml($suggester->suggest($this->appPath));

    expect($yaml)->toContain('deptrac:')
        ->and($yaml)->toContain('name: Domain')
        ->and($yaml)->toContain('name: Application')
        ->and($yaml)->toContain('classLike')
        ->and($yaml)->toContain('regex:')
        ->and($yaml)->toContain('ruleset:');
});
