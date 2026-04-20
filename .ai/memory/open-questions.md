---
name: Open Questions
description: Decisões pendentes que precisam ser resolvidas durante desenvolvimento
type: project
---

# Open Questions

## Design Pending

### Q1 — Extensibilidade de patterns customizados por projeto
O projeto consumidor pode criar patterns próprios? Onde? Como são carregados?

**Opções**:
- **A**: `base_path('.codeguard/patterns/**/*.yaml')` auto-discovered por PatternLoader
- **B**: config explícita `config('codeguard.patterns.custom_paths')`
- **C**: Ambos (auto-discovery default + config override)

**Recomendação**: C. Auto-discovery por convenção, config para edge cases.

### Q2 — Como Claude skills distribuídas no Composer package instalam?
Skills vivem em `resources/skills/codeguard-*/SKILL.md`. O Claude Code detecta skills em `.claude/skills/` do projeto. Como publicar?

**Opções**:
- **A**: `codeguard:install` copia `resources/skills/*` → `.claude/skills/*` do consumidor
- **B**: Symlink de `vendor/henryavila/codeguard/resources/skills/*` para `.claude/skills/*`
- **C**: Documentar manual: user adiciona caminho do package às settings do Claude

**Recomendação**: A (copy com flag `--symlink` opcional). Symlink quebra em Windows.

### Q3 — AI rules em AGENTS.md vs per-tool files
Comunidade está convergindo em AGENTS.md único (DeployHQ, keboca). Mas rules path-triggered funcionam melhor por tool.

**Opções**:
- **A**: Gerar ambos (AGENTS.md canonical + `.claude/rules/`, `.cursor/rules/`)
- **B**: Só AGENTS.md (simplicidade)
- **C**: Só per-tool (força path-triggering)

**Recomendação**: A durante 2026. Re-avaliar 2027 se AGENTS.md ganhar path-trigger.

### Q4 — Semver v0.x vs v1.0 threshold
Quando lançar v1.0.0?

**Critério proposto**:
- [ ] Arch consumindo em produção por 2 semanas sem bugs críticos
- [ ] Segundo projeto do Henry consumindo OK
- [ ] `codeguard:install` testado em 3+ cenários (fresh Laravel, projeto legado, Laravel 11 + 12)
- [ ] README completo com exemplos
- [ ] CI matrix PHP 8.3/8.4 × Laravel 11/12 verde

Até lá: `dev-main` → `1.0.0-alpha.N` → `1.0.0-beta.N` → `1.0.0`

### Q5 — Claude plugin repo strategy
Plugin é repo separado (`henryavila/codeguard-hooks`) ou subpasta em `henryavila/codeguard`?

**Opções**:
- **A**: Repo separado — cleanness, segue padrão Claude plugin marketplace
- **B**: Subpasta `claude-plugin/` no mesmo repo — menos overhead

**Recomendação**: A. Claude plugin tem ciclo de vida diferente (distribui via `/plugin install`, não Composer). Repo separado alinha com marketplace expectations.

### Q6 — Pattern presets: `core`, `php`, `php-laravel` — como consumidor seleciona?
Em `config/codeguard.php`:
```php
'patterns' => [
    'enabled_presets' => ['core', 'php', 'php-laravel'],
],
```

Mas Laravel projects tipicamente querem todos 3. Symfony projects: core + php. Vanilla PHP: só core + php.

**Decisão**: Auto-detect no `codeguard:install`. Project tem Laravel? Habilita `php-laravel`. Tem Symfony? Enabled `core + php` + futuro `php-symfony`.

## Implementation Pending

### Q7 — Pint vs CS Fixer vs ECS (RESOLVIDO 2026-04-16)
Arch usa Pint. Mas alguns Laravel devs preferem friendsofphp/php-cs-fixer.

**Decisão**: Stubs Pint por default. Gate `pint` no config pode apontar para qualquer binário via config override.

### Q7b — Hook Runner (REVISADO 2026-04-17 — ver ADR-010)
**Decisão original (2026-04-16)**: Lefthook.

**Revisão (2026-04-17)**: **CaptainHook** (ADR-010). Lefthook não existe no Packagist (pesquisa direta), requer install OS-specific que contradiz ADR-001. CaptainHook é PHP puro + Composer-native + permite PHP Action classes (diferencial pra CodeGuard shippar checks Laravel-nativos).

Tradeoffs aceitos: pre-commit 2-3× mais lento em projetos grandes; solo-maintainer. Triggers de reversão documentados em ADR-010. Eficácia a ser medida via telemetria local (Q13).

