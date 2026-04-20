# Spec: CaptainHook Migration + Local Telemetry + Install UX

**Data**: 2026-04-17
**Status**: Draft — aguarda aprovação para implementação
**Autor**: Henry Ávila + Claude
**Referências**:
- [ADR-010](../../.ai/memory/architecture-decisions.md#adr-010) — decisão Lefthook → CaptainHook
- [Q13](../../.ai/memory/open-questions.md#q13) — hipóteses mensuráveis
- [Q14](../../.ai/memory/open-questions.md#q14) — design da telemetria (aprovado)
- [Spec v5 canonical](./2026-04-16-codeguard-v2-architecture.md) — arquitetura base que este spec modifica

---

## 1. Goals / Non-Goals

### Goals

1. **G1 — Onboarding zero-fricção**: após `composer install`, hooks Git estão ativos sem comando manual adicional. Nenhum binário externo precisa estar no PATH.
2. **G2 — Coerência com ADR-001**: 100% Composer, 0% Node/Go/OS-specific binários.
3. **G3 — Plataforma extensível**: CodeGuard ganha capacidade de shippar quality checks Laravel-nativos como PHP Actions classes (diferencial vs wrappers de CLI).
4. **G4 — UX do installer não deixa warnings passarem**: "binary missing" ou similar nunca fica soterrado no meio da saída.
5. **G5 — Telemetria local opt-in cobrindo 7 camadas**: dados nunca saem da máquina do user; Claude analisa o `.jsonl` diretamente quando o user solicitar.
6. **G6 — Privacidade blindada**: zero PII, zero paths, zero código, zero identidade. Enforcement via allowlist de enums (não string livre).

### Non-Goals

- **NG1 — Retrocompatibilidade com configs Lefthook existentes**: v0.x do CodeGuard ainda não é usado por terceiros; cutover direto sem layer de compat.
- **NG2 — Dashboard de telemetria no pacote**: Claude é o analista; pacote só grava/apaga.
- **NG3 — Auto-export da telemetria**: pacote nunca cria copy/zip/send; user expõe o arquivo manualmente se quiser compartilhar.
- **NG4 — Suporte formal a Lefthook como backend alternativo**: Lefthook sai como código ativo. Fica como "alternativa manual documentada".
- **NG5 — Infrastructure-as-Code para telemetria remota**: sem OTel, sem StatsD, sem HTTP.
- **NG6 — Feature parity imediata com `lefthook stage_fixed: true`**: tratada como PHP Action separada em fase futura (documentada, não bloqueia cutover).

---

## 2. Architecture Overview

### 2.1. Três camadas independentes entregues em sequência

```
┌─────────────────────────────────────────────────────────────────────┐
│ Phase A — CaptainHook Migration (blocker para B)                    │
│   • src/Install/CaptainhookInstaller.php (ex-LefthookInstaller)     │
│   • resources/stubs/captainhook.json.stub (ex-lefthook.yml.stub)    │
│   • composer.json: drop lefthook refs, add captainhook deps         │
│   • CodeguardInstallCommand rewire                                  │
│   • GatePlanRegistry + NextStepsReporter rename                     │
│   • Tests update                                                    │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│ Phase C — Install UX (independent of B, improves A output)          │
│   • src/Install/InstallSummary.php (coletor + renderer de warnings) │
│   • src/Install/CodeguardDirectoryInitializer.php (.codeguard/.gi.) │
│   • Exit codes: 0 (ok), 2 (setup incomplete)                        │
│   • Color + icon on per-gate status                                 │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│ Phase B — Telemetry (7 camadas)                                     │
│   • src/Telemetry/Recorder.php                                      │
│   • src/Telemetry/ConfigGate.php                                    │
│   • src/Telemetry/Rotator.php                                       │
│   • src/Telemetry/Event.php (value object com allowlist)            │
│   • src/Telemetry/MeasuredAction.php (CaptainHook decorator)        │
│   • src/Commands/Telemetry/{Enable,Disable,Clear}Command.php        │
│   • Instrumentação em install, gates, hooks, test, analyze, prepare │
│   • config/codeguard.php: seção telemetry                           │
│   • .codeguard/.gitignore auto-gerado                               │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2. Fluxo do `composer install` pós-migração

```
$ composer install
  ↓ composer resolve deps (inclui captainhook/captainhook + captainhook/hook-installer)
  ↓ captainhook/hook-installer plugin dispara post-install-cmd
  ↓ plugin executa `vendor/bin/captainhook install`
  ↓ .git/hooks/{pre-commit,commit-msg,pre-push,...} populados
  ✓ hooks ativos, ZERO comandos manuais
```

### 2.3. Fluxo de pre-commit com telemetria

```
$ git commit
  ↓ git dispara .git/hooks/pre-commit
  ↓ CaptainHook lê captainhook.json
  ↓ para cada Action configurada:
      ↓ MeasuredAction decorator start (hrtime inicial)
      ↓ Action::execute() executa (shell OU PHP class)
      ↓ MeasuredAction decorator end → Recorder::record('gate.ended', {...})
  ↓ Recorder::record('hook.completed', {hook_type, failed_action_count})   ← duration_ms + status são top-level do Event
  ✓ commit OK (ou fail com stderr do primeiro failed action)
```

---

## 3. File-by-File Change Plan

### 3.1. Phase A — Rename / Edit / Create

| Arquivo atual | Ação | Destino / Razão |
|---|---|---|
| [src/Install/LefthookInstaller.php](../../src/Install/LefthookInstaller.php) | Rename + rewrite | `src/Install/CaptainhookInstaller.php` — responsibilities shift: não precisa mais detectar binário, usa `vendor/bin/captainhook install` que SEMPRE existirá após `composer install`. Wraps outcome em `CaptainhookInstallResult`. |
| resources/stubs/lefthook.yml.stub | Rename + rewrite | `resources/stubs/captainhook.json.stub` — traduz 1:1 os hooks (ver §4). |
| (novo) | Create | `resources/stubs/captainhook.json.README.md.stub` — doc adjacente explicando cada action (compensa falta de comentários JSON). Publicado lado-a-lado pelo `StubRegistry`. |
| [src/Install/EnvironmentDetector.php](../../src/Install/EnvironmentDetector.php) | Edit | Remove `hasLefthookBinary` detection. Adiciona `hasCaptainhookBinary` (só para diagnóstico; instalação é automática). |
| [src/Install/EnvironmentInfo.php](../../src/Install/EnvironmentInfo.php) | Edit | `hasLefthookBinary` → `hasCaptainhookBinary`. |
| [src/Install/GatePlanRegistry.php](../../src/Install/GatePlanRegistry.php) | Edit | Gate `Lefthook` → `CaptainHook`, `configMinutes` fica em 10 (mesma ordem de grandeza). |
| [src/Install/NextStepsReporter.php](../../src/Install/NextStepsReporter.php) | Edit | Rename referências; remove passo manual de install (não necessário). |
| [src/Install/StubRegistry.php](../../src/Install/StubRegistry.php) | Edit | `lefthook.yml.stub` → `captainhook.json.stub` nas listas de stubs dos presets. |
| [src/CodeguardServiceProvider.php](../../src/CodeguardServiceProvider.php) | Edit | Binding `LefthookInstaller` → `CaptainhookInstaller`. |
| [src/Commands/CodeguardInstallCommand.php](../../src/Commands/CodeguardInstallCommand.php) | Edit | Inject `CaptainhookInstaller`. Atualiza copy de prompts e renderers. |
| [composer.json](../../composer.json) | Edit | `require --dev`: adicionar `captainhook/captainhook: ^5.29` + `captainhook/hook-installer: ^1.0`. Plugin allow-list em `config.allow-plugins`. |
| [config/codeguard.php](../../config/codeguard.php) | Edit | Descrição do preset: `+ CaptainHook` em vez de `+ Lefthook`. `protected_configs`: `captainhook.json` em vez de `lefthook.yml`. |
| [README.md](../../README.md) | Edit | Seção pre-commit refletindo CaptainHook. |
| tests/Feature/CodeguardInstallCommandTest.php | Edit | remove Lefthook assertions, add CaptainHook equivalents |
| tests/Unit/Install/GatePlanRegistryTest.php | Edit | renomear "Lefthook" → "CaptainHook" nos asserts |
| tests/Unit/Install/GatePlanTest.php | Edit | renomear "Lefthook" → "CaptainHook" |
| tests/Unit/Install/PresetSelectorTest.php | Edit | renomear |
| tests/Unit/Install/EnvironmentInfoTest.php | Edit | `hasLefthookBinary` → `hasCaptainhookBinary` |
| tests/Unit/Install/CaptainhookInstallerTest.php | **Create** | novo teste substituindo LefthookInstallerTest (não existente ainda; novo) |
| `src/Hooks/StagedPhpFilesRunner.php` | **Create** | PHP Action classe que implementa `CaptainHook\App\Hook\Action`. Lê `$repo->getIndexOperator()->getStagedFiles('php')`, monta argv com `binary + flags + stagedFiles`, roda via `symfony/process`. Opções configuráveis via `$action->getOptions()`. ~70 LOC. **Diferencial CodeGuard: primeira PHP Action shippada.** |
| `tests/Unit/Hooks/StagedPhpFilesRunnerTest.php` | **Create** | Mock de `Repository` + `IndexOperator`, asserts: (a) comando montado com staged files apenas, (b) no-op quando nada staged, (c) ActionFailed em exit non-zero, (d) respeita options.binary/flags. ~60 LOC. |

### 3.2. New Files (Phase C)

| Arquivo | Responsabilidade | Tamanho estimado |
|---|---|---|
| `src/Install/InstallWarning.php` | DTO readonly: `{level: Warning\|Error, code: enum, message: string, remediation: string}` | ~30 linhas |
| `src/Install/InstallSummary.php` | Coletor de warnings durante install; renderer do bloco final | ~80 linhas |
| `src/Install/CodeguardDirectoryInitializer.php` | Cria `.codeguard/` + `.gitignore` auto-populado (entradas: `telemetry.jsonl`, `telemetry-*.jsonl`, `baseline.json`, `phpstan-extensions.yaml`, `layer-decisions.yaml`). Invocado por `CodeguardInstallCommand` antes do publish. Idempotente. | ~40 linhas |
| `tests/Unit/Install/InstallSummaryTest.php` | Cobertura: sem warnings, múltiplos warnings, ordenação por severidade | ~60 linhas |
| `tests/Unit/Install/CodeguardDirectoryInitializerTest.php` | Cobertura: diretório novo, diretório existente (idempotência), gitignore já presente (não duplica entradas) | ~50 linhas |

### 3.3. New Files (Phase B)

| Arquivo | Responsabilidade | Tamanho estimado |
|---|---|---|
| `src/Telemetry/EventName.php` | Enum fechado das 7 camadas + meta: 20 casos (catálogo completo em §5.2) | ~40 linhas |
| `src/Telemetry/EventStatus.php` | Enum: `Ok`, `Fail`, `Skip` | ~10 linhas |
| `src/Telemetry/Event.php` | Value object readonly: `ts, event (EventName), status, duration_ms, extras (array<string, scalar>)` + `toArray()` serialização canônica | ~60 linhas |
| `src/Telemetry/FieldAllowlist.php` | Central allowlist de chaves e tipos permitidos em `extras`. Rejeita fields fora do allowlist. | ~80 linhas |
| `src/Telemetry/ConfigGate.php` | Lê `telemetry.enabled` 1× por processo; expõe `isEnabled(): bool`. | ~30 linhas |
| `src/Telemetry/Recorder.php` | Entry point: `record(Event): void` — no-op se gate disabled. Usa `Rotator` + `JsonlWriter`. | ~60 linhas |
| `src/Telemetry/Rotator.php` | Ao record(), se `telemetry.jsonl` > 10MB, move para `telemetry-YYYY-MM-DD-HHMMSS.jsonl`. Retém 5 mais recentes. | ~60 linhas |
| `src/Telemetry/JsonlWriter.php` | Append 1 linha JSON ao `.codeguard/telemetry.jsonl` atômico (flock). | ~40 linhas |
| `src/Telemetry/StopwatchScope.php` | Helper: `Stopwatch::time(EventName, array $extras, callable $inner)` — mede duração e grava evento automaticamente. | ~40 linhas |
| `src/Telemetry/MeasuredAction.php` | Decorator para CaptainHook Actions: implementa `Action`, delega para inner, emite `gate.started` / `gate.ended`. | ~50 linhas |
| `src/Commands/Telemetry/EnableCommand.php` | signature `codeguard:telemetry:enable` — persiste `telemetry.enabled=true` em `config/codeguard.php` (ou `.codeguard/telemetry.conf.php` se não editável). | ~30 linhas |
| `src/Commands/Telemetry/DisableCommand.php` | signature `codeguard:telemetry:disable` — idem, false. | ~30 linhas |
| `src/Commands/Telemetry/ClearCommand.php` | signature `codeguard:telemetry:clear` — confirm Y/n → `unlink` em `telemetry*.jsonl`. | ~40 linhas |
| `tests/Unit/Telemetry/*` | 7 arquivos, ~50-80 linhas cada: EventTest, FieldAllowlistTest (enforcement!), RecorderGateTest, RotatorTest, JsonlWriterTest, StopwatchScopeTest, MeasuredActionTest | ~450 linhas total |
| `tests/Feature/TelemetryPrivacyTest.php` | **E2E privacy check**: roda install + gates com telemetria ativa; lê o `.jsonl` gerado; falha se qualquer field contiver string suspeita (regex de paths `/^\//`, emails, etc). | ~80 linhas |
| `config/codeguard.php` | Seção nova `telemetry` com schema abaixo | ~20 linhas |

**Schema de `config/codeguard.php → telemetry`** (novo):
```php
'telemetry' => [
    'enabled' => env('CODEGUARD_TELEMETRY', false),  // opt-in explícito
    'path' => base_path('.codeguard/telemetry.jsonl'),
    'strict_mode' => env('CODEGUARD_TELEMETRY_STRICT', false),  // true → LogicException em field fora do allowlist; false → drop + log
    'rotation' => [
        'max_bytes' => 10 * 1024 * 1024,  // 10MB
        'keep_files' => 5,  // quantos .jsonl rotacionados manter
    ],
],
```

**Nota**: `.codeguard/.gitignore` é responsabilidade de `CodeguardDirectoryInitializer` (listado em §3.2 Phase C). Phase B depende dele já existir para que `telemetry.jsonl` não seja commitado acidentalmente — por isso Phase C precede Phase B no roadmap.

### 3.4. Total LOC Estimado

- Phase A: ~430 LOC (300 rename/edit + 130 novos com StagedPhpFilesRunner + test) + 1 commit de tag
- Phase C: ~260 LOC novos + ~50 LOC edits no install command (inclui CodeguardDirectoryInitializer + test)
- Phase B: ~1.100 LOC novos (600 produção + 500 testes)

Granular total: **~1.830 LOC**. 32% tests (610 LOC) + 720 LOC de produção em 14 classes pequenas.

---

## 4. CaptainHook Mapping (tradução 1:1 do lefthook.yml.stub)

### 4.1. captainhook.json.stub — versão proposta (opção β aprovada 2026-04-19)

**Paridade funcional com Lefthook via PHP Action**: o stub usa `StagedPhpFilesRunner` (nova classe shippada em Phase A) para rodar PHPStan apenas em arquivos `*.php` staged. Mesmo tempo de wall-clock que o Lefthook com `{staged_files}` entregava (~3-8s típico). O stub separa pré-commit (rápido, staged-only) de pre-push (defesa em profundidade, full suite).

```json
{
  "commit-msg": {
    "enabled": true,
    "actions": []
  },
  "pre-commit": {
    "enabled": true,
    "actions": [
      {
        "action": "vendor/bin/pint --dirty",
        "conditions": [
          {
            "exec": "\\CaptainHook\\App\\Hook\\Condition\\FileStaged\\OfType",
            "args": ["php"]
          }
        ]
      },
      {
        "action": "\\Henryavila\\Codeguard\\Hooks\\StagedPhpFilesRunner",
        "options": {
          "binary": "vendor/bin/phpstan",
          "flags": ["analyse", "--no-progress", "--memory-limit=1G"]
        },
        "conditions": [
          {
            "exec": "\\CaptainHook\\App\\Hook\\Condition\\FileStaged\\OfType",
            "args": ["php"]
          }
        ]
      }
    ]
  },
  "pre-push": {
    "enabled": true,
    "actions": [
      {
        "action": "vendor/bin/pest --bail"
      }
    ]
  }
}
```

**Valor estratégico do β**: `StagedPhpFilesRunner` é a primeira PHP Action diferencial do CodeGuard. Serve dois propósitos:
1. Feature-parity com Lefthook no pre-commit.
2. Prova de conceito que valida o argumento central do ADR-010 ("CaptainHook é plataforma, não só runner"). Q13-H4 começa respondida desde o dia 1 de CaptainHook em produção.

### 4.2. Stub será documentado com comentários adjacentes

**Decisão (resolvida neste spec)**: default JSON. CaptainHook suporta config PHP também (permite comentários e lógica), mas JSON é a forma canônica da documentação oficial e o ecossistema tooling (IDE JSON schema hints, diff mais legível em PR) favorece JSON. Seguimos JSON.

Como JSON não aceita comentários nativos, CodeGuard publica lado a lado um arquivo `captainhook.json.README.md` explicando cada action. Ambos são parte do stub (`StubRegistry` → 2 arquivos relacionados).

Revisão futura: se a complexidade do stub crescer (condições dinâmicas, lógica condicional por preset), migrar para `captainhook.php`. Não é prioridade agora.

### 4.3. Gaps vs Lefthook (Phase A pós-β)

| Feature Lefthook | Estado em Phase A | Roadmap futuro |
|---|---|---|
| `parallel: true` | sequencial | issue #249 upstream; aceitar limitação |
| `{staged_files}` templating | **Resolvido via PHP Action `StagedPhpFilesRunner`** (Phase A inclui) | — |
| `stage_fixed: true` | dev faz `git add` manual após Pint reformatar | PHP Action `AutoStageAfterFormatter` (pós-B) |
| `skip: [merge, rebase]` | builtin via `conditions.IsMergeCommit` já disponível | — |
| `LEFTHOOK=0` env skip | `CAPTAINHOOK_SKIP=1` equivalente | — |

---

## 5. Telemetry Formal Schema

### 5.1. Event shape (canônico)

Todo evento é exatamente um objeto JSON em uma linha (`.jsonl`):

```json
{"ts":"2026-04-17T14:32:01.123-03:00","event":"gate.ended","status":"ok","duration_ms":4230,"gate":"phpstan","context":"pre-commit","violations_count":0,"files_scanned_count":3}
```

Campos comuns obrigatórios:
- `ts`: ISO-8601 com timezone local (não UTC — ajuda debug user-side)
- `event`: enum fechado `EventName` (20 valores — catálogo §5.2)
- `status`: `ok` | `fail` | `skip`
- `duration_ms`: int (0 permitido para eventos instantâneos)

Campos extras: allowlist específica por `EventName`.

### 5.2. Event catalog (7 camadas + 1 meta)

| # | Camada | event name | extras permitidos |
|---|---|---|---|
| 1 | Command | `command.start` | `command` (enum: install\|check\|test\|prepare\|analyze\|baseline\|telemetry), `preset_flag` (enum: default\|full\|codeguard\|codeguard-full\|null) |
| 1 | Command | `command.end` | `command`, `exit_code` (int 0-255) |
| 2 | Install | `install.env.detected` | `php_version_major_minor` (enum: 8.3\|8.4\|8.5\|other), `composer_version_major` (int 1\|2), `has_node` (bool), `has_captainhook_binary` (bool) |
| 2 | Install | `install.preset.selected` | `preset` (enum), `source` (enum: auto\|flag\|prompt) |
| 2 | Install | `install.phpstan_extensions.selected` | `count` (int), `enum_values` (list<enum PhpstanExtension>) |
| 2 | Install | `install.stub.processed` | `stub_name` (enum: pint\|phpstan\|phpstan-test-quality\|deptrac\|infection\|captainhook\|jscpd\|test-quality-test), `status` (enum: created\|unchanged\|overwritten\|kept_custom\|skipped), `diff_lines_added` (int), `diff_lines_removed` (int) |
| 2 | Install | `install.deptrac.detected` | `namespace_count` (int), `auto_classified_count` (int), `auto_skip_count` (int), `unclassified_count` (int) |
| 2 | Install | `install.deptrac.wizard_decision` | `layer_assigned` (enum: Domain\|Application\|Presentation\|Infrastructure\|Skip\|Custom), `was_saved_choice` (bool) |
| 2 | Install | `install.captainhook.activated` | `status` (enum: installed\|skipped\|failed) |
| 2 | Install | `install.next_steps.rendered` | `count` (int) |
| 3 | Gate | `gate.started` | `gate` (enum: pint\|phpstan\|deptrac\|infection\|jscpd\|insights\|test_quality\|audit), `context` (enum: pre-commit\|pre-push\|ci\|manual) |
| 3 | Gate | `gate.ended` | mesmos + `violations_count` (int), `files_scanned_count` (int) |
| 4 | Hook | `hook.triggered` | `hook_type` (enum: pre-commit\|commit-msg\|pre-push\|post-checkout), `action_count` (int) |
| 4 | Hook | `hook.completed` | `hook_type`, `failed_action_count` (int) |
| 5 | Test | `test.started` | `context` (enum: manual\|ci\|pre-push), `with_coverage` (bool) |
| 5 | Test | `test.ended` | `pass_count`, `fail_count`, `skip_count` (ints), `coverage_percent` (int 0-100 ou -1 for "unknown") |
| 6 | Analyze | `analyze.ended` | `patterns_checked_count` (int), `matches_count` (int) |
| 6 | Baseline | `baseline.ended` | `tool` (enum: phpstan\|deptrac), `entries_saved_count` (int) |
| 7 | Prepare | `prepare.step.ended` | `step_name` (enum: dump_schema\|hash_check\|migrations_run\|seed), `connection` (enum: sqlite\|mysql\|pgsql\|sqlsrv) |
| 0 | Meta | `telemetry.dropped_field` | `event` (enum EventName — qual evento teve field dropado), `field_name` (enum: allowlisted key) — **sem valor**, só identifica a key que violou para debug |

**Total: 20 eventos canônicos**. Expansível via PR — adicionar novo case em `EventName` + regra em `FieldAllowlist`.

### 5.3. FieldAllowlist enforcement

Classe `FieldAllowlist` expõe:
```php
public function validate(EventName $event, array $extras): array  // returns normalised extras
public function rejectFreeformStrings(array $extras): void  // throws if any string not in enum
```

Violação em dev → `LogicException` (refuse to serialize). Em prod (`config.telemetry.strict_mode=false`) → campo é descartado silenciosamente e um evento `telemetry.dropped_field` é gravado (sem o valor offensor).

### 5.4. Privacy invariants testados automaticamente

`tests/Feature/TelemetryPrivacyTest.php`:
1. Roda `codeguard:install --no-interactive` com telemetria ativada.
2. Lê `.codeguard/telemetry.jsonl`.
3. Para cada linha, valida:
   - Zero substring match `/home/`, `/Users/`, `C:\\`
   - Zero match para regex de email, UUID git, SHA-1, URL
   - Zero field livre (string que não seja timezone/event/status/enum conhecido)
4. Fail loud se encontrar.

---

## 6. Migration Strategy

### 6.1. Cutover direto (não-compat)

Justificativa:
- CodeGuard está em `v0.1.x`; nenhum consumer externo.
- Único consumer real é Arch (path repository), e ele adapta junto.
- Coexistência temporária (suportar Lefthook E CaptainHook) dobraria superfície sem ganho real.

### 6.2. Sequência de implementação (commits granulares)

**Phase A — 8 commits** (β: inclui PHP Action):
0. `chore(git): tag v0-last-lefthook before migration` — cria tag pre-migration para rollback documentado em §8.2
1. `refactor(composer): add captainhook/captainhook + hook-installer deps` — roda `composer install` para ter vendor/bin disponível nos passos seguintes
2. `feat(hooks): add StagedPhpFilesRunner PHP Action + test` — primeira PHP Action shippada pelo CodeGuard (Q13-H4 proof-of-concept)
3. `refactor(hooks): rename LefthookInstaller → CaptainhookInstaller`
4. `feat(hooks): add captainhook.json.stub + captainhook.json.README.md.stub (wires StagedPhpFilesRunner)`
5. `refactor(install): wire CaptainhookInstaller into install command`
6. `refactor(config): rename lefthook refs in config/codeguard.php + preset descriptions`
7. `test(install): update all 5 tests referencing Lefthook + new CaptainhookInstallerTest`

**Phase C — 3 commits**:
8. `feat(install): add InstallSummary warning aggregator + final report block`
9. `feat(install): exit code 2 when setup is incomplete, colored per-gate status`
10. `feat(install): CodeguardDirectoryInitializer generates .codeguard/.gitignore`

**Phase B — 5 commits**:
11. `feat(telemetry): Event, EventName, EventStatus, FieldAllowlist value objects with tests`
12. `feat(telemetry): Recorder + ConfigGate + Rotator + JsonlWriter with tests`
13. `feat(telemetry): StopwatchScope + MeasuredAction decorator with tests`
14. `feat(telemetry): 3 Artisan commands (enable/disable/clear) + privacy E2E test`
15. `feat(telemetry): instrument install/gates/hooks/test/analyze/prepare layers`

Cada commit passa `pest` + não quebra arch. Total: **16 commits** (A=8 incluindo tag + StagedPhpFilesRunner, C=3, B=5), cada um < 250 LOC.

### 6.3. Testing checkpoints

- Após #7 (fim Phase A): `vendor/bin/pest` verde + `cd /home/henry/arch && php artisan codeguard:install --no-interactive` funciona (hooks Git CaptainHook ativos, pre-commit executa Pint + PHPStan-staged em segundos).
- Após #9 (Phase C): saída do install com warning destacado em cor + exit 2 quando falta algo.
- Após #10 (fim Phase C): `.codeguard/.gitignore` gerado automaticamente; `git status` mostra que telemetria e demais arquivos locais ficam ignorados.
- Após #15 (fim Phase B): `telemetry.jsonl` populado após ciclo install+check+commit no Arch; privacy test verde.

---

## 7. Test Plan

### 7.1. Unit tests (todos)

- **Phase A**: 5 arquivos de teste existentes atualizados (GatePlanTest, GatePlanRegistryTest, PresetSelectorTest, EnvironmentInfoTest, CodeguardInstallCommandTest) + 1 novo (`CaptainhookInstallerTest`).
- **Phase C**: `InstallSummaryTest` (~60 linhas) + `CodeguardDirectoryInitializerTest` (~50 linhas).
- **Phase B**: 7 unit test files (~450 linhas total) + 1 feature privacy test.

### 7.2. Integration — End-to-end no Arch

Cenários a validar manualmente nos 3 checkpoints (após commit #7 da Phase A, após #10 da C, após #15 da B):

1. **Fresh Arch install** (rm `vendor/ .codeguard/` → `composer install`): hooks pre-commit ativos sem `vendor/bin/captainhook install` manual.
2. **Install wizard interativo**: prompts de telemetria aparecem; opt-in default N; escolha persiste em `config/codeguard.php`.
3. **Primeiro commit com pre-commit real**: Pint + PHPStan rodam; `.codeguard/telemetry.jsonl` ganha 4+ eventos (hook.triggered, 2× gate.ended, hook.completed).
4. **Privacy leak test** manual: `rg -nP '(/home/|/Users/|C:\\\\|[\w.+-]+@[\w-]+\.[\w.-]+|\b[0-9a-f]{40}\b|https?://)' .codeguard/telemetry.jsonl` retorna nada (regex: paths Linux/macOS/Windows, email, SHA-1 hash, URL).
5. **Disable flow**: `php artisan codeguard:telemetry:disable` → próximo commit não grava nada em `.jsonl`.
6. **Clear flow**: `php artisan codeguard:telemetry:clear` → `.jsonl` some (após confirm).

### 7.3. Coverage targets

- Total: ≥80% (padrão do projeto).
- Telemetry module: ≥95% (crítico por ser nova superfície de privacidade).
- Privacy test (`TelemetryPrivacyTest`): 100% das linhas do pipeline instalação→commit.

---

## 8. Risks & Rollback

### 8.1. Risks

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| CaptainHook sequencial deixa pre-commit >30s no Arch | Média | Médio | Telemetria vai pegar. Se acontecer, avaliar PHP Action `StagedFilesRunner` pra paralelizar via symfony/process. |
| `hook-installer` não ativa hooks em edge case (Windows?) | Baixa | Médio | Documentar `vendor/bin/captainhook install` manual como fallback. |
| Allowlist de fields rejeita um valor legítimo | Média (no início) | Baixo | `strict_mode=false` por default → descarta campo + grava evento `telemetry.dropped_field`; user abre issue; fix via PR. |
| `.codeguard/telemetry.jsonl` fica enorme em CI (milhares de runs) | Baixa | Baixo | Rotator limita a 5 arquivos × 10MB = 50MB max. CI geralmente começa clean, então pouco acúmulo. |
| Opt-in frustrating user (spam de prompt toda instalação) | Baixa | Médio | Prompt só aparece 1× na primeira instalação; persist escolha em config. |
| Privacy test flaky (regex pega falso positivo) | Média | Alto (bloqueia CI) | Iterar regex cuidadosamente; test local primeiro. |

### 8.2. Rollback strategy

**Phase A rollback**: `git checkout v0-last-lefthook` (tag criada no commit #0 da fase) OU `git revert` dos commits 1-7. Impacto: 1 comando git.

**Phase C rollback**: revert commits 8-10. Baixo risco (só altera renderers + adiciona gitignore).

**Phase B rollback**: `php artisan codeguard:telemetry:disable` resolve em runtime sem deploy. Remoção de código: revert commits 11-15.

### 8.3. ADR-010 re-evaluation triggers (duplicado do ADR para visibilidade)

Revert de Phase A pra Lefthook se:
- H1 violado (pre-commit >30s regular em 3+ projetos consumidores)
- CaptainHook sem release >6 meses OU CVE não patched
- PHP Action system se revelar menos útil que o previsto (H4 falha)

---

## 9. Estimation & Uncertainty Budget

### 9.1. Tempo estimado (com intervalo de incerteza)

| Phase | Low (P20) | Expected (P50) | High (P80) |
|---|---|---|---|
| A — CaptainHook migration (β com StagedPhpFilesRunner) | 3h | **4h** | 6h |
| C — Install UX | 45min | **1h 15min** | 2h |
| B — Telemetry (7 camadas) | 4h | **6h** | 9h |
| **Total** | 7h 45min | **11h 15min** | 17h |

Incerteza high é maior em B porque:
- Primeira implementação do FieldAllowlist pode pegar edge cases que exigem iteração.
- Privacy E2E test pode ficar flaky na primeira tentativa.
- Instrumentar 7 camadas = 7 pontos de edit em arquivos diferentes; se algum precisar refactor de DI, infla.

### 9.2. Pontos de verificação durante execução

Após cada phase, reportar ao user:
- Tempo real gasto vs estimado
- Descobertas que mudam o plano
- Se desvio > 2× expected, pausar e revisar

### 9.3. O que NÃO está no escopo deste spec (backlog)

- **Paralelismo pre-commit** via PHP Action custom — fase separada pós-B se H1 falhar.
- **`stage_fixed` equivalente** — fase separada após termos primeira PHP Action básica funcionando.
- **Quality Actions Laravel-específicas** (migration sem down, FormRequest sem rules, etc.) — vai para backlog de diferencial CodeGuard (Q13-H4).
- **Dashboard de telemetria externo (user-facing)** — não está nos non-goals por preguiça: está explicitamente NON-GOAL porque Claude é o analista.
- **Instrumentação de comandos Artisan de terceiros** — telemetria cobre apenas comandos `codeguard:*`.

---

## 10. Approval Checklist

Antes de começar implementação, user confirma:

- [ ] Seção 1 (Goals/Non-Goals) — nenhum goal faltando? nenhum non-goal surpresa?
- [ ] Seção 2 (Architecture) — sequência A→C→B OK?
- [ ] Seção 4 (CaptainHook mapping) — gaps aceitos ou quer algum deles na Phase A?
- [ ] Seção 5 (Telemetry schema) — 20 eventos suficientes? sobra? falta alguma camada?
- [ ] Seção 6 (Migration strategy) — cutover direto aprovado? ou quer coexistência?
- [ ] Seção 9 (Estimation) — 10h expected aceitável? ou quer quebrar em entregas menores?

Aprovação explícita esperada antes de tocar código. Qualquer ajuste vira edit neste spec primeiro.
