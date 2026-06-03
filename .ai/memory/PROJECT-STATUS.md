---
name: Project Status (canonical)
description: Fonte canônica única do estado do CodeGuard. LEIA ao iniciar sessão; ATUALIZE ao completar mudança significativa.
type: project
---

# CodeGuard — Project Status

> **Para Claude**: Este é o documento vivo de estado. Leia na primeira ferramenta-call de toda sessão substantiva. Atualize ao completar qualquer commit que mude escopo, ou ao mudar de sprint/foco. Em caso de conflito com outro arquivo de memória, este ganha (pra resolver drift, corrija o outro arquivo, não aqui).

**Última atualização**: 2026-06-03 (audit profundo multi-agente + replan colaborativo — corrigiu drift acumulado de 30 dias; foco vira **Patterns engine package-side**; integração Arch ADIADA por constraint do usuário)
**HEAD**: `4b32886` docs(memory): pivot sprint 9 → 0.2.0 release; close R5 + R8
**Branch**: `main`, **sincronizado com `origin/main`** (0 commits ahead, working tree limpo)
**Suite**: 377 tests / 928 assertions (verde — verificado via `vendor/bin/pest --ci` no audit 2026-06-03)
**Lint/Static**: Pint clean. PHPStan level 5 self-applied com baseline grandfathered (`156b297`) — R8 fechado. `composer ci` roda pint:test + phpstan + test:coverage; CI ativa em PHP 8.3 + 8.4 via `.github/workflows/ci.yml` (`65893ab`).
**Release publicado**: ✅ **`0.2.0` no Packagist desde 2026-05-04** (tag `0.2.0` @ `4b32886`, pushed). Arch consome via repo `vcs` GitHub pinado em `^0.2.0` (lock @ `4b32886`) — **NÃO** via path repo nem `dev-main`.

> ⚠️ **Correção de drift (2026-06-03)**: este arquivo ficou congelado 30 dias num snapshot pré-release e estava ERRADO em 4 fatos load-bearing (dizia "não publicado", "68 commits ahead", "Arch via path repo/dev-main", "Arch usa codeguard:check produtivamente"). Tudo corrigido abaixo via audit verificado contra git+FS. Detalhe da correção na seção "Drift corrigido".

---

## 🎯 Sprint Atual: Track A — Patterns engine (package-side) (2026-06-03 — em curso)

**Decisão estratégica do usuário (2026-06-03)**: construir o **diferencial primeiro** (Patterns/LLM review — o lado *reviewer* da meta G3, "policiar dev terceirizado que não usa IA"). + **constraint dura**: *NÃO tocar no Arch agora* (projeto grande em dev lá). Implementar **tudo que for package-side** antes de tratar integração.

### Consequência no roadmap

Track B original ("migrar Arch pro runtime / dogfood") sai do caminho crítico. Arch vira **lab read-only**. Foco 100% package-side, nesta ordem:

| Fase | Trabalho | Precisa Arch? | Estado |
|:---:|---|:---:|---|
| **0** | Limpeza canônica (este arquivo + docs stale) | ❌ | 🟡 em curso |
| **1** | Bug fixes package-side (ver abaixo) | ❌ (lê Arch só como referência) | 🔜 fila |
| **2** | **Patterns engine** (`src/Patterns/*` + `codeguard:analyze`) | ❌ | 🟡 design rodando |
| **3** | Schema dump (`codeguard:prepare`) + AI rules generator | ❌ (testáveis via fixtures/SQLite) | ⏸️ depois |
| **4** | 🔒 Integração Arch + dogfood real | ✅ | ⛔ **ADIADO** (constraint do usuário) |

### Fase 1 — bug fixes package-side (decididos no audit)

1. **Assertion traits que lançam** — `TestQualityAssertions` + `ParallelSafetyAssertions`: todos os 7 métodos fazem `throw RuntimeException('Not yet implemented')` (`src/Assertions/*.php`) e estão wired no stub publicado `resources/stubs/tests/Arch/TestQualityTest.php.stub`. **Decisão (Q2): IMPLEMENTAR** portando a lógica grep-based que o Arch escreveu inline em `tests/Arch/TestQualityTest.php` (ler como referência, NÃO modificar Arch).
2. **`coverage_percent` hardcoded `-1`** em `src/Commands/CodeguardTestCommand.php:102` — telemetria de coverage nunca reflete real.
3. **Config morto** — blocos `patterns` / `ai_rules` / `prepare` em `config/codeguard.php` são parsed-into-DTO mas lidos por ninguém. Neutralizar/comentar como roadmap até os engines existirem (ou deixar e ativar junto com cada engine).

### Decisões conscientes (Q3 — "deixar o público como está")

- **SEM hotfix 0.2.1.** README:5 + `composer.json:3` continuam vendendo Patterns/AI-rules/schema sem ressalva. Aposta: Track A torna isso verdade. Risco aceito (R9).
- Bug dos traits é corrigido no código mas **vai no próximo minor (0.3.0)**, não em patch de emergência. Até lá, consumer que copie o stub e chame um método crasha — aceito porque o único consumer (Arch) já contornou inline e não há outro consumer (R10).

