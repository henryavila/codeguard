---
name: Architecture Decisions Log
description: Decisões arquiteturais chave com rationale — não reabrir sem motivo forte
type: project
---

# Architecture Decision Log (ADR)

## ADR-001: Pivot Node.js → PHP/Composer (2026-04-16)

**Decisão**: Abandonar `@henryavila/codeguard` npm package (v0.1.1) em favor de `henryavila/codeguard` Composer package.

**Contexto**:
- Stack real do usuário é PHP/Laravel/Vue (não Node)
- npm core era "agnostic" mas 90% do valor era Laravel-specific
- hook runner Node.js adicionava 50MB+ node_modules sem ganho proporcional
- Reviewers convergiram: npm core é "theoretical tax"

**Consequências**:
- `v0-npm-archive` branch + `v0-last-npm` tag preservam estado Node
- npm registry mantém v0.1.1 publicado (não deprecated formalmente)
- `main` branch será reescrita em PHP
- 28 pattern YAMLs migram para `resources/patterns/` como data contract
- Skills migram para `resources/skills/` (integradas via Composer install)

**Clarificação (2026-04-16, pós discussão preset design)**:

Escopo do "no Node.js":
- ✅ **Package core** é 100% PHP (`henryavila/codeguard` não requer Node runtime para funcionar)
- ✅ **Preset default (`codeguard`)** usa apenas tools PHP/binário: Pint + PHPStan + Deptrac + Infection + **Lefthook** (Go binary)
- ⚠️ **Preset opt-in (`codeguard-full`)** referencia tools Node: jscpd. Justificativa: ecossistema PHP de CPD é fraco (phpcpd arquivado desde Dez/2020; phpmd CPD inferior). Node é requisito **documentado e opt-in**, auto-detectado pelo installer (projetos Laravel+Vue já têm Node).
- ❌ Package core NÃO bundles node_modules, NÃO exige `node` no $PATH para rodar comandos base (`codeguard:install`, `codeguard:check`, `codeguard:test`, `codeguard:analyze`)

## ADR-002: 2 Packages, Não 3 (2026-04-16)

**Decisão**: Shippar apenas 2 packages:
1. `henryavila/codeguard` (Composer) — tudo PHP/Laravel
2. `henryavila/codeguard-hooks` (Claude plugin) — bash hooks

**Descartado**: `@henryavila/codeguard` npm separado.

**Contexto**:
- Pattern engine pode ser PHP nativo (`symfony/yaml` + pattern loader em PHP)
- Skills embutidas no Composer package (publicadas via `codeguard:install`)
- AI rules generator em PHP puro
- Baseline manager em PHP
- Usuário principal usa Laravel — package manager nativo é Composer

**Consequências**:
- Manutenção reduzida (~60h/ano vs ~120h 3-package)
- Sem node_modules no stack
- Extensibilidade futura via **companion packages nativos** (`codeguard-symfony`, `codeguard-python`), não via "agnostic core"

## ADR-003: Option A — Reusar Repo `henryavila/codeguard` (2026-04-16)

**Decisão**: Pivotar `main` do repo existente para PHP. Não criar repo novo.

**Razão**:
- Brand recognition preservado (`codeguard` name)
- README legacy em `docs/legacy/` para referência
- npm v0.1.1 tem adoção quase-zero (baixo risco de quebrar)
- `v0-npm-archive` branch permite voltar se necessário

**Alternativas descartadas**:
- **Option B** — novo repo `laravel-codeguard`: fragmentaria brand
- **Option C** — monorepo com `packages/{npm,composer,hooks}`: complexidade prematura

## ADR-004: "Hard Enforcement" → "Best-Effort Nudges" (2026-04-16, pós-reviews)

**Decisão**: Reposicionar Claude hooks plugin como "best-effort nudges" em vez de "hard enforcement".

