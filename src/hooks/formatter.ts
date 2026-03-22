import chalk from 'chalk';

import type {
  Enforcement,
  OutputFormatter,
  FormatterContext,
  AnalysisViolation,
  ToolError,
} from '../core/types/index.js';

function enforcementForViolation(
  v: AnalysisViolation,
  toolEnforcement?: Record<string, Enforcement>,
): Enforcement {
  return toolEnforcement?.[v.tool] ?? 'block';
}

function violationSymbol(
  v: AnalysisViolation,
  toolEnforcement?: Record<string, Enforcement>,
): string {
  const enforcement = enforcementForViolation(v, toolEnforcement);
  if (enforcement === 'block') return chalk.red('✗');
  if (enforcement === 'warn') return chalk.yellow('⚠');
  return chalk.blue('→');
}

export const hookFormatter: OutputFormatter = {
  formatFindings(context: FormatterContext): string {
    const lines: string[] = [];
    lines.push(chalk.bold('codeguard · pre-commit'));
    lines.push('');

    for (const v of context.violations) {
      const symbol = violationSymbol(v, context.toolEnforcement);
      lines.push(`  ${symbol} ${v.file}:${v.line}`);
      lines.push(`    ${capitalize(v.tool)}: ${v.message}`);
      lines.push('');
    }

    for (const err of context.errors) {
      lines.push(`  ${chalk.yellow('⚠')} ${err.tool} failed: ${err.reason}`);
      lines.push('');
    }

    return lines.join('\n');
  },

  formatError(error: ToolError): string {
    return `  ${chalk.yellow('⚠')} ${error.tool} failed: ${error.reason}`;
  },

  formatSummary(context: FormatterContext): string {
    const total = context.violations.length + context.errors.length;
    const blocking = context.violations.filter(
      (v) => enforcementForViolation(v, context.toolEnforcement) === 'block',
    ).length;

    let summary: string;
    if (total === 0) {
      summary = chalk.green('✓ All checks passed');
    } else {
      const parts = [`${total} findings`, `${blocking} blocking`];
      const outcome =
        blocking > 0
          ? chalk.red('commit blocked')
          : chalk.green('commit passed');
      parts.push(outcome);
      summary = `  ${parts.join(' · ')}`;
    }

    if (context.baselineCount > 0) {
      summary += chalk.dim(
        ` (${context.baselineCount} baselined, suppressed)`,
      );
    }

    return summary;
  },
};

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
