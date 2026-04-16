# Design: CodeGuard v2 — Unified Architecture

**Data:** 2026-04-16
**Status:** Draft v4 (pos-reviews 10 agentes: 6 adversariais + 4 steelman)
**Autor:** Henry Avila + Claude
**Decisao:** 3 pacotes em camadas (npm agnostic + Composer Laravel + Claude Plugin)
**Contexto:** Merge do CodeGuard v0.1 + laravel-quality spec, mantendo agnosticismo

---

## Problema

CodeGuard v0.1 tem o melhor sistema de pattern detection com AI semantic analysis para code governance — 28 patterns YAML, verification rules, false-positive prevention, module hierarchy extensivel. Mas falta test orchestration, test assertions, schema dump, e enforcement hard em tempo de IA.

O spec laravel-quality tem o melhor test orchestration (multi-stage runner, anti-pattern detection, parallel safety, schema dump) e enforcement via Claude hooks. Mas falta pattern system, AI analysis, e agnosticismo.

Nenhum dos dois e completo sozinho. Juntos, criam algo que nao existe em nenhum lugar.

## Principio Fundamental

**CodeGuard e agnostico a linguagem/framework.** Laravel e UM preset entre muitos possiveis. O core NUNCA sabe sobre TestSuiteRunner, Pest, ou Artisan. Features PHP/Laravel vivem em um pacote Composer separado que ENHANCES o core sem modifica-lo.

---

## Arquitetura em 3 Camadas

```
┌─────────────────────────────────────────────────────┐
│  @henryavila/codeguard (npm)                        │
│  ─── Agnostic Core ───                              │
│                                                     │
│  • Pattern YAML system + module hierarchy            │
│  • AI semantic analysis (skills: setup, run, health) │
│  • Hook runner (Node.js bundle, adapter interface)   │
│  • IDE deployer (7 IDEs)                            │
│  • Baseline system                                  │
│  • CLI (npx codeguard install)                      │
│                                                     │
│  modules/core/        → 13 patterns universais       │
│  modules/php/         → 6 patterns PHP               │
│  modules/php-laravel/ → 9 patterns Laravel           │
│  modules/js-react/    → futuro                       │
│  modules/python-django/ → futuro                     │
└──────────────────────┬──────────────────────────────┘
                       │ enhances (optional)
┌──────────────────────▼──────────────────────────────┐
│  henryavila/codeguard (Composer)             │
│  ─── Laravel Power-Up ───                           │
│                                                     │
│  • TestSuiteRunner (multi-stage, parallel phases)    │
│  • PrepareTestDatabaseCommand (schema dump, multi-db)│
│  • TestQualityAssertions + ParallelSafetyAssertions  │
│  • Pest custom expectations                         │
│  • Artisan commands (codeguard:*)                    │
│  • AI rules multi-tool (Claude/Cursor/Copilot/Wind) │
│  • Stubs (phpstan, pint, deptrac, infection...)      │
│  • Deptrac 23-layer template                        │
│  • Quality gate orchestration (auto-detect)          │
└──────────────────────┬──────────────────────────────┘
                       │ enhances (optional, Claude-only)
┌──────────────────────▼──────────────────────────────┐
│  henryavila/codeguard-hooks (Claude Plugin)       │
│  ─── Best-Effort Nudges (CI is the real gate) ───   │
│                                                     │
│  • PostToolUse: php -l + Pint (warn)                │
│  • PreToolUse: PHPStan pre-commit (block)           │
│  • PreToolUse: config-protection (block)            │
│  • Stop: testes condicionais (block)                │
└─────────────────────────────────────────────────────┘
```

### Cada Camada Funciona Sozinha

| Combinacao | O que o user ganha |
|------------|-------------------|
| **So npm** (`npx codeguard install`) | Patterns + AI analysis + hook runner + skills. Funciona para PHP, React, Django, qualquer stack |
| **So Composer** (`codeguard`) | Test runner + assertions + quality gates + schema dump + AI rules. Sem pattern analysis, sem hook runner |
| **npm + Composer** (recomendado) | Tudo: patterns + AI analysis + hook runner + test runner + assertions + stubs |
| **npm + Composer + Claude plugin** | Tudo + enforcement hard em tempo real |

---

## Pacote 1: @henryavila/codeguard (npm) — Agnostic Core

**Ja existe:** v0.1.1 publicado no npm. Sem mudancas arquiteturais necessarias.

### Positioning Honesto (pos-steelman)

O npm core **nao e static analyzer** — e um **rule distribution format + LLM adjudicator** onde AST nao alcanca. Analise dos 28 patterns:

| Classe | Count | Abordagem |
|--------|:-:|-----------|
| **AST-replaceable** | 12 (43%) | Delegar para phpat / pest-arch / PHPMD / Deptrac |
| **Hybrid** (AST narrows, LLM adjudicates) | 13 (46%) | AST candidates + LLM judgment |
| **Pure semantic** | 3 (11%) | So LLM pode fazer (`single-responsibility`, `dry behavioral`, `value-objects primitive obsession`) |

