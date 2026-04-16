---
name: Reviews Consolidated (10 agents, 3 rounds)
description: Findings de 6 reviews adversariais + 4 steelman reviews do design CodeGuard v2
type: project
---

# Reviews Consolidated — 10 Agents, 3 Rounds

## Round 1 (implícito) — Design iteration inicial
Iterações anteriores do design doc em `~/arch/docs/plans/designs/2026-04-16-codeguard-v2-architecture.md`.

## Round 2 — 6 Adversarial Agents (2026-04-16)

Prompts explicitamente adversariais ("mate ideias ruins AGORA", "seja brutalmente honesto"). Resultado: **prompt-induced bias 7/10** detectado em Round 3.

### Agent 1 — AI Rules Efficacy (3/10)
**Verdict original**: AI rules são "security theater".
**Claims factuais válidos**:
- Cursor 2.0 tem bug documentado com globs em MDC files
- Copilot trunca `copilot-instructions.md` em 4000 chars
- AgentIF benchmark: <30% instruction following em agentic scenarios
- Issues Claude Code #7777, #15443: CLAUDE.md ignorado em long sessions

**Correção Round 3**:
- Design NUNCA assumiu rules como enforcement determinístico (linhas 60-69 posicionam rules como onboarding; hooks como enforcement)
- AgentIF benchmark é agentic multi-turn, não file-scoped rules
- Reviewer atacou strawman

