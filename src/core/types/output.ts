import type { Enforcement } from './config.js';
import type { AnalysisViolation, ToolError } from './violations.js';

export interface FormatterContext {
  violations: AnalysisViolation[];
  errors: ToolError[];
  baselineCount: number;
  totalFiles: number;
  scope: 'hook' | 'run' | 'health';
  toolEnforcement?: Record<string, Enforcement>;
}

export interface OutputFormatter {
  formatFindings(context: FormatterContext): string;
  formatError(error: ToolError): string;
  formatSummary(context: FormatterContext): string;
}
