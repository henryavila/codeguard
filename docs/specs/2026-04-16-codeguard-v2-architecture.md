# Design: CodeGuard v5 — Laravel Quality Gates Composer Package

**Data**: 2026-04-16
**Status**: Active spec (supersedes v4)
**Autor**: Henry Ávila + Claude
**Decisão**: 2 pacotes (Composer core PHP + Claude plugin bash hooks) — PHP/Laravel-only
**Contexto**: Consolidação de quality gates para múltiplos projetos Laravel do usuário, distribuído via Composer para rodar em múltiplas máquinas e controlar qualidade de dev terceirizado que não usa IA

> **Supersedes**: este documento substitui o design v4 ("3-package agnostic architecture") após redesign da sessão 2026-04-16. Razões documentadas em `.ai/memory/preset-design-evolution.md` e `.ai/memory/architecture-decisions.md` (ADR-001 pivot, ADR-002 2 packages, ADR-006 preset redesign).

---

## Problema

O usuário tem múltiplos projetos PHP/Laravel (Arch + outros) que precisam do mesmo stack de quality gates: Pint (formatação), PHPStan (type safety), Deptrac (arquitetura), Infection (test quality), Lefthook (pre-commit enforce). Problemas concretos:

1. **Padronização manual é frágil** — cada projeto ganha uma versão ligeiramente diferente dos configs. Evolução do padrão exige cherry-pick em N repos.
2. **Multi-machine drift** — Henry trabalha em desktop + notebook; diferentes versões das tools quebram CI reproducibility.
3. **Dev terceirizado sem IA** — precisa de defesas writer-side (CI gates + pre-commit) e reviewer-side (AI rules + pattern analysis). Nenhuma ferramenta OSS cobre ambos em bundle coeso.
4. **Ecossistema fragmentado** — existem excelentes tools individuais mas integração entre elas (consolidated report, multi-stage parallel, schema dump multi-DB) exige engineering custom em cada projeto.

O Arch (`/home/henry/arch`) já escreveu ~770 LOC de `TestSuiteRunner` + `TestQualityAssertions` + `PrepareTestDatabaseCommand` porque nenhuma OSS atende. Essa base, extraída e generalizada como Composer package, resolve os 4 problemas.

## Princípio Fundamental

**CodeGuard é um package Laravel.** Não é agnostic core, não é multi-framework wrapper. Stack target: PHP 8.3+ + Laravel 11|12 + Pest 3|4. Componentes não-Laravel (Vue, TypeScript, MongoDB) são **stages plugáveis**, não ecossistemas paralelos.

Escopo "PHP-only" refere-se ao **core do package**: zero `node_modules` bundled, roda em Alpine PHP container sem Node runtime. Tools opcionais que requerem Node (jscpd) vivem em preset **opt-in** (`codeguard-full`) com dependência documentada e auto-detectada.

Escalabilidade futura para outras linguagens é feita via **companion packages nativos** (`codeguard-symfony`, `codeguard-python` — se demanda real surgir), não via "agnostic core".

---

## Arquitetura em 2 Camadas

```
┌─────────────────────────────────────────────────────┐
│  henryavila/codeguard (Composer)                    │
│  ─── Laravel Quality Gates Core ───                 │
│                                                     │
│  • Artisan commands (codeguard:*)                   │
│    ├─ install      guided hybrid install            │
│    ├─ check        quality gates (fail-fast)        │
│    ├─ test         multi-stage test runner          │
│    ├─ prepare      schema dump multi-DB             │
│    ├─ analyze      pattern engine (LLM adjudicator) │
│    └─ baseline     findings baseline manager        │
│                                                     │
│  • DTOs + services                                  │
│    ├─ TestSuiteRunner (multi-stage)                 │
│    ├─ CodeguardConfig + StageConfig + PrepareConfig │
│    ├─ TestQualityAssertions (Traits + Pest expect)  │
│    ├─ ParallelSafetyAssertions                      │
│    └─ SchemaDumper (multi-driver)                   │
│                                                     │
│  • Resources (distribuído via Composer)             │
│    ├─ patterns/       28 YAMLs (data contract)      │
│    ├─ rules/          canonical AI rules markdown   │
│    ├─ stubs/          Pint, PHPStan, Deptrac, etc.  │
│    └─ skills/         Claude skills (setup, run)    │
│                                                     │
│  • Pattern engine (PHP nativo + symfony/yaml)       │
│  • AI rules multi-tool generator                    │
└──────────────────────┬──────────────────────────────┘
                       │ complements (optional)
┌──────────────────────▼──────────────────────────────┐
│  henryavila/codeguard-hooks (Claude Plugin)         │
│  ─── Best-Effort Nudges — CI is the real gate ───   │
│                                                     │
│  • PreToolUse: config-protection (nudge)            │
│  • PreToolUse: Bash matcher (sed/awk/no-verify)     │
│  • Stop: git tree-hash sentinel                     │
│  • PostToolUse: php -l + Pint warning               │
└─────────────────────────────────────────────────────┘
```

### Cada Camada Funciona Sozinha

| Install | O que o user ganha |
|---------|-------------------|
| **Só Composer** (`composer require --dev henryavila/codeguard`) | Full feature set: commands, DTOs, assertions, patterns, AI rules, schema dump, stubs. Funciona para qualquer projeto Laravel 11/12. |
| **Composer + Claude plugin** (`/plugin install henryavila/codeguard-hooks`) | + config-protection nudges em tempo de edit Claude. CI continua sendo o gate real. |

