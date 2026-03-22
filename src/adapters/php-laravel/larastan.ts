import type {
  ToolAdapter,
  CommandSpec,
  ToolConfig,
  ToolResult,
  AnalysisViolation,
} from '../../core/types/index.js';

interface PhpStanFile {
  errors: number;
  messages: PhpStanMessage[];
}

interface PhpStanMessage {
  message: string;
  line: number;
  ignorable: boolean;
  identifier?: string;
}

interface PhpStanJsonOutput {
  totals: { errors: number; file_errors: number };
  files: Record<string, PhpStanFile>;
  errors: string[];
}

export const larastanAdapter: ToolAdapter = {
  name: 'larastan',
  binary: 'vendor/bin/phpstan',
  supportsFix: false,

  buildCommand(_files: string[], config: ToolConfig): CommandSpec {
    const args = ['analyse', '--error-format=json', '--no-progress'];
    if (config.level !== undefined) {
      args.push(`--level=${config.level}`);
    }
    if (config.config) {
      args.push(`--configuration=${config.config}`);
    }
    return { binary: config.binary, args, timeout: 120_000 };
  },

  parseOutput(raw: string): ToolResult {
    let data: PhpStanJsonOutput;
    try {
      data = JSON.parse(raw) as PhpStanJsonOutput;
    } catch {
      return {
        success: false,
        error: {
          tool: 'larastan',
          reason: 'Failed to parse PHPStan JSON output',
          fix: 'Ensure PHPStan is configured correctly and produces valid JSON',
        },
      };
    }

    if (data.errors && data.errors.length > 0) {
      return {
        success: false,
        error: {
          tool: 'larastan',
          reason: data.errors.join('; '),
          fix: 'Fix PHPStan configuration errors',
        },
      };
    }

    const violations: AnalysisViolation[] = [];

    for (const [filePath, fileData] of Object.entries(data.files)) {
      for (const msg of fileData.messages) {
        violations.push({
          tool: 'larastan',
          rule: msg.identifier ?? 'unknown',
          severity: 'critical',
          file: filePath,
          line: msg.line,
          message: msg.message,
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
    const staged = new Set(stagedFiles);
    return violations.filter((v) => staged.has(v.file));
  },
};