**Valor real que o pattern system entrega:**
1. **Calibration anchors** — `verification.rules` citam PHPMD/SonarQube/NDepend (DIT=6, Miller's Law 7 params, McCabe CC=10). Ancora LLM em numeros da industria, nao alucinados
2. **False-positive carve-outs** — cada pattern tem exception clause ("framework base classes", "DI params aceitaveis", "config-driven switches nao sao branching"). **Isso AST nao consegue encodar**
3. **Semantic distinctions** — DRY pattern separa estrutural (PHPCPD) de behavioral duplication. AST nao distingue
4. **Extraction recommendations** — `action-classes`, `value-objects`, `dto` nao so detectam, sugerem refactor proximo. AST flags; nao advise

**Tagline corrigida**: "AI review where AST can't reach."

**Custo real**: patterns rodam **on-demand via skill** (`/codeguard-run` quando invocado), NAO batch full-repo semanal. Custo por invocacao = 1-5k tokens de input por arquivo analisado.

### O que ja tem (mantido intacto)

- 28 pattern YAMLs (13 core + 6 PHP + 9 Laravel) com detection, verification, examples
- ai-rules/*.md (3 camadas: core, php, laravel) com false-positive prevention
- Module hierarchy (core → php → php-laravel) com detection heuristics
- Hook runner Node.js (self-contained bundle) com 4 adapters (Larastan, Pint, PHPMD, Pest)
- Baseline system com hash matching
- 3 Skills (codeguard-setup, codeguard-run, codeguard-health)
- IDE deployer para 7 IDEs
- CLI (`npx codeguard install`)
- codeguard.yaml schema com capabilities + patterns + thresholds
- 18 test files (Vitest)

### Mudancas para v2

1. **README atualizado**: Mencionar `codeguard` como Laravel power-up
2. **Skill `codeguard-setup`**: Detectar se Composer package esta instalado e sugerir
3. **Roadmap modules**: `js-react/`, `python-django/`, `go/` como exemplos

Nenhuma mudanca no core. O agnosticismo e preservado.

---

## Pacote 2: henryavila/codeguard (Composer) — Laravel Power-Up

### Proposta de valor

```bash
composer require --dev henryavila/codeguard

# Setup interativo (stubs, test config, AI rules)
php artisan codeguard:setup

# Quality gates (auto-detect tools disponiveis)
php artisan codeguard:check

# Multi-stage test runner
php artisan codeguard:test --mode=report

# Schema dump com hash caching
php artisan codeguard:prepare
```

### Estrutura

```
henryavila/codeguard/
├── src/
│   ├── CodeguardLaravelServiceProvider.php
│   ├── Commands/
│   │   ├── CodeguardSetupCommand.php      # php artisan codeguard:setup (Laravel wizard)
│   │   ├── CodeguardCheckCommand.php        # php artisan codeguard:check
│   │   ├── CodeguardTestCommand.php         # php artisan codeguard:test
│   │   └── CodeguardPrepareCommand.php      # php artisan codeguard:prepare
│   ├── Testing/
│   │   ├── TestSuiteRunner.php            # Orquestrador multi-stage
│   │   ├── TestRunResult.php              # Resultado imutavel
│   │   ├── TestStageResult.php            # Resultado por stage
│   │   ├── StageConfig.php               # DTO config de stage
│   │   ├── PrepareConfig.php             # DTO config de schema dump
│   │   ├── CodeguardConfig.php             # DTO config completa (via constructor)
│   │   ├── CommandExecutor.php            # Interface
│   │   ├── AsyncCommandExecutor.php       # Interface async
│   │   ├── ProcessCommandExecutor.php     # Implementacao Symfony Process
│   │   ├── RunningCommand.php             # Interface
│   │   └── ProcessRunningCommand.php      # Implementacao
│   └── Assertions/
│       ├── TestQualityAssertions.php      # Trait: anti-pattern checks
│       ├── ParallelSafetyAssertions.php   # Trait: parallel-safe checks
│       ├── PestExpectations.php           # Pest expect()->quality() registration
│       └── QualityExpectation.php         # Fluent API: ->noTautological()->noModelMock()
├── config/
│   └── codeguard.php                      # Laravel config (stages, gates, prepare)
├── stubs/
│   ├── phpstan.neon.stub
│   ├── phpstan-test-quality.neon.stub
│   ├── pint.json.stub
│   ├── deptrac.yaml.stub                  # Template 23 camadas Laravel
│   ├── .jscpd.json.stub
│   ├── infection.json5.stub
│   ├── .husky/
│   │   ├── pre-commit.stub
│   │   └── pre-push.stub
│   ├── scripts/
│   │   └── check-hooks.sh.stub
│   ├── tests/
│   │   └── Arch/
│   │       └── TestQualityTest.php.stub
│   └── .claude/                           # For --claude-project-hooks
│       ├── settings.json.stub
│       └── hooks/*.sh.stub
├── rules/                                 # Canonical AI rules (source of truth)
│   ├── php-quality.md
│   ├── php-testing.md
│   ├── laravel-services.md
│   ├── laravel-models.md
│   ├── laravel-security.md
│   ├── quality-gates.md
│   └── parallel-tests.md
├── tests/
│   ├── Unit/
│   │   ├── TestSuiteRunnerTest.php
│   │   ├── CodeguardCheckCommandTest.php
│   │   ├── TestQualityAssertionsTest.php
│   │   └── ParallelSafetyAssertionsTest.php
│   └── fixtures/
│       ├── vitest-passed.json
│       ├── vitest-failed.json
│       ├── junit-passed.xml
│       └── junit-failed.xml
├── docs/
│   ├── SETUP.md
│   ├── STAGES.md
│   ├── QUALITY-GATES.md
│   ├── AI-RULES.md
│   ├── CUSTOMIZATION.md
│   ├── MIGRATION.md
│   └── TROUBLESHOOTING.md
├── composer.json
├── LICENSE
└── README.md
```

### Integracao com CodeGuard npm

O ServiceProvider detecta se o npm package esta instalado:

```php
class CodeguardLaravelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->hasNpmCodeguard = file_exists(base_path('.codeguard/hook-runner.js'));

        // Enhanced mode: reference patterns, integrate with codeguard.yaml
        // Standalone mode: use own config/codeguard.php only
    }
}
```

Dois modos de operacao:

| Modo | Condicao | Comportamento |
|------|----------|---------------|
| **Enhanced** | `.codeguard/` existe (npm installed) | Integra com codeguard.yaml, patterns, hook runner. `codeguard:setup` complementa o `/codeguard-setup` skill |
| **Standalone** | `.codeguard/` nao existe | Usa `config/codeguard.php` como unica fonte. `codeguard:setup` e self-contained. Avisa para instalar npm |

### Config (config/codeguard.php)

```php
return [
    'stages' => [
        [
            'key' => 'frontend',
            'label' => 'Frontend (Vitest)',
            'phase' => 1,
            'enabled' => true,
            'command' => ['./node_modules/.bin/vitest', 'run', '--dom', '--reporter=json'],
            'report_type' => 'vitest-json',
            'report_file' => 'frontend.json',
            'report_arg_prefix' => '--outputFile=',
            'fast_fail_arguments' => ['--bail=1'],
        ],
        [
            'key' => 'prepare',
            'label' => 'Prepare Database',
            'phase' => 1,
            'enabled' => true,
            'command' => [PHP_BINARY, 'artisan', 'codeguard:prepare'],
            'report_type' => null,
            'report_file' => null,
            'report_arg_prefix' => null,
            'fast_fail_arguments' => [],
        ],
        [
            'key' => 'php-main',
            'label' => 'Unit + Feature + Integration',
            'phase' => 2,
            'enabled' => true,
            'command' => ['./vendor/bin/pest', '--testsuite=Unit,Feature,Integration'],
            'report_type' => 'junit',
            'report_file' => 'php-main.xml',
            'report_arg_prefix' => '--log-junit=',
            'fast_fail_arguments' => ['--bail'],
        ],
    ],

    'gates' => [
        'audit'    => ['enabled' => true,  'label' => 'Security Audit',        'command' => 'composer audit --format=plain'],
        'pint'     => ['enabled' => true,  'label' => 'Code Style (Pint)',      'command' => './vendor/bin/pint --test'],
        'phpstan'  => ['enabled' => true,  'label' => 'Static Analysis',        'command' => './vendor/bin/phpstan analyse --memory-limit=8G --no-progress'],
        'deptrac'  => ['enabled' => false, 'label' => 'Architecture (Deptrac)', 'command' => './vendor/bin/deptrac analyse --no-progress'],
        'jscpd'    => ['enabled' => false, 'label' => 'Copy-Paste Detection',   'command' => 'npx jscpd app/ --min-lines 5 --min-tokens 50 --threshold 10'],
        'insights' => ['enabled' => false, 'label' => 'Code Quality Insights',  'command' => 'php artisan insights --no-interaction --summary'],
    ],

    'report_dir' => storage_path('framework/testing/test-reports'),

    'prepare' => [
        'connection' => env('CODEGUARD_PREPARE_CONNECTION', 'sqlite'),
        'connection_overrides' => [],
        'extra_migration_paths' => [],
        'schema_path' => database_path('schema'),
        'hash_file' => database_path('schema/.migrations-hash'),
    ],

    'protected_configs' => [
        'phpstan.neon', 'phpstan-baseline.neon', 'phpstan-test-quality.neon',
        'pint.json', 'deptrac.yaml', 'deptrac-baseline.yaml',
        'psalm.xml', 'psalm-baseline.xml', 'infection.json5',
        'phpunit.xml', '.jscpd.json', '.jscpd-tests.json',
    ],
];
```

### Artisan Commands

| Comando | Funcao | Modo Enhanced | Modo Standalone |
|---------|--------|:---:|:---:|
| `codeguard:setup` | Wizard: stubs + test config + AI rules + hooks | Complementa /codeguard-setup | Self-contained |
| `codeguard:check` | Quality gates sequenciais (fail-fast, auto-detect) | ✅ | ✅ |
| `codeguard:test` | Multi-stage test runner com report consolidado | ✅ | ✅ |
| `codeguard:prepare` | Schema dump com hash caching, multi-driver | ✅ | ✅ |

### codeguard:setup Wizard

```
$ php artisan codeguard:setup

 CodeGuard Laravel Setup
 =======================

 ℹ CodeGuard npm detected (.codeguard/ exists)
   Pattern analysis available via /codeguard-setup skill.

 Choose a preset (default: Minimal — press enter):
  [1] Minimal (Pint + PHPStan — 2 files)              ← DEFAULT
  [2] Standard (+ Deptrac + Infection + Husky hooks — 7 files)
  [3] Full (+ jscpd + Insights + TestQualityTest — 12 files)
 > [enter]

 Publishing stubs (Minimal preset)...
 ✓ phpstan.neon
 ✓ pint.json

 Generate AI rules for your coding assistant? [yes/no]
 > yes

 Which AI tools do you use?
 [x] Claude Code (.claude/rules/)
 [x] Cursor (.cursor/rules/)
 [ ] GitHub Copilot
 [ ] Windsurf
 > ↵

 ✓ .claude/rules/php-quality.md (+ 3 more)
 ✓ .cursor/rules/php-quality.mdc (+ 3 more)

 Add Composer scripts? [yes/no]
 > yes
 ✓ Added quality, codeguard:test, codeguard:test:ci

 Done! Run `php artisan codeguard:check` to verify.
```

### AI Rules Multi-Tool

Canonical source em `rules/`. O wizard gera formato nativo para cada tool:

| Tool | Formato | Path | Path trigger |
|------|---------|------|-------------|
| Claude Code | Markdown + `paths:` frontmatter | `.claude/rules/*.md` | YAML `paths:` |
| Cursor | MDC + `globs:` frontmatter | `.cursor/rules/*.mdc` | YAML `globs:` |
| Copilot | Plain markdown (append) | `.github/copilot-instructions.md` | Nenhum (always loaded) |
| Windsurf | Plain markdown (append) | `.windsurfrules` | Nenhum (always loaded) |

7 rules distribuidas:

| Rule | Scope (`file_patterns`) | Conteudo principal |
|------|------------------------|--------------------|
| `php-quality` | `app/**/*.php` | strict_types, sprintf, null checks, validated() |
| `php-testing` | `tests/**`, `database/factories/**` | Pest syntax, no assertTrue(true), no model mock, no truncate |
| `laravel-services` | `app/Services/**` | SRP, DI constructor, value objects, no HTTP in services |
| `laravel-models` | `app/Models/**` | Data + queries only, no service imports |
| `laravel-security` | `app/Http/**`, `routes/**` | validated(), no raw SQL, CSRF, mass assignment |
| `quality-gates` | `phpstan.neon`, `pint.json`, `deptrac.yaml` | HARD GATE: never weaken configs |
| `parallel-tests` | `tests/**` | function_exists, no global state, factory isolation |

### DTOs — Config via Constructor Injection

```php
final readonly class StageConfig
{
    public function __construct(
        public string $key,
        public string $label,
        public int $phase,
        public bool $enabled,
        public array $command,
        public ?string $reportType,       // 'vitest-json' | 'junit' | null
        public ?string $reportFile,
        public ?string $reportArgPrefix,
        public array $fastFailArguments,
    ) {}

    public static function fromArray(array $data): self { /* ... */ }
}

