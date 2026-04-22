---
name: Conversation Handoff
description: Onde paramos — próximo passo concreto
type: project
---

# Conversation Handoff

**Última atualização**: 2026-04-22 (sessão 7 — 8 tasks backlog + validação Arch + 2 stub fixes pré-existentes)

**Sessões**:
- Sessão 1: Pivot Node→PHP, 10 reviews, consolidação memória
- Sessão 2 (2026-04-16/17): Redesign de presets (3→2), Onda 1/2/3, ADR-009, expansão PHPStan ecosystem, Deptrac wizard 4 camadas
- Sessão 3 (2026-04-19/20): ADR-010 Lefthook→CaptainHook + spec 2026-04-17 + Phase A β implementada end-to-end
- Sessão 4 (2026-04-20): Phase C β completa em 4 commits + code-reviewer adversarial pass + review fixes aplicados
- Sessão 5 (2026-04-22): Phase B β completa em 5 commits + dual review gate (code-reviewer + security-reviewer) + 1 review-fix commit
- Sessão 6 (2026-04-22): análise install Arch (9 arquivos sobrescritos vs 3 percebidos) + comparação per-file + 8 merges aplicados no Arch + backlog 10 itens consolidado
- **Sessão 7 (2026-04-22)**: 8 tasks do backlog + validação **interativa** no Arch + 2 bugs pré-existentes do stub + **2 design gaps fechados** (wizard Deptrac e applier PHPStan passaram a respeitar `StubOverrides`). HEAD `e9c1269`. Suite 325 passed.

---

## Estado Atual (2026-04-22 — fim sessão 7)

### Working tree CodeGuard
Branch `main`. **Working tree limpo**, 53 commits ahead de `origin/main`.

### Working tree Arch (`/home/henry/arch`) — branch `chore/fix-composer-quality-debt`
9 arquivos modificados (merge da sessão 6 + sessão 7):

```
Modified:
  .jscpd.json, composer.json, composer.lock, infection.json5,
  phpstan-test-quality.neon, phpstan.neon, pint.json (dead-code providers
  removidos + _rule_docs movido top-level), tests/Arch/TestQualityTest.php
  deptrac.yaml — RESTAURADO à versão 30-layer (git checkout -- deptrac.yaml
  depois que install non-interactive tinha sobrescrito para 4-layer wizard)

Untracked: captainhook.json, captainhook.json.README.md
```

### Bugs corrigidos na sessão 7 (fora do backlog original)

- **Pint `_rule_docs` inside rules**: Pint 1.29+ rejeita chaves desconhecidas dentro de `rules`. Movido para top-level do JSON (sibling de `_comment`/`_docs`). Commit `cc3e776`.
- **shipmonk usageProviders.laravel/eloquent**: removidos em `shipmonk/dead-code-detector` 0.14+. Stub referenciava keys inexistentes → "Unexpected item" error. Removidos; built-in vendor provider cobre o use case. Commit `d936b43`.

### 2 design gaps descobertos E FIXADOS na sessão 7

Pattern: componentes que mutam arquivos sob raiz do projeto precisam consultar `StubOverrides` antes de gravar, com `--refresh-stubs` como escape hatch.

- **Gap #1 — Deptrac wizard** (`maybeSuggestDeptracLayers`): descoberto na validação non-interativa (install sobrescreveu a 30-layer config do Arch com wizard default 4-layer). Fix `fb63ed3` + 2 tests. O deptrac.yaml do Arch foi restaurado via `git checkout --`.
- **Gap #2 — PHPStan extension applier** (`applyPhpstanExtensionsToStub`): descoberto na validação **interativa** (usuário testou install interativo após fix #1; PHPStan acusou `Duplicated key '@codeguard:ext' on line 140` porque o applier tocou sentinels fragilizados de sessões anteriores). Fix `e9c1269` + 2 tests. Sentinels do Arch foram manualmente restaurados.

