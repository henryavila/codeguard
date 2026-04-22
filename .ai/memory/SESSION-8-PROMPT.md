---
name: Session 8 Prompt — TestSuiteRunner extract + codeguard:test command
description: Self-contained prompt to paste into next Claude session
type: project
---

# Prompt para colar no início da Sessão 8

> Copie tudo abaixo da linha tracejada e cole no início da nova sessão.

---

Estamos retomando o CodeGuard (`/home/henry/codeguard`). Sessão 7 fechou: 15 commits shippados (8 backlog + 5 fixes de bugs descobertos + 2 docs). Arch consumer commitado em 2 grupos. Suite 325 verdes. Sprint 8 agora ataca a **Opção A do caminho crítico pra release alpha**: extrair `TestSuiteRunner` do Arch pra CodeGuard.

## Context load obrigatório (na ordem)

1. Lê `/home/henry/codeguard/CLAUDE.md`
2. Lê `/home/henry/codeguard/.ai/memory/PROJECT-STATUS.md` (snapshot canônico)
3. Lê `/home/henry/codeguard/.ai/memory/MEMORY.md` (índice)
4. Lê `/home/henry/codeguard/.ai/memory/conversation-handoff.md` (sessão 7 completa — especialmente seção "Estado Atual" + "Resultados da validação")
5. Lê `/home/henry/codeguard/docs/specs/2026-04-16-codeguard-v2-architecture.md` (Fase 5 do roadmap é exatamente isso)

## Verificação de estado antes de começar

```bash
cd /home/henry/codeguard
git log --oneline -3                                         # último deve ser fc1e777
git status                                                   # working tree limpo
vendor/bin/pest --colors=never 2>&1 | tail -3               # esperado: 325 verdes
ls src/Testing/                                              # DTOs: CodeguardConfig, GateConfig, PrepareConfig, Preset, StageConfig
ls /home/henry/arch/app/Services/Testing/                   # Arch tem: AsyncCommandExecutor, CommandExecutor, ProcessCommandExecutor, ProcessRunningCommand, RunningCommand, TestRunResult, TestStageResult, TestSuiteRunner
```

Se divergência, PARE e investigue antes de começar.

## Escopo da sprint 8

**Extrair 8 arquivos** de `Arch/app/Services/Testing/*` → `CodeGuard/src/Testing/*`:

| Arch file | LOC | Complexidade | Arch-specific? |
|---|---:|---|---|
| `CommandExecutor.php` | 13 | Interface | não |
| `RunningCommand.php` | 20 | Interface | não |
| `AsyncCommandExecutor.php` | 15 | Interface (extends) | não |
| `ProcessCommandExecutor.php` | 42 | Impl concreta | não |
| `ProcessRunningCommand.php` | 52 | Impl concreta | não |
| `TestStageResult.php` | 42 | DTO | não |
| `TestRunResult.php` | 64 | DTO | não |
| **`TestSuiteRunner.php`** | **522** | Orquestrador | **SIM** — 5 stages hardcoded, refs Playwright/MongoDB, PHP_BINARY artisan test:prepare |
| **TOTAL** | **770** | | |

**Namespace alvo**: `Henryavila\Codeguard\Testing\*` (já existe com DTOs).

**Comando novo**: `codeguard:test` com signature compatível com Arch:
- `--stage=<key>` (restringir a 1 stage)
- `--mode=fast-fail|report` (fail-fast vs collect-all)
- `--no-coverage` (default: coverage on)

**Telemetria Layer 5**: emitir `test.started` + `test.ended` por stage (enum cases já existem em `EventName`? verificar — senão adicionar).

## Plano de execução — 4 blocos

### 🔧 Bloco 1 — Port dos primitivos sem lógica Arch-específica (~1h30min)

Arquivos sem Arch-coupling: port direto com rename de namespace.

```
Arch                                          CodeGuard
App\Services\Testing\CommandExecutor      →  Henryavila\Codeguard\Testing\CommandExecutor
App\Services\Testing\RunningCommand       →  Henryavila\Codeguard\Testing\RunningCommand
App\Services\Testing\AsyncCommandExecutor →  Henryavila\Codeguard\Testing\AsyncCommandExecutor
App\Services\Testing\ProcessCommandExecutor →  ...ProcessCommandExecutor
App\Services\Testing\ProcessRunningCommand →  ...ProcessRunningCommand
App\Services\Testing\TestStageResult      →  ...TestStageResult
App\Services\Testing\TestRunResult        →  ...TestRunResult
```