final readonly class PrepareConfig
{
    public function __construct(
        public string $connection,
        public array $connectionOverrides,
        public array $extraMigrationPaths,
        public string $schemaPath,
        public string $hashFile,
    ) {}

    public static function fromArray(array $data): self { /* ... */ }
}

final readonly class CodeguardConfig
{
    /**
     * @param StageConfig[] $stages
     * @param array<string, array{enabled: bool, label: string, command: string}> $gates
     * @param string[] $protectedConfigs
     */
    public function __construct(
        public array $stages,
        public array $gates,
        public string $reportDir,
        public array $protectedConfigs,
        public PrepareConfig $prepare,
    ) {}

    public static function fromArray(array $config): self { /* ... */ }
}
```

ServiceProvider wiring:

```php
// ServiceProvider — testavel sem framework
$this->app->singleton(CodeguardConfig::class, fn ($app) =>
    CodeguardConfig::fromArray($app['config']['codeguard'])
);

$this->app->bind(CommandExecutor::class, ProcessCommandExecutor::class);

// TestSuiteRunner recebe config via constructor
public function __construct(
    private readonly CodeguardConfig $config,
    private readonly CommandExecutor $executor,
) {}
```

### TestQualityAssertions + ParallelSafetyAssertions

Dual: Traits (PHPUnit compat) + Pest expectations (idiomatic).

**TestQualityAssertions (3 checks):**
- `assertNoTautologicalAssertions()` / `expect()->quality()->noTautologicalAssertions()`
- `assertNoEloquentModelMocking()` / `expect()->quality()->noEloquentModelMocking()`
- `assertNoBareAssertNotNull()` / `expect()->quality()->noBareAssertNotNull()`

**ParallelSafetyAssertions (4 checks):**
- `assertNoTruncateInTests(allowlist)` / `expect()->quality()->noTruncateInTests()`
- `assertNoForceDeleteInTests(allowlist)` / `expect()->quality()->noForceDeleteInTests()`
- `assertNoDbQueriesInFactoryDefinition(allowlist)` / `expect()->quality()->noDbQueriesInFactories()`
- `assertNoEagerCreateInFactoryDefinition(allowlist)` / `expect()->quality()->noEagerCreateInFactories()`

### Pest Expectations — Registro

`PestExpectations.php` registra custom expectations via `Pest\Expectation::extend()`. O ServiceProvider chama o registro condicionalmente (Pest pode nao estar instalado):

```php
// CodeguardLaravelServiceProvider::boot()
if (class_exists(\Pest\Expectation::class) && app()->runningUnitTests()) {
    PestExpectations::register();
}