---

## Pacote 1: henryavila/codeguard (Composer)

### Estrutura

```
codeguard/
├── src/
│   ├── CodeguardServiceProvider.php         # register + boot + publishes
│   ├── Commands/
│   │   ├── CodeguardInstallCommand.php      # wizard guided híbrido
│   │   ├── CodeguardCheckCommand.php        # gates sequencial fail-fast
│   │   ├── CodeguardTestCommand.php         # multi-stage orchestrator
│   │   ├── CodeguardPrepareCommand.php      # schema dump multi-DB
│   │   ├── CodeguardAnalyzeCommand.php      # pattern engine (Fase 2)
│   │   └── CodeguardBaselineCommand.php     # baseline manager (Fase 2)
│   ├── Testing/
│   │   ├── Preset.php                       # enum Default | Full
│   │   ├── CodeguardConfig.php              # DTO principal
│   │   ├── StageConfig.php                  # DTO stage
│   │   ├── PrepareConfig.php                # DTO schema dump
│   │   ├── GateConfig.php                   # DTO gate
│   │   ├── TestSuiteRunner.php              # orquestrador
│   │   ├── TestRunResult.php                # resultado imutável
│   │   ├── TestStageResult.php              # resultado por stage
│   │   ├── CommandExecutor.php              # interface
│   │   ├── AsyncCommandExecutor.php         # interface
│   │   ├── ProcessCommandExecutor.php       # symfony/process impl
│   │   ├── RunningCommand.php               # interface
│   │   └── ProcessRunningCommand.php        # impl
│   ├── Assertions/
│   │   ├── TestQualityAssertions.php        # Trait (PHPUnit compat)
│   │   ├── ParallelSafetyAssertions.php     # Trait
│   │   ├── PestExpectations.php             # Pest registration
│   │   └── QualityExpectation.php           # fluent API
│   ├── Install/
│   │   ├── EnvironmentDetector.php          # PHP/Composer/Node detect
│   │   ├── PresetSelector.php               # auto-detect + prompt
│   │   ├── StubPublisher.php                # idempotent stub publish
│   │   ├── DeptracLayerSuggester.php        # namespace scan + heurística
│   │   ├── LefthookInstaller.php            # binary check + config install
│   │   └── NextStepsReporter.php            # post-install report
│   ├── Patterns/
│   │   ├── PatternLoader.php                # symfony/yaml
│   │   ├── PatternValidator.php             # schema validation
│   │   ├── PatternAnalyzer.php              # contexto para LLM
│   │   ├── AnalysisContext.php              # DTO
│   │   ├── BaselineManager.php              # hash match, filter
│   │   └── Finding.php                      # DTO
│   ├── AiRules/
│   │   ├── RulesGenerator.php               # orchestrator multi-tool
│   │   ├── ClaudeFormatter.php              # paths: frontmatter
│   │   ├── CursorFormatter.php              # globs: MDC
│   │   ├── AgentsMdFormatter.php            # universal AGENTS.md
│   │   └── CopilotFormatter.php             # .github/copilot-instructions.md
│   └── Schema/
│       ├── SchemaDumperInterface.php
│       ├── NativeDriver.php                 # delega php artisan schema:dump
│       ├── SqlitePdoDriver.php              # :memory: via sqlite_master
│       └── SqlServerFallbackDriver.php      # prod sqlsrv → test sqlite
├── config/
│   └── codeguard.php
├── resources/
│   ├── patterns/                            # 28 YAMLs
│   │   ├── core/*.yaml                      # 13
│   │   ├── php/*.yaml                       # 6
│   │   └── php-laravel/*.yaml               # 9
│   ├── rules/                               # canonical markdown
│   │   ├── php-quality.md
│   │   ├── php-testing.md
│   │   ├── laravel-services.md
│   │   ├── laravel-models.md
│   │   ├── laravel-security.md
│   │   ├── quality-gates.md
│   │   └── parallel-tests.md
│   ├── skills/
│   │   ├── codeguard-setup/SKILL.md
│   │   ├── codeguard-run/SKILL.md
│   │   └── codeguard-health/SKILL.md
│   └── stubs/
│       ├── phpstan.neon.stub
│       ├── pint.json.stub
│       ├── deptrac.yaml.stub
│       ├── infection.json5.stub
│       ├── lefthook.yml.stub
│       ├── .jscpd.json.stub
│       └── tests/Arch/TestQualityTest.php.stub
├── tests/
│   ├── Unit/
│   ├── Feature/
│   └── fixtures/
├── composer.json
├── LICENSE (MIT)
├── README.md
└── CHANGELOG.md
```

### Presets — 2 Opções com Auto-Detection

Dois presets binários (sem "progressão forçada" estilo Minimal/Standard/Full). A decisão real do usuário é: *"meu projeto tem Node?"*.

| Preset | Tools | Requer Node? | Auto-select quando |
|--------|-------|:---:|-------------------|
| **`codeguard`** (default) | Pint + PHPStan + Deptrac + Infection + Lefthook | ❌ | Sem `package.json` e sem `node_modules/` |
| **`codeguard-full`** | + jscpd + Insights + TestQualityTest | ✅ | `package.json` OU `node_modules/` presente |

**Auto-detection** no `codeguard:install`:

