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
        let exitCode = 0;
        if (error) {
          const code = 'code' in error ? error.code : undefined;
          exitCode = typeof code === 'number' ? code : 1;
        }
        resolve({ stdout: stdout ?? '', stderr: stderr ?? '', exitCode });
      },
    );
  });
}

interface StageResult {
  violations: AnalysisViolation[];
  errors: ToolError[];
  toolEnforcement: Record<string, Enforcement>;
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

interface ToolRunResult {
  violations: AnalysisViolation[];
  errors: ToolError[];
}

async function runAnalysisTool(
  adapter: ToolAdapter,
  toolConfig: ToolConfig,
  stagedFiles: string[],
  installCommand?: string,
): Promise<ToolRunResult> {
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
            fix: installCommand
              ? `Install with: ${installCommand}`
              : `Check ${adapter.name} installation`,
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

function findInstallCommand(
  installCommands: string[],
  toolName: string,
): string | undefined {
  return installCommands.find((cmd) => cmd.includes(toolName));
}

export async function runAnalysisStage(
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
      toolEnforcement: {},
    };
  }

  const tasks: { promise: Promise<ToolRunResult>; toolName: string; enforcement: Enforcement }[] = [];

  for (const [capName, capConfig] of Object.entries(config.capabilities)) {
    if (!capConfig.enabled) continue;
    if (capConfig.enforcement === 'autofix') continue; // handled in Stage 1

    const moduleCap = moduleEntry.module.capabilities[capName];
    if (!moduleCap) continue;

    const adapter = ADAPTER_MAP[moduleCap.tool];
    if (!adapter) continue;

    const presetTool = moduleEntry.preset.tools[moduleCap.tool];
    if (!presetTool) continue;

    const enforcement = validateEnforcement(
      adapter,
      capConfig.enforcement,
      moduleCap.tool,
    );
    const toolConfig = resolveToolConfig(
      { ...capConfig, enforcement },
      presetTool,
    );

    const installCommand = findInstallCommand(
      moduleEntry.preset.install_commands,
      moduleCap.tool,
    );

    tasks.push({
      promise: runAnalysisTool(adapter, toolConfig, stagedPhpFiles, installCommand),
      toolName: moduleCap.tool,
      enforcement,
    });
  }

  const results = await Promise.allSettled(tasks.map((t) => t.promise));

  const allViolations: AnalysisViolation[] = [];
  const allErrors: ToolError[] = [];
  const toolEnforcement: Record<string, Enforcement> = {};

  for (let i = 0; i < results.length; i++) {
    const result = results[i];
    const task = tasks[i];
    toolEnforcement[task.toolName] = task.enforcement;

    if (result.status === 'fulfilled') {
      allViolations.push(...result.value.violations);
      allErrors.push(...result.value.errors);
    } else {
      allErrors.push({
        tool: task.toolName,
        reason: String(result.reason),
        fix: 'Check tool configuration',
      });
    }
  }

  return { violations: allViolations, errors: allErrors, toolEnforcement };
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
  const { violations, errors, toolEnforcement } = await runAnalysisStage(config, refreshedFiles);

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
    toolEnforcement,
  };

  if (active.length > 0 || errors.length > 0) {
    console.log(hookFormatter.formatFindings(context));
  }

  console.log(hookFormatter.formatSummary(context));

  // Exit code decision: uses enforcement config, not hardcoded severity
  const hasBlockingViolations = active.some(
    (v) => (toolEnforcement[v.tool] ?? 'block') === 'block',
  );
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