// PestExpectations.php
final class PestExpectations
{
    public static function register(): void
    {
        expect()->extend('quality', fn () => new QualityExpectation());
    }
}

// QualityExpectation — fluent API
final class QualityExpectation
{
    public function noTautologicalAssertions(): self { /* scan test files */ }
    public function noEloquentModelMocking(): self { /* scan test files */ }
    // ... 5 mais
}
```

Resultado: `expect()->quality()->noTautologicalAssertions()->noEloquentModelMocking()` encadeaveis.

### CodeguardCheckCommand — Auto-detect

Cada gate verifica se a ferramenta existe antes de executar. Se PHPStan nao esta instalado mas enabled=true, o gate e pulado com aviso claro.

### CodeguardPrepareCommand — Multi-driver (Killer Feature)

Laravel `schema:dump` tem limitacoes severas documentadas em Laravel 12.x:

| Driver | Laravel nativo | CodeGuard fallback |
|--------|:-:|:-:|
| MySQL / MariaDB / PostgreSQL | ✅ (mysqldump / pg_dump) | delega ao nativo |
| SQLite (file) | ⚠️ (buggy — bug [#52131](https://github.com/laravel/framework/issues/52131) inclui `sqlite_stat*` tables) | delega + filtra `sqlite_%` internal tables |
| **SQLite `:memory:`** | ❌ (so load, nao dump — `SqliteSchemaState.php:65-72`) | ✅ PDO + `sqlite_master` export |
| **SQL Server (sqlsrv)** | ❌ `throw new RuntimeException('Schema dumping is not supported')` | ✅ PDO export para connection secundario SQLite (common pattern: prod sqlsrv, tests sqlite) |
| Windows sem `sqlite3` CLI | ❌ (issue [#35162](https://github.com/laravel/framework/issues/35162)) | ✅ PDO nao precisa binario |
| MongoDB | N/A (sem Schema grammar) | stage separado no runner |

**Target projetos (que nativo nao atende):**
- Prod sqlsrv + tests sqlite — `schema:dump` lanca RuntimeException no connection prod
- Tests em SQLite `:memory:` (`LazilyRefreshDatabase`) — dump path nao existe
- Containers Alpine / Windows sem `sqlite3` CLI
- Multi-path migrations (`database/migrations/externals/` para bancos externos)
- Multi-package config overrides (Spatie activitylog, spatie/health)

**Benchmark** (Laravel News, Alex Vanderbist):
- 126 migrations: 11s → 1.2s (85% reducao)
- 235 migrations × 20 ParaTest workers (caso Arch) = ~4.700 DDL statements evitados por `composer test`

**Guard contra producao**: recusa se `APP_ENV=production` ou `DB_HOST` nao e localhost.

### CodeguardTestCommand — UX Features

1. Progress callback (conta ticks Pest + dots ParaTest, atualiza 500ms)
2. Log tee (stdout + arquivo simultaneamente)
3. Duration formatting (4 faixas: ms/s/m/h)
4. Consolidated report (tabela por stage + totals + failure list)

### Dependencias

```json
{
  "require": {
    "php": "^8.2",
    "illuminate/console": "^11.0|^12.0",
    "illuminate/support": "^11.0|^12.0",
    "illuminate/filesystem": "^11.0|^12.0",
    "symfony/process": "^7.0"
  },
  "suggest": {
    "@henryavila/codeguard": "Full pattern analysis and AI-powered code governance",
    "larastan/larastan": "Static analysis with Laravel support",
    "laravel/pint": "Code style formatting",
    "qossmic/deptrac": "Architectural boundary enforcement",
    "infection/infection": "Mutation testing",
    "nunomaduro/phpinsights": "Code quality insights"
  }
}
```

### vendor:publish tags

```php
$this->publishes([...], 'codeguard-config');
$this->publishes([...], 'codeguard-phpstan');
$this->publishes([...], 'codeguard-pint');
$this->publishes([...], 'codeguard-deptrac');
$this->publishes([...], 'codeguard-test-assertions');
$this->publishes([...], 'codeguard-husky');
```

---

## Pacote 3: henryavila/codeguard-hooks (Claude Plugin)

Hooks-only. **Best-effort nudges** (não "hard enforcement") — Claude-exclusive. CI continua sendo o gate real.

### Limitacoes Reconhecidas (Claude Code)

Hooks do Claude Code tem limitacoes arquiteturais documentadas em issues oficiais (fechadas como "not planned"):

| # | Limitacao | Issue | Mitigacao CodeGuard |
|---|-----------|-------|---------------------|
| L1 | `Bash(sed/awk/tee/>)` contorna `Edit|Write` matcher | [#6876](https://github.com/anthropics/claude-code/issues/6876), [#29709](https://github.com/anthropics/claude-code/issues/29709) | Adicionar matcher `Bash` ao config-protection com regex de file-mutating commands |
| L2 | `git commit --no-verify` / `HUSKY=0` burla pre-commit | [#40117](https://github.com/anthropics/claude-code/issues/40117) (Opus 4.6 fazendo isso em 6 commits seguidos) | Bash matcher detecta `--no-verify`; CI e o real gate |
| L3 | Task tool spawna subagents — **nao herdam hooks** | [#27661](https://github.com/anthropics/claude-code/issues/27661) (OPEN) | Documentar honestamente: subagents bypassam. Reforcar com CI |
| L4 | MCP tools (`mcp__*__write_file`) contornam matcher Edit/Write | config matcher | Adicionar `mcp__.*` ao matcher list |
| L5 | PostToolUse exit code ignorado para Write/Edit | [#13744](https://github.com/anthropics/claude-code/issues/13744) | Documentar: PostToolUse e warn-only |
| L6 | Hooks rodam com user permissions — `chmod -x` os desabilita | — | Mitigacao inerente-Claude, nao CodeGuard |

**Framing honesto**: "Best-Effort Nudges for honest mistakes — CI is the real gate. Never merge on hook success alone."

### Estrutura

```
henryavila/codeguard-hooks/
├── .claude-plugin/
│   └── plugin.json
├── hooks/
│   ├── hooks.json
│   ├── post-php-lint.sh          # PostToolUse: php -l + Pint (warn)
│   ├── pre-commit-phpstan.sh     # PreToolUse: PHPStan staged (block)
│   ├── stop-verify-tests.sh      # Stop: PHPStan + Pest if .php modified (block)
│   └── config-protection-php.sh  # PreToolUse: block config edits (block)
├── README.md
└── LICENSE
```

### Hook Design (incorporando fixes dos 10 reviews)

**Seguranca basica:**
- JSON parsing: `jq` primario, python3 fallback
- Newline stripping: `tr -d '\n'` apos extracao
- Config-protection: `realpath` + case-insensitive comparison
- Temp files: `trap EXIT` + `chmod 600`
- Stop hook: sentinel file `.quality-verified` como opt-in (com caveats — ver L7)

**Matchers expandidos (incorporando bypasses conhecidos):**

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Edit|Write|mcp__.*__write_file|mcp__.*__str_replace",
        "hooks": [{"command": "config-protection-php.sh"}]
      },
      {
        "matcher": "Bash",
        "hooks": [{"command": "config-protection-bash.sh"}],
        "description": "Block sed/awk/tee/>/cat > on protected configs + git commit --no-verify"
      }
    ]
  }
}
```

