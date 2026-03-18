// Severity levels per UX specification
export type Severity = 'critical' | 'warning' | 'suggestion';

// Normalized violation — unified format across all tools (PHPStan, Pint, PHPMD)
export interface AnalysisViolation {
  tool: string;           // e.g. 'phpstan', 'pint', 'phpmd'
  rule: string;           // tool-specific rule identifier
  severity: Severity;
  file: string;           // relative to project root (never absolute)
  line: number;
  column?: number;        // optional — reported by PHPStan, PHPMD
  message: string;        // normalized message (line numbers/abs paths stripped)
  standard: string;       // project standard being violated
  reference: string;      // CODEGUARD.md section reference
  fixable?: boolean;      // true if tool can auto-fix (e.g. Pint)
}

// Tool-level error (tool crash, missing binary, etc.)
export interface ToolError {
  tool: string;
  reason: string;
  fix: string;            // actionable next step for developer
}

// Result type pattern — no exceptions for flow control
export type ToolResult =
  | { success: true; violations: AnalysisViolation[] }
  | { success: false; error: ToolError };

// Aggregate result from the analysis pipeline
export interface AnalysisResult {
  violations: AnalysisViolation[];
  errors: ToolError[];
  timestamp: string;      // ISO 8601
}