```
1. Existe node_modules/ OU package.json em base_path()?
   → HIGH CONFIDENCE: projeto usa Node ativamente → codeguard-full
2. Existe binário `node` globalmente (which node)?
   → MEDIUM CONFIDENCE: tem Node mas projeto não usa → codeguard (+ hint)
3. Nenhum dos dois
   → LOW CONFIDENCE: ambiente PHP puro → codeguard
```

**Override flags**:

```bash
php artisan codeguard:install                    # auto-detect
php artisan codeguard:install --preset=full      # force full
php artisan codeguard:install --preset=default   # force PHP-only
php artisan codeguard:install --no-interactive   # CI mode, use detection
php artisan codeguard:install --refresh-stubs    # update stubs, preserve custom edits
```

### Install Híbrido — 3 Camadas

**Camada 1 — Stubs inteligentes** (7/8 gates):
- Comentários inline explicando cada opção (o dev aprende lendo o arquivo)
- Auto-preenchimento via inspeção de `composer.json` PSR-4 → Infection `srcDir`
- Defaults calibrados (PHPStan level 5, Infection min-msi 60, Lefthook parallel=true)

**Camada 2 — Guided setup para Deptrac** (único gate que não funciona sem input):
- Scan de `app/*` subdirectories via `symfony/finder`
- Pattern matching heurístico:
  - Nome contém "Domain" → layer `Domain`
  - Nome contém "Services" → layer `Application`
  - Nome contém "Http|Controllers" → layer `Application`
  - Nome contém "Models|Infrastructure|Repositories" → layer `Persistence`
- Propor YAML resultante ao usuário com 3 opções: `[Y]` usar, `[E]` editar em `$EDITOR`, `[S]` skip (gera depfile.yaml vazio)

**Camada 3 — Post-install next-steps report**:
- Lista cada gate instalado
- Próxima ação concreta por gate (ex: "Deptrac → verify layers in depfile.yaml match your architecture")
- Link para docs relevantes

Exemplo de output do `codeguard:install`:

```
Detecting environment...
  ✓ PHP 8.3.12
  ✓ Composer 2.7.0
  ✓ Node.js 20.10.0 (found)
  ✓ package.json detected

Recommended preset: codeguard-full  ⭐

? Install codeguard-full (8 quality gates)? [Y/n]

=== Gates to install ===
  ✓ Pint        auto-format         config: 0       CI: ~5s
  ✓ PHPStan     type safety          config: 15min   CI: ~30s
  ✓ Deptrac     architecture         config: 30min   CI: ~15s
  ✓ Infection   test quality         config: 20min   CI: +3min
  ✓ Lefthook    pre-commit enforce   config: 10min   CI: 0
  ✓ jscpd       duplication          config: 5min    CI: +10s
  ✓ Insights    metrics              config: 0       CI: +20s
  ✓ TestQualityTest  meta-quality    config: 15min   CI: +5s

Estimated total config: ~1h 45min

Deptrac layer detection:
  I scanned your app/ directory and found:
    • app/Domain/*              (34 files)
    • app/Http/Controllers/*    (12 files)
    • app/Services/*            (8 files)
    • app/Models/*              (15 files)
    • app/Infrastructure/*      (4 files)

? Use this suggested layer structure?
    Layer: Domain          ← app/Domain/**
    Layer: Application     ← app/Services/**, app/Http/**
    Layer: Persistence     ← app/Models/**, app/Infrastructure/**
    Rules:
      Domain cannot depend on Application, Persistence
      Application cannot depend on Persistence (except via contracts)

  [Y] Use suggestion    [E] Edit in $EDITOR    [S] Skip

Publishing stubs...
  ✓ pint.json (exists, kept — use --refresh-stubs to update)
  ✓ phpstan.neon
  ✓ deptrac.yaml
  ✓ infection.json5
  ✓ lefthook.yml
  ✓ .jscpd.json
  ✓ tests/Arch/TestQualityTest.php

Lefthook post-install:
  ✓ lefthook install (.git/hooks/ registered)

Next steps:
  1. PHPStan    → review level in phpstan.neon (currently 5)
                  Run: composer codeguard:check
  2. Deptrac    → verify layers in depfile.yaml
                  Run: vendor/bin/deptrac analyse
  3. Infection  → generate baseline:
                  Run: vendor/bin/infection --initial-tests-only
  4. Lefthook   → test hook: git commit --allow-empty -m test
  5. jscpd      → review threshold in .jscpd.json

Documentation: https://github.com/henryavila/codeguard#configuration
```

### Artisan Commands

| Comando | Função | MVP? |
|---------|--------|:---:|
| `codeguard:install` | Wizard híbrido: env detect + preset + stubs + Deptrac guided + Lefthook + report | ✅ |
| `codeguard:check` | Gates sequenciais fail-fast, auto-detect tool presence | ✅ |
| `codeguard:test` | Multi-stage test runner com consolidated report | ✅ |
| `codeguard:prepare` | Schema dump com hash cache (multi-driver) | ✅ |
| `codeguard:analyze` | Pattern engine (LLM adjudicator) sobre code/diff | Fase 2 |
| `codeguard:baseline` | Gerar/atualizar baseline de findings | Fase 2 |

### Config (`config/codeguard.php`)