Ambos os fixes seguem o mesmo shape:
```php
if (! $forceOverwrite && $stubOverrides->contains($path)) {
    // short-circuit com mensagem explicativa
    return;
}
// ... caminho normal
```

### Papercuts menores anotados para pós-alpha

- `NextStepsReporter` tem hardcode `"Review level in phpstan.neon (currently 5)"` — não lê level real do arquivo.
- `StubOverrides::save()` sobrescreve arquivo com header canônico — perde comentários per-entry que o user escreva manualmente.

### O que entrou na sessão 7 (ordem cronológica)

| Commit | Tipo | Descrição |
|---|---|---|
| `04b36a0` | P0 fix | (session 6 carry-over) deptrac `regex` → `value` |
| `b89a32a` | P0 test | regression tests locking sentinel `#` preservation — bug não reproduz no código atual |
| `b4f2e1d` | P1 feat | `.codeguard/stub-overrides.yaml` — `StubOverrides` service + `KeptCustomPermanent` status + 4ª opção "Keep + remember" no prompt + `codeguard:install:override` command |
| `4c7a75e` | P1 feat | `LegacyStubCleaner` — prompt "Delete lefthook.yml?" interativo; warning no summary em `--no-interactive` |
| `fa2866c` | P2 feat | Carbon PHPStan extension always-on (no sentinel) |
| `65e59b6` | P2 feat | Peststan opt-in — enum case + `EnvironmentDetector::detectPestUsage()` + auto-preselect no selector + composer dep |
| `0446349` | P2 feat | infection excludes refine — Configurators/Middleware/Abstracts permanecem in-scope |
| `ebc709d` | P2 feat | Pint +3 rules (combine_unsets, combine_issets, explicit_string_variable) |
| `cc3e776` | fix | stub: `_rule_docs` para top-level (Pint 1.29+) |
| `d936b43` | fix | stub: drop shipmonk usageProviders.laravel/eloquent |
| `2d73935` | docs | memory: session 7 closeout inicial |
| `fb63ed3` | fix | **design gap #1**: wizard Deptrac respeita `StubOverrides` |
| `62cfdbb` | docs | SESSION-8-ARCH-TEST.md (roteiro pra teste pós-almoço) |
| `e9c1269` | fix | **design gap #2**: PhpstanExtensionApplier respeita `StubOverrides` |

### Resultados da validação sessão 7

| Alvo | Comando | Status |
|---|---|---|
| Suite CodeGuard | `vendor/bin/pest` | ✅ 325 / 787 (+41 vs início sessão) |
| Path repo refresh | `composer update henryavila/codeguard` (no Arch) | ✅ |
| Install NON-interativo Arch (validação 1) | `php artisan codeguard:install --no-interactive --preset=default` | ✅ mas descobriu gap #1 (deptrac.yaml sobrescrito pelo wizard) |
| Install **interativo** Arch (validação 2, pós-seed overrides) | `php artisan codeguard:install` | ✅ — Peststan auto-select, 4ª opção "Keep + remember" funciona, wizard Deptrac skip silencioso. Descobriu gap #2 (applier corrompeu sentinels). |
| Arch Pint | `vendor/bin/pint --test` | ✅ roda (reporta débito Arch — esperado) |
| Arch PHPStan | `vendor/bin/phpstan analyse` | ✅ roda pós-fix `e9c1269` + sentinels restaurados manualmente (1130 erros — débito Arch) |
| Arch Deptrac | `vendor/bin/deptrac analyse` | ✅ 5804 allowed / 0 violations (30-layer preservada pelo `fb63ed3`) |

### Estado final do Arch (working tree)

