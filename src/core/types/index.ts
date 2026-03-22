export type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
} from './violations.js';

export type {
  Enforcement,
  ToolConfig,
  CapabilityConfig,
  PatternsConfig,
  ThresholdsConfig,
  HookConfig,
  BaselineConfig,
  ProjectConfig,
  CodeGuardConfig,
} from './config.js';

export type {
  CommandSpec,
  DetectionSignal,
  PatternDetection,
  PatternVerification,
  PatternExamples,
  PatternDefinition,
  ModuleCapability,
  ModuleDetection,
  ModuleDefinition,
  PresetTool,
  PresetDefinition,
  DetectionResult,
  ToolAdapter,
} from './modules.js';

export type {
  FormatterContext,
  OutputFormatter,
} from './output.js';

export type { Result } from './result.js';

export type {
  BaselineEntry,
  BaselineFile,
} from './baseline.js';
