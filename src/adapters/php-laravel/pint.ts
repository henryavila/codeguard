import type {
  ToolAdapter,
  CommandSpec,
  ToolConfig,
  ToolResult,
  AnalysisViolation,
} from '../../core/types/index.js';

export const pintAdapter: ToolAdapter = {
  name: 'pint',
  binary: 'vendor/bin/pint',
  supportsFix: true,

  buildCommand(files: string[], config: ToolConfig): CommandSpec {
    const args = [...files];
    if (config.config) {
      args.push(`--config=${config.config}`);
    }
    if (config.preset) {
      args.push(`--preset=${config.preset}`);
    }
    return { binary: config.binary, args, timeout: 60_000 };
  },

  parseOutput(_raw: string): ToolResult {
    // Pint in autofix mode modifies files directly.
    // No violations to report — autofix has no findings.
    return { success: true, violations: [] };
  },

  filterToStaged(
    violations: AnalysisViolation[],
    _stagedFiles: string[],
  ): AnalysisViolation[] {
    // Passthrough — Pint only runs on staged files already
    return violations;
  },
};
