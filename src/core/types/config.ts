export interface ToolConfig {
  enabled: boolean;
  binary: string;         // e.g. 'vendor/bin/phpstan'
  level?: number;         // tool-specific (e.g. PHPStan level)
  rules?: Record<string, unknown>;
  enforcement: 'block' | 'warn';
}

export interface PresetConfig {
  name: string;           // e.g. 'laravel'
  stack: string;          // e.g. 'php-laravel'
  tools: Record<string, ToolConfig>;
  patterns: string[];     // pattern names to include
}

export interface HookConfig {
  preCommit: boolean;
  enforcement: 'block' | 'warn';
}

export interface BaselineConfig {
  path: string;           // default: '.codeguard-baseline.json'
  autoGenerate: boolean;
}

// Top-level config — nested by domain, mirrors codeguard.yaml
export interface CodeGuardConfig {
  preset: string;
  tools: Record<string, ToolConfig>;
  patterns: string[];
  hooks: HookConfig;
  baseline: BaselineConfig;
}