**Tasks**:
1. Copiar cada arquivo, ajustar `namespace` + `use` statements
2. Adicionar `declare(strict_types=1)` se faltando
3. Escrever tests Pest pra cada um (unit level):
   - `CommandExecutorTest` — mock concreta, verify interface
   - `ProcessCommandExecutorTest` — executa `echo hello`, verify output+exit
   - `TestStageResultTest` / `TestRunResultTest` — serialização, status
4. Registrar no ServiceProvider (bindings)

**Critério**: Pest verde + coverage 80%+ desses arquivos. Zero Arch-isms residuais.

**Commit**: `feat(testing): port command executors + result DTOs from Arch`

### 🏗 Bloco 2 — StageConfig evolve + CodeguardConfig integration (~1h)

O `StageConfig` atual tem 5 campos (key, enabled, command, env, reportFormat). Arch precisa de MAIS:
- `label` (display)
- `phase` (int — stages de mesmo phase podem rodar paralelo)
- `description`
- `command` array (hoje é string)
- `reportFile` (nome do arquivo gerado)
- `reportArgPrefix` (ex: `--log-junit=`, `--outputFile=`)
- `fastFailArguments` (list<string>)

**Decisão importante**: evoluir `StageConfig` ou criar novo DTO `StageDefinition` paralelo?

Recomendação: **evoluir StageConfig** (breaking change OK — v0.x, nenhum consumer externo).

**Tasks**:
1. Refactor `StageConfig` pra incluir os 8 campos (marcar readonly + nullable onde apropriado)
2. Refactor `fromArray()` pra parse do novo shape
3. Atualizar `config/codeguard.php` com exemplo completo de stages default (unit/feature/integration básicos — versão enxuta, sem Playwright/MongoDB)
4. Escrever `StageConfigTest` cobrindo: todos campos preenchidos, campos opcionais default, fromArray malformado
5. Atualizar `CodeguardConfig::fromArray()` se precisar (provavelmente não — só delega)

**Commit**: `refactor(testing): extend StageConfig with phase + report metadata`

### 🎯 Bloco 3 — TestSuiteRunner generalização (~3h)

Arch's TestSuiteRunner tem:
- Método privado `stages()` hardcoded → substituir por ctor-injected `StageConfig[]`
- `killStalePlaywrightServers()` → **remover** (Arch-specific; se projetos precisarem, podem hook via event)
- 5 phases: frontend (vitest), prepare (sqlite), php-main, php-mongodb, php-browser → stages vêm do config
- `parseVitestJson` + `parseJunit` → manter ambos (projetos JS usam vitest; PHP usa junit)

**Tasks**:
1. Criar `src/Testing/TestSuiteRunner.php` (~400 LOC esperado após limpeza)
2. Construtor: `CommandExecutor $executor`, `array<StageConfig> $stages`, `?string $reportDir = null`
3. Portar `run()`, `runSequential()`, `runParallel()`, `runSingleStage()`, `makeCapturingCallback()`, `writeFailureLog()`, `buildCommand()`, `reportPath()`, `parseVitestJson()`, `parseJunit()`, `makeStageResult()`, etc.
4. Remover `killStalePlaywrightServers()` + todos seus call sites
5. Generalizar `phases()` pra agrupar `StageConfig[]` por `$stage->phase`
6. Tests: pelo menos 10 testes cobrindo:
   - Run sequential (single stage, single phase)
   - Run parallel (multi-stage, same phase, canRunAsync=true)
   - Fail-fast mode aborta nos seguintes
   - Report mode rode todos mesmo com falha
   - writeFailureLog gera log quando há falha
   - stages de phase 2 só rodam se phase 1 passou
   - parseVitestJson / parseJunit com fixtures reais
7. Registrar no ServiceProvider (bindings)

**Commit**: `feat(testing): extract generalized TestSuiteRunner with StageConfig-driven stages`

### 🚀 Bloco 4 — `codeguard:test` command + telemetria (~2h)

