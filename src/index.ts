// Main entry point — re-exports core types for public API
export type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
  ToolConfig,
  PresetConfig,
  HookConfig,
  BaselineConfig,
  CodeGuardConfig,
  DetectionResult,
  PatternDefinition,
  CodeGuardModule,
  ToolAdapter,
  FormatterContext,
  OutputFormatter,
} from './core/types/index.js';
