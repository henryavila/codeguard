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

## ADR-006: Default Preset = Minimal (2026-04-16)

**Decisão**: `codeguard:install` default = Minimal (Pint + PHPStan). Standard/Full behind opt-in flags.

**Razão**:
- JetBrains State of PHP 2025: 42% devs usam zero tools; PHPStan 36%, Pint 30%, Rector 10%
- Deptrac/Infection/jscpd abaixo do noise floor
- "Full preset publica 12 arquivos root" foi critica justa
- Adoption friction mata adoption

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
