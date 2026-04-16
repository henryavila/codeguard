---
name: CodeGuard v2 Design Decisions (Consolidated)
description: Decisões consolidadas do design após 10 reviews + pivot Node→PHP
type: project
---

# CodeGuard v2 — Design Decisions (PHP-Only)

## Stack Alvo
- **PHP**: ^8.3
- **Laravel**: ^11.0 | ^12.0
- **Pest**: ^3.0 | ^4.0 (dev)
- **Symfony components**: ^7.0 (console, process, yaml, finder)

## Package Structure

```
henryavila/codeguard/                     # Composer
├── src/
│   ├── CodeguardServiceProvider.php
│   ├── Commands/
│   │   ├── CodeguardInstallCommand.php   # codeguard:install (wizard)
│   │   ├── CodeguardCheckCommand.php     # codeguard:check
│   │   ├── CodeguardTestCommand.php      # codeguard:test
│   │   ├── CodeguardPrepareCommand.php   # codeguard:prepare
│   │   ├── CodeguardAnalyzeCommand.php   # codeguard:analyze (pattern engine)
│   │   └── CodeguardBaselineCommand.php  # codeguard:baseline
│   ├── Testing/
│   │   ├── TestSuiteRunner.php           # multi-stage orchestrator
│   │   ├── TestRunResult.php
│   │   ├── TestStageResult.php
│   │   ├── StageConfig.php               # DTO
│   │   ├── PrepareConfig.php             # DTO
│   │   ├── CodeguardConfig.php           # DTO principal
│   │   ├── CommandExecutor.php           # interface
│   │   ├── AsyncCommandExecutor.php      # interface
│   │   ├── ProcessCommandExecutor.php    # symfony/process impl
│   │   ├── RunningCommand.php            # interface
│   │   └── ProcessRunningCommand.php     # impl
│   ├── Assertions/
│   │   ├── TestQualityAssertions.php     # trait (PHPUnit compat)
│   │   ├── ParallelSafetyAssertions.php  # trait
│   │   ├── PestExpectations.php          # registration
│   │   └── QualityExpectation.php        # fluent API
│   ├── Patterns/
│   │   ├── PatternLoader.php             # symfony/yaml, lê resources/patterns/
│   │   ├── PatternValidator.php          # schema validation
│   │   ├── PatternAnalyzer.php           # executa pattern em AnalysisContext
│   │   ├── AnalysisContext.php           # DTO para LLM skill consumir
│   │   ├── BaselineManager.php           # hash match, filter findings
│   │   └── Finding.php                   # DTO resultado
│   ├── AiRules/
│   │   ├── RulesGenerator.php            # orchestrator multi-tool
│   │   ├── ClaudeFormatter.php           # paths: frontmatter
│   │   ├── CursorFormatter.php           # globs: MDC
│   │   ├── AgentsMdFormatter.php         # universal AGENTS.md
│   │   └── CopilotFormatter.php          # append .github/
│   └── Schema/
│       ├── SchemaDumperInterface.php
│       ├── NativeDriver.php              # delega php artisan schema:dump
│       ├── SqlitePdoDriver.php           # :memory: fallback via sqlite_master
│       └── SqlServerFallbackDriver.php   # pattern: prod sqlsrv → test sqlite
├── resources/                            # distribuído com o package
│   ├── patterns/                         # 28 YAMLs (data contract)
│   │   ├── core/*.yaml                   # 13 patterns
│   │   ├── php/*.yaml                    # 6 patterns
│   │   └── php-laravel/*.yaml            # 9 patterns
│   ├── rules/                            # canonical markdown
│   │   ├── php-quality.md
│   │   ├── php-testing.md
│   │   ├── laravel-services.md
│   │   ├── laravel-models.md
│   │   ├── laravel-security.md
│   │   ├── quality-gates.md
│   │   └── parallel-tests.md
│   ├── skills/                           # Claude skills
│   │   ├── codeguard-setup/SKILL.md
│   │   ├── codeguard-run/SKILL.md
│   │   └── codeguard-health/SKILL.md
│   └── stubs/                            # vendor:publish targets
│       ├── phpstan.neon.stub
│       ├── pint.json.stub
│       ├── deptrac.yaml.stub
│       ├── infection.json5.stub
│       ├── .jscpd.json.stub
│       ├── husky/
│       │   ├── pre-commit.stub
│       │   └── pre-push.stub
│       ├── github/workflows/
│       │   ├── tests.yml.stub
│       │   └── quality.yml.stub
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

## Commands (namespace `codeguard:*`)

| Comando | Função | MVP? |
|---------|--------|:----:|
| `codeguard:install` | Wizard: preset + stubs + AI rules + hooks | ✅ |
| `codeguard:check` | Roda todos quality gates (sequencial, fail-fast, auto-detect) | ✅ |
| `codeguard:test` | Multi-stage test runner com consolidated report | ✅ |
| `codeguard:prepare` | Schema dump com hash cache (multi-driver) | ✅ |
| `codeguard:analyze` | Pattern engine sobre code/diff (JSON output para skill) | Fase 2 |
| `codeguard:baseline` | Gerar/atualizar baseline findings | Fase 2 |

## Config Structure (`config/codeguard.php`)

```php
return [
    'stages' => [ /* StageConfig[] */ ],
    'gates' => [
        'audit'    => ['enabled' => true,  'command' => 'composer audit --format=plain'],
        'pint'     => ['enabled' => true,  'command' => './vendor/bin/pint --test'],
        'phpstan'  => ['enabled' => true,  'command' => './vendor/bin/phpstan analyse'],
        'deptrac'  => ['enabled' => false, 'command' => './vendor/bin/deptrac analyse'],
        'jscpd'    => ['enabled' => false, 'command' => 'npx jscpd app/'],
        'insights' => ['enabled' => false, 'command' => 'php artisan insights --summary'],
    ],
    'report_dir' => storage_path('framework/testing/test-reports'),
    'prepare' => [ /* PrepareConfig */ ],
    'protected_configs' => [
        'phpstan.neon', 'phpstan-baseline.neon',
        'pint.json', 'deptrac.yaml', 'deptrac-baseline.yaml',
        'psalm.xml', 'infection.json5', 'phpunit.xml',
        '.jscpd.json',
    ],
    'patterns' => [
        'enabled_presets' => ['core', 'php', 'php-laravel'],
        'custom_patterns_path' => base_path('.codeguard/patterns'),
    ],
];
```

## Presets

| Preset | Contém | Arquivos root | Default? |
|--------|--------|:---:|:---:|
| **Minimal** | Pint + PHPStan | 2 | ✅ |
| Standard | + Deptrac + Infection + Husky | 7 | |
| Full | + jscpd + Insights + TestQualityTest | 12 | |

## Assertions Library

**TestQualityAssertions** (3 checks):
- `assertNoTautologicalAssertions()` — detecta `assertTrue(true)`, `assertEquals(1, 1)`
- `assertNoEloquentModelMocking()` — detecta `Mockery::mock(App\Models\*::class)`
- `assertNoBareAssertNotNull()` — detecta `assertNotNull` solo sem verificar valor

**ParallelSafetyAssertions** (4 checks):
- `assertNoTruncateInTests(allowlist)`
- `assertNoForceDeleteInTests(allowlist)`
- `assertNoDbQueriesInFactoryDefinition(allowlist)`
- `assertNoEagerCreateInFactoryDefinition(allowlist)`

Dual expression:
- Trait: `uses(TestQualityAssertions::class); $this->assertNoTautologicalAssertions()`
- Pest: `expect()->quality()->noTautologicalAssertions()->noEloquentModelMocking()`

## Pattern Engine Flow

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

## Schema Dump Multi-DB Flow

```
codeguard:prepare
    ↓
