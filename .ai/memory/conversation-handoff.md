---
name: Conversation Handoff
description: Onde paramos — próximo passo concreto
type: project
---

# Conversation Handoff

**Última atualização**: 2026-04-19/20 (sessão 3 — CaptainHook migration Phase A β completa)

**Sessões prévias**:
- Sessão 1: Pivot Node→PHP, 10 reviews, consolidação memória
- Sessão 2 (2026-04-16/17): Redesign de presets (3→2), Onda 1/2/3, ADR-009, expansão PHPStan ecosystem, Deptrac wizard 4 camadas
- Sessão 3 (2026-04-19/20): ADR-010 Lefthook→CaptainHook + spec 2026-04-17 + Phase A β implementada end-to-end

---

## Estado Atual (2026-04-20)

### Working tree
Branch `main`, limpo. Último commit: `8e07024 fix(hooks): run captainhook install with --force --only-enabled`.

### Tag de rollback
`v0-last-lefthook` criada pre-migração (commit alvo de `git checkout` se precisar reverter).

### Suite de testes
**116 testes / 272 assertions, todos verdes.**
```bash
cd /home/henry/codeguard && vendor/bin/pest --colors=never
```

### Validação end-to-end
Feita em `/home/henry/arch` (path repository):
- `composer update henryavila/codeguard` puxa captainhook + hook-installer + deps
- `php artisan codeguard:install --no-interactive` publica stubs + wire hooks
- 3 hooks ativos: commit-msg, pre-commit, pre-push (em `.husky/_/` porque Arch tinha `core.hooksPath` legado — CaptainHook respeita)
- `./husky/_/pre-commit` dry-run despacha Pint (--dirty) + StagedPhpFilesRunner (PHPStan sobre staged) corretamente

### Ferramentas migradas
| Aspecto | Antes (Lefthook) | Agora (CaptainHook) |
|---|---|---|
| Distribuição | Go binary fora do Composer | `captainhook/captainhook` + `captainhook/hook-installer` em `require` do codeguard (não `require-dev`, pra propagar pros consumidores) |
| Onboarding | brew/apt/npm/binary | `composer install` + 1× `composer config allow-plugins.captainhook/hook-installer true` no projeto consumidor (fricção mínima) |
| Stub | `lefthook.yml` | `captainhook.json` + `captainhook.json.README.md` (docs adjacente porque JSON não tem comments) |
| PHP Action diferencial | n/a | `StagedPhpFilesRunner` — primeira Action shippada pelo codeguard (Q13-H4 proof-of-concept) |

---

## Spec Canônico da Migração

**`docs/specs/2026-04-17-captainhook-migration-and-telemetry.md`** — leia antes de tocar código. Opção **β aprovada** (não α). 3 phases:

| Phase | Status | Commits |
|---|---|---|
| A — CaptainHook migration | ✅ **COMPLETA** (2026-04-20) | 9 commits + 1 tag |
| C — Install UX | ⏳ próximo | 3 commits planejados |
| B — Telemetry 7-camadas | 🔜 depois de C | 5 commits planejados |

---

## Próximo Passo Concreto: Phase C

### Escopo (3 commits, P50 ~1h 15min)

**Commit #8** — `feat(install): add InstallSummary warning aggregator + final report block`
- Criar `src/Install/InstallWarning.php` (~30 LOC) — DTO readonly: `{level: Warning|Error, code: enum, message: string, remediation: string}`
- Criar `src/Install/InstallSummary.php` (~80 LOC) — coletor de warnings durante install + renderer do bloco final
- Integrar em `CodeguardInstallCommand`: cada detecção de pendência (PHP < 8.3, composer.lock stale, captainhook plugin não autorizado, Node missing no preset Full) `$summary->warn(...)`. Render final antes dos next-steps.
- Criar `tests/Unit/Install/InstallSummaryTest.php` (~60 LOC) — sem warnings, múltiplos warnings, ordenação por severidade