**config-protection-bash.sh** (novo hook) deve regex-parse o comando Bash por:
- `(sed|awk|perl|python|tee|>\s*|>>)\s+.*\b(phpstan\.neon|pint\.json|deptrac\.yaml|\.neon|\.yaml baseline)\b`
- `git commit.*--no-verify`
- `git.*core\.hooksPath`
- `HUSKY=0`, `chmod -x .*hooks`

**Stop hook — sentinel upgrade (L7 mitigation):**

```bash
# Nao basta arquivo existir — verifica tree hash
EXPECTED=$(git rev-parse HEAD:)
ACTUAL=$(cat .quality-verified 2>/dev/null)
[[ "$EXPECTED" != "$ACTUAL" ]] && exit 2
```

Touch vazio nao passa. Codeguard:check escreve `git rev-parse HEAD:` no sentinel ao passar.

**Correcoes dos reviews:**
- PostToolUse: warn-only (documentado honestamente — exit code ignorado em Write/Edit)
- PreToolUse PHPStan: timeout 120s, `--error-format=table`, **staged-files only** via `--paths-file` (resolve monorepo performance)
- Stop hook: PHPStan primeiro, depois Pest focado (Unit+Feature+Integration)
- **README deve declarar**: "Hooks sao best-effort nudges. CI e o gate real. Nunca faca merge so com hook success."