Read CodeguardConfig.prepare
    ↓
  driver?
    ├─ MySQL/PostgreSQL → delega php artisan schema:dump (native)
    ├─ SQLite file → delega native + filter sqlite_% (bug #52131)
    ├─ SQLite :memory: → PDO sqlite_master export
    ├─ SQL Server → pattern: swap to sqlite for dump, document
    └─ MongoDB → N/A (stage separado)
    ↓
Hash migrations dir → compare with hash file
    ↓
If match: skip (use existing dump)
If mismatch: regenerate + update hash
```

## Claude Plugin Structure (separate repo/package)

```
henryavila/codeguard-hooks/
├── .claude-plugin/plugin.json
├── hooks/
│   ├── hooks.json                     # matchers: Edit|Write|Bash|mcp__.*
│   ├── config-protection.sh           # block edits phpstan.neon etc
│   ├── config-protection-bash.sh      # regex Bash para sed/awk/tee/git --no-verify
│   ├── pre-commit-phpstan.sh          # intercepta git commit
│   ├── stop-verify-tests.sh           # tree-hash sentinel
│   └── post-php-lint.sh               # warn-only
├── README.md                          # honest limitations documented
└── LICENSE
```

## Anti-Patterns Reconhecidos (NÃO fazer)

1. ❌ Reintroduzir Node.js para qualquer coisa
2. ❌ Reescrever de novo o pattern engine em outra linguagem
3. ❌ Forçar usuário a usar Husky (sugerir Lefthook como alternativa)
4. ❌ Publicar arquivos sem `confirm()` em `codeguard:install` se já existem
5. ❌ Default preset Standard/Full — manter Minimal
6. ❌ Claim "hard enforcement" em hooks — usar "best-effort nudges"
7. ❌ Implementar 3 packages split — 2 é suficiente
8. ❌ Usar `config()` helper em services — usar constructor injection de DTO

## Padrão de Nomes

- Classes PHP: `Henryavila\Codeguard\*`
- Composer package: `henryavila/codeguard`
- Claude plugin: `henryavila/codeguard-hooks`
- Config key: `codeguard` (singular)
- Commands: `codeguard:*` (namespace único)
- Env vars: `CODEGUARD_*` (ex: `CODEGUARD_MODE`, `CODEGUARD_PREPARE_CONNECTION`)
