export type DeploymentMechanism = 'copy' | 'symlink' | 'plugin-hook';

export interface IdeTarget {
  id: string;
  label: string;
  skillsDir: string;
  mechanism: DeploymentMechanism;
}

export const IDE_REGISTRY: readonly IdeTarget[] = [
  { id: 'claude-code', label: 'Claude Code', skillsDir: '.claude/skills', mechanism: 'copy' },
  { id: 'cursor', label: 'Cursor', skillsDir: '.cursor/skills', mechanism: 'copy' },
  { id: 'codex-cli', label: 'Codex CLI', skillsDir: '.codex/skills', mechanism: 'symlink' },
  { id: 'opencode', label: 'OpenCode', skillsDir: '.opencode/skills', mechanism: 'plugin-hook' },
  { id: 'gemini-cli', label: 'Gemini CLI', skillsDir: '.gemini/skills', mechanism: 'copy' },
  { id: 'copilot-cli', label: 'GitHub Copilot CLI', skillsDir: '.copilot/skills', mechanism: 'copy' },
  { id: 'windsurf', label: 'Windsurf', skillsDir: '.windsurf/skills', mechanism: 'copy' },
] as const;

export function getIdeById(id: string): IdeTarget | undefined {
  return IDE_REGISTRY.find((ide) => ide.id === id);
}