**Commit #9** — `feat(install): exit code 2 when setup is incomplete, colored per-gate status`
- `CodeguardInstallCommand::handle()` retorna `self::SUCCESS` (0) se summary não tem warnings, `2` se tem pelo menos 1 Warning/Error
- Status dos gates: `✔ green` (ok), `⚠ yellow` (warning), `✘ red` (error). Atualmente tudo mostra cinza.
- CI/scripts podem detectar "setup incomplete" via `$? -ne 0`

**Commit #10** — `feat(install): CodeguardDirectoryInitializer generates .codeguard/.gitignore`
- Criar `src/Install/CodeguardDirectoryInitializer.php` (~40 LOC) — cria `.codeguard/` na primeira instalação + escreve `.gitignore` com entradas: `telemetry.jsonl`, `telemetry-*.jsonl`, `baseline.json`, `phpstan-extensions.yaml`, `layer-decisions.yaml`
- Invocado por `CodeguardInstallCommand` **antes** do publish de stubs. Idempotente.
- Teste `tests/Unit/Install/CodeguardDirectoryInitializerTest.php` (~50 LOC): diretório novo, diretório existente, gitignore já presente (não duplica)

### Follow-ups capturados na Phase A (integrar em Phase C)

**(a) Auto-configure allow-plugins no projeto consumidor**
- Descoberta: `composer require --dev henryavila/codeguard` falha silenciosamente com "captainhook/hook-installer is blocked by your allow-plugins config" no projeto consumidor. User precisa rodar: `composer config allow-plugins.captainhook/hook-installer true` 1×.
- Opções:
  - (i) `CodeguardInstallCommand` detecta (lê composer.json do consumidor), se bloqueado, oferece `confirm("Auto-add to allow-plugins?")` e reescreve composer.json
  - (ii) Apenas adicionar no `InstallSummary` um warning com remediation precisa
- **Recomendação**: (i) no commit #9, fallback (ii) se o user dizer "não" à auto-config
- Arquivo provável: `src/Install/ComposerAllowPluginsCheck.php` (~40 LOC novo)

**(b) README do stub CaptainHook menciona core.hooksPath**
- Descoberta: Arch tinha `core.hooksPath=.husky/_` (Husky legado). CaptainHook instalou em `.husky/_/` ao invés de `.git/hooks/`. Funciona, mas pode confundir devs.
- Adicionar seção em `resources/stubs/captainhook.json.README.md.stub`: "Where hooks actually live — if `git config --get core.hooksPath` retorna algo não-vazio, hooks vão pra lá. Normal em projetos migrando de Husky."

---

## Phase B (depois de C)