```php
return [
    'mode' => env('CODEGUARD_MODE', 'default'),  // default | ci | dev | debug

    'preset' => env('CODEGUARD_PRESET', 'codeguard'),

    'gates' => [
        'audit'     => ['enabled' => true,  'command' => 'composer audit --format=plain',                        'description' => 'Composer security audit'],
        'pint'      => ['enabled' => true,  'command' => './vendor/bin/pint --test',                             'description' => 'Laravel Pint (code style check)'],
        'phpstan'   => ['enabled' => true,  'command' => './vendor/bin/phpstan analyse --no-progress',           'description' => 'PHPStan static analysis'],
        'deptrac'   => ['enabled' => true,  'command' => './vendor/bin/deptrac analyse --no-progress',           'description' => 'Deptrac architecture boundaries'],
        'infection' => ['enabled' => true,  'command' => './vendor/bin/infection --min-msi=60 --min-covered-msi=70 --show-mutations=false', 'description' => 'Infection mutation testing'],
        'jscpd'     => ['enabled' => false, 'command' => 'npx jscpd --reporters console --threshold 3',          'description' => 'Code duplication detection (requires Node.js)'],
        'insights'  => ['enabled' => false, 'command' => 'php artisan insights --no-interaction --summary',      'description' => 'PHP Insights metrics'],
    ],

    'stages' => [
        'unit'    => ['enabled' => true, 'command' => './vendor/bin/pest --testsuite=Unit',    'env' => [], 'report_format' => 'junit'],
        'feature' => ['enabled' => true, 'command' => './vendor/bin/pest --testsuite=Feature', 'env' => [], 'report_format' => 'junit'],
    ],

    'report_dir' => storage_path('framework/testing/test-reports'),

    'prepare' => [
        'connection'      => env('CODEGUARD_PREPARE_CONNECTION', env('DB_CONNECTION', 'sqlite')),
        'dump_path'       => database_path('schema/dump.sql'),
        'hash_path'       => database_path('schema/.dump-hash'),
        'migrations_path' => database_path('migrations'),
    ],

    'protected_configs' => [
        'phpstan.neon', 'phpstan-baseline.neon',
        'pint.json', 'deptrac.yaml', 'deptrac-baseline.yaml',
        'psalm.xml', 'infection.json5', 'phpunit.xml',
        '.jscpd.json', 'lefthook.yml',
    ],

    'patterns' => [
        'enabled_presets' => ['core', 'php', 'php-laravel'],
        'custom_paths'    => [],  // auto-discovers base_path('.codeguard/patterns') + this list
        'baseline_path'   => base_path('.codeguard/baseline.json'),
    ],

    'ai_rules' => [
        'targets' => [
            'claude'    => true,   // .claude/rules/*.md + CLAUDE.md
            'cursor'    => true,   // .cursor/rules/*.mdc
            'copilot'   => true,   // .github/copilot-instructions.md
            'agents_md' => true,   // AGENTS.md (universal)
        ],
    ],
];
```

### Dependencies

```json
{
    "require": {
        "php": "^8.3",
        "illuminate/console": "^11.0|^12.0",
        "illuminate/support": "^11.0|^12.0",
        "illuminate/filesystem": "^11.0|^12.0",
        "laravel/prompts": "^0.1.15|^0.3",
        "symfony/process": "^7.0",
        "symfony/yaml": "^7.0",
        "symfony/finder": "^7.0",
        "sebastian/diff": "^5.1|^6.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0|^10.0",
        "pestphp/pest": "^3.0|^4.0",
        "pestphp/pest-plugin-laravel": "^3.0|^4.0",
        "laravel/pint": "^1.15",
        "phpstan/phpstan": "^1.11"
    }
}
```

**Rationale de versões**:
- PHP 8.3+ obrigatório para `readonly` classes, typed enums, match expressions idiomatic
- Laravel 11|12 para suportar ambos sem lock
- `laravel/prompts` para UI interativa moderna (select, confirm, text)
- `sebastian/diff` para mostrar diffs ao usuário em `--refresh-stubs`

### ServiceProvider Wiring

```php
final class CodeguardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/codeguard.php', 'codeguard');

        $this->app->singleton(CodeguardConfig::class, fn ($app) =>
            CodeguardConfig::fromArray($app['config']->get('codeguard', []))
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->bootConsole();
        }
    }

    private function bootConsole(): void
    {
        $this->commands([
            CodeguardInstallCommand::class,
            // CodeguardCheckCommand::class (Fase 2)
            // CodeguardTestCommand::class (Fase 2)
            // CodeguardPrepareCommand::class (Fase 2)
        ]);

        $this->publishes([
            __DIR__.'/../config/codeguard.php' => config_path('codeguard.php'),
        ], 'codeguard-config');

        // Stub publish tags (usados por CodeguardInstallCommand, não vendor:publish direto)
        $this->publishes([...], 'codeguard-stubs-default');   // pint + phpstan
        $this->publishes([...], 'codeguard-stubs-advanced');  // deptrac + infection + lefthook
        $this->publishes([...], 'codeguard-stubs-full');      // jscpd + TestQualityTest
        $this->publishes([...], 'codeguard-rules');           // resources/rules
        $this->publishes([...], 'codeguard-patterns');        // resources/patterns
    }
}
```

### DTOs — Readonly Classes + Named Arguments

Todos os DTOs são `final readonly` com `fromArray()` factory. Padrão Laravel 11+ moderno, type-safe, imutável.