**Tasks**:
1. Criar `src/Commands/CodeguardTestCommand.php` com signature:
   ```
   codeguard:test
     {--stage= : Limit to specific stage key}
     {--mode=fast-fail : fast-fail|report}
     {--no-coverage : Skip XDEBUG_MODE=coverage}
   ```
2. `handle()`: resolve `CodeguardConfig` → extrai stages habilitados (com `--stage=` filter) → monta `TestSuiteRunner` → chama `run()` → renderiza resultado
3. Telemetria: antes/depois de cada stage, emit `test.started` / `test.ended` com extras (stage_key, phase, exit_code, duration_ms, report_path)
4. Adicionar enum case `TestStarted` / `TestEnded` em `EventName` se não existir
5. Adicionar enum values em `FieldAllowlist` pra `stage_key`
6. Tests:
   - `CodeguardTestCommandTest` — E2E stub do runner, verify comando passa config correta
   - `CodeguardTestTelemetryTest` — verify emission dos eventos
7. Atualizar `NextStepsReporter` se precisar (adicionar next-step pra `codeguard:test`)
8. Atualizar `CodeguardServiceProvider::bootConsole()` pra registrar o command

**Commit**: `feat(commands): codeguard:test with Layer 5 telemetry instrumentation`

## Critérios de sucesso (sprint 8)

- ✅ Suite passando >= 360 tests (325 atual + ~35 novos esperados)
- ✅ `php artisan codeguard:test --stage=unit` roda em projeto teste
- ✅ Config default tem stages mínimos utilizáveis (unit, feature) sem refs Arch
- ✅ Telemetria Layer 5 emite jsonl válido
- ✅ Zero refs a Playwright/MongoDB/Nova nos novos arquivos
- ✅ Tests cobrem run sequential + parallel + fail-fast + report modes

## Validação end-to-end (fim da sprint, antes do commit final)

```bash
cd /home/henry/codeguard
vendor/bin/pest --colors=never        # todos verdes
vendor/bin/pint --test src/Testing/ src/Commands/Codeguard*  # formatação
git log --oneline origin/main..HEAD | wc -l                  # ~60 commits ahead
```

**No Arch** (smoke test real):
```bash
cd /home/henry/arch
composer update henryavila/codeguard --no-interaction
# Configurar stages no config/codeguard.php (se não publicado ainda):
#   copiar da config default do package + customizar (Arch já tem stages próprios no app/Services/Testing/)
php artisan codeguard:test --stage=unit --mode=fast-fail
# Esperado: roda pest --testsuite=Unit e retorna exit code + telemetria
```

## Princípios da sessão 8

- **DDD-inspired (NÃO strict)** — Service→Model OK, Testing namespace fica framework-free, mas pode depender de Symfony\Process (já é convenção)
- **Evidence-based** — qualquer estimativa "1-2h" requer "com base em quê?"
- **Privacy first** — telemetria nunca capta string livre; stage_key vira enum
- **Prefer simplification** — se 5 stages hardcoded vira 2 stages util default na config, melhor
- **NÃO reabrir decisões fechadas** (ver PROJECT-STATUS "Diretrizes que NÃO podem ser reabertas")

## Ao terminar a sessão 8

1. Atualizar `PROJECT-STATUS.md` (HEAD novo, contadores, sprint 9 = Arch migra imports inline→package OU release alpha prep)
2. Atualizar `conversation-handoff.md` com narrativa sessão 8 (o que entrou, decisões tomadas, tradeoffs)
3. Se algum item ficou pendente (esp. bloco 4 telemetria — pode demandar mais tempo): documentar exatamente onde parou
4. Confirmar `git status` working tree limpo
5. Se viável: começar rascunho do README mínimo pra release alpha (bloco 4 do roadmap spec v5)

## Após sessão 8 (sprint 9 candidata)

Se bloco 4 completou: **Arch migra `App\Services\Testing` inline → `Henryavila\Codeguard\Testing`**:
- Atualizar `tests/Arch/ArchitectureTest.php` pra permitir ambos namespaces durante transição
- Remover `app/Services/Testing/*` do Arch
- Update `composer.json quality` script pra usar `php artisan codeguard:test` (ou paralelo por enquanto)

Alternativa: **release `1.0.0-alpha.1`** — README mínimo, CHANGELOG bootstrap, `git tag v1.0.0-alpha.1`, `git push origin main && git push --tags`.

Boa sessão.
