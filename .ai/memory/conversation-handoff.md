---
name: Conversation Handoff
description: Onde paramos — próximo passo concreto
type: project
---

# Conversation Handoff

**Data**: 2026-04-16 (fim de tarde)
**Sessão anterior**: ~15 user messages intensos — revisão crítica de design, pivot Node→PHP, decisão Option A

## Estado Atual (ao fazer este handoff)

### Repo
- `~/codeguard` reorganizado: Node artifacts removidos do `main`, preservados em branch `v0-npm-archive` + tag `v0-last-npm`
- npm registry: `@henryavila/codeguard@0.1.1` continua publicado
- Assets valiosos migrados para novo layout:
  - `resources/patterns/` (28 YAMLs: 13 core + 6 php + 9 laravel + 2 meta files)
  - `resources/skills/` (3 skills: setup, run, health — podem precisar adapt para Composer)
  - `docs/legacy/npm-v0-README.md` (preservado)

### Contexto Persistido
- `.ai/memory/MEMORY.md` — índice
- `.ai/memory/user-goals.md` — 3 metas reais
- `.ai/memory/architecture-decisions.md` — 8 ADRs
- `.ai/memory/codeguard-v2-design.md` — design consolidado PHP-only
- `.ai/memory/reviews-consolidated.md` — 10 agent reviews
- `.ai/memory/open-questions.md` — pendências
- `CLAUDE.md` — bootstrapping para Claude

### Arch (projeto cliente) — ainda intocado
- `/home/henry/arch` tem TestSuiteRunner (505 LOC) + RunTestsCommand (271 LOC) + PrepareTestDatabaseCommand
- Design doc v4 em `/home/henry/arch/docs/plans/designs/2026-04-16-codeguard-v2-architecture.md`
- Ainda não começou consolidação inline nem path repository setup

## Próximo Passo Concreto (sequência recomendada)

### Bloco 1 — Fundação do package (1-2h)
1. **Criar `composer.json`** inicial com:
   - PSR-4 autoload `Henryavila\\Codeguard\\`
   - Dependências base (illuminate/console, illuminate/support, symfony/process, symfony/yaml, symfony/finder)
   - Dev dependencies (pest, orchestra/testbench)
   - Scripts: test, test:coverage, pint, phpstan
   - `extra.laravel.providers` para auto-discover

2. **Criar `src/CodeguardServiceProvider.php`**:
   - `register()` merge config padrão
   - `boot()`:
     - registrar commands (condicional `$this->app->runningInConsole()`)
     - `publishes()` com tags: `codeguard-config`, `codeguard-stubs`, `codeguard-rules`
     - registrar Pest expectations se `class_exists(\Pest\Expectation::class) && app()->runningUnitTests()`

3. **Criar `src/Testing/CodeguardConfig.php`** (DTO principal)
4. **Criar `src/Testing/StageConfig.php` + `src/Testing/PrepareConfig.php`**
5. **Criar `config/codeguard.php`** com defaults

6. **Criar `README.md`** inicial declarando:
   - "Laravel quality gates that survive your AI agent"
   - Status: early development, pivot from npm announced
   - Link para `docs/legacy/` para versão Node

### Bloco 2 — Primeiro Command + Arch consome (2-3h)
1. **`codeguard:install` esqueleto** (só publish stubs Minimal por default)
2. **Setup path repository no Arch**:
   ```json
   // ~/arch/composer.json
   "repositories": [
     {"type": "path", "url": "/home/henry/codeguard", "options": {"symlink": true}}
   ],
   "require-dev": {"henryavila/codeguard": "@dev"}
   ```
3. **Validar**: `composer update` no Arch puxa package local; `php artisan list` mostra `codeguard:install`

### Bloco 3 — Extract TestSuiteRunner (3-4h)
1. Copy `/home/henry/arch/app/Services/Testing/*.php` → `src/Testing/`
2. Adjust namespaces `App\Services\Testing` → `Henryavila\Codeguard\Testing`
3. Refactor `TestSuiteRunner::stages()` hardcoded → `$this->config->stages` (CodeguardConfig DTO)
4. Tests portados (ou novos escritos)
5. No Arch: `use Henryavila\Codeguard\Testing\TestSuiteRunner` nas pages/commands que usam
6. CI Arch verde

### Bloco 4+ (depois)
- Assertions + Pest expectations
- Schema dump multi-DB
- AI rules generator
- Pattern engine
- Claude plugin em repo separado

## Decisões Pendentes (leves)

1. **Nome do Packagist**: `henryavila/codeguard` confirmado
2. **Config path repository absoluto ou relativo**: absoluto para não quebrar entre máquinas
3. **Versão dev inicial**: `dev-main` vs `1.0.0-alpha.1` — começar `dev-main`, tagar alpha quando install estabilizar

## Como Retomar

Em nova sessão Claude Code, rodar:
```
cd ~/codeguard
# Claude lê automaticamente CLAUDE.md e .ai/memory/MEMORY.md
# Usuário pede: "continue de onde paramos" ou "começa Bloco 1"
```

Claude deve:
1. Ler MEMORY.md + user-goals + architecture-decisions + este handoff
2. Confirmar estado real (git status, existence de composer.json, etc)
3. Propor Bloco 1 passo-a-passo
4. Executar após confirmação

## Observação Importante

O usuário é **multilíngue** (português/inglês), tipa rápido com muitos typos — isso é normal, não é falta de contexto. Interpretar intenção, não letra.

Usuário usa **Claude Code via VSCode extension** (não CLI) — `!command` não funciona.