```php
namespace Henryavila\Codeguard\Testing;

enum Preset: string
{
    case Default = 'codeguard';
    case Full = 'codeguard-full';

    public function requiresNode(): bool { return $this === self::Full; }
    public function label(): string { /* ... */ }
    public function enabledGateKeys(): array { /* ... */ }
}

final readonly class GateConfig
{
    public function __construct(
        public string $key,
        public bool $enabled,
        public string $command,
        public string $description,
    ) {}
    public static function fromArray(string $key, array $data): self { /* ... */ }
}

final readonly class StageConfig
{
    /** @param array<string, string> $env */
    public function __construct(
        public string $key,
        public bool $enabled,
        public string $command,
        public array $env,
        public string $reportFormat,
    ) {}
    public static function fromArray(string $key, array $data): self { /* ... */ }
}

final readonly class PrepareConfig
{
    public function __construct(
        public string $connection,
        public string $dumpPath,
        public string $hashPath,
        public string $migrationsPath,
    ) {}
    public static function fromArray(array $data): self { /* ... */ }
}

final readonly class CodeguardConfig
{
    /**
     * @param array<string, GateConfig> $gates
     * @param array<string, StageConfig> $stages
     * @param list<string> $protectedConfigs
     * @param list<string> $enabledPresets
     * @param list<string> $customPatternPaths
     * @param array<string, bool> $aiRulesTargets
     */
    public function __construct(
        public string $mode,
        public Preset $preset,
        public array $gates,
        public array $stages,
        public string $reportDir,
        public PrepareConfig $prepare,
        public array $protectedConfigs,
        public array $enabledPresets,
        public array $customPatternPaths,
        public string $baselinePath,
        public array $aiRulesTargets,
    ) {}
    public static function fromArray(array $config): self { /* ... */ }

    /** @return list<GateConfig> */
    public function enabledGates(): array { /* filter enabled */ }

    /** @return list<StageConfig> */
    public function enabledStages(): array { /* filter enabled */ }
}
```

### Assertions — TestQualityAssertions + ParallelSafetyAssertions

Dual expression: Traits (PHPUnit compat) + Pest expectations (idiomatic).

**TestQualityAssertions (3 checks)**:
- `assertNoTautologicalAssertions()` / `expect()->quality()->noTautologicalAssertions()` — detecta `assertTrue(true)`, `assertEquals(1, 1)`, etc.
- `assertNoEloquentModelMocking()` / `expect()->quality()->noEloquentModelMocking()` — detecta `Mockery::mock(App\Models\*::class)`
- `assertNoBareAssertNotNull()` / `expect()->quality()->noBareAssertNotNull()` — detecta `assertNotNull($x)` sem verificar conteúdo depois

**ParallelSafetyAssertions (4 checks)**:
- `assertNoTruncateInTests(allowlist)`
- `assertNoForceDeleteInTests(allowlist)`
- `assertNoDbQueriesInFactoryDefinition(allowlist)`
- `assertNoEagerCreateInFactoryDefinition(allowlist)`

Pest registration condicional (Pest pode não estar instalado):

```php
// CodeguardServiceProvider::boot()
if (class_exists(\Pest\Expectation::class) && app()->runningUnitTests()) {
    PestExpectations::register();
}
```

### Schema Dump Multi-Driver (Killer Feature)

Laravel `schema:dump` tem limitações documentadas:

| Driver | Laravel nativo | CodeGuard fallback |
|--------|:-:|:-:|
| MySQL / MariaDB / PostgreSQL | ✅ (mysqldump / pg_dump) | delega ao nativo |
| SQLite (file) | ⚠️ bug #52131 (inclui `sqlite_stat*` tables) | delega + filtra `sqlite_%` |
| **SQLite `:memory:`** | ❌ (só load, não dump — `SqliteSchemaState.php:65-72`) | ✅ PDO + `sqlite_master` export |
| **SQL Server (sqlsrv)** | ❌ `throw new RuntimeException('Schema dumping is not supported')` | ✅ PDO export para connection secundário SQLite |
| Windows sem `sqlite3` CLI | ❌ issue #35162 | ✅ PDO não precisa binário |
| MongoDB | N/A (sem Schema grammar) | stage separado no runner |

**Target de projetos** (que o nativo não atende):
- Prod sqlsrv + tests sqlite (caso Arch)
- Tests em SQLite `:memory:` com `LazilyRefreshDatabase`
- Containers Alpine / Windows sem `sqlite3` CLI
- Multi-path migrations

**Benchmark** (Arch, 235 migrations × 20 ParaTest workers): ~4.700 DDL statements evitados por `composer test`.

**Guard contra produção**: recusa se `APP_ENV=production` ou `DB_HOST` não é localhost.

### Pattern Engine (Fase 2)

```
php artisan codeguard:analyze --scope=diff:main
           ↓
    PatternLoader reads resources/patterns/*.yaml
           ↓
    PatternValidator (schema check)
           ↓
    PatternAnalyzer builds AnalysisContext
           ↓
    Output JSON {patterns: [...], context: {...}}
           ↓
    Claude skill codeguard-run reads JSON
           ↓
    LLM applies verification.rules to code
           ↓
    Findings reported (filtered by BaselineManager)
```

**Positioning**: pattern system NÃO é "static analyzer". É **structured prompt distribution + LLM adjudicator onde AST não alcança**. Dos 28 patterns: 12 AST-replaceable (delegar para phpat/PHPMD), 13 hybrid, 3 pure semantic.

**Tagline**: *"AI review where AST can't reach."*

### AI Rules Multi-Tool

Canonical source em `resources/rules/`. Generator gera formato nativo para cada tool:

| Tool | Formato | Path | Path trigger |
|------|---------|------|-------------|
| Claude Code | Markdown + `paths:` frontmatter | `.claude/rules/*.md` + `CLAUDE.md` | YAML `paths:` |
| Cursor | MDC + `globs:` frontmatter | `.cursor/rules/*.mdc` | YAML `globs:` |
| Copilot | Plain markdown (append) | `.github/copilot-instructions.md` | Nenhum |
| AGENTS.md | Concatenated markdown | `AGENTS.md` | Nenhum |

7 rules canônicas:

| Rule | Scope | Conteúdo |
|------|-------|----------|
| `php-quality` | `app/**/*.php` | strict_types, sprintf, null checks, validated() |
| `php-testing` | `tests/**`, `database/factories/**` | Pest syntax, no tautological assertions, no model mock |
| `laravel-services` | `app/Services/**` | SRP, DI constructor, value objects |
| `laravel-models` | `app/Models/**` | Data + queries only, no service imports |
| `laravel-security` | `app/Http/**`, `routes/**` | validated(), no raw SQL, CSRF, mass assignment |
| `quality-gates` | `phpstan.neon`, `pint.json`, `deptrac.yaml` | HARD: never weaken configs |
| `parallel-tests` | `tests/**` | function_exists, no global state, factory isolation |

---

## Pacote 2: henryavila/codeguard-hooks (Claude Plugin)

**Positioning**: *Best-Effort Nudges — CI is the real gate*. Repo separado (não subpasta) porque ciclo de vida é diferente (distribui via `/plugin install`, não Composer).

### Limitações Reconhecidas (Claude Code)

Hooks do Claude Code têm limitações arquiteturais documentadas em issues oficiais:

| # | Limitação | Issue | Mitigação CodeGuard |
|---|-----------|-------|---------------------|
| L1 | `Bash(sed/awk/tee/>)` contorna `Edit\|Write` matcher | #6876, #29709 | Adicionar matcher `Bash` com regex de file-mutating commands |
| L2 | `git commit --no-verify` / `HUSKY=0` burla pre-commit | #40117 (Opus 4.6 fez isso em 6 commits seguidos) | Bash matcher detecta `--no-verify` |
| L3 | Task tool spawna subagents — **não herdam hooks** | #27661 OPEN | Documentar honestamente |
| L4 | MCP tools (`mcp__*__write_file`) contornam matcher | config | Adicionar `mcp__.*` ao matcher |
| L5 | PostToolUse exit code ignorado em Write/Edit | #13744 | Documentar: PostToolUse é warn-only |
| L6 | Hooks rodam com user permissions — `chmod -x` desabilita | — | Limitação inerente |

**Framing honesto**: *"Best-Effort Nudges for honest mistakes — CI is the real gate. Never merge on hook success alone."*

### Estrutura

```
codeguard-hooks/
├── .claude-plugin/
│   └── plugin.json
├── hooks/
│   ├── hooks.json                      # matchers: Edit|Write|Bash|mcp__.*
│   ├── config-protection.sh            # block edits em phpstan.neon etc.
│   ├── config-protection-bash.sh       # regex Bash para sed/awk/tee/git --no-verify
│   ├── pre-commit-phpstan.sh           # intercepta git commit
│   ├── stop-verify-tests.sh            # git tree-hash sentinel
│   └── post-php-lint.sh                # warn-only (PostToolUse)
├── README.md                            # honest limitations documented
└── LICENSE
```