---

## Onde Cada Feature Mora

| Feature | npm (agnostic) | Composer (Laravel) | Claude Plugin |
|---------|:-:|:-:|:-:|
| 28 pattern YAMLs | ✅ | Acessa via .codeguard/ | |
| AI rules + false-positive prevention | ✅ | | |
| Module hierarchy (core→php→laravel) | ✅ | | |
| Hook runner (Node.js) | ✅ | | |
| Tool adapters (Larastan, Pint, PHPMD, Pest) | ✅ | | |
| Baseline system | ✅ | | |
| Skills (setup, run, health) | ✅ | | |
| IDE deployer (7 IDEs) | ✅ | | |
| codeguard.yaml schema | ✅ | Le se disponivel | |
| CODEGUARD.md generation | ✅ (via skill) | | |
| TestSuiteRunner | | ✅ | |
| PrepareTestDatabaseCommand | | ✅ | |
| TestQualityAssertions (7 checks) | | ✅ | |
| Pest custom expectations | | ✅ | |
| Artisan commands (codeguard:*) | | ✅ | |
| AI rules multi-tool | | ✅ | |
| Stubs (phpstan, pint, deptrac) | | ✅ | |
| Deptrac 23-layer template | | ✅ | |
| Quality gate orchestration | | ✅ | |
| Config protection (protected_configs) | | ✅ (list) | ✅ (enforcement) |
| PostToolUse php -l + Pint | | | ✅ |
| PreToolUse PHPStan | | | ✅ |
| PreToolUse config-protection | | | ✅ |
| Stop testes condicionais | | | ✅ |

---

## Extracao do Arch — Delta

O codigo atual no Arch (`app/Services/Testing/`) e a base para o Composer package. Esta secao documenta o que muda durante a extracao.

### Ja existe no Arch (extrair diretamente)

| Classe Arch | Classe Package | Mudancas |
|-------------|---------------|----------|
| `TestSuiteRunner` | `TestSuiteRunner` | Remover `stages()` hardcoded → receber `StageConfig[]` via `CodeguardConfig` |
| `TestRunResult` | `TestRunResult` | Nenhuma — ja e imutavel e desacoplado |
| `TestStageResult` | `TestStageResult` | Nenhuma |
| `CommandExecutor` | `CommandExecutor` | Nenhuma (interface) |
| `AsyncCommandExecutor` | `AsyncCommandExecutor` | Nenhuma (interface) |
| `ProcessCommandExecutor` | `ProcessCommandExecutor` | Nenhuma |
| `RunningCommand` | `RunningCommand` | Nenhuma (interface) |
| `ProcessRunningCommand` | `ProcessRunningCommand` | Nenhuma |
| `RunTestsCommand` | `CodeguardTestCommand` | Renomear, remover refs Arch-specific (MongoDB stage, Playwright cleanup) |

### Nao existe no Arch (criar do zero)

| Classe | Razao |
|--------|-------|
| `StageConfig` | Stages sao hardcoded em `TestSuiteRunner::stages()` — precisa virar DTO |
| `PrepareConfig` | Schema dump config esta dispersa no Arch |
| `CodeguardConfig` | Unifica stages + gates + prepare num unico DTO injetavel |
| `CodeguardLaravelServiceProvider` | Wiring de singletons, merge config, auto-detect |
| `CodeguardSetupCommand` | Wizard com presets — nao existe no Arch |
| `CodeguardCheckCommand` | Quality gates — no Arch sao scripts Composer, nao artisan |
| `CodeguardPrepareCommand` | Schema dump — no Arch e inline no runner |
| `TestQualityAssertions` | Anti-pattern checks — no Arch sao testes avulsos, nao trait reutilizavel |
| `ParallelSafetyAssertions` | Parallel-safety checks — mesma situacao |
| `PestExpectations` | Registration via `Pest\Expectation::extend()` — nao existe |
| `QualityExpectation` | Fluent API para expectations encadeaveis — nao existe |

