// Hook runner entry point — self-contained bundle (deps: { alwaysBundle: [/.*/] })
// Compiled by tsdown as dist/hooks/runner.js, copied to .codeguard/hook-runner.js during setup.

import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

import { parse } from 'yaml';


import { loadBaseline, filterBaselined } from './baseline.js';
import { hookFormatter } from './formatter.js';
import { MODULE_REGISTRY } from './generated/module-registry.js';
import { getStagedFiles, gitAddFiles } from './staged-files.js';
import { larastanAdapter, pintAdapter, phpmdAdapter, pestAdapter } from '../adapters/php-laravel/index.js';
import type {
  CodeGuardConfig,
  CapabilityConfig,
  PresetTool,
  ToolConfig,
  ToolAdapter,
  AnalysisViolation,
  ToolError,
  Enforcement,
} from '../core/types/index.js';

// Adapter lookup by tool name
const ADAPTER_MAP: Record<string, ToolAdapter> = {
  larastan: larastanAdapter,
  pint: pintAdapter,
  phpmd: phpmdAdapter,
  pest: pestAdapter,
};

export function resolveToolConfig(
  capability: CapabilityConfig,
  preset: PresetTool,
): ToolConfig {
  return {
    enabled: capability.enabled,
    enforcement: capability.enforcement,
    binary: preset.binary,
    config: preset.config,
    extensions: preset.extensions,
    directory: preset.directory,
    rulesets: preset.rulesets,
    preset: preset.preset,
    level: capability.level ?? preset.level,
  };
}

function validateEnforcement(
  adapter: ToolAdapter,
  enforcement: Enforcement,
  toolName: string,
): Enforcement {
  if (enforcement === 'autofix' && !adapter.supportsFix) {
    console.warn(
      `  ⚠ ${toolName}: autofix not supported, falling back to warn`,
    );
    return 'warn';
  }
  return enforcement;
}

async function loadConfig(): Promise<CodeGuardConfig | null> {
  const configPath = resolve('codeguard.yaml');
  if (!existsSync(configPath)) return null;

  const raw = await readFile(configPath, { encoding: 'utf-8' });
  return parse(raw) as CodeGuardConfig;
}

function execTool(
  binary: string,
  args: string[],
  timeout: number,
): Promise<{ stdout: string; stderr: string; exitCode: number }> {
  return new Promise((resolve) => {
    execFile(
      binary,
      args,
      { encoding: 'utf-8', timeout, maxBuffer: 10 * 1024 * 1024 },
      (error, stdout, stderr) => {
        const exitCode = error && 'code' in error ? (error.code as number) : 0;
        resolve({ stdout: stdout ?? '', stderr: stderr ?? '', exitCode });
      },
    );
  });
}

interface StageResult {
  violations: AnalysisViolation[];
  errors: ToolError[];
}

async function runAutofixStage(
  config: CodeGuardConfig,
  stagedPhpFiles: string[],
): Promise<void> {
  const capConfig = config.capabilities['formatting'];
  if (!capConfig?.enabled || capConfig.enforcement !== 'autofix') return;
  if (stagedPhpFiles.length === 0) return;

  const moduleEntry = MODULE_REGISTRY[config.project.module];
  if (!moduleEntry) return;

  const presetTool = moduleEntry.preset.tools['pint'];
  if (!presetTool) return;

  const toolConfig = resolveToolConfig(capConfig, presetTool);
  const cmd = pintAdapter.buildCommand(stagedPhpFiles, toolConfig);

  try {
    const result = await execTool(cmd.binary, cmd.args, cmd.timeout ?? 60_000);

    if (result.exitCode !== 0 && result.exitCode !== 1) {
      // Pint exit 1 means files were changed (success), other codes are errors
      console.log(`  ⚠ Pint failed: ${result.stderr.trim()}`);
      return;
    }

    // Re-stage any files that Pint modified
    await gitAddFiles(stagedPhpFiles);
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    console.log(`  ⚠ Pint failed: ${message}`);
  }
}