### Q8 — CodeguardTestCommand progress output
Arch's RunTestsCommand conta ticks Pest + dots ParaTest manualmente. Feio mas funciona.

**Alternativas**:
- **A**: Manter lógica atual (port direto do Arch)
- **B**: Usar `symfony/console` ProgressBar
- **C**: Laravel 12 tem `components->spinner()` — usar se PHP8.3+

**Recomendação**: A por ora (já funciona no Arch). Refatorar para C se virar incômodo.

### Q9 — Windows compatibility
Arch roda em WSL. Henry usa Windows? Composer package precisa rodar nativo no Windows?

**Decisão**: Suportar WSL nativamente, Windows best-effort. Shell scripts em hooks são bash-only (Claude plugin). Composer package é PHP puro → roda Windows sem issues.

## Business/Strategy Pending

### Q10 — README do pivot vs README novo PHP
Documentar o pivot no README principal ou em docs separado?

**Opções**:
- **A**: README principal fala do PHP package. Seção pequena "coming from npm" no fim com link para docs/legacy/
- **B**: README principal é sobre pivot, depois explica PHP
- **C**: README curto, detalhes em docs/

**Recomendação**: A. README serve primariamente novos users PHP. Legacy users (se existirem) seguem link.

### Q11 — Changelog strategy
Manter v0.1.1 no CHANGELOG.md ou reset?

**Decisão**: Reset. v1.0.0 começa do zero. CHANGELOG-v0-npm.md preserva histórico npm em docs/legacy/.

### Q12 — Breaking changes entre v0 (npm) e v1 (Composer)
Tudo é breaking — stack, API, instalação. Não há path de migração.

**Decisão**: Documentar francamente: "v1.0 is a complete rewrite. No migration from npm v0.x supported. v0.x users: continue using `@henryavila/codeguard@0.1.1` ou migrate to Composer package."

## Measurement & Validation Pending

### Q13 — Validar eficácia real do CaptainHook em produção (2026-04-17)
ADR-010 troca Lefthook por CaptainHook assumindo que o tradeoff (performance ↔ Composer-native + extensibilidade PHP) vale pra pacote. **Precisamos dados**, não fé.

**Hipóteses a testar** (medir em projetos reais):

- **H1**: Pre-commit wall-time stays < 30s em 95% dos commits no Arch (projeto de referência). Se >30s regular → trigger de revisão.
- **H2**: Dev terceirizado consegue clonar + rodar primeiro commit sem travar em <5min após `composer install` (sem ajuda).
- **H3**: Solo-maintainer continua ativo (release cadence <3 meses entre versões).
- **H4**: CodeGuard consegue shippar ≥3 PHP Actions Laravel-específicas em 3 meses (viabilidade da "plataforma" prometida).

**Método de medição**: telemetria local opt-in (nunca sai da máquina) — ver design proposto na seção "Implementation Pending".

**Quando reavaliar**:
- 3 meses após integrar CaptainHook (prazo mínimo)
- Imediatamente se qualquer trigger de ADR-010 disparar (perf >30s OU CaptainHook stagnant >6mo OU CVE unpatched)

### Q14 — Telemetria local opt-in: escopo expandido (2026-04-17, aprovado)
Para validar Q13 e futuras hipóteses sem enviar dados do user para lugar nenhum. **Claude (assistente) analisa lendo o arquivo diretamente** — o pacote não tem dashboard/export; só grava.

**Escopo**: todas as camadas do CodeGuard emitem eventos, não só pre-commit gates. Permite responder "onde o pacote gasta tempo? onde falha? onde o user abandona?".

**Infra**
- **Arquivo**: `.codeguard/telemetry.jsonl` (append-only, uma linha por evento)
- **Gitignore auto**: installer gera `.codeguard/.gitignore` com `telemetry.*` e rotated files
- **Rotação**: ao atingir 10MB, renomeia para `telemetry-YYYY-MM-DD-HHMMSS.jsonl` e abre novo. Retenção: últimos 5 arquivos (deleta os mais antigos).
- **Default**: DESABILITADO. Só ativa com opt-in explícito.
- **Toggle**: `config/codeguard.php` → `'telemetry.enabled' => bool` OU os 3 comandos Artisan.

**3 comandos (mínimo absoluto)**
- `codeguard:telemetry enable`
- `codeguard:telemetry disable`
- `codeguard:telemetry clear` (confirma Y/n; apaga `telemetry*.jsonl`)