### Hook Design — Matchers Expandidos

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Edit|Write|mcp__.*__write_file|mcp__.*__str_replace",
        "hooks": [{"command": "config-protection.sh"}]
      },
      {
        "matcher": "Bash",
        "hooks": [{"command": "config-protection-bash.sh"}],
        "description": "Block sed/awk/tee/>/git --no-verify on protected configs"
      }
    ],
    "Stop": [
      {"command": "stop-verify-tests.sh"}
    ],
    "PostToolUse": [
      {
        "matcher": "Edit|Write",
        "hooks": [{"command": "post-php-lint.sh"}],
        "description": "Warn (not block) on PHP lint/Pint failures"
      }
    ]
  }
}
```

### Stop Hook — Sentinel Upgrade

Empty-file sentinel é trivialmente spoofável (`touch .quality-verified`). CodeGuard usa **git tree-hash sentinel**:

```bash
EXPECTED=$(git rev-parse HEAD:)
ACTUAL=$(cat .quality-verified 2>/dev/null)
[[ "$EXPECTED" != "$ACTUAL" ]] && exit 2
```

`codeguard:check` escreve `git rev-parse HEAD:` no sentinel ao passar. Touch vazio não passa porque não é hash válido do tree atual.

### Segurança Básica

- JSON parsing: `jq` primário, `python3` fallback
- Newline stripping: `tr -d '\n'` após extração
- Config-protection: `realpath` + case-insensitive comparison
- Temp files: `trap EXIT` + `chmod 600`

---

## Dual-Track Development (ADR-007)

Arch (`/home/henry/arch`) é **primeiro consumidor real**, via `composer path repository` (symlink) durante desenvolvimento.

```json
// ~/arch/composer.json
"repositories": [
    {"type": "path", "url": "/home/henry/codeguard", "options": {"symlink": true}}
],
"require-dev": {
    "henryavila/codeguard": "@dev"
}
```

**Benefícios**:
- Arch valida package em uso real desde dia 1 (dogfooding)
- Namespaces no Arch já espelham package (`App\Services\Testing\*` → `Henryavila\Codeguard\Testing\*`) para find/replace único quando migrar
- Ciclo feedback apertado: edit no package → symlink propaga → Arch rebuild → problemas aparecem hoje, não semana que vem

**Limitações** (documentadas):
- Symlink quebra em Windows nativo (Henry usa WSL — fine)
- `composer update` no Arch pode pegar breaking changes — Henry controla ambos, risco baixo

## Extração do Arch — Delta

### Extrair diretamente (sem mudança semântica)

| Classe Arch | Classe Package | Mudanças |
|-------------|---------------|----------|
| `TestSuiteRunner` | `Testing\TestSuiteRunner` | Remover `stages()` hardcoded → receber `StageConfig[]` via `CodeguardConfig` |
| `TestRunResult` | `Testing\TestRunResult` | Nenhuma (já imutável) |
| `TestStageResult` | `Testing\TestStageResult` | Nenhuma |
| `CommandExecutor` | `Testing\CommandExecutor` | Nenhuma (interface) |
| `AsyncCommandExecutor` | `Testing\AsyncCommandExecutor` | Nenhuma (interface) |
| `ProcessCommandExecutor` | `Testing\ProcessCommandExecutor` | Nenhuma |
| `RunningCommand` | `Testing\RunningCommand` | Nenhuma (interface) |
| `ProcessRunningCommand` | `Testing\ProcessRunningCommand` | Nenhuma |
| `RunTestsCommand` | `Commands\CodeguardTestCommand` | Renomear, remover refs Arch-specific (Playwright cleanup) |

### Criar do zero (não existe no Arch)

| Classe | Razão |
|--------|-------|
| `Preset` (enum) | Nova categorização de presets |
| `StageConfig` | Stages hardcoded no Arch → virar DTO |
| `PrepareConfig` | Schema dump config disperso |
| `GateConfig` | Gates hardcoded no Arch → virar DTO |
| `CodeguardConfig` | Unifica tudo num DTO injetável |
| `CodeguardServiceProvider` | Wiring, merge config, publishes |
| `CodeguardInstallCommand` | Wizard híbrido — não existe no Arch |
| `CodeguardCheckCommand` | Gates — no Arch são scripts Composer |
| `CodeguardPrepareCommand` | Schema dump — no Arch inline |
| `Install\*` (6 classes) | Install orchestration — não existe no Arch |
| `Assertions\TestQualityAssertions` | Anti-pattern checks — no Arch são testes avulsos |
| `Assertions\ParallelSafetyAssertions` | Parallel-safety — mesma situação |
| `Assertions\PestExpectations` + `QualityExpectation` | Fluent Pest API — não existe |

---

## Fluxo de Instalação (Novo User)

```bash
# 1. Require dev
composer require --dev henryavila/codeguard

# 2. Guided install (auto-detects Node, selects preset)
php artisan codeguard:install
# → publishes stubs, suggests Deptrac layers, installs Lefthook hooks, prints next-steps

# 3. Verify
composer codeguard:check

