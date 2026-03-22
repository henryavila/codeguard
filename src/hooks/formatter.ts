import chalk from 'chalk';

import type {
  OutputFormatter,
  FormatterContext,
  AnalysisViolation,
  ToolError,
} from '../core/types/index.js';

function severitySymbol(v: AnalysisViolation, enforcement: string): string {
  if (enforcement === 'block' || v.severity === 'critical') {
    return chalk.red('✗');
  }
  if (v.severity === 'warning') {
    return chalk.yellow('⚠');
  }
  return chalk.blue('→');
}

export const hookFormatter: OutputFormatter = {
  formatFindings(context: FormatterContext): string {
    const lines: string[] = [];
    lines.push(chalk.bold('codeguard · pre-commit'));
    lines.push('');

    for (const v of context.violations) {
      const symbol = severitySymbol(v, 'block');
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
      (v) => v.severity === 'critical',
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
