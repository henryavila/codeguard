---
name: Conversation Handoff
description: Onde paramos — próximo passo concreto
type: project
---

# Conversation Handoff

**Última atualização**: 2026-04-16 (segunda sessão, pós design-refinement)
**Sessões prévias**:
- Sessão 1: Pivot Node→PHP, 10 reviews, consolidação memória
- Sessão 2 (atual): Revisão ADRs/Open Questions via mdprobe, redesign de presets (3→2), início do Bloco 1

## Estado Atual

### Repo — Estrutura criada

```
codeguard/
├── composer.json                          ✅ criado (deps: Laravel 11|12, Pest 3|4, Prompts, Finder, Diff)
├── config/codeguard.php                   ✅ criado (2 presets + gates completas)
├── src/
│   ├── CodeguardServiceProvider.php       ✅ criado (register + boot + 5 publish tags)
│   └── Testing/
│       ├── Preset.php                     ✅ enum Default|Full
│       ├── GateConfig.php                 ✅ readonly DTO
│       ├── StageConfig.php                ✅ readonly DTO
│       ├── PrepareConfig.php              ✅ readonly DTO
│       └── CodeguardConfig.php            ✅ readonly DTO principal
├── resources/
│   ├── patterns/                          ✅ herdado da v0 (28 YAMLs)
│   ├── skills/                            ✅ herdado da v0
│   ├── rules/                              ⚠️ vazio — preencher depois
│   └── stubs/                              ❌ a criar (Onda 3)
├── tests/                                 ❌ vazio — a criar (Onda 3)
├── docs/
│   ├── specs/                              ✅ preservado
│   ├── legacy/                             ✅ preservado
│   └── review/
│       └── 2026-04-16-adrs-and-open-questions.md  ✅ criado + revisado (2 annotations resolvidas)
└── .ai/memory/
    ├── MEMORY.md                          ✅ atualizado
    ├── architecture-decisions.md          ✅ ADR-001 + ADR-006 atualizadas
    ├── conversation-handoff.md            ✅ este arquivo
    ├── open-questions.md                  ✅ Q7b (Lefthook) adicionado
    ├── preset-design-evolution.md         ✅ novo (jornada 3→2 presets)
    └── [outros inalterados]
```

### Design Decisions (pós-segunda sessão)

1. **2 presets** (`codeguard` + `codeguard-full`) com auto-detection de Node
2. **Husky → Lefthook** (mérito técnico, não anti-Node)
3. **jscpd mantido em Full** com Node como requisito documentado opt-in
4. **Install híbrido**: smart stubs + guided Deptrac + post-install report
5. **Estimativas honestas**: ~1h 15min (`codeguard`) / ~1h 45min (`codeguard-full`)
6. **ADR-001 clarificada**: package core PHP; stubs podem referenciar Node

### Memória Global Criada

Em `/home/henry/.claude/projects/-home-henry-codeguard/memory/`:
- `MEMORY.md` (index)
- `user-profile.md` (Henry — dev Laravel, WSL2, Arch consumer)
- `feedback-evidence-based-estimates.md` (nunca inflar números)
- `feedback-prefer-simplification.md` (simplificar opções quando intent preserva)
- `feedback-honest-tradeoffs.md` (mostrar custos junto benefícios)
- `feedback-node-when-justified.md` (Node OK se tool for superior)
- `feedback-portuguese-typos.md` (interpretar intenção)
- `project-codeguard-state.md` (stack, presets, dual-track)
- `reference-mdprobe-review.md` (como revisar docs com mdprobe)

## Próximo Passo Concreto

### Estratégia Aprovada

- **Onda 1** (inline, ~2h): `CodeguardInstallCommand` skeleton com env detect + preset select + basic stub publish
- **Onda 2** (inline, ~2-3h): Deptrac layer suggestion + idempotent re-run + Lefthook post-install
- **Onda 3** (subagents paralelos, ~1h wall-clock): 8 stubs + Pest tests + README

**Checkpoint entre cada onda** — reportar ao usuário antes de prosseguir.

### Onda 1 — Próximas Ações Concretas

1. Criar `src/Commands/CodeguardInstallCommand.php` com:
   - `$signature = 'codeguard:install {--preset=} {--no-interactive} {--refresh-stubs}'`
   - `handle()` método orquestrando fases
   - Private methods:
     - `detectEnvironment()` — PHP, Composer, Node, package.json, node_modules
     - `selectPreset()` — auto-detect + `select()` prompt se interactive
     - `publishStubs(Preset $preset)` — copy stubs por preset
     - `showNextSteps(Preset $preset)` — post-install report
   - Usar `laravel/prompts` (`select`, `confirm`, `info`, `warning`)
2. Validar com `composer dump-autoload` no Arch path-repo setup
3. Testar manualmente: `php artisan codeguard:install --no-interactive` no Arch

### Dependências Técnicas Pendentes

- `composer install` ainda não rodado (deps não resolvidas). Intelephense mostra errors esperados em ServiceProvider — ignorar. Resolverá quando:
  - Path repository setup no Arch (Bloco 2 task)
  - Ou quando `composer install` for possível (pode ser antes se for local dev em `/home/henry/codeguard` com `composer install --no-interaction`)

## Como Retomar

Em nova sessão:
```
cd ~/codeguard
# Claude lê automaticamente CLAUDE.md + .ai/memory/MEMORY.md
# Usuário pede: "continua da Onda 1" ou "onde paramos?"
```

Claude deve:
1. Ler este handoff + memória global (Henry profile, feedback)
2. Confirmar estado (git status, existência dos arquivos listados)
3. Começar Onda 1 sem re-discutir decisions

## Observação Crítica

**Não re-abrir decisões já resolvidas**:
- Presets (2, não 3) — resolvido
- Husky → Lefthook — resolvido
- jscpd em Full com Node — resolvido
- Install híbrido — resolvido
- Estimativas honestas — resolvido

Se usuário questionar, ele terá motivo novo — escutar, mas não tratar como re-discussão padrão.
