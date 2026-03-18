// Barrel re-export — named exports only
export type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
} from './violations.js';

export type {
  ToolConfig,
  PresetConfig,
  HookConfig,
  BaselineConfig,
  CodeGuardConfig,
} from './config.js';

export type {
  DetectionResult,
  PatternDefinition,
  CodeGuardModule,
  ToolAdapter,
} from './modules.js';

export type {
  FormatterContext,
  OutputFormatter,
} from './output.js';