### Agent 2 — Enforcement Depth (4/10)
**Verdict original**: "Reinvention Wrapped in AI-Flavored Packaging"
**Claims factuais válidos**:
- Bash bypass de Edit|Write matcher é real (issues #6876, #29709)
- `git commit --no-verify` + `HUSKY=0` derrotam pre-commit (issue #40117)
- Sentinel file trivialmente spoofável
- PHPStan 120s timeout inviável em monorepo sem staged-files scoping

**Correção Round 3**:
- "GrumPHP faz 80%" é FALSO. GrumPHP não faz: multi-stage heterogêneo, Vitest JSON + JUnit XML consolidated, schema dump multi-driver, MongoDB stage isolado
- Solution: adicionar Bash + mcp__* matchers ao config-protection; usar tree-hash sentinel; staged-files scoping

### Agent 3 — Pattern System (2/10 original, 6/10 steelman)
**Verdict original**: "Fraude semântica — prompts em YAML disfarçados de analyzer"
**Claims factuais válidos**:
- `loader.ts` só faz parse+validação shape (confirmado)
- Skill `codeguard-run` Step 9: "You analyze" = LLM faz o trabalho
- Pattern `no-env-outside-config` detection é glob simples

**Correção Round 3 (Agent Steelman Classification)**:
- 28 patterns: 12 AST-replaceable, 13 hybrid, 3 pure semantic
- 16/28 codificam judgment AST não capta (`single-responsibility`, `dry behavioral`, `value-objects`, `action-classes`, etc)
- Calibration anchors em `verification.rules` (PHPMD/SonarQube/NDepend thresholds)
- False-positive carve-outs reduzem variance LLM
- Extraction recommendations (action-classes, dto) não só detectam, **advise next refactor**
- Custo $200/dev/mês foi ficção — roda on-demand, não batch

### Agent 4 — Adoption Friction (8/10 near-dealbreaker)
**Verdict original**: "Catastrophically misleading" 1-2 step install
**Claims factuais válidos**:
- JetBrains State of PHP 2025: 42% devs zero tools; PHPStan 36%, Pint 30%, Rector 10%
- Full preset publica 12 arquivos root-level
- Husky tem 1500 dep bloat (Lefthook é alternativa moderna)

**Correção Round 3**:
- Persona errada: target não são os 42% zero-tools, são os 36% que já usam Pint+PHPStan e querem mais
- Default preset = Minimal resolve 90% da fricção

### Agent 5 — Security Bypass (3/10 security theater)
**Verdict original**: 12 bypasses concretos
**Bypasses VÁLIDOS incorporados no design v4**:
- B1: Bash(sed) contorna config-protection → adicionar Bash matcher ✅
- B2: git commit --no-verify → Bash matcher intercepta ✅
- B3: Task subagent não herda hooks (issue #27661 OPEN) → documentar honestamente
- B4: mcp__* tools → adicionar mcp__.* matcher ✅
- B7: sentinel trivial → usar git tree-hash ✅

**Correção Round 3**:
- Bypasses são limitações do Claude Code, não design CodeGuard
- Design v4 incorporou fixes onde possível
- Documentar honestamente onde limites são inerentes

### Agent 6 — Competitive Positioning (3/8 diferenciadores sobrevivem)
**Verdict original**: 5 de 8 "diferenciadores únicos" são marketing fluff
**Análise correta**:
- ✅ SOBREVIVEM: test anti-pattern kit, multi-tool AI rules generator, AI config-protection hooks, schema dump multi-DB, multi-stage orchestration
- ❌ DESCARTAR: language-agnostic core (MegaLinter já faz), Deptrac 23-layer (é gist), pattern YAML isolado sem reposicionamento

**Correção Round 3**:
- "Schema dump = 30 linhas" FACTUALMENTE FALSO — 182 linhas reais, Laravel não suporta sqlsrv/`:memory:`/Windows (issues #52131, #35162, #19430)

## Round 3 — 4 Steelman Agents (2026-04-16, pós-pushback do usuário)

### Agent 7 — Pattern System Steelman (classificação completa dos 28)
Entregue classificação AST-replaceable/Hybrid/Semantic. Correção da generalização do Round 2.

### Agent 8 — Schema Dump Reality (factual audit)
**Evidência dura do source Laravel 12**:
- `SqlServerConnection.php:131-134` — `throw new RuntimeException('Schema dumping is not supported')`
- `MigrateCommand.php:295` — gate para sqlsrv load também
- `SqliteSchemaState.php:65-72` — `:memory:` só tem branch load, não dump
- Laravel docs confirmam: "squashing only for MariaDB, MySQL, PostgreSQL, SQLite"

**Arch está na intersecção de TRÊS casos não-suportados**: prod sqlsrv + tests `:memory:` + Windows sem sqlite3 CLI. Fallback PDO é **único caminho viável**.

### Agent 9 — Methodology Audit (meta-análise)
**Prompt-induced bias score: 7/10**
- 6/6 agentes convergiram em 3-4/10 mesmo com findings contraditórios
- Todos abriram com "snake oil", "teatro", "commercially non-viable" — retórica > análise
- Zero agentes listaram strengths (só "what survives")

**Strengths que NENHUM adversarial identificou**:
1. Compositional layering (cada pacote testável isolado)
2. Multi-stage heterogeneous report parsing (Vitest + JUnit)
3. `CODEGUARD_MODE` env override (defensive engineering)
4. Constructor DTO injection (testável sem framework)
5. Dual expression (Trait + Pest) — acomoda legacy E idiomatic

### Agent 10 — Multi-Stack Monorepo Value (context-specific)
**Verdict**: Reviewer original correto para 70% dos projetos Laravel, errado para os 30% tipo Arch
- Arch escreveu 770 LOC de TestSuiteRunner porque nenhuma OSS faz
- 14 protected configs + 3 baselines = AI-regression attack surface real
- TCO estimado: **$15k-35k/ano BR rates, $45k-90k/ano US/EU rates** para Arch-class projects
- SHOULD adopt: enterprise Laravel multi-stack
- SHOULD NOT: Laravel APIs simples, pure JS monorepos, teams sem AI

## Score Agregado Revisado (honesto)

| Componente | Round 2 (adversarial) | Round 3 (steelman) | Delta |
|------------|:---:|:---:|:---:|
| Composer package | 3-4/10 | **7/10** | +3 |
| npm core → eliminado em v4 | 3/10 | N/A | removed |
| Claude hooks | 3/10 | **6/10** | +3 |
| Pattern system (repositioned) | "snake oil" | 16/28 válidos | invertido |
| Schema dump multi-DB | "WEAK, 30 LOC" | **killer feature** | correção factual |
| Test orchestration | "90% GrumPHP" | nenhuma OSS cobre >40% | correção factual |
| **Design agregado** | **3.5/10** | **6.0/10** | **+2.5** |

## Lições Metodológicas

1. **Prompts adversariais causam convergência artificial em scores baixos** — usar steelman em balance
2. **Reviewers atacam amostra pequena e generalizam** — exigir análise completa do dataset
3. **Context-specific value é perdido em análise genérica** — fornecer persona do user real
4. **Factual claims precisam verificação código-fonte** — "30 LOC" virou 182 LOC real
5. **Strengths precisam ser prompt explícito** — adversarial prompt exclui esse registro
