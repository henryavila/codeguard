<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * R3 namespace→dependency graph. Parses the scoped files' top-level `use`
 * statements (via {@see PhpFileInspector}) into a first-party adjacency map and
 * pre-computes circular-dependency groups.
 *
 * This is the cross-file context the three architectural patterns
 * (layer-dependency-direction, bounded-contexts, no-circular-dependencies)
 * need but a per-file review cannot see. It is emitted in the work order so the
 * reviewing subagent can judge dependency direction and module boundaries, and
 * the deterministically-detected cycles are handed over as a hint.
 *
 * "First-party" = an edge is kept only when its target FQCN is itself a scoped
 * node, so vendor imports (Illuminate, Symfony, …) never pollute the graph.
 * With a narrow `--changed-only` scope the graph is partial by construction.
 */
final class NamespaceGraph
{
    /**
     * @param  list<string>  $files  Absolute paths.
     * @return array{nodes: list<array{fqcn: string, file: string}>, edges: list<array{from: string, to: string}>, cycles: list<list<string>>}
     */
    public function build(array $files, string $workingDirectory): array
    {
        $sources = [];
        foreach ($files as $file) {
            $contents = is_file($file) ? (file_get_contents($file) ?: '') : '';
            $sources[$this->relative($file, $workingDirectory)] = $contents;
        }

        return $this->fromSources($sources);
    }

    /**
     * @param  array<string, string>  $sources  relativePath => file contents
     * @return array{nodes: list<array{fqcn: string, file: string}>, edges: list<array{from: string, to: string}>, cycles: list<list<string>>}
     */
    public function fromSources(array $sources): array
    {
        /** @var array<string, string> $nodeFile  fqcn => relative path */
        $nodeFile = [];
        /** @var array<string, list<string>> $importsByFqcn */
        $importsByFqcn = [];

        foreach ($sources as $relative => $contents) {
            $fqcn = PhpFileInspector::fqcn($contents);
            if ($fqcn === null) {
                continue;
            }
            $nodeFile[$fqcn] = $relative;
            $importsByFqcn[$fqcn] = PhpFileInspector::imports($contents);
        }

        $edges = [];
        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];
        foreach ($importsByFqcn as $from => $imports) {
            foreach ($imports as $to) {
                if ($to !== $from && array_key_exists($to, $nodeFile)) {
                    $edges[] = ['from' => $from, 'to' => $to];
                    $adjacency[$from][] = $to;
                }
            }
        }

        $nodes = [];
        foreach ($nodeFile as $fqcn => $relative) {
            $nodes[] = ['fqcn' => $fqcn, 'file' => $relative];
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'cycles' => $this->findCycles($adjacency),
        ];
    }

    /**
     * Depth-first cycle detection. Returns a representative set of dependency
     * cycles (not guaranteed exhaustive — enough to flag entanglement to the
     * reviewer). Each cycle is the node path between a back-edge's endpoints.
     *
     * @param  array<string, list<string>>  $adjacency
     * @return list<list<string>>
     */
    private function findCycles(array $adjacency): array
    {
        $cycles = [];
        /** @var array<string, bool> $seen */
        $seen = [];
        /** @var array<string, bool> $visited */
        $visited = [];

        foreach (array_keys($adjacency) as $node) {
            if (! isset($visited[$node])) {
                $this->walk($node, $adjacency, $visited, [], [], $cycles, $seen);
            }
        }

        return $cycles;
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, bool>  $visited
     * @param  array<string, int>  $stackPos  node => index in $path (the active recursion path)
     * @param  list<string>  $path
     * @param  list<list<string>>  $cycles
     * @param  array<string, bool>  $seen
     */
    private function walk(string $node, array $adjacency, array &$visited, array $stackPos, array $path, array &$cycles, array &$seen): void
    {
        $stackPos[$node] = count($path);
        $path[] = $node;

        foreach ($adjacency[$node] ?? [] as $next) {
            if (isset($stackPos[$next])) {
                $cycle = array_slice($path, $stackPos[$next]);
                $key = $this->cycleKey($cycle);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $cycles[] = $cycle;
                }
            } elseif (! isset($visited[$next])) {
                $this->walk($next, $adjacency, $visited, $stackPos, $path, $cycles, $seen);
            }
        }

        $visited[$node] = true;
    }

    /**
     * @param  list<string>  $cycle
     */
    private function cycleKey(array $cycle): string
    {
        $sorted = $cycle;
        sort($sorted);

        return implode('|', $sorted);
    }

    private function relative(string $absolute, string $workingDirectory): string
    {
        $prefix = rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return str_replace('\\', '/', $relative);
    }
}
