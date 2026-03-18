import type { AnalysisViolation, ToolError } from './violations.js';

export interface FormatterContext {
  violations: AnalysisViolation[];
  errors: ToolError[];
  baselineCount: number;  // how many violations were baselined
  totalFiles: number;
  scope: 'hook' | 'scan' | 'review';
}

// Strategy pattern — Terminal and Markdown implementations
export interface OutputFormatter {
  formatFindings(context: FormatterContext): string;
  formatError(error: ToolError): string;
  formatSummary(context: FormatterContext): string;
}