### Mudanca arquitetural principal

```
ANTES (Arch):
  TestSuiteRunner::stages() → array hardcoded com 5 stages
  RunTestsCommand → instancia runner com CommandExecutor + $reportDir string

DEPOIS (Package):
  config/codeguard.php → StageConfig[] via CodeguardConfig::fromArray()
  CodeguardTestCommand → instancia runner com CodeguardConfig + CommandExecutor
```

O Arch depois de migrar sera consumidor do package: `composer require --dev henryavila/codeguard` e override de stages em `config/codeguard.php` (adicionando mongodb, browser, etc.).

---

## Fluxos de Instalacao

### Full Stack (recomendado para Laravel + AI)

```bash
# 1. Core: patterns + AI analysis + hook runner
npx @henryavila/codeguard install

# 2. Laravel: test runner + assertions + quality gates
composer require --dev henryavila/codeguard

# 3. AI skill: configure everything
/codeguard-setup

# 4. Laravel-specific: stubs + test config + AI rules
php artisan codeguard:setup

# 5. (Optional) Claude enforcement hooks
# In Claude Code:
/plugin install henryavila/codeguard-hooks
```

### Laravel-Only (sem npm)

```bash
composer require --dev henryavila/codeguard
php artisan codeguard:setup
php artisan codeguard:check
```

### Non-Laravel (React, Django, etc.)

```bash
npx @henryavila/codeguard install
/codeguard-setup
# No Composer package needed
```

---

## Roadmap

| Fase | O que | Repo |
|------|-------|------|
| **1** | Criar `codeguard` — extrair TestSuiteRunner + assertions do Arch | Composer |
| **2** | codeguard:setup artisan + wizard com presets + stubs | Composer |
| **3** | AI rules multi-tool generation | Composer |
| **4** | Integracao enhanced/standalone com .codeguard/ detection | Composer |
| **5** | Claude plugin (4 hooks com fixes) | Plugin |
| **6** | Arch migra para `codeguard` + testa ambos modos | Arch |
| **7** | Atualizar CodeGuard npm README + link para Composer package | npm |
| **8** | Lancamento coordenado | Todos |

---

## Diferenciadores Reais (pos-steelman)

Dos 8 "diferenciadores" originais, 5 sobrevivem honestidade factual:

| # | Claim | Veredicto | Evidencia |
|---|-------|-----------|-----------|
| 1 | Pattern YAML + AI adjudicator | **SOBREVIVE** (repositioning) | 16/28 patterns codificam judgment AST nao captura (`single-responsibility`, `dry behavioral`, `value-objects`, `action-classes`, `no-logic-in-blade`, `no-god-object`, `bounded-contexts`, `separation-of-concerns`, `policies`) |
| 2 | Multi-stage parallel test orchestration | **SOBREVIVE** | Arch escreveu 770 LOC porque nenhuma OSS faz. GrumPHP tem tasks homogeneas, nao multi-stage heterogeneo com report parsing (Vitest JSON + JUnit XML) |
| 3 | Test anti-pattern kit (traits + Pest expectations) | **SOBREVIVE PARCIAL** | 7 checks packaged como kit — novo. Mas conteudo se sobrepoe a `pest-plugin-arch` presets (delegar onde possivel) |
| 4 | AI rules native format × 4 tools | **SOBREVIVE FRAGIL** | 6 meses de janela antes de commodification (AGENTS.md emergindo). Ate la, unico |
| 5 | AI-time config-protection via Claude hooks | **SOBREVIVE FORTE** | Genuinamente unico. "Block AI de enfraquecer phpstan.neon" ninguem oferece |
| 6 | Schema dump multi-driver + hash cache | **SOBREVIVE FORTE** | Laravel nativo nao suporta sqlsrv, `:memory:`, Windows sem sqlite3 CLI. Killer feature para multi-DB |
| 7 | Deptrac 23-layer template | **DESCARTAR** | E um YAML — ship como example gist, nao default stub |
| 8 | Language-agnostic core | **DESCARTAR** | MegaLinter ja cobre 50 linguagens. Claim com so PHP e marketing |

**Tagline honesta**: *"Laravel quality gates that survive your AI agent — pattern recognition where AST can't reach, test orchestration where GrumPHP can't reach, schema acceleration where Laravel can't reach."*

---

## Riscos

| Risco | Mitigacao |
|-------|----------|
| 2 installs (npm + Composer) e fricao | Cada um funciona sozinho; full stack e opcional |
| npm CodeGuard parado desde marco 2026 | v2 update com link para Composer package revive |
| Config split (codeguard.yaml vs codeguard.php) | Enhanced mode le ambos; standalone usa so .php |
| Complexidade 3 pacotes | Separacao clara de responsabilidade; cada um e simples |
| PHP devs sem Node.js | Composer standalone funciona sem npm |
| Node.js devs sem PHP | npm standalone funciona sem Composer |
| Claude plugin audiencia limitada | AI rules universais no Composer compensam |

---

## Review Round 2 — Resultados Consolidados

**Agents:** Architecture, DX/Adoption, Migration v0.1→v2, Naming/Positioning (2026-04-16)

### Consenso: Composer e o Hero

Todos os 4 reviews convergem: **o Composer package deve ser o entry point principal**. O npm package e optional power-up, mencionado em secao "Advanced".

README hero: `composer require --dev henryavila/codeguard && php artisan codeguard:setup`

### Hard Conflicts Resolvidos