Nada de `export`, `dashboard`, `show`, `analyze`. Claude abre o arquivo quando o user pedir análise.

**Taxonomia de eventos (todas as camadas)**

Shape base de todo evento:
```json
{"ts":"ISO-8601","event":"event.name","status":"ok|fail|skip","duration_ms":N,...}
```

**Camada 1 — Command lifecycle**
- `command.start` (command: install|check|test|prepare|analyze|baseline, preset_flag)
- `command.end` (exit_code, duration_ms)

**Camada 2 — Install phases** (dentro de `codeguard:install`)
- `install.env.detected` (php_version_major_minor, composer_version_major, has_node, lefthook_in_path, has_captainhook_binary)
- `install.preset.selected` (preset: codeguard|codeguard-full, source: auto|flag|prompt)
- `install.phpstan_extensions.selected` (count, enum_values: [larastan,phpunit,...])
- `install.stub.processed` (stub_name, status: created|unchanged|overwritten|kept_custom|skipped, diff_lines_added, diff_lines_removed)
- `install.deptrac.detected` (namespace_count, auto_classified_count, auto_skip_count, unclassified_count)
- `install.deptrac.wizard_decision` (namespace_count, layer_assigned: Domain|Application|..|Skip|Custom, was_saved_choice: bool)
- `install.captainhook.activated` (status: installed|skipped|failed)
- `install.next_steps.rendered` (count)

**Camada 3 — Gate execution** (pre-commit / pre-push / CI / manual via `codeguard:check`)
- `gate.started` (gate: pint|phpstan|deptrac|infection|jscpd|insights|test_quality, context: pre-commit|pre-push|ci|manual)
- `gate.ended` (gate, context, duration_ms, status: pass|fail|skip, violations_count, files_scanned_count)

**Camada 4 — Hook lifecycle** (CaptainHook invocation)
- `hook.triggered` (hook_type: pre-commit|commit-msg|pre-push|post-checkout, action_count)
- `hook.completed` (hook_type, duration_ms, status, failed_action_names: [...])

**Camada 5 — Test execution**
- `test.started` (context: manual|ci|pre-push, with_coverage: bool)
- `test.ended` (duration_ms, pass_count, fail_count, skip_count, coverage_percent, status)

**Camada 6 — Analyze / Baseline**
- `analyze.ended` (duration_ms, patterns_checked_count, matches_count)
- `baseline.ended` (duration_ms, entries_saved_count, tool: phpstan|deptrac)

**Camada 7 — Prepare** (setup commands)
- `prepare.step.ended` (step_name, duration_ms, status)

**Zero coleta de PII / código**
Campos proibidos: paths, filenames, code content, commit hashes/messages, branch names, usernames, emails, hostnames, cwd, repo remote URLs, stdout/stderr raw.

Se algum campo futuro precisar de string, PASSA por allowlist (enum fechado) — nunca string livre.

**Banner no install (copy aprovada, a refinar)**
```
┌ Enable local telemetry? ───────────────────────────────────────────┐
│ Records CodeGuard usage LOCALLY to help improve quality gates.     │
│                                                                    │
│ ✓ Collects: timestamps, command names, durations, pass/fail.       │
│ ✗ Does NOT collect: filenames, code, commit messages, personal     │
│   info, repo URLs, user identity, hostnames, branch names.         │
│                                                                    │
│ ✓ Written to .codeguard/telemetry.jsonl (auto-gitignored).         │
│ ✓ NEVER sent anywhere. Your machine. Your data.                    │
│ ✓ Disable anytime: `php artisan codeguard:telemetry disable`.      │
│ ✓ Clear anytime: `php artisan codeguard:telemetry clear`.          │
│                                                                    │
│ Default: disabled. Helps Henry + Claude validate real-world usage. │
└────────────────────────────────────────────────────────────────────┘
[y/N]
```

**Implementação — abordagem modular**

- `Telemetry\Recorder` — single entry point: `Recorder::record(event: string, fields: array): void`
- `Telemetry\ConfigGate` — lê `telemetry.enabled` uma vez; no-op se disabled
- `Telemetry\Rotator` — checa tamanho antes de append; rotaciona se necessário
- `Telemetry\MeasuredAction` — decorator p/ CaptainHook Actions (mede duration automaticamente)
- Instrumentação via método/facade estático OU injection em cada serviço (a decidir: prefiro injection — mais testável)

**Status**: APROVADO (2026-04-17). Implementar na Fase B do roadmap (após migração CaptainHook).
