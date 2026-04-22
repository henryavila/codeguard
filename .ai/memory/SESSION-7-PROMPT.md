---
name: Session 7 Prompt — overwrite mechanisms + backflow + validation
description: Self-contained prompt to paste into next Claude session
type: project
---

# Prompt para colar no início da Sessão 7

> Copie tudo abaixo da linha tracejada e cole no início da nova sessão.

---

Estamos retomando o trabalho no CodeGuard (`/home/henry/codeguard`). A sessão 6 terminou com 8 merges aplicados no Arch (`/home/henry/arch`) e um backlog consolidado de 8 itens implementáveis nesta sessão + validação end-to-end.

## Context load obrigatório (na ordem)

1. Lê `/home/henry/codeguard/CLAUDE.md`
2. Lê `/home/henry/codeguard/.ai/memory/PROJECT-STATUS.md` (snapshot canônico)
3. Lê `/home/henry/codeguard/.ai/memory/MEMORY.md` (índice)
4. Lê `/home/henry/codeguard/.ai/memory/conversation-handoff.md` (sessão 6 — narrativa completa do que aconteceu, especialmente a seção "BACKLOG SESSÃO 7")
5. Lê `/home/henry/codeguard/docs/specs/2026-04-17-captainhook-migration-and-telemetry.md` (spec canônico — phases A/B/C estão completas, mas é a base arquitetural)

## Verifica o estado real antes de começar

```bash
cd /home/henry/codeguard
git log --oneline -3                                   # último deve ser e6665d1
git status                                              # working tree TEM mudanças (fix regex→value)
vendor/bin/pest --colors=never 2>&1 | tail -5         # esperado: 284 verdes
git diff --stat                                         # esperado: 3 arquivos (Suggester + stub + test)
```

Se `git diff --stat` mostrar diferente de 3 arquivos esperados, PARE e investigue antes de continuar — alguém pode ter mexido entre as sessões.

## Plano de execução — 8 itens em 4 blocos

Use `TodoWrite` ao iniciar para criar as 9 tasks (8 implementação + validação).

### 🔥 Bloco 1 — P0 Fixes (~50min)

**Task 1**: Commit do fix `regex → value` (5min)
```bash
cd /home/henry/codeguard
git add src/Install/DeptracLayerSuggester.php resources/stubs/deptrac.yaml.stub tests/Unit/Install/DeptracLayerSuggesterTest.php
git commit -m "fix(deptrac): use 'value' key for classLike (Deptrac 2.x format)"
```

**Task 2**: Bug `PhpstanExtensionApplier` removendo `#` de sentinels `:end` (30-45min)
- Arquivo: `src/Install/PhpstanExtensionApplier.php`
- Sintoma documentado: `/home/henry/arch/phpstan.neon` linhas 89, 110, 142 ficaram com `@codeguard:ext=...:params:end` SEM `#`. Stub está correto. Loop em `commentBlockBody`/`uncommentBlockBody` parece correto (i=1 a lineCount-2).
- Passos:
  1. Escrever test reproduzindo: bloco enabled no estado inicial, chamar `apply()` com extensão enabled, verificar que ambos sentinels (`:start` e `:end`) ficam intactos
  2. Se reproduzir → fix no Applier
  3. Se NÃO reproduzir → confirmar que stub no Arch foi de versão antiga (provável); ainda assim adicionar test de regressão garantindo invariant
  4. Commit: `fix(applier): preserve sentinel '#' on block end markers`

### 🎯 Bloco 2 — P1 Mecanismos overwrite (~2h15min)

**Task 3**: `.codeguard/stub-overrides.yaml` (skip permanente) (~1h30min)
- Detalhes completos em `conversation-handoff.md` seção "BACKLOG SESSÃO 7 → P1 #3"
- Arquivos a criar:
  - `src/Install/StubOverrides.php` (~80 LOC)
  - `tests/Unit/Install/StubOverridesTest.php` (~80 LOC)
  - `src/Commands/CodeguardInstallOverrideCommand.php` (~40 LOC, signature `codeguard:install:override <stub-path>`)