| Conflict | Resolucao |
|----------|-----------|
| `.git/hooks/pre-commit` — npm hook-runner vs Husky | Enhanced: skip Husky (npm hook-runner e o pre-commit). Standalone: Husky |
| Pint/PHPStan rodam 2x — hook runner + `codeguard:check` | Documentar: hook runner = commit-time, `codeguard:check` = CI/manual |
| 2 config files (yaml + php) | yaml = AI/hooks (npm scope), php = artisan commands (Composer scope). Standalone: so .php |
| 2 setup flows confusos | Enhanced mode: artisan detecta npm e skip duplicatas |
| `.codeguard/` deteccao fragil | Config: `'mode' => env('CODEGUARD_MODE', 'auto')` |
| Enhanced mode merge semantics | codeguard.yaml vence para tool config; codeguard.php vence para stages/gates |

### Naming (confirmado)

- `henryavila/codeguard` (Packagist) — `-laravel` redundante (illuminate/* no require)
- `henryavila/codeguard-hooks` (Claude Plugin) — brand coherence
- Namespace `codeguard:*` para todos os artisan commands
- Tagline: "Your codebase has standards. Now they're enforced."

### Validados sem problemas

3-package split sound, dependency direction unidirecional (Composer→npm), extensibility funciona, Packagist zero colisao

---

## Review Round 3 — Steelman Pos-Adversarial (2026-04-16 tarde)

Apos Round 2 adversarial (6 agentes) chegar a veredicto 3.5/10, foi conduzido Round 3 com 4 agentes em modo **steelman** + **methodology audit**. Resultado: Round 2 teve **prompt-induced bias score 7/10** — tom adversarial amplificou gaps factuais.

### Score Agregado Revisado (honesto)

| Componente | Round 2 (adversarial) | Round 3 (steelman) | Delta |
|------------|:---:|:---:|:---:|
| Composer package (TestSuiteRunner + assertions + prepare) | 3-4/10 | **7/10** | +3 |
| npm core (patterns + AI adjudicator) | 3/10 | **5/10** | +2 |
| Claude hooks plugin | 3/10 | **6/10** | +3 |
| 3-package split | 3/10 | **6/10** | +3 |
| Schema dump multi-DB | "WEAK, 30 LOC" | **HIGH (killer feature)** | correcao factual |
| 28 patterns value | "snake oil" | 16/28 codificam judgment real | invertido |
| Test orchestration | "90% GrumPHP" | nenhuma OSS cobre >40% | correcao factual |
| **Agregado** | **3.5/10** | **6.0/10** | **+2.5** |

### Gaps Metodologicos Identificados (Round 2)

| # | Review | Gap | Correcao |
|---|--------|-----|----------|
| 1 | AI Rules Efficacy | Strawman — atacou "rules como enforcement deterministico" (design nao assume isso) | Rules sao onboarding; hooks sao enforcement |
| 2 | Enforcement Depth | False equivalence com GrumPHP (GrumPHP nao faz multi-stage Vitest+Pest+Mongo+Browser) | Stack Arch real comprova |
| 3 | Pattern System | Atacou pattern mais fraco (`no-env-outside-config`) e generalizou | 16/28 sao semantic/hybrid |
| 4 | Adoption Friction | Persona errada (42% zero tools — target sao 36% que ja usam Pint+PHPStan) | Default preset = Minimal |
| 5 | Security Bypass | Bypasses sao limitacoes do Claude Code, nao do CodeGuard | Adicionar Bash matcher + documentar honestamente |
| 6 | Competitive | "Schema dump = 30 linhas" factualmente falso | Laravel nao suporta sqlsrv/`:memory:`/Windows |

### Claims Factualmente Errados do Round 2

1. "Schema dump = 30 linhas" → Real: 182 linhas resolvendo gaps do Laravel nativo
2. "GrumPHP faz 80%" → GrumPHP nao faz multi-stage heterogeneo (Vitest JSON + JUnit XML + Browser)
3. "28 patterns = fraude" → 16/28 codificam judgment nao-AST
4. "Copilot 4k limit" → Design gera 7 arquivos separados, nao concatenado
5. "AgentIF <30% compliance" → Benchmark agentic generico, nao file-scoped rules

### Strengths que Nenhum Adversarial Reviewer Identificou

1. **Compositional layering** — cada pacote funciona sozinho (testavel/deployavel/rollback independente)
2. **Multi-stage heterogeneous report parsing** — Vitest JSON + JUnit XML consolidados (GrumPHP nao faz)
3. **`CODEGUARD_MODE` env override** — resolve fragile detection *antes* de virar bug
4. **Constructor DTO injection** — runner testavel sem framework
5. **Dual expression (Trait + Pest)** — acomoda legacy PHPUnit E Pest idiomatic

---

## Decisoes Pendentes (atualizado pos-review)

| Decisao | Opcoes | Recomendacao Review | Quando |
|---------|--------|-------------------|--------|
| Config name | `codeguard.php` vs `quality.php` | `codeguard.php` (brand coherence) | Fase 1 |
| `protected_configs` duplicacao | Gerar bash de PHP vs listas independentes | Listas independentes (pragmatismo) | Fase 5 |
| Claude plugin tests | BATS vs manual | Manual para v1, BATS para v2 | Fase 5 |
| Enhanced mode: como Pint enforcement resolve | `codeguard.yaml` vence vs gate skip | Documentar: hook runner = commit, `codeguard:check` = CI/manual | Fase 4 |
| Enhanced mode: quem instala git hooks | npm hook-runner vs Husky | npm hook-runner em Enhanced, Husky em Standalone | Fase 4 |
| Enhanced mode: AI rules gerados por quem | npm skill vs artisan setup | artisan setup e autoritativo para rules multi-tool; skill gera CODEGUARD.md | Fase 3-4 |
