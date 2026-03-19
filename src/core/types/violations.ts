export type Severity = 'critical' | 'warning' | 'suggestion';

export interface AnalysisViolation {
  tool: string;
  rule: string;
  severity: Severity;
  file: string;
  line: number;
  column?: number;
  message: string;
  standard?: string;
  reference?: string;
  fixable?: boolean;
}

export interface ToolError {
  tool: string;
  reason: string;
  fix: string;
}

export type ToolResult =
  | { success: true; violations: AnalysisViolation[] }
  | { success: false; error: ToolError };

export interface AnalysisResult {
  violations: AnalysisViolation[];
  errors: ToolError[];
  timestamp: string;
}