**Razão**:
- Issues oficiais Claude Code (#6876, #29709, #27661, #13744, #40117) confirmam bypasses:
  - Bash(sed/awk/tee/>) contorna Edit|Write matcher
  - git commit --no-verify / HUSKY=0 burla pre-commit
  - Task subagents não herdam hooks
  - MCP tools contornam matcher
  - PostToolUse exit code ignorado em Write/Edit
  - Opus 4.6 documentadamente usa --no-verify para burlar gates
- "Hard enforcement" é materialmente enganoso — CI é o gate real

**Consequências**:
- README/docs declaram honestamente: "hooks são nudges; CI é o gate"
- Adicionar Bash + mcp__* matchers ao config-protection
- Stop hook sentinel usa git tree-hash (não empty file touch)

## ADR-005: Pattern System = Rule Distribution Format + LLM Adjudicator (2026-04-16)

**Decisão**: Reposicionar pattern YAML system — NÃO é "static analyzer", É "structured prompt distribution + LLM adjudicator onde AST não alcança".

**Razão** (steelman review Round 3):
- Análise dos 28 patterns: 12 AST-replaceable (43%), 13 hybrid (46%), 3 pure semantic (11%)
- 16 de 28 codificam judgment AST não captura (`single-responsibility`, `dry behavioral`, `value-objects`, `action-classes`, `no-logic-in-blade`, `no-god-object`, `bounded-contexts`)
- Verification rules + false-positive carve-outs reduzem variance do LLM
- Custo é on-demand via skill, não batch full-repo ($200/dev/mês foi cálculo errado)

**Consequências**:
- Tagline: "AI review where AST can't reach"
- 12 AST-replaceable patterns delegar para phpat/pest-arch/PHPMD/Deptrac (reduzir redundância)
- Keep pattern YAMLs como data contract em `resources/patterns/`

## ADR-006: 2 Presets com Auto-Detection + Install Híbrido (2026-04-16, revisado)

**Decisão**: Simplificar de 3 presets (Minimal/Standard/Full) para **2 presets** binários baseados em Node availability. Install é **híbrido** (stubs inteligentes + guided Deptrac + post-install report).

### Presets Finais

| Preset | Tools | Requer Node? | Auto-select quando |
|--------|-------|:---:|-------------------|
| **`codeguard`** (default) | Pint + PHPStan + Deptrac + Infection + Lefthook | ❌ | Projeto não tem `package.json` nem binário `node` |
| **`codeguard-full`** | + jscpd + Insights + TestQualityTest | ✅ | Projeto tem `package.json` ou `node_modules/` |

### Rationale da Simplificação

Descartou-se preset "Starter/Minimal" (só Pint + PHPStan) porque:
- **Persona inexistente**: "dev experimentando 30s" não é usuário real
- **Meta 3 (dev terceirizado) exige Infection + Lefthook** — preset fraco não atende objetivo do projeto
- **Falso conforto**: preset Minimal deixa dev achando que está protegido quando não está
- **Progressive disclosure inútil**: tooling PHP não tem "níveis" comparáveis a PHPStan levels; ou você tem arch enforcement ou não tem

### Auto-Detection do Installer

```
1. Existe node_modules/ OU package.json em base_path()?
   → Pre-select: codeguard-full (high confidence)

2. Existe binário `node` globalmente (which node)?
   → Pre-select: codeguard (medium confidence — tem Node mas não usa no projeto)
   → Hint: "node detected globally; use --preset=full to include jscpd"

3. Nenhum dos dois
   → Pre-select: codeguard (low/zero node presence)
```

### Install Híbrido (3 camadas)

**Camada 1 — Stubs inteligentes** (7/8 gates):
- Stubs com comentários inline explicando cada opção (dev aprende lendo)
- Auto-preenchimento via inspeção de `composer.json` (PSR-4 autoload → infection source dirs)
- Defaults sensatos (PHPStan level 5, Infection min-msi 60)

**Camada 2 — Guided setup para Deptrac** (único gate que não funciona sem input):
- Scan `app/*` namespaces via `symfony/finder`
- Pattern matching heurístico para propor layers (Domain/Application/Persistence)
- Usuário confirma, edita em `$EDITOR`, ou skip (gera depfile.yaml vazio)

**Camada 3 — Post-install next-steps report**:
- Lista cada gate instalado
- Próxima ação concreta por gate (ex: "Deptrac → verify layers match architecture")
- Link para docs

### Override Flags

```bash
php artisan codeguard:install                    # auto-detect
php artisan codeguard:install --preset=full      # force full
php artisan codeguard:install --preset=default   # force PHP-only
php artisan codeguard:install --no-interactive   # CI mode, use detection result
php artisan codeguard:install --refresh-stubs    # update stubs without losing customizations
```

### Numbers Honestos (não inflados)

| Gate | Config time real |
|------|:---:|
| Pint | 0 (Laravel preset) |
| PHPStan | ~15min (ajustar level + excludePaths) |
| Deptrac | ~30min (layers via guided suggestion + first analyse) |
| Infection | ~20min (srcDir via auto-detect + baseline) |
| Lefthook | ~10min (review stub) |
| jscpd | ~5min |
| Insights | 0 |
| TestQualityTest | ~15min (allowlist ajustes) |
| **Total `codeguard`** | **~1h 15min** |
| **Total `codeguard-full`** | **~1h 45min** |

### Anti-Patterns Rejeitados

- ❌ Minimal default (deixa usuário achando que tá protegido)
- ❌ 3 presets com progressão forçada (escolhas desnecessárias)
- ❌ Wizard de 15 perguntas (repetitivo em multi-machine)
- ❌ Stub dump cru sem explicação (frustra dev que não sabe por onde começar)
- ❌ Estimativas inflacionadas (perde credibilidade)

## ADR-007: Dual-Track Development (Work-in-place + Extract-as-you-go) (2026-04-16)

**Decisão**: Arch (projeto real) recebe consolidação inline HOJE. Package desenvolve em paralelo via `composer path repository` (symlink). Extract gradual.

**Razão**:
- Usuário não pode esperar 2 semanas para ter quality gates no Arch
- Arch tem 770 LOC de TestSuiteRunner + assertions já em produção
- Path repository permite Arch consumir versão `@dev` local sem publicar
- Extração gradual valida package em uso real desde dia 1

**Consequências**:
- Namespaces no Arch desde início espelham package (`App\Testing\Concerns\*` → `Henryavila\Codeguard\Assertions\*`)
- Find/replace único quando migra
- Primeiro projeto consumidor é laboratório de testes

## ADR-008: Timeline AI-Assisted ~1-2 Semanas (2026-04-16)

**Decisão**: Estimativa realista AI-assisted:
- Composer package MVP: **15-25h focadas** = 2-4 dias
- Pattern engine + 28 YAMLs: **8-12h** = 1-2 dias
- Claude plugin: **6-10h** = 1-2 dias
- Arch migra: **3-5h** = 0.5-1 dia
- 2º projeto smoke test: **4-8h** = 0.5-1 dia
- Buffer debugging/polish: **8-12h** = 1-2 dias
- **Total: ~45-70h focadas = 6-11 dias úteis = 1.5-2.5 semanas calendar**

**Razão**: estimativas anteriores (4-6 semanas) usavam velocidade humana pré-IA. Com Claude Code, código formulaico (extract + stubs + wizard + docs) é 5-10x mais rápido. Human bottleneck permanece em decisões de API, verificação, edge cases — ~30% overhead.