Spec §5. 5 commits, ~6h estimated. **Não começar sem completar C primeiro** (Phase B depende do `.codeguard/.gitignore` criado em #10 para que `telemetry.jsonl` seja auto-ignorado).

**Resumo**:
- 20 eventos em 7 camadas + 1 meta (catálogo em spec §5.2)
- `FieldAllowlist` enforce privacy (enum-only, zero string livre)
- `Recorder` + `ConfigGate` + `Rotator` + `JsonlWriter`
- `MeasuredAction` decorator (wrapper para CaptainHook Actions)
- 3 Artisan: `codeguard:telemetry:enable/:disable/:clear`
- **TelemetryPrivacyTest** E2E (rg -nP regex de paths/emails/hash/URL)

---

## Como Retomar (cheatsheet para próxima sessão)

```bash
# 1. Claude lê automaticamente:
#    CLAUDE.md → .ai/memory/MEMORY.md → este arquivo

# 2. Verificar estado:
cd /home/henry/codeguard
git log --oneline -5
git status                    # deve estar limpo
vendor/bin/pest --colors=never  # deve dar 116 passed
git tag -l | grep lefthook    # deve mostrar v0-last-lefthook

# 3. Ler o spec (obrigatório — modifica arquitetura):
cat docs/specs/2026-04-17-captainhook-migration-and-telemetry.md

# 4. Começar Phase C #8:
#    - Ler src/Install/InstallWarning.php (não existe ainda — criar)
#    - Ler src/Commands/CodeguardInstallCommand.php para ver onde plugar
#    - TDD: escrever InstallSummaryTest primeiro
```

### Diretrizes que NÃO podem ser reabertas nesta sessão

- **ADR-010 (CaptainHook)**: decidido, implementado, validado. NÃO voltar para Lefthook sem trigger explícito do ADR (perf > 30s em produção OU maintainer inativo 6mo+).
- **Opção β (StagedPhpFilesRunner na Phase A)**: shipped. Não reabrir "pra simplificar".
- **Phase order A → C → B**: C antes de B por causa de `.codeguard/.gitignore`. Manter ordem.
- **Cutover direto (não-compat)**: v0.x, nenhum consumer externo. Não reintroduzir Lefthook como backend alternativo.
- **3 comandos de telemetria (enable/disable/clear)**: não criar `export`, `dashboard`, `show`, `analyze`. Claude analisa o jsonl diretamente.
- **Privacy first**: `FieldAllowlist` é enum-only, sem strings livres. Nunca reabrir pra "conveniência".

### Se user questionar

Ele terá motivo novo — escutar, não tratar como re-discussão padrão. Mas confirmar antes de alterar decisões fixas.

---

## Lista de Tasks Persistida

O harness de tasks desta sessão (TaskList tool) pode não persistir entre sessões. Se o próximo Claude precisar recriar:

```
#1  Phase C #8 — InstallWarning + InstallSummary + test
#2  Phase C #9 — exit code 2 + colored per-gate status + auto-config allow-plugins (follow-up a)
#3  Phase C #10 — CodeguardDirectoryInitializer + test + .codeguard/.gitignore
#4  Phase B #11 — Event, EventName, EventStatus, FieldAllowlist + tests
#5  Phase B #12 — Recorder, ConfigGate, Rotator, JsonlWriter + tests
#6  Phase B #13 — StopwatchScope, MeasuredAction + tests
#7  Phase B #14 — 3 Artisan commands + TelemetryPrivacyTest E2E
#8  Phase B #15 — Instrumentar 7 camadas (install/gates/hooks/test/analyze/prepare)
```

---

## Arquivos-chave para ler antes de começar

**Obrigatórios** (context load):
1. [CLAUDE.md](../../CLAUDE.md)
2. [MEMORY.md](MEMORY.md)
3. [architecture-decisions.md](architecture-decisions.md) — especialmente ADR-010
4. [open-questions.md](open-questions.md) — especialmente Q13, Q14
5. [docs/specs/2026-04-17-captainhook-migration-and-telemetry.md](../../docs/specs/2026-04-17-captainhook-migration-and-telemetry.md) — canonical spec da migração

**Referenciais** (abrir só se relevante):
- `src/Install/CaptainhookInstaller.php` — pra entender o pattern do installer ao escrever CodeguardDirectoryInitializer
- `src/Hooks/StagedPhpFilesRunner.php` — pra entender o pattern de PHP Action (Phase B usa isso em MeasuredAction)
- `src/Install/PhpstanExtensionStore.php` — pra entender o pattern de YAML persistence (inspiração pro Rotator)
- `resources/stubs/captainhook.json.stub` + `.README.md.stub` — exemplo do stub-com-doc-adjacente

---

## Memória Global (Henry profile)

Em `/home/henry/.claude/projects/-home-henry-codeguard/memory/`:
- `feedback-evidence-based-estimates.md` — nunca inflar números, Henry pedirá "com base em quê?"
- `feedback-prefer-simplification.md` — quando 2 opções viram 3, simplificar
- `feedback-honest-tradeoffs.md` — mostrar custos junto com benefícios
- `feedback-node-when-justified.md` — Node OK se tool for genuinely melhor
- `feedback-portuguese-typos.md` — interpretar intenção, não letra
- `user-profile.md` — Laravel dev, multi-projeto, WSL2, Arch é consumer primário
- `project-codeguard-state.md` — stack, presets, dual-track

Essas memórias guiam como interagir com Henry, não o escopo técnico do projeto.