- Modificar:
  - `src/Install/StubPublisher.php` — receber `StubOverrides` no construtor; checar `contains()` antes de processar cada stub; adicionar 4ª opção "Keep + remember" no prompt
  - `src/Install/StubPublishStatus.php` — novo case `KeptCustomPermanent`
  - `src/CodeguardServiceProvider.php` — registrar singletons + command
  - `src/Commands/CodeguardInstallCommand.php` — injetar `StubOverrides`, mostrar contagem no resumo final
  - `tests/Feature/CodeguardInstallCommandTest.php` — test E2E mostrando "Keep + remember" pulando arquivo na próxima execução
- `--refresh-stubs` IGNORA overrides (force flag)
- Commit: `feat(install): add .codeguard/stub-overrides.yaml for permanent skip`

**Task 4**: Legacy stubs cleanup (~45min)
- Criar `src/Install/LegacyStubCleaner.php` (~50 LOC)
  - Const `LEGACY_PATHS = ['lefthook.yml']`
  - Método `detect(string $basePath): array<string>`
- Modificar `CodeguardInstallCommand`:
  - Após preset selection, antes de publish stubs
  - Para cada legacy detected: prompt confirm em modo interativo
  - Em `--no-interactive`: warning no `InstallSummary`, sem deletar
- Tests: `tests/Unit/Install/LegacyStubCleanerTest.php` (~50 LOC)
- Commit: `feat(install): detect and offer to remove legacy stub files`

### 📦 Bloco 3 — P2 Backflow do Arch (~1h50min)

**Task 5**: Carbon ext always-on (15min)
- `resources/stubs/phpstan.neon.stub` — adicionar entre `larastan` e `phpunit`:
  ```yaml
      # Carbon-aware static analysis — types Carbon::macro() + DatePeriod
      # Bundled with nesbot/carbon (always present in Laravel projects).
      - vendor/nesbot/carbon/extension.neon
  ```
- Sem sentinel `@codeguard:ext` (não toggleable)
- Atualizar `tests/Unit/Install/PhpstanExtensionApplierTest.php` (já cobre includes? confirmar)
- Adicionar exemplo no ADR-009 architecture-decisions.md
- Commit: `feat(stubs): always include Carbon PHPStan extension (universal in Laravel)`

**Task 6**: Peststan opt-in (~1h)
- `src/Install/PhpstanExtension.php` — adicionar case `Peststan = 'peststan'`
  - `displayName()`: 'Peststan'
  - `description()`: '$this resolution in Pest closures'
  - `defaultEnabled()` — não incluir (é opt-in via auto-detect)
  - `dependsOn()` — null
- `composer.json` (CodeGuard) — adicionar `mrpunyapal/peststan: ^0.2` em `require`
- `resources/stubs/phpstan.neon.stub`:
  ```yaml
      - vendor/mrpunyapal/peststan/extension.neon  # @codeguard:ext=peststan

      # @codeguard:ext=peststan:params:start
      peststan:
          testCaseClass: Tests\TestCase
      # @codeguard:ext=peststan:params:end
  ```
- `src/Install/EnvironmentDetector.php` ou `PhpstanExtensionSelector` — método novo `detectPestUsage(string $basePath): bool` que parseia composer.json do consumer e procura `pestphp/pest` em require/require-dev
- `PhpstanExtensionSelector::autoResolve()` — se `detectPestUsage()` true, marcar Peststan
- Tests: estender `PhpstanExtensionSelectorTest` com cenário "Pest detected → auto-mark Peststan"
- ADR-009 update
- Commit: `feat(phpstan): add Peststan as opt-in (auto-detect via pestphp/pest in composer.json)`

**Task 7**: `infection.json5.stub` ajustes (20min)
- Atualizar `excludes` removendo Console/Exceptions/Providers genéricos, manter apenas:
  ```json5
  "excludes": [
      "Nova",                  // legacy code being removed
      "Overwrites/Nova",       // legacy code being removed
      "Console/Kernel.php",    // framework scheduling (file, not whole dir)
      "Exceptions/Handler.php", // framework hook (file, not whole dir)
      "Providers"              // service container wiring
      // INTENCIONALMENTE NÃO excluído:
      // - Configurators (security-critical: Gate::after, Password rules)
      // - Http/Middleware (auth/throttling logic)
      // - Abstracts (shared logic)
  ]
  ```
- Adicionar comment top-of-file sobre primeira execução podendo falhar minMsi
- Commit: `feat(stubs): refine infection excludes — keep mutation testing on logic-bearing code`

