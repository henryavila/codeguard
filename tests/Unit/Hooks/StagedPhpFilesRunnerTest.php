<?php

declare(strict_types=1);

use CaptainHook\App\Config;
use CaptainHook\App\Console\IO;
use CaptainHook\App\Exception\ActionFailed;
use Henryavila\Codeguard\Hooks\StagedPhpFilesRunner;
use SebastianFeldmann\Git\Operator\Index;
use SebastianFeldmann\Git\Repository;

beforeEach(function (): void {
    $this->runner = new StagedPhpFilesRunner;

    $this->config = Mockery::mock(Config::class);
    $this->io = Mockery::mock(IO::class);
    $this->io->shouldReceive('write')->byDefault();

    $this->index = Mockery::mock(Index::class);
    $this->repository = Mockery::mock(Repository::class);
    $this->repository->shouldReceive('getIndexOperator')->andReturn($this->index);
});

afterEach(function (): void {
    Mockery::close();
});

function makeAction(array $options = []): Config\Action
{
    return new Config\Action(
        action: StagedPhpFilesRunner::class,
        options: $options,
    );
}

it('is a no-op when no PHP files are staged', function (): void {
    $this->index->shouldReceive('getStagedFilesOfType')->with('php')->andReturn([]);
    $this->io->shouldReceive('write')
        ->once()
        ->with(Mockery::pattern('/No staged PHP files/'), true);

    expect(fn () => $this->runner->execute($this->config, $this->io, $this->repository, makeAction()))
        ->not->toThrow(Throwable::class);
});

it('runs the configured binary and succeeds when exit code is zero', function (): void {
    $this->index->shouldReceive('getStagedFilesOfType')->with('php')->andReturn(['src/Foo.php']);

    $action = makeAction([
        'binary' => PHP_BINARY,
        'flags' => ['-r', 'exit(0);'],
    ]);

    expect(fn () => $this->runner->execute($this->config, $this->io, $this->repository, $action))
        ->not->toThrow(Throwable::class);
});

it('throws ActionFailed when the configured binary exits non-zero', function (): void {
    $this->index->shouldReceive('getStagedFilesOfType')->with('php')->andReturn(['src/Foo.php']);

    $action = makeAction([
        'binary' => PHP_BINARY,
        'flags' => ['-r', 'exit(1);'],
    ]);

    expect(fn () => $this->runner->execute($this->config, $this->io, $this->repository, $action))
        ->toThrow(ActionFailed::class);
});

it('passes staged files as the final arguments to the binary', function (): void {
    $this->index->shouldReceive('getStagedFilesOfType')
        ->with('php')
        ->andReturn(['src/Foo.php', 'src/Bar.php']);

    $capturedOutput = '';
    $this->io->shouldReceive('write')->andReturnUsing(
        function (string $buffer) use (&$capturedOutput): void {
            $capturedOutput .= $buffer;
        },
    );

    // Echo the argv (excluding the binary) so we can assert the order.
    $action = makeAction([
        'binary' => PHP_BINARY,
        'flags' => ['-r', 'echo implode("|", array_slice($argv, 1));'],
    ]);

    $this->runner->execute($this->config, $this->io, $this->repository, $action);

    expect($capturedOutput)->toContain('src/Foo.php')
        ->and($capturedOutput)->toContain('src/Bar.php');
});

it('defaults to vendor/bin/phpstan when no binary option is provided', function (): void {
    $this->index->shouldReceive('getStagedFilesOfType')->with('php')->andReturn([]);
    $this->io->shouldReceive('write')
        ->once()
        ->with(Mockery::pattern('/phpstan/'), true);

    expect(fn () => $this->runner->execute($this->config, $this->io, $this->repository, makeAction()))
        ->not->toThrow(Throwable::class);
});