### Próxima ação concreta

🔜 **Aguardando design doc do Patterns engine** (workflow `patterns-engine-design` rodando: research da data contract dos 30 YAMLs + research externo de LLM-review + judge-panel de 3 arquiteturas → spec + task list TDD). Output salvo em `docs/specs/`.

**Em paralelo / assim que o design voltar**: começar Fase 1 (assertion traits via TDD) — independe do design e é package-side puro.

**Fork em aberto pro design resolver**: transporte LLM (SDK PHP Anthropic vs shell-out a CLI vs interface pluggable sem driver). Default proposto: **interface pluggable, sem Node** (respeita ADRs). Confirmar quando o design apresentar com recomendação.

---

## 🟢 Implementado hoje (inventário objetivo)

### Comandos Artisan — 7 de 10

| Comando | Status | Arquivo |
|---------|:-----:|---------|
| `codeguard:install` | ✅ | src/Commands/CodeguardInstallCommand.php |
| `codeguard:install:override` | ✅ | src/Commands/CodeguardInstallOverrideCommand.php |
| `codeguard:check` | ✅ | src/Commands/CodeguardCheckCommand.php |
| `codeguard:test` | ✅ | src/Commands/CodeguardTestCommand.php |
| `codeguard:telemetry:enable\|disable\|clear` | ✅ | src/Commands/Telemetry/*.php |
| `codeguard:analyze` | 🟡 **Track A (sprint atual)** | — |
| `codeguard:prepare` | ⏸️ Fase 3 | — |
| `codeguard:baseline` | ⏸️ pós-engines | — |

### Camadas — estado por namespace

| Namespace | Status | Observação |
|-----------|:-----:|------------|
| `Install\*` | ✅ completo | ~900 LOC no command + ~40 classes de suporte; testado |
| `Telemetry\*` | ✅ completo | Subsistema mais coberto (Recorder/FieldAllowlist/JsonlWriter/Rotator/MeasuredAction/...) |
| `Commands\Telemetry\*` | ✅ completo | 3 commands |
| `Gates\*` | ✅ | GateRunner + GateRunResult; consumido pelo CheckCommand + Layer 3 telemetry |
| `Hooks\*` | 🟡 parcial | StagedPhpFilesRunner existe |
| `Testing\*` | ✅ completo | TestSuiteRunner generalizado + StageConfig (8 campos) + executors + DTOs |
| `Assertions\*` | 🔴 **broken** | 2 traits existem mas **lançam `RuntimeException('Not yet implemented')` em 7/7 métodos**. Fase 1 corrige. |
| `Patterns\*` | ❌ ausente | **30** YAMLs em resources/patterns/ (13 core + 6 php + 11 php-laravel incl. preset.yaml) mas ZERO código consumidor. **Track A constrói.** |
| `AiRules\*` | ❌ duplo-morto | config targets existe + `resources/rules/` VAZIA (0/7 markdowns, sem git history). Fase 3. |
| `Schema\*` | ❌ ausente | só `PrepareConfig` DTO (4 campos). Fase 3. |

### Recursos (resources/)

| Caminho | Status |
|---------|:-----:|
| `resources/stubs/*.stub` | ✅ stubs (pint, phpstan, phpstan-test-quality, deptrac, infection, captainhook+README, phpunit, jscpd, CI workflow, TestQualityTest) |
| `resources/patterns/**/*.yaml` | ✅ **30** (data dormente até Patterns engine) |
| `resources/skills/*/SKILL.md` | 🔴 **stale Node-era** — os 3 mandam rodar `npx @henryavila/codeguard`, ler `node_modules`, `codeguard.yaml` (não existe no package PHP). Quebrariam um usuário real. Backlog cleanup. |
| `resources/rules/*.md` | ❌ 0/7 (dir vazia) |

---

## 🚦 Scorecard honesto por perspectiva de uso (corrigido no audit 2026-06-03)

| Perspectiva | Real | Justificativa |
|---|:-:|---|
| "install + rodar gates + rodar tests" | **~80%** | Commands reais, installer ~900 LOC, telemetria completa, 377 tests verdes. Descontado: traits lançam exception, `coverage_percent -1`, e o único consumer **não roda** check/test. |
| "pattern-based LLM review" (o diferencial) | **~15%** | 30 YAMLs + slots de telemetria existem como dado inerte/scaffolding. Zero engine. **Track A ataca.** (status antigo dizia ~30%, super-creditava dado dormente) |
| "AI rules generator" | **~3%** | duplo-morto: `src/AiRules/` ausente + `resources/rules/` vazia |
| "schema dump multi-DB" | **~8%** | só `PrepareConfig` DTO |
| "publicar/distribuir" | **~85%** | genuinamente no Packagist, tagged, lockável, Node-free. Descontado: footprint `.codeguard/` é git-ignored (não cruza máquinas), 0 downloads, único consumer bypassa a CLI |

---

## 🔎 Drift corrigido (2026-06-03) — rebuild de confiança no arquivo canônico

Audit multi-agente verificou cada alegação contra git+FS. Corrigido neste arquivo:

| Era (errado/stale) | É (verificado) |
|---|---|
| "Release publicado: nenhum ainda" | 0.2.0 no Packagist 2026-05-04 + tag pushed |
| "68 commits ahead de origin" | `origin/main...HEAD` = `0 0` |
| HEAD `9f5de93` | `4b32886` |
| Arch via "path repo / dev-main" | Arch via `vcs` + `^0.2.0`, lock `4b32886` |
| "Arch usa codeguard:check produtivamente" | Arch NUNCA chama check/test; roda pint/phpstan/deptrac direto + `tests:run` inline; **sem `config/codeguard.php`** |
| "28 patterns" | **30** YAMLs no disco |

**Ainda stale (backlog, não corrigido neste arquivo)**: `conversation-handoff.md` congelado na sessão 7 (325-passed, não registra release nem suite 377); specs marcam `codeguard:prepare` "✅" enquanto código ausente; CLAUDE.md diz "28 YAMLs"; CHANGELOG:43-44 vende assertions como funcionando + :51 diz Rotator "daily/.codeguard/telemetry/" (é size-based, flat file). README:5↔:7 se contradizem (decisão Q3: deixar).

---

## ⚠️ Riscos e blockers ativos

| # | Risco | Estado |
|---|-------|--------|
| R1 | Arch consome o package como **stub-seeder one-shot, não runtime**; dogfood real do check/test nunca rodou em campo | **ADIADO conscientemente** — constraint do usuário (não tocar Arch). Integração = Fase 4, quando liberado |
| R7 | 30 YAMLs eram peso morto até Patterns engine | **SENDO ATACADO** — Track A é a sprint atual |
| R9 | Marketing público (README:5, composer.json:3) vende features ausentes | **aceito (Q3)** — aposta que Track A torna verdade; reavaliar se Track A atrasar |
| R10 | Assertion traits lançam exception num release PUBLICADO + wired no stub → consumer que use crasha | **mitigando** — Fase 1 implementa; SEM hotfix 0.2.1 (Q3), vai no 0.3.0. Único consumer (Arch) já contornou inline |
| R11 | Skills `resources/skills/*` são Node-era e quebrariam um usuário real | backlog cleanup |
| ~~R5~~ | ~~README mínimo~~ | ✅ FECHADO (README existe + alinhado a 0.2.0) |
| ~~R8~~ | ~~package não se autoanalisa~~ | ✅ FECHADO (`156b297`: phpstan level 5 + baseline) |

---

## 📚 Fontes documentais (não duplicar aqui)

- **Spec canônico v5 arquitetural**: [`docs/specs/2026-04-16-codeguard-v2-architecture.md`](../../docs/specs/2026-04-16-codeguard-v2-architecture.md)
- **Spec CaptainHook + Telemetry**: [`docs/specs/2026-04-17-captainhook-migration-and-telemetry.md`](../../docs/specs/2026-04-17-captainhook-migration-and-telemetry.md)
- **Pivot npm→Composer**: [`docs/specs/2026-04-16-pivot-npm-to-composer.md`](../../docs/specs/2026-04-16-pivot-npm-to-composer.md)
- **Design doc Patterns engine**: [`docs/specs/2026-06-03-patterns-engine-design.md`](../../docs/specs/2026-06-03-patterns-engine-design.md) — Thin Adjudicator (MVP 4.5d + driver 1.5d); Fork 4 (transporte LLM) aberto
- **ADRs**: [`.ai/memory/architecture-decisions.md`](architecture-decisions.md)
- **Open questions**: [`.ai/memory/open-questions.md`](open-questions.md)
- **Conversation handoff**: [`.ai/memory/conversation-handoff.md`](conversation-handoff.md) — ⚠️ stale (sessão 7)
- **User goals**: [`.ai/memory/user-goals.md`](user-goals.md)

---

## 📝 Protocolo de atualização (instrução pra Claude)

**Ao iniciar sessão**: ler este arquivo *primeiro*. Single source of truth do "onde estou e pra onde vou".

**Ao terminar unidade de trabalho** (commit que muda escopo):
1. Atualizar `Última atualização` + `HEAD` + contadores (tests, commits ahead/synced, branch)
2. Mover items completados pro inventário; atualizar tabelas de comandos/camadas
3. Atualizar scorecard + riscos
4. Se mudou sprint/foco, reescrever a seção `🎯 Sprint Atual`

**NÃO atualizar pra**: progresso intra-commit (é TaskList), design (vira ADR/open-question), narrativa de sessão (vai pro conversation-handoff).

**EM CASO DE CONFLITO** com outros docs: este ganha. Corrija o outro arquivo — não edite aqui pra concordar com dado stale.