- `.codeguard/stub-overrides.yaml` com 7 entradas (`.jscpd.json`, `deptrac.yaml`, `infection.json5`, `phpstan-test-quality.neon`, `phpstan.neon`, `pint.json`, `tests/Arch/TestQualityTest.php`)
- `phpstan.neon` sentinels `#` restaurados manualmente (lines 118, 140, 172)
- `deptrac.yaml` 30-layer (687 linhas, 28 layers) intacto
- CaptainHook registrado com 3 hooks
- Todos os 7 arquivos customizados protegidos contra re-installs

### Aprendizados da sessão 7 (não mover pra ADR ainda)

1. **Pattern "design gap" identificado**: qualquer componente que mute arquivos sob raiz do projeto (não apenas `StubPublisher`) precisa consultar `StubOverrides` antes de gravar. Sessão 7 encontrou 2 ocorrências (wizard Deptrac + applier PHPStan) e fechou ambas. Pattern: `if (!$forceOverwrite && $stubOverrides->contains($path)) return;`.
2. **Pint 1.29+ validation stricter than 1.x**. Stub mantido "_rule_docs" inside `rules` por anos funcionou; upgrade quebrou. Lição: padrão "underscore comment keys" deve ficar sempre no nível raiz do JSON.
3. **Shipmonk dead-code 0.14+ schema mudou silenciosamente**. Nenhum deprecation warning — só um erro direto ao rodar. Lição: stub deve depender de features core, não de keys que podem sumir.
4. **Validação interativa descobre gaps que non-interativa esconde**. Fix #1 foi descoberto na non-interativa (sobrescreveu deptrac.yaml). Fix #2 precisou de interativa (com overrides já pré-seeded + extension multiselect exercitado) pra manifestar. Lição: validar ambos os modos a cada mudança de install flow.
5. **Pre-seed do `stub-overrides.yaml` no consumidor é UX friction real**. Na sessão 7 user precisou de 2 iterações (esqueceu `tests/Arch/TestQualityTest.php` de primeira) + 1 comando `install:override` pós-install. Lição: considerar command `codeguard:install:override --detect` que sugere paths baseado em diff vs stub.

### Próximos passos (sessão 8)

**Decisão pendente do Henry**: Opção A (TestSuiteRunner extract, ~6-8h, caminho crítico pra release alpha) ou Opção C (DDD-pragmatic Deptrac ruleset, ~2h15min, quick win). Recomendação do agente: Opção A — acelera release mínimo.

**Push para `origin/main`**: **não feito** (53 commits ahead). Ação shared-state que aguarda aprovação explícita do Henry.

Ver `PROJECT-STATUS.md` para análise de tradeoffs.

---

## Estado anterior (2026-04-22 — fim sessão 6)

### Working tree CodeGuard
Branch `main`. **Modificações UNCOMMITTED** desta sessão:

```
src/Install/DeptracLayerSuggester.php       — fix regex → value (linha 188-194)
resources/stubs/deptrac.yaml.stub           — fix regex → value (4 ocorrências)
tests/Unit/Install/DeptracLayerSuggesterTest.php — assertion ajustada (linha 240)
.ai/memory/MEMORY.md                        — link para spec 2026-04-17 atualizado
.ai/memory/architecture-decisions.md        — ADR-010 adicionado (sessões anteriores)
.ai/memory/open-questions.md                — Q13/Q14 telemetry hypotheses
docs/specs/2026-04-17-captainhook-migration-and-telemetry.md — spec completo
```