async function runAnalysisTool(
  adapter: ToolAdapter,
  toolConfig: ToolConfig,
  stagedFiles: string[],
): Promise<StageResult> {
  const cmd = adapter.buildCommand(stagedFiles, toolConfig);

  try {
    // Check if binary exists
    if (!existsSync(cmd.binary)) {
      return {
        violations: [],
        errors: [
          {
            tool: adapter.name,
            reason: `Binary not found: ${cmd.binary}`,
            fix: `Install with: composer require --dev the appropriate package`,
          },
        ],
      };
    }

    const result = await execTool(cmd.binary, cmd.args, cmd.timeout ?? 120_000);
    const toolResult = adapter.parseOutput(result.stdout || result.stderr);

    if (!toolResult.success) {
      return { violations: [], errors: [toolResult.error] };
    }

    const filtered = adapter.filterToStaged(toolResult.violations, stagedFiles);
    return { violations: filtered, errors: [] };
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    return {
      violations: [],
      errors: [
        {
          tool: adapter.name,
          reason: message,
          fix: `Check ${adapter.name} configuration`,
        },
      ],
    };
  }
}

async function runAnalysisStage(
  config: CodeGuardConfig,
  stagedPhpFiles: string[],
): Promise<StageResult> {
  const moduleEntry = MODULE_REGISTRY[config.project.module];
  if (!moduleEntry) {
    return {
      violations: [],
      errors: [
        {
          tool: 'codeguard',
          reason: `Module "${config.project.module}" not found in registry`,
          fix: 'Check project.module in codeguard.yaml',
        },
      ],
    };
  }

  const tasks: Promise<StageResult>[] = [];

  for (const [capName, capConfig] of Object.entries(config.capabilities)) {
    if (!capConfig.enabled) continue;
    if (capConfig.enforcement === 'autofix') continue; // handled in Stage 1

    const moduleCap = moduleEntry.module.capabilities[capName];
    if (!moduleCap) continue;

    const adapter = ADAPTER_MAP[moduleCap.tool];
    if (!adapter) continue;

    const presetTool = moduleEntry.preset.tools[moduleCap.tool];
    if (!presetTool) continue;

    const toolConfig = resolveToolConfig(capConfig, presetTool);
    toolConfig.enforcement = validateEnforcement(
      adapter,
      toolConfig.enforcement,
      moduleCap.tool,
    );

    tasks.push(runAnalysisTool(adapter, toolConfig, stagedPhpFiles));
  }

  const results = await Promise.allSettled(tasks);

  const allViolations: AnalysisViolation[] = [];
  const allErrors: ToolError[] = [];

  for (const result of results) {
    if (result.status === 'fulfilled') {
      allViolations.push(...result.value.violations);
      allErrors.push(...result.value.errors);
    } else {
      allErrors.push({
        tool: 'codeguard',
        reason: String(result.reason),
        fix: 'Check tool configuration',
      });
    }
  }

  return { violations: allViolations, errors: allErrors };
}

async function main(): Promise<void> {
  const config = await loadConfig();
  if (!config) {
    console.error(
      'codeguard.yaml not found — run /codeguard-setup first',
    );
    process.exit(1);
  }

  // Check if hook is disabled
  const hookConfig = config.hooks?.['pre-commit'];
  if (hookConfig && !hookConfig.enabled) {
    process.exit(0);
  }

  // Get staged PHP files
  const stagedPhpFiles = await getStagedFiles(['.php']);
  if (stagedPhpFiles.length === 0) {
    process.exit(0);
  }

  // Stage 1: Autofix (Pint)
  await runAutofixStage(config, stagedPhpFiles);

  // Refresh staged files after autofix (Pint may have changed them)
  const refreshedFiles = await getStagedFiles(['.php']);

  // Stage 2: Analysis (parallel)
  const { violations, errors } = await runAnalysisStage(config, refreshedFiles);

  // Apply baseline
  const baselinePath = config.baseline?.path ?? '.codeguard/baseline.json';
  const baseline = await loadBaseline(baselinePath);

  if (!baseline) {
    console.log(
      '  ℹ No baseline found — run /codeguard-run to establish baseline',
    );
  }

  const { active, baselinedCount } = filterBaselined(violations, baseline);

  // Format and output
  const context = {
    violations: active,
    errors,
    baselineCount: baselinedCount,
    totalFiles: refreshedFiles.length,
    scope: 'hook' as const,
  };

  if (active.length > 0 || errors.length > 0) {
    console.log(hookFormatter.formatFindings(context));
  }

  console.log(hookFormatter.formatSummary(context));

  // Exit code decision
  const hasBlockingViolations = active.some((v) => v.severity === 'critical');
  const allToolsFailed =
    errors.length > 0 && active.length === 0 && violations.length === 0;

  if (hasBlockingViolations || allToolsFailed) {
    process.exit(1);
  }

  process.exit(0);
}

main().catch((err) => {
  console.error('codeguard hook runner crashed:', err);
  process.exit(1);
});
