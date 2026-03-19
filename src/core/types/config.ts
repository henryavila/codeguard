export type Enforcement = 'block' | 'warn' | 'autofix';

export interface ToolConfig {
  enabled: boolean;
  binary: string;
  level?: number;
  rules?: Record<string, unknown>;
  enforcement: Enforcement;
  preset?: string;
  rulesets?: string[];
  config?: string;
  extensions?: string[];
  directory?: string;
}

export interface CapabilityConfig {
  enabled: boolean;
  enforcement: Enforcement;
  level?: number;
  presets?: string[];
}

export interface PatternsConfig {
  catalog: string[];
  discovered: string[];
  custom: string[];
}

export interface ThresholdsConfig {
  max_method_lines?: number;
  max_indentation_levels?: number;
}

export interface HookConfig {
  enabled: boolean;
  scope: 'staged-files';
}

export interface BaselineConfig {
  path: string;
  generated?: string;
}

export interface ProjectConfig {
  language: string;
  framework: string;
  module: string;
}

export interface CodeGuardConfig {
  version: string;
  project: ProjectConfig;
  capabilities: Record<string, CapabilityConfig>;
  patterns: PatternsConfig;
  thresholds?: ThresholdsConfig;
  hooks: Record<string, HookConfig>;
  baseline: BaselineConfig;
}
