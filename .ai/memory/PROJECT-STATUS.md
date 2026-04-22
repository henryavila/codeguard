---
name: Project Status (canonical)
description: Fonte canônica única do estado do CodeGuard. LEIA ao iniciar sessão; ATUALIZE ao completar mudança significativa.
type: project
---

# CodeGuard — Project Status

> **Para Claude**: Este é o documento vivo de estado. Leia na primeira ferramenta-call de toda sessão substantiva. Atualize ao completar qualquer commit que mude escopo, ou ao mudar de sprint/foco. Em caso de conflito com outro arquivo de memória, este ganha (pra resolver drift, corrija o outro arquivo, não aqui).

**Última atualização**: 2026-04-22 (sessão 6 — 8 merges no Arch + backlog overwrite-mecanismos)
**HEAD**: `e6665d1` docs(memory): mark Option A #1 shipped, update status to point at TestSuiteRunner extract
**Branch**: `main`, 37 commits ahead de `origin/main` + **working tree TEM mudanças não-commitadas** (fix `regex→value` em DeptracLayerSuggester)
**Suite**: 284 tests / 706 assertions (todos verdes — após fix regex→value)
**Lint/Static**: Pint clean; PHPStan level 0 clean
**Release publicado**: nenhum (dev @ v0.x)

---

## 🎯 Sprint Atual: Sessão 7 — overwrite-mecanismos + backflow stubs

**Decisão tomada em 2026-04-22 (sessão 6)**: pausar sprint Option A (TestSuiteRunner extract) pra atacar primeiro 8 itens do backlog que **destravam o uso real do CodeGuard sem perder customizações Arch**. Sem isso, cada install no Arch arrisca sobrescrever 9 arquivos sem confirmação.

**Meta funcional sessão 7**: implementar fix bugs + mecanismos de stub-overrides + cleanup legacy + 4 backflows do Arch (Carbon/Peststan/infection/pint), depois validar end-to-end no Arch.

**Estimativa sessão 7**: ~5h30min (P0 50min + P1 2h15min + P2 1h50min + validação 30min).

### Próxima ação concreta (sessão 7 inicia AQUI)

