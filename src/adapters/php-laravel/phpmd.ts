import type {
  ToolAdapter,
  CommandSpec,
  ToolConfig,
  ToolResult,
  AnalysisViolation,
} from '../../core/types/index.js';

interface PhpmdViolation {
  beginLine: number;
  endLine: number;
  rule: string;
  ruleSet: string;
  priority: number;
  description: string;
}

interface PhpmdFile {
  file: string;
  violations: PhpmdViolation[];
}

interface PhpmdJsonOutput {
  version: string;
  package: string;
  timestamp: string;
  files: PhpmdFile[];
}

export const phpmdAdapter: ToolAdapter = {
  name: 'phpmd',
  binary: 'vendor/bin/phpmd',
  supportsFix: false,

  buildCommand(files: string[], config: ToolConfig): CommandSpec {
    const rulesets =
      config.rulesets && config.rulesets.length > 0
        ? config.rulesets.join(',')
        : 'unusedcode,codesize';

    // PHPMD accepts comma-separated file list as first arg
    const args = [files.join(','), 'json', rulesets];

    return { binary: config.binary, args, timeout: 60_000 };
  },

  parseOutput(raw: string): ToolResult {
    let data: PhpmdJsonOutput;
    try {
      data = JSON.parse(raw) as PhpmdJsonOutput;
    } catch {
      return {
        success: false,
        error: {
          tool: 'phpmd',
          reason: 'Failed to parse PHPMD JSON output',
          fix: 'Ensure PHPMD is installed and produces valid JSON',
        },
      };
    }

    const violations: AnalysisViolation[] = [];

    for (const fileEntry of data.files) {
      for (const v of fileEntry.violations) {
        violations.push({
          tool: 'phpmd',
          rule: `${v.ruleSet}.${v.rule}`,
          severity: 'warning',
          file: fileEntry.file,
          line: v.beginLine,
          message: v.description,
          fixable: false,
        });
      }
    }

    return { success: true, violations };
  },

  filterToStaged(
    violations: AnalysisViolation[],
    stagedFiles: string[],
  ): AnalysisViolation[] {
    // PHPMD runs only on staged files, but filter anyway for safety
    const staged = new Set(stagedFiles);
    return violations.filter((v) => staged.has(v.file));
  },
};