**Task 8**: `pint.json.stub` adicionar 3 rules (15min)
- Adicionar em `_rule_docs`:
  ```json
  "combine_consecutive_unsets": "Merges consecutive unset() calls into a single statement",
  "combine_consecutive_issets": "Merges consecutive isset() checks into a single statement",
  "explicit_string_variable": "Wraps interpolated variables in {} for clarity"
  ```
- Adicionar em `rules`:
  ```json
  "combine_consecutive_unsets": true,
  "combine_consecutive_issets": true,
  "explicit_string_variable": true,
  ```
- Commit: `feat(stubs): add 3 universal Pint rules (combine_unsets, combine_issets, explicit_string_variable)`

### 🧪 Bloco 4 — Validação end-to-end no Arch (~30min)

```bash
# 1. Suite local
cd /home/henry/codeguard
vendor/bin/pest --colors=never            # esperado: 290+ tests verdes (8 commits trouxeram tests novos)

# 2. Push
git log --oneline origin/main..HEAD       # ~46 commits ahead
git push origin main

# 3. Update Arch path repo
cd /home/henry/arch
composer update henryavila/codeguard --no-interaction

# 4. Verificar lefthook.yml ainda removido (sessão 6 fez)
ls lefthook.yml 2>&1                       # esperado: No such file

# 5. Install interativo (cuidadoso — vai prompt cada arquivo que difere)
php artisan codeguard:install --refresh-stubs

# Validar durante:
# - Carbon ext aparece SEM opt-out (always on)
# - Peststan aparece pré-selecionado (auto-detected pq Pest está no composer.json)
# - Quando phpstan.neon prompt aparecer com diff: deve haver 4ª opção "Keep + remember (never ask again)"
# - Se lefthook.yml fosse re-criado por algum motivo: prompt "Delete legacy?"
# - InstallSummary final mostra contagens (X stubs in overrides, Y warnings)

# 6. Validar arquivos finais
git status
git diff phpstan.neon                      # phpstan.neon deve refletir Carbon + Peststan + customizações Arch
ls .codeguard/                              # se user marcou "Keep + remember": stub-overrides.yaml existe

# 7. Quality gates final no Arch
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G  # respeita baseline (47k entries)
vendor/bin/deptrac analyse                    # config 30-layer original (deptrac.yaml HEAD restored)
vendor/bin/infection --skip-initial-tests --no-progress 2>&1 | grep "MSI:"
```

**Critérios de sucesso**:
- ✅ CodeGuard suite verde
- ✅ Install completa sem erros
- ✅ Nenhum arquivo do Arch é sobrescrito sem confirmação
- ✅ "Keep + remember" funciona (próxima install não pergunta sobre arquivo marcado)
- ✅ Carbon ext + Peststan no phpstan.neon final
- ✅ lefthook.yml não reaparece
- ✅ Todos quality gates rodam sem erro inesperado

Se algo falhar: PARE e reporte ao Henry com diagnóstico (não tente "consertar" no impulso). Item P0 #2 (bug Applier) é especialmente importante — se reproduzir, queremos a regressão coberta.

## Princípios desta sessão

- **NÃO reabrir** decisões fechadas (ver "Diretrizes que NÃO podem ser reabertas" no handoff)
- **DDD-inspired (NÃO strict)** — Service→Model OK, Domain stays framework-free
- **Privacy first** — telemetria nunca capta string livre
- **Evidence-based** — qualquer estimativa "1-2h" requer "com base em quê?"
- **Prefer simplification** — se opção mais simples preserva intenção, escolher ela

## Memória global do Henry (já carregada)

`/home/henry/.claude/projects/-home-henry-codeguard/memory/`:
- `feedback-evidence-based-estimates.md`
- `feedback-prefer-simplification.md`
- `feedback-honest-tradeoffs.md`
- `feedback-node-when-justified.md`
- `feedback-portuguese-typos.md`
- `feedback-ddd-inspired-not-strict.md` (sessão 6)
- `user-profile.md`
- `project-codeguard-state.md`

## Ao terminar a sessão 7

1. Atualizar `PROJECT-STATUS.md` (HEAD novo, contadores, sprint 8 = TestSuiteRunner extract OU Opção C deptrac ruleset)
2. Atualizar `conversation-handoff.md` com narrativa sessão 7 (o que entrou, decisões tomadas, tradeoffs)
3. Se algum item ficou pendente: documentar exatamente onde parou (file + line + por que)
4. Confirmar `git status` working tree limpo OU mudanças intencionais documentadas

Boa sessão.
