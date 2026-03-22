import type {
  ToolAdapter,
  CommandSpec,
  ToolConfig,
  ToolResult,
  AnalysisViolation,
} from '../../core/types/index.js';

// Regex to parse Pest FAIL lines, e.g.:
// FAIL  Tests\Architecture\CodeGuardArchTest > arch: controllers should not depend on models
const FAIL_LINE_RE =
  /FAIL\s+(.+?)\s+>\s+(.+)/;

// Regex to extract file:line from the "at" line following a FAIL
const AT_LINE_RE = /at\s+(.+?):(\d+)/;

export const pestAdapter: ToolAdapter = {
  name: 'pest',
  binary: 'vendor/bin/pest',
  supportsFix: false,

  buildCommand(_files: string[], config: ToolConfig): CommandSpec {
    const directory = config.directory ?? 'tests/Architecture';
    const args = [directory, '--colors=never'];
    return { binary: config.binary, args, timeout: 120_000 };
  },

  parseOutput(raw: string): ToolResult {
    const lines = raw.split('\n');
    const violations: AnalysisViolation[] = [];

    for (let i = 0; i < lines.length; i++) {
      const failMatch = FAIL_LINE_RE.exec(lines[i]);
      if (!failMatch) continue;

      const testClass = failMatch[1];
      const testName = failMatch[2];

      // Look ahead for "at file:line" info
      let file = testClass.replace(/\\/g, '/');
      let line = 0;

      for (let j = i + 1; j < Math.min(i + 5, lines.length); j++) {
        const atMatch = AT_LINE_RE.exec(lines[j]);
        if (atMatch) {
          file = atMatch[1];
          line = parseInt(atMatch[2], 10);
          break;
        }
      }

      violations.push({
        tool: 'pest',
        rule: `arch.${testName.replace(/\s+/g, '-').toLowerCase()}`,
        severity: 'critical',
        file,
        line,
        message: `Arch test failed: ${testName}`,
        fixable: false,
      });
    }

    return { success: true, violations };
  },

  filterToStaged(
    violations: AnalysisViolation[],
    _stagedFiles: string[],
  ): AnalysisViolation[] {
    // Arch tests are project-wide, not file-scoped — return all
    return violations;
  },
};
