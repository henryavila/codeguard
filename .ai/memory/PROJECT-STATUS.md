---
name: Project Status (canonical)
description: Fonte canônica única do estado do CodeGuard. LEIA ao iniciar sessão; ATUALIZE ao completar mudança significativa.
type: project
---

# CodeGuard — Project Status

> **Para Claude**: Este é o documento vivo de estado. Leia na primeira ferramenta-call de toda sessão substantiva. Atualize ao completar qualquer commit que mude escopo, ou ao mudar de sprint/foco. Em caso de conflito com outro arquivo de memória, este ganha (pra resolver drift, corrija o outro arquivo, não aqui).

**Última atualização**: 2026-05-04 (sprint pivot — release `0.2.0` em preparação após pivot do usuário "preciso de versão minimamente estável pra publicar")
**HEAD**: `9f5de93` docs: initial CHANGELOG.md following Keep a Changelog 1.1.0
**Branch**: `main`, 68 commits ahead de `origin/main` (working tree limpo)
**Suite**: 377 tests / 928 assertions (todos verdes — sem regressão durante pivot)
**Lint/Static**: Pint clean (débito pré-existente em TestCase.php + StagedPhpFilesRunnerTest.php fixado em `a576501`). PHPStan level 5 self-applied com baseline grandfathered de 405 errors (`156b297`) — R8 fechado. `composer ci` roda pint:test + phpstan + test:coverage; CI ativa em PHP 8.3 + 8.4 via `.github/workflows/ci.yml` (`65893ab`).
**Release publicado**: nenhum ainda — `0.2.0` aguarda apenas tag + push + Packagist submission do usuário

---

## 🎯 Sprint Atual: Release `0.2.0` (2026-05-04 — em curso)

Pivot estratégico do usuário: "preciso de versão minimamente estável pra publicar". Sprint da sessão 9 mudou de "Patterns engine" para "Release 0.2.0". Patterns engine empurra para sessão 10. Decisão de versão: **0.2.0** (não `1.0.0-alpha.1` como a spec original sugeria) — continua a numeração v0.x do projeto sem prometer estabilidade de v1.0.

### Itens shippados na sprint (ordem cronológica)

| Commit | Tipo | Conteúdo |
|---|---|---|
| `18df00f` | feat | `phpunit.xml.stub` enforça `failOnRisky` + `beStrictAboutTestsThatDoNotTestAnything` |
| `76e0074` | feat | `.github/workflows/codeguard-ci.yml.stub` mínimo, delega a `composer codeguard:check` |
| `18da3f1` | docs | Status update intermediário |
| `a576501` | chore | Pint debt cleanup pré-release (TestCase.php + StagedPhpFilesRunnerTest.php) |
| `156b297` | feat | Self-PHPStan level 5 + baseline grandfathered (R8 fechado) |
| `65893ab` | ci | Package CI workflow `.github/workflows/ci.yml` (PHP 8.3 + 8.4) |
| `04c9302` | docs | README accuracy fix — moveu shipped → Available, removeu Vitest/Browser/MongoDB stale |
| `9f5de93` | docs | CHANGELOG.md inicial (Keep-a-Changelog) |

Suite: 370 → 377 (+7 / +22 assertions, sem regressão).

### Próxima ação concreta — handoff para o usuário

Tudo que requer credenciais ou autoriza ação pública é responsabilidade do usuário:

1. **Smoke test** — rodar em projeto Laravel novo: `composer require --dev henryavila/codeguard:dev-main` (via path repo) + `php artisan codeguard:install` + `composer codeguard:check`. Confirmar comportamento esperado antes de tag.
2. **`git tag 0.2.0`** + **`git push origin main --tags`** — versão prefixada (sem `v`) é o convencional para Packagist; ambos estilos funcionam.
3. **Submeter no [Packagist](https://packagist.org/packages/submit)** — primeiro submit pega URL `https://github.com/henryavila/codeguard.git` e cria a página. Releases subsequentes são auto-discovered via webhook.
4. **Anunciar/divulgar** se for o caso.

### O que NÃO está em 0.2.0 (intencional, documentado em README "Roadmapped")

- `codeguard:analyze` + Patterns engine (R7 — sessão 10)
- `codeguard:prepare` + schema dump multi-DB
- AI rules generator
- Companion packages (`codeguard-symfony`, `codeguard-python`)

### Riscos pós-release identificados

- **Breakage potencial em consumer existente (Arch)** se path repo apontar pra tag em vez de branch. Arch hoje consome `dev-main` via path; tag 0.2.0 é o primeiro snapshot estável. Recomendado: Arch pinar `^0.2` após release.
- **Patterns engine vazia continua peso morto narrative** (R7 vivo) — README ainda menciona "AI review where AST can't reach" no header. Sprint 10 fecha esse débito.

## 🎯 Sprint Anterior: Sessão 8 COMPLETA — TestSuiteRunner extract + codeguard:test shipped

**Sessão 8 (2026-04-23) fechou os 4 blocos do SESSION-8-PROMPT em uma única sessão** (originalmente estimado 6-8h; saiu mais rápido por zero retrabalho entre blocos + zero retests repetidos).

**Entregáveis shippados** (4 commits):

- `0d8b27b` — **Bloco 1** port 7 primitivos (CommandExecutor/RunningCommand/AsyncCommandExecutor interfaces + ProcessCommandExecutor/ProcessRunningCommand concretes + TestStageResult/TestRunResult DTOs). 18 tests (5+6+7). Provider aliases bound.
- `1007b49` — **Bloco 2** StageConfig evoluído com 8 campos (label, phase, description, command list, reportType nullable, reportFile, reportArgPrefix, fastFailArguments). 6 novos tests StageConfigTest. Breaking change v0.x aceito.
- `0269021` — **Bloco 3** TestSuiteRunner generalizado (400 LOC vs 522 do Arch). `stages()` hardcoded → ctor-injected `list<StageConfig>`. `killStalePlaywrightServers` removido. File facade → Filesystem injetado. 13 tests com FakeCommandExecutor/FakeRunningCommand. Service binding em `registerTestingServices()`.
- `3f3f16a` — **Bloco 4** CodeguardTestCommand + Layer 5 telemetry. Signature `--stage/--mode/--no-coverage/--context`. Emite command.start/test.started/test.ended/command.end. 8 feature tests. Test doubles extraídos pra `tests/Support/` pra reuso. NextStepsReporter agora promove `codeguard:test` como primeiro next-step.

**Critérios de sucesso do plano**:
- ✅ Suite ≥ 360 tests: 370 verdes (plano previa 325 + ~35; saiu +45)
- ✅ Zero refs a Playwright/MongoDB/Nova em files novos
- ✅ Tests cobrem sequential + parallel + fast-fail + report modes
- ✅ Config default tem stages utilizáveis sem Arch-isms
- ✅ Telemetria Layer 5 emite jsonl válido (FieldAllowlist já tinha schema)

**Decisão de escopo tomada**: `stage_key` em telemetria não emitido (schema existente do FieldAllowlist é aggregate-por-run, não per-stage). Manter assim — privacy-safe. Se granularidade per-stage virar necessidade, adicionar enum fechado ao allowlist num sprint futuro.

**Gap conhecido anotado**: `--no-coverage` hoje só flipa telemetria `with_coverage`. Coverage real depende de env do projeto (XDEBUG_MODE). Fazer plumbing de `StageConfig::env` através do executor é um follow-up (exige também `AsyncCommandExecutor` aceitar env per-call; hoje hardcoded `APP_ENV=testing`).

### Próxima ação concreta (sessão 9 — DECIDIDA 2026-04-23)

🔜 **[NEXT] Atacar Patterns engine** (ataque a R7 — fecha o gap entre marketing e código, cria o motor que consome os 28 YAMLs dormentes).

**Motivação da escolha**: o usuário está usando `codeguard:check` produtivamente no Arch; o pacote é utilizável hoje; o que FALTA pra ele cumprir a promessa "quality gates que sobrevivem ao seu agente" é a feature diferenciadora — pattern review automatizado. Sem Patterns engine, CodeGuard é "mais um wrapper de pint+phpstan+deptrac". Com Patterns engine, é único.

**Prioridade revisada pós-sessão 8**:

1. **Sessão 9 (próxima)** — Patterns engine v0 (src/Patterns/* + codeguard:analyze + testes). Estimativa honesta: ~1 semana de sessões, não 1 sessão. Começa por brainstorm/plano antes de codar.
2. **Sessão N+k** — Arch migra Testing inline → package (~2-3h, destravado por sessão 8)
3. **Sessão N+k+1** — README + `1.0.0-alpha.1` release (só faz sentido depois que Patterns engine existe e Arch validou no campo)
4. **Pós-alpha** — Schema dump (`prepare`), AI rules generator, hooks plugin

**Itens empurrados explicitamente**:
- README/alpha release: NÃO antes de Patterns engine estar rodando no Arch
- Schema dump: alto custo (8-12h real + drivers MySQL/Postgres/sqlsrv), baixo ROI sem 2º consumer que exija sqlsrv ou in-memory SQLite
- AI rules generator: mais útil depois que Patterns engine tem pelo menos um motor de consumo de markdown

### Itens arrastados da sessão 7 para backlog pós-alpha

Nenhum item do backlog original ficou pendente — os 8 tasks + validação end-to-end + 2 design gaps + 2 bugs de config foram todos fechados. Sessão 7 rodou substancialmente além do planejado (13 commits no total vs 8 previstos) por causa dos descobrimentos na validação interativa.

**Padrão "design gap" confirmado e fechado**: componentes que mutam arquivos sob raiz do projeto precisam consultar `StubOverrides` antes de gravar (`--refresh-stubs` como escape hatch). Sessão 7 fechou 2 ocorrências desse padrão:

- `fb63ed3` — `maybeSuggestDeptracLayers` (wizard) escrevia `deptrac.yaml` direto
- `e9c1269` — `applyPhpstanExtensionsToStub` tocava sentinels de `phpstan.neon` direto

Para futuros componentes similares: seguir o shape desses 2 fixes (check `contains($path)` → short-circuit com mensagem explicativa; force flag ignora lista).

**Bugs de config de check (fixados na validação interativa)**:

- `41ec10c` — infection `--show-mutations=false` é inválido; aceita integer ou "max". Flag removida, `--no-progress` substituindo. PHPStan também ganhou `--memory-limit=2G` (projetos >20k LOC OOMam sem).
- `fc1e777` — infection `testFramework: pest` é rejeitado em 0.32 (aceita phpunit/phpspec/codeception). Mudado pra `phpunit` (Pest instala binário compatível); comment no stub explica que `pest` requer infection/pest-plugin separado.

**Papercuts menores anotados para pós-alpha**:
- `NextStepsReporter` tem string hardcoded `"Review level in phpstan.neon (currently 5)"` — não lê level real do arquivo. Cosmetic.
- `StubOverrides::save()` sobrescreve arquivo com header canônico — perde comentários per-entry que o user escreva manualmente.
- `codeguard:install:override --detect` sub-comando: compara diff vs stub e sugere paths candidatos a override. UX friction real — usuário esqueceu `tests/Arch/TestQualityTest.php` no pre-seed da primeira iteração.

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
| `codeguard:test` | ✅ | src/Commands/CodeguardTestCommand.php |
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
| `Testing\*` | ✅ completo | Preset + CodeguardConfig + StageConfig (8 campos) + GateConfig + PrepareConfig + CommandExecutor/RunningCommand/AsyncCommandExecutor interfaces + ProcessCommandExecutor/ProcessRunningCommand concretes + TestStageResult/TestRunResult DTOs + **TestSuiteRunner generalizado** |
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
| 5 | `TestSuiteRunner` extract + `CodeguardTestCommand` | ✅ | sessão 8 (2026-04-23) |
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

**~60% do escopo total do spec v5 shipped** (Fases 1-3 + 5 completas, extras A/B/C/D todos shipped). Restam Fases 4 (README + alpha release), 6 (Assertions classes), 7 (Schema dump), 8 (Patterns), 9 (AI rules), 10 (hooks plugin), 11 (Arch migration), 12 (v1.0).

### Scorecard honesto por perspectiva de uso (2026-04-23)

O número "~60% shipped" agregado esconde uma bifurcação importante. Medindo por perspectiva real de consumidor:

| Perspectiva de uso | Estado real | Por quê |
|---|:-:|---|
| "install + rodar gates + rodar tests" (Arch hoje) | **~85%** | install/check/test production-ready; telemetria install+gates em pé; CaptainHook ativo |
| "pattern-based LLM review" (o diferencial marketing) | **~30%** | 28 YAMLs de dados existem em `resources/patterns/`; zero consumer code em `src/Patterns/`; `codeguard:analyze` não existe |
| "AI rules generator" | **~15%** | config targets existe; zero código em `src/AiRules/` |
| "schema dump multi-DB" | **~10%** | `PrepareConfig` DTO existe (4 campos); `src/Schema/` não existe; `codeguard:prepare` não existe |
| "publicar v1.0 no Packagist" | **~60%** | falta README, CHANGELOG, tag, 2º consumer, migration Arch inline |

**Diagnóstico honesto**: o projeto é produtivo pro uso imediato (Arch consumindo quality gates), mas vende uma narrativa (pattern review LLM) que ainda não existe em código. Isso NÃO é falso — é incompleto. O risco concreto é publicar alpha antes de ter a feature diferenciadora.

---

## ⚠️ Riscos e blockers ativos

| # | Risco | Mitigação em curso |
|---|-------|--------------------|
| R1 | Arch ainda consome Testing inline; falta migração pro package — M1 não 100% validada em uso real | Sessão 9 Opção A resolve |
| R2 | Spec v5 não previa CaptainHook+Telemetry (adicionado via ADR-010 e Q14) — roadmap original está sub-estimado | Aceitar: ajustar expectativa de timeline (ver ADR-008) |
| R3 | ~~TestSuiteRunner extract pode surfar edge cases Arch-specific~~ **MITIGADO sessão 8** — extract limpo, 13 tests cobrem modes, Arch-isms (Playwright/MongoDB/Nova) todos removidos | ✅ fechado |
| R4 | Telemetria CaptainHook Actions requer bootstrap Laravel dentro do processo do hook — não-trivial | Adiado: Layer 4 de telemetria fica pós-alpha |
| ~~R5~~ | ~~Release alpha precisa de README mínimo (hoje não existe)~~ | **FECHADO 2026-05-04**: README existe (218 linhas) e foi corrigido para alinhar com 0.2.0 (`04c9302`); CHANGELOG criado (`9f5de93`) |
| R6 | `--no-coverage` hoje só flipa telemetria; coverage real via XDEBUG_MODE depende do projeto. StageConfig::env não plumbed através do executor | Follow-up: exige AsyncCommandExecutor aceitar env per-call |
| R7 | **28 YAMLs em `resources/patterns/` são peso morto até Patterns engine shippar** — marketing vende "pattern-based LLM review" que não existe em código | **Sessão 9 ataca essa dívida** (ver sprint abaixo) |
| ~~R8~~ | ~~CodeGuard não tem `phpstan.neon` na raiz~~ | **FECHADO 2026-05-04** (`156b297`): level 5 + baseline grandfathered de 405 errors + `reportUnmatchedIgnoredErrors: true`. Pacote agora se autoanalisa. |

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