🔜 **[NEXT — P0 #1]** Commit do fix `regex → value` em DeptracLayerSuggester.
- Working tree já tem mudanças aplicadas (3 arquivos). Falta apenas:
  ```bash
  cd /home/henry/codeguard
  git add src/Install/DeptracLayerSuggester.php resources/stubs/deptrac.yaml.stub tests/Unit/Install/DeptracLayerSuggesterTest.php
  git commit -m "fix(deptrac): use 'value' key for classLike (Deptrac 2.x format)"
  ```
- Razão: Deptrac 2.x espera `value`, não `regex` (mensagem de erro do tool é ambígua). Verificado em vendor source. Sem fix, qualquer install novo gera deptrac.yaml inválido.

### Backlog completo sessão 7 (10 itens, ordem dependência)

Ver `.ai/memory/conversation-handoff.md` seção "BACKLOG SESSÃO 7" para detalhes completos. Resumo:

**🔥 P0 — Fixes (~50min)**:
1. ✅ Working tree pronto / 🔜 commit fix `regex → value`
2. 🔜 Investigar bug `PhpstanExtensionApplier` removendo `#` de `:end` sentinels (3 ocorrências no Arch)

**🎯 P1 — Mecanismos overwrite (~2h15min)**:
3. 🔜 `.codeguard/stub-overrides.yaml` (skip permanente) — novo `StubOverrides` service + opção 4ª no prompt diff "Keep + remember" + comando `codeguard:install:override` + status `KeptCustomPermanent`
4. 🔜 Legacy stubs cleanup — novo `LegacyStubCleaner`, prompt "Delete legacy lefthook.yml? [Y/n]" pós preset switch

**📦 P2 — Backflow Arch → stubs (~1h50min)**:
5. 🔜 Carbon ext always-on em `phpstan.neon.stub`
6. 🔜 Peststan opt-in (enum case + composer dep + auto-detect via pestphp/pest no consumer composer.json)
7. 🔜 `infection.json5.stub` ajustes (não excluir Configurators/Middleware/Abstracts)
8. 🔜 `pint.json.stub` +3 rules (combine_unsets/issets/explicit_string_variable)

**Validação end-to-end (~30min)** — após #1-#8:
- `vendor/bin/pest` no CodeGuard (deve manter 284+ verdes)
- `git push` 38+ commits ahead
- `composer update henryavila/codeguard` no Arch
- `php artisan codeguard:install --refresh-stubs` interativo, validar Carbon/Peststan/4ª opção/legacy cleanup
- Quality gates (Pint, PHPStan, Deptrac, Infection) rodando

### Fora do escopo da sprint

- **#9 — Opção C "Laravel-pragmatic" Deptrac ruleset** (~2h15min) — sessão 8
- **#10 — Telemetria Layers 3-7** — bloqueado por commands (`codeguard:test/prepare/analyze/baseline`)
- **TestSuiteRunner extract** (sprint Option A original, ~6-8h) — retomado depois da sessão 7
- Pegar `TestSuiteRunner` (770 LOC no Arch `/home/henry/arch/app/Testing/`) + dependências
- Generalizar stages hardcoded → consumir `StageConfig[]` via `CodeguardConfig`
- Remover refs Arch-specific (Playwright cleanup, MongoDB hooks — mover para fora do runner)
- Registrar `Henryavila\Codeguard\Testing\*` (namespace já existe com DTOs)
- Novo `src/Commands/CodeguardTestCommand.php` com signature compatível (`--stage=`, `--with-coverage`)
- Instrumentar com `test.started` + `test.ended` (Layer 5 telemetria)
- Estimativa: ~6-8h

### Backlog da sprint (ordem dependência)

1. ✅ ~~`codeguard:check` — roda gates por preset~~ — shipped `e60fb00`, 284 tests
2. 🔜 Extract `TestSuiteRunner` do Arch → `src/Testing/*` + `codeguard:test` (~6-8h)
   - 770 LOC no Arch; mudança semântica: stages hardcoded → `StageConfig[]` via DTO
   - Inclui `CommandExecutor`, `AsyncCommandExecutor`, `ProcessCommandExecutor`, `RunningCommand`, `ProcessRunningCommand`
3. 📋 Arch consome package @dev via path repository + migra imports Arch→package (~2-3h)
4. 📋 Release `1.0.0-alpha.1`: README bootstrap + CHANGELOG + `git tag` + `git push` origin (~2h)

### Fora do escopo da sprint (empurrado pra pós-alpha)

- Pattern engine (`src/Patterns/*` + `codeguard:analyze`)
- AI rules generator (`src/AiRules/*`)
- Schema dump multi-DB (`src/Schema/*` + `codeguard:prepare`)
- Baseline manager (`codeguard:baseline`)
- `henryavila/codeguard-hooks` Claude plugin (repo separado)
- Telemetry instrumentation layers 3-7 (bloqueado por comandos acima)

---

## 🟢 Implementado hoje (inventário objetivo)

### Comandos Artisan — 5 de 9

| Comando | Status | Arquivo |
|---------|:-----:|---------|
| `codeguard:install` | ✅ | src/Commands/CodeguardInstallCommand.php |
| `codeguard:check` | ✅ | src/Commands/CodeguardCheckCommand.php |
| `codeguard:telemetry:enable` | ✅ | src/Commands/Telemetry/EnableCommand.php |
| `codeguard:telemetry:disable` | ✅ | src/Commands/Telemetry/DisableCommand.php |
| `codeguard:telemetry:clear` | ✅ | src/Commands/Telemetry/ClearCommand.php |
| `codeguard:test` | 🔜 sprint atual | — |
| `codeguard:analyze` | ⏸️ pós-alpha | — |
| `codeguard:baseline` | ⏸️ pós-alpha | — |
| `codeguard:prepare` | ⏸️ pós-alpha | — |

### Camadas — estado por namespace

| Namespace | Status | Observação |
|-----------|:-----:|------------|
| `Install\*` | ✅ completo | Environment + Preset + StubPublisher + DeptracLayerSuggester + DeptracLayerWizard + LayerDecisionStore + CaptainhookInstaller + ComposerAllowPluginsCheck + CodeguardDirectoryInitializer + InstallSummary + PhpstanExtension{Selector,Store,Applier} + NextStepsReporter + GatePlan{,Registry} + InstallTelemetry |
| `Telemetry\*` | ✅ completo | Event + EventName + EventStatus + FieldAllowlist + Recorder + ConfigGate + Rotator + JsonlWriter + StopwatchScope + MeasuredAction + TelemetryStateStore |
| `Commands\Telemetry\*` | ✅ completo | 3 commands |
| `Gates\*` | ✅ novo | GateRunner + GateRunResult; consumido pelo CheckCommand e primeira emissão de Layer 3 telemetry |
| `Hooks\*` | 🟡 parcial | StagedPhpFilesRunner existe; outras PHP Actions viriam depois |
| `Testing\*` (DTOs) | 🟡 parcial | Preset + CodeguardConfig + StageConfig + GateConfig + PrepareConfig existem. TestSuiteRunner ainda no Arch. |
| `Assertions\*` | 🟡 parcial | TestQualityAssertions + ParallelSafetyAssertions traits existem. PestExpectations + QualityExpectation faltam. |
| `Patterns\*` | ❌ ausente | 28 YAMLs em resources/patterns/ mas nenhum código pra carregar |
| `AiRules\*` | ❌ ausente | config('codeguard.ai_rules.targets') existe mas sem consumer |
| `Schema\*` | ❌ ausente | killer feature ainda virgem |

### Recursos (resources/) — state

| Caminho | Status |
|---------|:-----:|
| `resources/stubs/*.stub` | ✅ 7 stubs (pint, phpstan, phpstan-test-quality, deptrac, infection, captainhook+README, .jscpd) |
| `resources/patterns/**/*.yaml` | ✅ 28/28 (13 core + 6 php + 9 php-laravel) |
| `resources/skills/*/SKILL.md` | ✅ 3/3 (codeguard-{setup,run,health}) |
| `resources/rules/*.md` | ❌ 0/7 canonical markdown (dir existe, vazia) |

### Tag de rollback
`v0-last-lefthook` válida (pre-Phase-A CaptainHook migration).

---

## 📚 Fontes documentais (não duplicar aqui)

- **Spec canônico v5 arquitetural**: [`docs/specs/2026-04-16-codeguard-v2-architecture.md`](../../docs/specs/2026-04-16-codeguard-v2-architecture.md) — 2 packages, presets, install híbrido, roadmap original
- **Spec CaptainHook + Telemetry**: [`docs/specs/2026-04-17-captainhook-migration-and-telemetry.md`](../../docs/specs/2026-04-17-captainhook-migration-and-telemetry.md) — Phases A/B/C, schema telemetria
- **Pivot npm→Composer**: [`docs/specs/2026-04-16-pivot-npm-to-composer.md`](../../docs/specs/2026-04-16-pivot-npm-to-composer.md)
- **ADRs**: [`.ai/memory/architecture-decisions.md`](architecture-decisions.md) — 10 decisões congeladas
- **Open questions**: [`.ai/memory/open-questions.md`](open-questions.md) — decisões pendentes
- **Conversation handoff**: [`.ai/memory/conversation-handoff.md`](conversation-handoff.md) — narrativa cronológica por sessão (este arquivo é snapshot sincrônico; handoff é o log)
- **User goals**: [`.ai/memory/user-goals.md`](user-goals.md) — 3 metas reais

---

## 🚦 Scorecard vs spec v5 original

Fases do [roadmap do spec v5](../../docs/specs/2026-04-16-codeguard-v2-architecture.md) + extras da sessão 3-5:

| Fase | Entregável | Status | Ref |
|:---:|---|:---:|---|
| 1 | composer.json + config + DTOs + ServiceProvider (foundation) | ✅ | — |
| 2 | `CodeguardInstallCommand` híbrido | ✅ | sessões 2-4 |
| 3 | Stubs 8 gates + Pest tests | ✅ | 7 stubs + 284 tests |
| 4 | README + `1.0.0-alpha.1` | ⏳ | sprint atual #4 |
| 5 | `TestSuiteRunner` extract + `CodeguardTestCommand` | 🔜 | sprint atual #2 (NEXT) |
| **+D** (2026-04-22) | `codeguard:check` + `Gates\*` + Layer 3 telemetry | ✅ | sessão 5, sprint Option A #1 |
| 6 | Assertions (PestExpectations + QualityExpectation) | ⏸️ | 2 traits prontas, 2 classes faltam |
| 7 | Schema dump + `CodeguardPrepareCommand` | ⏸️ | pós-alpha |
| 8 | Pattern engine + `CodeguardAnalyzeCommand` | ⏸️ | pós-alpha |
| 9 | AI rules generator | ⏸️ | pós-alpha |
| 10 | `codeguard-hooks` Claude plugin (repo separado) | ⏸️ | pós-alpha |
| 11 | Arch migra do inline → package @dev | 🔜 | sprint atual #3 |
| 12 | 2º projeto + `1.0.0` | ⏸️ | pós-alpha |
| **+A** (2026-04-17) | CaptainHook migration (β) | ✅ | sessão 3 |
| **+C** (2026-04-17) | Install UX (β) | ✅ | sessão 4 |
| **+B** (2026-04-17) | Telemetry (install layer) | ✅ | sessão 5 |

**~45% do escopo total do spec v5 shipped** (Fases 1-3 completas + 2/3 dos extras). Pós-sprint Option A, projetado em ~60% + release alpha.

---

## ⚠️ Riscos e blockers ativos

| # | Risco | Mitigação em curso |
|---|-------|--------------------|
| R1 | Arch ainda não consome o package — M1 não validada em uso real | Sprint atual resolve em passo #3 |
| R2 | Spec v5 não previa CaptainHook+Telemetry (adicionado via ADR-010 e Q14) — roadmap original está sub-estimado | Aceitar: ajustar expectativa de timeline (ver ADR-008) |
| R3 | `TestSuiteRunner` tem 770 LOC no Arch — extract pode surfar edge cases Arch-specific (Playwright, MongoDB) | Sprint #2 prevê esforço + checkpoint |
| R4 | Telemetria CaptainHook Actions requer bootstrap Laravel dentro do processo do hook — não-trivial | Adiado: Layer 4 de telemetria fica pós-alpha |
| R5 | Release alpha precisa de README mínimo (hoje não existe) | Parte do sprint #4 |

---

## 📝 Protocolo de atualização (instrução pra Claude)

**Ao iniciar sessão**: ler este arquivo *primeiro*, antes de qualquer outro arquivo de memória ou spec. Use-o como single source of truth do "onde estou e pra onde vou".

**Ao terminar uma unidade de trabalho** (tipicamente um commit que muda escopo):
1. Atualizar `Última atualização` + `HEAD` + contadores (tests, commits ahead, branch)
2. Mover items do backlog da sprint para `Implementado hoje` quando completados
3. Atualizar a tabela "Comandos Artisan" e "Camadas"
4. Se a sprint terminou, começar nova sessão "Sprint Atual" e mover a antiga pra histórico compacto
5. Atualizar scorecard de fases (✅/🔜/⏸️)
6. Se algum risco foi mitigado ou surgiu novo, ajustar tabela

**Ao mudar de sprint/foco**: reescrever a seção `🎯 Sprint Atual` inteira. Itens descartados viram nota curta no histórico.

**NÃO atualizar este arquivo para**:
- Progresso intra-commit (isso é TaskList/plan)
- Discussões de design (isso vira ADR ou entra em open-questions)
- Narrativa de sessão (isso vai pro conversation-handoff)

**EM CASO DE CONFLITO** entre este arquivo e outros docs de memória: este ganha. Corrija o outro arquivo pra alinhar — não edite aqui pra "concordar com" dado stale.
