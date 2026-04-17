<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Post-processes the published `phpstan.neon` file to comment out sections
 * for extensions the user did NOT enable. The stub ships with all sections
 * active + sentinel markers identifying each extension; this applier flips
 * disabled sections to commented lines.
 *
 * Inline sentinels (single-line include entries):
 *   - vendor/foo/bar/extension.neon  # @codeguard:ext=NAME
 *
 * Block sentinels (multi-line parameter blocks):
 *   # @codeguard:ext=NAME:params:start
 *   ...
 *   # @codeguard:ext=NAME:params:end
 */
final class PhpstanExtensionApplier
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @param  list<PhpstanExtension>  $enabled
     */
    public function apply(string $phpstanNeonPath, array $enabled): void
    {
        if (! $this->filesystem->exists($phpstanNeonPath)) {
            return;
        }

        $enabledValues = array_map(
            static fn (PhpstanExtension $ext): string => $ext->value,
            $enabled,
        );

        $content = $this->filesystem->get($phpstanNeonPath);
        $content = $this->toggleInlineSentinels($content, $enabledValues);
        $content = $this->toggleBlockSentinels($content, $enabledValues);

        $this->filesystem->put($phpstanNeonPath, $content);
    }

    /**
     * @param  list<string>  $enabledValues
     */
    private function toggleInlineSentinels(string $content, array $enabledValues): string
    {
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            if (preg_match('/#\s*@codeguard:ext=([a-z-]+)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $extensionName = $matches[1];
            $isEnabled = in_array($extensionName, $enabledValues, strict: true);
            $isCurrentlyCommented = $this->isLineCommented($line);

            if ($isEnabled && $isCurrentlyCommented) {
                $lines[$index] = $this->uncommentLine($line);
            } elseif (! $isEnabled && ! $isCurrentlyCommented) {
                $lines[$index] = $this->commentLine($line);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $enabledValues
     */
    private function toggleBlockSentinels(string $content, array $enabledValues): string
    {
        $pattern = '/^([ \t]*)#\s*@codeguard:ext=([a-z-]+):params:start\s*$/m';

        if (preg_match_all($pattern, $content, $starts, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return $content;
        }

        // Process in reverse order so byte offsets remain valid after mutations.
        $starts = array_reverse($starts);

        foreach ($starts as $startMatch) {
            $extensionName = $startMatch[2][0];
            $startOffset = (int) $startMatch[0][1];
            $indent = $startMatch[1][0];

            $endPattern = sprintf(
                '/^%s#\s*@codeguard:ext=%s:params:end\s*$/m',
                preg_quote($indent, '/'),
                preg_quote($extensionName, '/'),
            );

            if (preg_match($endPattern, $content, $endMatch, PREG_OFFSET_CAPTURE, $startOffset) !== 1) {
                continue;
            }

            $endOffset = (int) $endMatch[0][1] + strlen($endMatch[0][0]);
            $blockLength = $endOffset - $startOffset;
            $block = substr($content, $startOffset, $blockLength);

            $isEnabled = in_array($extensionName, $enabledValues, strict: true);

            $newBlock = $isEnabled
                ? $this->uncommentBlockBody($block)
                : $this->commentBlockBody($block);

            $content = substr_replace($content, $newBlock, $startOffset, $blockLength);
        }

        return $content;
    }

    private function isLineCommented(string $line): bool
    {
        $trimmed = ltrim($line);

        return str_starts_with($trimmed, '#');
    }

    private function commentLine(string $line): string
    {
        if ($this->isLineCommented($line)) {
            return $line;
        }

        if (preg_match('/^(\s*)(.*)$/s', $line, $matches) === 1) {
            $leading = $matches[1];
            $rest = $matches[2];

            return $leading.'# '.$rest;
        }

        return '# '.$line;
    }

    private function uncommentLine(string $line): string
    {
        return preg_replace('/^(\s*)#\s?/', '$1', $line, 1) ?? $line;
    }

    /**
     * Comment out every line between (exclusive) the start and end sentinels.
     * Sentinel lines themselves are preserved intact.
     */
    private function commentBlockBody(string $block): string
    {
        $lines = explode("\n", $block);
        $lineCount = count($lines);

        for ($i = 1; $i < $lineCount - 1; $i++) {
            if ($lines[$i] === '' || $this->isLineCommented($lines[$i])) {
                continue;
            }

            $lines[$i] = $this->commentLine($lines[$i]);
        }

        return implode("\n", $lines);
    }

    private function uncommentBlockBody(string $block): string
    {
        $lines = explode("\n", $block);
        $lineCount = count($lines);

        for ($i = 1; $i < $lineCount - 1; $i++) {
            if ($lines[$i] === '' || ! $this->isLineCommented($lines[$i])) {
                continue;
            }

            $lines[$i] = $this->uncommentLine($lines[$i]);
        }

        return implode("\n", $lines);
    }
}
