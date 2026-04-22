---
name: Project Status (canonical)
description: Fonte canônica única do estado do CodeGuard. LEIA ao iniciar sessão; ATUALIZE ao completar mudança significativa.
type: project
---

# CodeGuard — Project Status

> **Para Claude**: Este é o documento vivo de estado. Leia na primeira ferramenta-call de toda sessão substantiva. Atualize ao completar qualquer commit que mude escopo, ou ao mudar de sprint/foco. Em caso de conflito com outro arquivo de memória, este ganha (pra resolver drift, corrija o outro arquivo, não aqui).

**Última atualização**: 2026-04-22 (sessão 7 — overwrite-mecanismos + validação interativa no Arch + 2 design gaps fechados)
**HEAD**: `e9c1269` fix(install): PhpstanExtensionApplier respects stub-overrides.yaml
**Branch**: `main`, 53 commits ahead de `origin/main` (working tree limpo)
**Suite**: 325 tests / 787 assertions (todos verdes)
**Lint/Static**: Pint clean; PHPStan level 0 clean
**Release publicado**: nenhum (dev @ v0.x)

---

## 🎯 Sprint Atual: Sessão 8 — TestSuiteRunner extract OU Opção C Deptrac ruleset

**Sessão 7 (2026-04-22) fechou**: todos os 8 itens do backlog + validação **interativa** no Arch + 2 bugs pré-existentes do stub (Pint `_rule_docs`, shipmonk usageProviders) + **2 design gaps** descobertos e fixados (wizard Deptrac e applier PHPStan modificavam arquivos sem consultar `StubOverrides`). Arch consome package via path repo, install interativo valida 4ª opção "Keep + remember", Pint/PHPStan/Deptrac rodam (Deptrac 5804/0).

**Meta sessão 8** (escolher um):
- **Opção A** — retomar sprint Option A: extrair `TestSuiteRunner` (770 LOC do Arch) → `src/Testing/*` + `codeguard:test` (estimativa ~6-8h).
- **Opção C** — DDD-pragmatic Deptrac ruleset (~2h15min): novo `LayerOption`, `DEFAULT_RULESET` com Application→Infrastructure permitido, copy do wizard, ADR-011.

### Próxima ação concreta (sessão 8 inicia AQUI)

🔜 **Decisão pendente**: qual opção começar? Opção A é o item mais valioso (destrava M1 e release alpha); Opção C desbloqueia uso de Deptrac sem Override manual. Recomendação do agente: Opção A primeiro.

### Itens arrastados da sessão 7 para backlog pós-alpha

Nenhum item ficou pendente de sessão 7 — todos os 8 tasks + validação + 2 design gaps foram cumpridos.

**Padrão "design gap" confirmado e fechado**: componentes que mutam arquivos sob raiz do projeto precisam consultar `StubOverrides` antes de gravar (`--refresh-stubs` como escape hatch). Sessão 7 fechou 2 ocorrências desse padrão:

- `fb63ed3` — `maybeSuggestDeptracLayers` (wizard) escrevia `deptrac.yaml` direto
- `e9c1269` — `applyPhpstanExtensionsToStub` tocava sentinels de `phpstan.neon` direto

Para futuros componentes similares: seguir o shape desses 2 fixes (check `contains($path)` → short-circuit com mensagem explicativa; force flag ignora lista).

**Papercut menor anotado**: `NextStepsReporter` tem string hardcoded `"Review level in phpstan.neon (currently 5)"` — não lê level real do arquivo. Cosmetic; vai pra pós-alpha.

**Papercut menor #2**: `StubOverrides::save()` sobrescreve arquivo com header canônico — perde comentários per-entry que o user possa ter escrito. Considerar preservar linhas de comentário ao re-gravar.

### Validação na sessão 7 (o que rodou onde)

| Alvo | Comando | Resultado |
|------|---------|-----------|
| CodeGuard suite | `vendor/bin/pest` | 325 passed / 787 assertions |
| Arch path repo | `composer update henryavila/codeguard` | OK |
| Arch install NON-interativo | `php artisan codeguard:install --no-interactive --preset=default` | OK mas sobrescreveu deptrac.yaml → descoberta design gap #1 |
| Arch install **interativo** | `php artisan codeguard:install` | OK — validou Peststan pré-selecionado, 4ª opção "Keep + remember", wizard skip, 6 files como `kept custom (remembered)` |
| Arch Pint | `vendor/bin/pint --test` | Roda — reporta formatação pending (débito Arch) |
| Arch PHPStan | `vendor/bin/phpstan analyse` | Após sentinels restaurados + `e9c1269` fix: 1130 errors post-baseline (débito Arch) |
| Arch Deptrac | `vendor/bin/deptrac analyse` | 5804 allowed / 0 violations (30-layer intacto graças ao `fb63ed3`) |

### Bugs pré-existentes corrigidos fora do backlog sessão 7

- `cc3e776` — Pint 1.29+ rejeita `_rule_docs` dentro de `rules` (stub fix)
- `d936b43` — shipmonk/dead-code-detector removeu `usageProviders.laravel/eloquent` em 0.14+ (stub fix)

### Backlog pós-alpha (itens empurrados)

- **#9 — Opção C Laravel-pragmatic Deptrac ruleset** (~2h15min) — sessão 8 candidata
- **#10 — Telemetria Layers 3-7** — bloqueado por commands faltantes
- **TestSuiteRunner extract** (Option A original, ~6-8h) — sessão 8 candidata
- **wizard respeita stub-overrides.yaml** — fechar design gap anotado acima (~30min)
- Pattern engine (`src/Patterns/*` + `codeguard:analyze`)
- AI rules generator (`src/AiRules/*`)
- Schema dump multi-DB (`src/Schema/*` + `codeguard:prepare`)
- Baseline manager (`codeguard:baseline`)
- `henryavila/codeguard-hooks` Claude plugin (repo separado)

---

## 🟢 Implementado hoje (inventário objetivo)

### Comandos Artisan — 6 de 10

| Comando | Status | Arquivo |
|---------|:-----:|---------|
| `codeguard:install` | ✅ | src/Commands/CodeguardInstallCommand.php |
| `codeguard:install:override` | ✅ | src/Commands/CodeguardInstallOverrideCommand.php |
| `codeguard:check` | ✅ | src/Commands/CodeguardCheckCommand.php |
| `codeguard:telemetry:enable` | ✅ | src/Commands/Telemetry/EnableCommand.php |
| `codeguard:telemetry:disable` | ✅ | src/Commands/Telemetry/DisableCommand.php |
| `codeguard:telemetry:clear` | ✅ | src/Commands/Telemetry/ClearCommand.php |
| `codeguard:test` | 🔜 sprint 8 | — |
| `codeguard:analyze` | ⏸️ pós-alpha | — |
| `codeguard:baseline` | ⏸️ pós-alpha | — |
| `codeguard:prepare` | ⏸️ pós-alpha | — |

### Camadas — estado por namespace

| Namespace | Status | Observação |
|-----------|:-----:|------------|
| `Install\*` | ✅ completo | Environment + Preset + StubPublisher + StubOverrides + LegacyStubCleaner + DeptracLayerSuggester + DeptracLayerWizard + LayerDecisionStore + CaptainhookInstaller + ComposerAllowPluginsCheck + CodeguardDirectoryInitializer + InstallSummary + PhpstanExtension{Selector,Store,Applier} + NextStepsReporter + GatePlan{,Registry} + InstallTelemetry |
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
