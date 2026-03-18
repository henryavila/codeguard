import type { CodeGuardConfig, PresetConfig, ToolConfig } from './config.js';
import type { ToolResult } from './violations.js';

export interface DetectionResult {
  detected: boolean;
  stack: string;          // e.g. 'php-laravel', 'php-generic'
  confidence: number;     // 0-1
  signals: string[];      // what was detected (e.g. 'composer.json', 'artisan')
}

export interface PatternDefinition {
  name: string;
  description: string;
  stackScope: string;
  detectionHeuristics: string[];
  verificationRules: string[];
  examples: { correct: string; violation: string };
}

// Thin module interface — core pipeline handles normalization/baseline/formatting
export interface CodeGuardModule {
  name: string;
  detect(projectRoot: string): Promise<DetectionResult>;
  analyze(config: CodeGuardConfig, files: string[]): Promise<ToolResult[]>;
  getPreset(): PresetConfig;
  getTemplate(): string;  // Handlebars template path
  getPatterns(): Promise<PatternDefinition[]>;
}

// Tool adapter — one per quality tool, lives inside module
export interface ToolAdapter {
  name: string;           // e.g. 'phpstan'
  analyze(files: string[], config: ToolConfig): Promise<ToolResult>;
}
