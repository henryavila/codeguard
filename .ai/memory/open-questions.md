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

### Q7 — Pint vs CS Fixer vs ECS
Arch usa Pint. Mas alguns Laravel devs preferem friendsofphp/php-cs-fixer.

**Decisão**: Stubs Pint por default. Gate `pint` no config pode apontar para qualquer binário via config override.

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
