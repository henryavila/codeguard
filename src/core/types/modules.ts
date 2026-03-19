import type { ToolConfig } from './config.js';
import type { AnalysisViolation, ToolResult } from './violations.js';

export interface CommandSpec {
  binary: string;
  args: string[];
  cwd?: string;
  timeout?: number;
}

export interface DetectionSignal {
  type: 'directory' | 'file' | 'dependency' | 'import';
  value: string;
}

export interface PatternDetection {
  signals: DetectionSignal[];
  confidence: 'high' | 'medium' | 'low';
}

export interface PatternVerification {
  rules: string[];
}

export interface PatternExamples {
  correct: string;
  violation: string;
}

export interface PatternDefinition {
  name: string;
  description: string;
  category: 'architecture' | 'clean-code' | 'solid' | 'ddd' | 'php' | 'framework';
  layer: 'core' | 'php' | 'laravel';
  severity: 'critical' | 'warning' | 'suggestion';
  classification: 'mvp' | 'roadmap';
  detection: PatternDetection;
  verification: PatternVerification;
  examples: PatternExamples;
  related_patterns?: string[];
}

export interface ModuleCapability {
  tool: string;
  default_level?: number;
  preset?: string;
  rulesets?: string[];
  presets?: string[];
}

export interface ModuleDetection {
  files: string[];
  dependencies?: string[];
  confidence: 'high' | 'medium' | 'low';
}

export interface ModuleDefinition {
  name: string;
  label: string;
  language: string;
  framework: string;
  detection: ModuleDetection;
  capabilities: Record<string, ModuleCapability>;
}

export interface PresetTool {
  binary: string;
  config?: string;
  level?: number;
  preset?: string;
  rulesets?: string[];
  extensions?: string[];
  directory?: string;
}

export interface PresetDefinition {
  tools: Record<string, PresetTool>;
  install_commands: string[];
}

export interface DetectionResult {
  detected: boolean;
  module: string;
  confidence: 'high' | 'medium' | 'low';
  signals: string[];
}

export interface ToolAdapter {
  readonly name: string;
  readonly binary: string;
  buildCommand(files: string[], config: ToolConfig): CommandSpec;
  parseOutput(raw: string): ToolResult;
  filterToStaged(violations: AnalysisViolation[], stagedFiles: string[]): AnalysisViolation[];
}