# 4. Optional: Claude enforcement plugin
/plugin install henryavila/codeguard-hooks
```

---

## Roadmap

| Fase | Entregável | Depende de |
|------|-----------|------------|
| **1 (feita)** | composer.json + config + DTOs + ServiceProvider (foundation) | — |
| **2** | `CodeguardInstallCommand` com hybrid flow completo | Fase 1 |
| **3** | Stubs para 8 gates + initial Pest tests | Fase 2 |
| **4** | README final + primeiro release alpha (`1.0.0-alpha.1`) | Fase 3 |
| **5** | Extract `TestSuiteRunner` do Arch + `CodeguardTestCommand` | Fase 2 |
| **6** | Assertions (TestQualityAssertions + ParallelSafetyAssertions) | Fase 5 |
| **7** | Schema dump multi-DB + `CodeguardPrepareCommand` | Fase 5 |
| **8** | Pattern engine + `CodeguardAnalyzeCommand` | Fase 7 |
| **9** | AI rules generator (multi-tool) | Fase 8 |
| **10** | Claude plugin (`codeguard-hooks` repo separado) | Fase 9 |
| **11** | Arch migra do inline para package `@dev` | Fase 5 |
| **12** | Segundo projeto Henry adota → `1.0.0-beta.1` → `1.0.0` | Fase 11 |

**Timeline AI-assisted**: ~1.5–2.5 semanas calendar (ADR-008).

---

## Diferenciadores Reais (pós-steelman)

| # | Claim | Verdict | Evidência |
|---|-------|---------|-----------|
| 1 | Pattern YAML + LLM adjudicator | **SOBREVIVE** (reposicionado) | 16/28 patterns codificam judgment não-AST |
| 2 | Multi-stage test orchestration | **SOBREVIVE** | Arch escreveu 770 LOC porque nenhuma OSS cobre multi-stage heterogêneo (Vitest + Pest + Browser + Mongo) |
| 3 | Test anti-pattern kit (traits + Pest) | **SOBREVIVE** | 7 checks packaged como kit |
| 4 | AI rules multi-tool generator | **SOBREVIVE** (janela 6 meses) | AGENTS.md emergindo mas sem path-triggering em 2026 |
| 5 | AI-time config-protection | **SOBREVIVE FORTE** | Genuinamente único no mercado |
| 6 | Schema dump multi-DB | **SOBREVIVE FORTE** | Laravel nativo falha em sqlsrv/`:memory:`/Windows |
| 7 | Guided hybrid install | **NOVO** | Deptrac layer suggestion + idempotent stubs + auto-detect Node |
| 8 | Lefthook integration out-of-box | **NOVO** | Parallel execution, zero runtime, superior a Husky |

**Tagline honesta**: *"Laravel quality gates that survive your AI agent — consolidated install, honest hybrid setup, and AI review where AST can't reach."*

---

## Riscos

| Risco | Mitigação |
|-------|-----------|
| Symlink path repo quebra | Fallback `"type": "vcs"` + branch local |
| Laravel 11 vs 12 compatibility em `laravel/prompts` | CI matrix PHP 8.3/8.4 × Laravel 11/12 |
| Usuário sem Node tenta preset Full | Auto-detect bloqueia seleção inválida + clear error message |
| Deptrac layer suggestion erra | Fallback: skip option sempre disponível, user pode editar depois |
| Lefthook binário não instalado no ambiente | Install command detecta e oferece `brew/apt/composer install lefthook` |
| Pest 3 vs 4 breaking changes | Testar ambos em CI; declarar `^3.0|^4.0` só se ambos funcionam |
| Claude plugin audiência limitada | AI rules universais no Composer compensam |

---

## Apêndice Histórico — Round 2 Reviews (6 adversarial agents)

Round 2 produziu veredicto agregado **3.5/10** com **prompt-induced bias 7/10**. Gaps metodológicos identificados no Round 3:

| # | Review | Gap |
|---|--------|-----|
| 1 | AI Rules Efficacy | Strawman — atacou "rules como enforcement determinístico" (design nunca assumiu isso) |
| 2 | Enforcement Depth | False equivalence com GrumPHP (GrumPHP não faz multi-stage heterogêneo) |
| 3 | Pattern System | Atacou amostra pequena (pattern mais fraco) e generalizou |
| 4 | Adoption Friction | Persona errada (42% zero-tools — target são os 36% que já usam Pint+PHPStan) |
| 5 | Security Bypass | Bypasses são limitações do Claude Code, não do CodeGuard |
| 6 | Competitive | Claims factualmente errados (schema dump "30 LOC" → real 182 LOC) |

**Claims factualmente errados**:
1. "Schema dump = 30 linhas" → Real: 182 linhas resolvendo gaps Laravel
2. "GrumPHP faz 80%" → GrumPHP não faz multi-stage heterogêneo
3. "28 patterns = fraude" → 16/28 codificam judgment não-AST
4. "Copilot 4k limit" → Design gera 7 arquivos separados

Ver análise completa em `.ai/memory/reviews-consolidated.md`.

## Apêndice Histórico — Round 3 Steelman (4 agents)

Score agregado revisado:

| Componente | R2 (adversarial) | R3 (steelman) | Delta |
|------------|:---:|:---:|:---:|
| Composer package | 3-4/10 | **7/10** | +3 |
| Pattern system (repositioned) | "snake oil" | 16/28 válidos | invertido |
| Schema dump multi-DB | "WEAK 30 LOC" | **killer feature** | correção factual |
| Test orchestration | "90% GrumPHP" | nenhuma OSS >40% | correção factual |
| **Agregado** | **3.5/10** | **6.0/10** | **+2.5** |

**Strengths que nenhum adversarial identificou**:
1. Compositional layering (cada pacote testável isolado)
2. Multi-stage heterogeneous report parsing (Vitest + JUnit)
3. `CODEGUARD_MODE` env override (defensive engineering)
4. Constructor DTO injection (testável sem framework)
5. Dual expression (Trait + Pest) — acomoda legacy E idiomatic

## Apêndice Histórico — v4 Pre-Preset-Redesign

v4 propunha **3 packages** (npm agnostic + Composer + Claude plugin) com **3 presets** (Minimal/Standard/Full) e **Husky**. Foi superseded pelas decisões desta sessão:

- 3 packages → 2 packages (ADR-002)
- Agnostic core → Laravel-only (ADR-001)
- Minimal/Standard/Full → codeguard/codeguard-full com auto-detect (ADR-006)
- Husky → Lefthook (Q7b)
- Stub dump → Install híbrido (smart stubs + guided Deptrac)

Jornada documentada em `.ai/memory/preset-design-evolution.md`.

---

## Decisões Resolvidas (não reabrir sem motivo forte)

- **Stack**: PHP 8.3+ / Laravel 11|12 / Pest 3|4 / Composer-only
- **2 packages**: `henryavila/codeguard` + `henryavila/codeguard-hooks`
- **Namespace**: `Henryavila\Codeguard\*`
- **Commands**: `codeguard:*` (install, check, test, prepare, analyze, baseline)
- **Default preset**: `codeguard` (PHP-native: Pint + PHPStan + Deptrac + Infection + Lefthook)
- **Full preset**: `codeguard-full` (adds jscpd + Insights + TestQualityTest, requires Node)
- **Auto-detect**: Node presence pre-selects preset
- **Install**: hybrid (smart stubs + guided Deptrac + idempotent re-run + post-install report)
- **Pre-commit**: Lefthook (não Husky)
- **Pattern engine**: PHP nativo com `symfony/yaml` (não Node)
- **Hooks plugin**: best-effort nudges (CI é o gate real)
- **Timeline**: ~1.5–2.5 semanas calendar AI-assisted

## Decisões Pendentes

Ver `.ai/memory/open-questions.md`. Nenhuma bloqueia Bloco 1. Q2 (skill distribution) exige pesquisa ampla antes de Fase 9.