33 commits ahead de `origin/main`. Pendente: commit do fix `regex→value` (item P0 #1).

### Working tree Arch (`/home/henry/arch`) — branch `chore/fix-composer-quality-debt`
8 arquivos modificados (merges aplicados nesta sessão) + 2 untracked:

```
Modified:
  .jscpd.json                    threshold 10, Nova ignores, output storage/quality/cpd
  composer.json                  typo codegaurd → codeguard
  composer.lock                  deps captainhook (sem ação manual)
  infection.json5                Nova excludes, timeout 30, github logs, NÃO exclui Configurators/Middleware/Abstracts
  phpstan-test-quality.neon      aceito do stub
  phpstan.neon                   level 10, baseline include, Carbon, peststan, Nova excludes, processTimeout, deadMethods false
  pint.json                      Nova/public excludes, +3 rules: combine_unsets/issets/explicit_string_variable
  tests/Arch/TestQualityTest.php híbrido: trait API + allowlists + 3 testes Arch-específicos + helpers locais

Untracked (novos do CodeGuard install):
  captainhook.json
  captainhook.json.README.md

deptrac.yaml — REVERTIDO ao HEAD (30-layer config preservada — sessão 7 fará Opção C)
lefthook.yml — REMOVIDO (legado pré-CaptainHook)
```

### Tag de rollback
`v0-last-lefthook` continua válida (pre-Phase-A).

### Suite de testes
**284 testes / 706 assertions, todos verdes** (após fix regex→value).
```bash
cd /home/henry/codeguard && vendor/bin/pest --colors=never
```

### Validação end-to-end
- Phase A validada em `/home/henry/arch` (composer install → hooks ativos).
- Phase C não re-validada no Arch nesta sessão.
- Phase B não re-validada no Arch nesta sessão.
- **Sessão 7 fará validação completa após resolver os 9 itens do backlog.**

---

## Spec Canônico da Migração

**`docs/specs/2026-04-17-captainhook-migration-and-telemetry.md`** — Opção **β aprovada**. 3 phases:

| Phase | Status | Commits |
|---|---|---|
| A — CaptainHook migration | ✅ COMPLETA (2026-04-20, sessão 3) | 9 commits + 1 tag |
| C — Install UX | ✅ COMPLETA (2026-04-20, sessão 4) | 3 commits + 1 review-fix commit |
| B — Telemetry (install layer) | ✅ COMPLETA (2026-04-22, sessão 5) | 5 commits + 1 review-fix commit |

---

## BACKLOG SESSÃO 7 — Resolver tudo, depois validar no Arch

### 🔥 P0 — Fixes (~50min)

**#1 — Commit do fix `regex → value` (5min)**
- Working tree CodeGuard já tem mudanças aplicadas em:
  - `src/Install/DeptracLayerSuggester.php` (linha 188-194 — array key + comment)
  - `resources/stubs/deptrac.yaml.stub` (4× `regex:` → `value:`)
  - `tests/Unit/Install/DeptracLayerSuggesterTest.php` (linha 240 — assertion)
- Comando: `cd /home/henry/codeguard && git add src/Install/DeptracLayerSuggester.php resources/stubs/deptrac.yaml.stub tests/Unit/Install/DeptracLayerSuggesterTest.php && git commit -m "fix(deptrac): use 'value' key for classLike (Deptrac 2.x format)"`
- Razão: Deptrac 2.x usa `value`, não `regex`. Mensagem de erro do próprio Deptrac é ambígua ("needs the regex configuration" mas código procura `$config['value']`). Verificado em `vendor/deptrac/deptrac/src/Core/Layer/Collector/AbstractTypeCollector.php`. Sem fix, install em qualquer Arch-like quebra com `Invalid collector definition`.

**#2 — Bug PhpstanExtensionApplier removendo `#` de `:end` sentinels (30-45min)**
- Sintoma: instalação no Arch produziu phpstan.neon onde linhas `# @codeguard:ext=NAME:params:end` ficaram `@codeguard:ext=NAME:params:end` (sem `#`). 3 ocorrências: cognitive-complexity, dead-code, disallowed-calls.
- Stub e o regex inline (`/#\s*@codeguard:ext=([a-z-]+)\s*$/`) estão CORRETOS.
- Hipóteses:
  - Loop em `commentBlockBody`/`uncommentBlockBody` está correto (i=1 a lineCount-2, exclui sentinels)
  - Regex inline NÃO matcha `:end` (testado mentalmente)
  - Pode ser execução dupla? Versão antiga do CodeGuard? Stub que foi publicado antes do bug fix?
- Investigação:
  1. Adicionar test reproduzindo o cenário exato (block enabled, ver se end fica intacto)
  2. Se reproduzir: fix no Applier
  3. Se não reproduzir: confirmar que era versão antiga do código no Arch (provável)
- Em qualquer caso: adicionar test de regressão garantindo sentinels nunca são modificados.

### 🎯 P1 — Mecanismos overwrite (~2h15min)

**#3 — `.codeguard/stub-overrides.yaml` (skip permanente) (~1h30min)**

Design:
```yaml
# .codeguard/stub-overrides.yaml — generated by codeguard:install or codeguard:install:override
# Files in this list are NEVER overwritten by codeguard:install (even with --refresh-stubs).
# To re-enable stub publishing for a file, remove its entry from this list.
overrides:
  - phpstan.neon         # custom: peststan + level 10 + project paths
  - deptrac.yaml         # custom: 30-layer config (HEAD restore)
```

Implementação:
- `src/Install/StubOverrides.php` — read/write `.codeguard/stub-overrides.yaml`
  - `load(): array<string>` (lista de paths normalized)
  - `add(string $path): void` (idempotente)
  - `remove(string $path): void`
  - `contains(string $path): bool`
- Tests: `tests/Unit/Install/StubOverridesTest.php` (~80 LOC)
- Modificação no `StubPublisher.php`:
  - `publish()` recebe `StubOverrides $overrides`
  - Para cada stub no preset: se `$overrides->contains($stub->target)` → status `KeptCustomPermanent`, NÃO prompta, NÃO publica
- Modificação no prompt de diff (`StubPublisher::overwriteAfterDiffReview` ou similar):
  - Após "Keep custom" / "Overwrite" / "Show full diff", adicionar 4ª opção:
    - "Keep custom + remember (never ask again for this file)"
  - Se escolhido: `$overrides->add($stub->target)` antes de retornar `KeptCustom`
- Novo `StubPublishStatus::KeptCustomPermanent` (case enum)
- Novo comando: `codeguard:install:override <stub-path>` — adiciona ao yaml manualmente
  - Útil quando user já edited e quer marcar sem rodar install
  - `src/Commands/CodeguardInstallOverrideCommand.php`
- Modo `--refresh-stubs` IGNORA overrides (force flag)
- Update install command output: mostrar contagem "X files in stub-overrides (skipped)" no resumo final

Como funciona na primeira instalação:
- yaml não existe → comportamento normal (prompta cada diff)
- User escolhe "Keep custom + remember" → yaml é criado com 1 entrada
- Próximas instalações: yaml lido no início, paths skipados silenciosamente

**#4 — Legacy stubs cleanup (~45min)**

Design:
- `src/Install/LegacyStubCleaner.php` — lista hardcoded de paths legados:
  ```php
  private const LEGACY_PATHS = [
      'lefthook.yml',           // pre-CaptainHook era
      // futuros adicionados aqui quando rename/remove de stubs
  ];
  ```
- Método `detect(): array<string>` retorna paths que existem no projeto
- Modificação em `CodeguardInstallCommand`:
  - Após preset selection, antes de publish stubs
  - Para cada legacy path detected: prompt "Delete legacy {path}? It was replaced by {new}. [Y/n]"
  - Se Y: `unlink()` + log no InstallSummary como warning info
  - Se N: skip + warning "Legacy file {path} kept — please remove manually"
- Tests: `tests/Unit/Install/LegacyStubCleanerTest.php` (~50 LOC)
- Em modo `--no-interactive`: NÃO deleta automaticamente (safety), só lista no warning summary

### 📦 P2 — Backflow do Arch para os stubs (~1h50min)

**#5 — Carbon ext always-on no `phpstan.neon.stub` (15min)**
- Adicionar entre os includes obrigatórios (after larastan):
  ```yaml
      # Carbon-aware static analysis — types Carbon::macro() returns + DatePeriod
      # Bundled with nesbot/carbon (always present in Laravel projects), zero install cost.
      - vendor/nesbot/carbon/extension.neon
  ```
- SEM sentinel `@codeguard:ext=carbon` (always-on, não opt-out via wizard)
- ADR-009 backflow note: 11.7M downloads/mo, universal Laravel, zero custo
- Update test que verifica includes
- Não precisa adicionar ao enum `PhpstanExtension` (não é toggleable)

**#6 — Peststan opt-in (~1h)**
- Adicionar enum case `PhpstanExtension::Peststan` em `src/Install/PhpstanExtension.php`
- Adicionar `mrpunyapal/peststan: ^0.2` ao `composer.json` `require` (igual outras extensions)
- Stub `phpstan.neon.stub` ganha:
  ```yaml
      - vendor/mrpunyapal/peststan/extension.neon  # @codeguard:ext=peststan

      # @codeguard:ext=peststan:params:start
      peststan:
          testCaseClass: Tests\TestCase
      # @codeguard:ext=peststan:params:end
  ```
- `PhpstanExtensionSelector::autoResolve()` — auto-marcar Peststan se `pestphp/pest` em composer.json do consumidor (precisa novo método em `EnvironmentDetector` ou similar pra inspecionar composer.json)
- Decisão técnica: ADR-009 explicar por que Peststan é opt-in (12k downloads/mo, solo maintainer, pre-1.0) mas vale shippar

**#7 — Stub `infection.json5.stub` ajustes (20min)**
- Atualizar `excludes` baseado na análise de Configurators/Middleware/Abstracts (não excluir code com lógica testável)
- Adicionar comments explicando cada exclude e o porquê das EXCLUSÕES INTENCIONAIS NÃO feitas
- Considerar incluir warning na install output: "Primeira execução de Infection pode falhar minMsi — rode `--initial-tests-only` se acontecer"

**#8 — Stub `pint.json.stub` adicionar 3 rules (15min)**
- `combine_consecutive_unsets: true`
- `combine_consecutive_issets: true`
- `explicit_string_variable: true`
- Mesma estrutura: rule + comment em `_rule_docs`
- ADR-009 backflow

### Validação end-to-end no Arch (~30min — última etapa)

Após #1-#8 completos:

```bash
# 1. Push commits do CodeGuard
cd /home/henry/codeguard
vendor/bin/pest --colors=never        # deve dar TODOS verdes
git log --oneline origin/main..HEAD   # ver quantos commits ahead
git push origin main

# 2. Update Arch path repository (composer reload da nova versão)
cd /home/henry/arch
composer update henryavila/codeguard --no-interaction

# 3. Rodar install em modo interativo pra validar:
#    - Carbon ext aparece na multiselect (sem opt-out)
#    - Peststan aparece na multiselect (auto-marcado pq Pest detectado)
#    - Quando phpstan.neon difere: aparece prompt com 4ª opção "Keep + remember"
#    - lefthook.yml legado: prompt "Delete? [Y/n]"
#    - InstallSummary mostra warnings agregados
php artisan codeguard:install --refresh-stubs

# 4. Validar arquivos finais
git status
git diff phpstan.neon          # deve estar como o merge atual + 0 perdas
ls .codeguard/                  # stub-overrides.yaml deve existir se user marcou algum

# 5. Rodar quality gates pra confirmar nada quebrou:
vendor/bin/pint --test
vendor/bin/phpstan analyse      # respeita baseline
vendor/bin/deptrac analyse      # config 30-layer original
vendor/bin/infection --skip-initial-tests --no-progress 2>&1 | grep MSI:
```

Critérios de sucesso:
- ✅ Install completa sem erros
- ✅ Nenhum arquivo do Arch é sobrescrito sem confirmação
- ✅ Após escolher "Keep + remember", próxima install não pergunta novamente
- ✅ Carbon ext + Peststan estão no phpstan.neon final
- ✅ lefthook.yml não reaparece
- ✅ Todos os quality gates rodam (PHPStan respeitando baseline, Deptrac com 30 layers, etc.)

---

## ITENS DEIXADOS PARA SESSÃO 8+

**#9 — Opção C "Laravel-pragmatic" ruleset (~2h15min)** — Não inclui na sessão 7 pra manter escopo administrável. Decisão tomada (DDD-inspired ruleset com Application→Infrastructure permitido), implementação fica pra depois.
- Files: `LayerOption`, `DeptracLayerSuggester::DEFAULT_RULESET`, `deptrac.yaml.stub`, `DeptracLayerWizard` copy, todos os testes deptrac
- ADR-011 a criar
- Re-validação no Arch

**#10 — Telemetria Layers 3-7 (~3h)** — Bloqueado em pre-requisitos:
- Precisa comandos `codeguard:check`, `codeguard:test`, `codeguard:prepare`, `codeguard:analyze`, `codeguard:baseline` (não existem ainda — apenas `codeguard:install` e telemetry commands)
- Precisa hook-bootstrap mechanism para Laravel container rodar dentro de captainhook processes
- 3 enum cases existentes sem método: `InstallStubProcessed`, `InstallDeptracDetected`, `InstallDeptracWizardDecision`

---

## Diretrizes que NÃO podem ser reabertas

- **ADR-010 (CaptainHook)**: decidido, implementado, validado. NÃO voltar para Lefthook sem trigger explícito do ADR (perf > 30s em produção OU maintainer inativo 6mo+).
- **Opção β (StagedPhpFilesRunner na Phase A)**: shipped. Não reabrir "pra simplificar".
- **Phase order A → C → B**: cumprida. C entregue antes de B por causa de `.codeguard/.gitignore`.
- **Cutover direto (não-compat)**: v0.x, nenhum consumer externo. Não reintroduzir Lefthook como backend alternativo.
- **3 comandos de telemetria (enable/disable/clear)**: não criar `export`, `dashboard`, `show`, `analyze`. Claude analisa o jsonl diretamente.
- **Privacy first**: `FieldAllowlist` é enum-only, sem strings livres.
- **DDD-inspired (NÃO strict)** (sessão 6 reaffirmada): Service→Model OK, Filament→Model OK, Domain stays framework-free. Reflete em ADR-010 e futura ADR-011.

---

## Memória Global (Henry profile)

Em `/home/henry/.claude/projects/-home-henry-codeguard/memory/`:
- `feedback-evidence-based-estimates.md`
- `feedback-prefer-simplification.md`
- `feedback-honest-tradeoffs.md`
- `feedback-node-when-justified.md`
- `feedback-portuguese-typos.md`
- `feedback-ddd-inspired-not-strict.md` (NOVO sessão 6)
- `user-profile.md`
- `project-codeguard-state.md`

---

## Cheatsheet para sessão 7

```bash
# 1. Context load (Claude faz automaticamente):
#    CLAUDE.md → .ai/memory/MEMORY.md → este arquivo (handoff)

# 2. Verificar estado:
cd /home/henry/codeguard
git status                                       # working tree TEM mudanças não-commitadas (regex→value fix)
vendor/bin/pest --colors=never                  # 284 verdes
git log --oneline -5                             # último commit deve ser o de docs/handoff

# 3. Verificar bug applier:
grep -n "@codeguard:ext.*params:end" /home/henry/arch/phpstan.neon
# Se mostrar linhas SEM # → bug ainda presente (P0 #2)
# Se mostrar com # → user manualmente fixou OU re-instalou

# 4. Ler spec:
sed -n '/## 5\. Telemetry/,/## 6\. Migration/p' docs/specs/2026-04-17-captainhook-migration-and-telemetry.md
```
