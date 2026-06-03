# CodeGuard Patterns Engine — Design (`codeguard:analyze`)

**Status:** Proposed · **Date:** 2026-06-03 · **Author:** synthesis of 3 design tracks + 3 judge reviews (workflow `patterns-engine-design`)
**Chosen architecture:** **Thin Adjudicator** (unanimous judge winner, ranked #1 by all three) with grafted hardening from the Correctness-First and Seamed tracks.
**Supersedes:** the analyze sketch in `docs/specs/2026-04-16-codeguard-v2-architecture.md:575-597` for the package-side engine.

> **Scope constraint (user directive 2026-06-03):** package-side only. `/home/henry/arch` is read-only reference; nothing in it changes. Everything here is testable with fixtures + a mocked LLM (no network in CI).

---

## 1. Goal & scope

`codeguard:analyze` is CodeGuard's differentiator: a PHP-native command that loads the curated pattern corpus (`resources/patterns/{core,php,php-laravel}/*.yaml`), decides which source files to review, and asks an LLM to judge each file against the patterns whose `detection.signals` match it — reaching smells **AST tools cannot** ("god object", "logic in blade", "service-layer discipline"). It returns structured findings, gates the exit code on severity, and emits telemetry through the existing `Recorder`.

**One sentence:** load matching patterns → select files (git-changed by default) → batch each file's matching patterns into ONE structured-JSON LLM call → validate findings into immutable DTOs → format + gate exit on `--fail-on` severity.

### Why "thin"
The v0 (npm) ancestor had **zero programmatic LLM code** — the host IDE *was* the LLM, driven by a 13-step markdown procedure (`git grep -liE 'anthropic|openai|llm' v0-last-npm -- src/**` → NONE). We are adding the thinnest correct PHP-native adjudication path that fits existing conventions 1:1, not building a framework. All three judges independently rejected the heavier tracks as speculative generality.

### Explicitly deferred (NOT this engine)
| Deferred | Why | Where it lands |
|---|---|---|
| Real network driver (`AnthropicLlmClient`) | Engine is fully exercisable with `Null`/`Fake`; real value is a one-file follow-up behind the seam | +1.5d follow-up (Increment D) |
| `schema-dump` / `prepare` command | Sibling — `EventName::PrepareStepEnded` + `PrepareConfig.php` already exist | Reuses the `PatternRepository` seam |
| `ai-rules` generation | Sibling; consumes pattern data via the same repository | Reuses `PatternRepository` |
| AI-finding baseline/suppression | Ported v0 rule: **AI findings are never baselined** (report-only every run) | Post-MVP; `--fail-on` is the only noise control |
| Result caching (`sha1` skip) | Not needed for the changed-only path | Named seam in front of the runner loop |
| `llm_cost_usd` telemetry | Privacy gate forbids it without a `FieldAllowlist::SCHEMA` edit + sync-test change | Privacy-gated follow-up |
| AST-delegation (skip ~12 AST-replaceable patterns) | Cost optimization; spec:595 says 12/28 better served by phpstan/PHPMD | Documented roadmap note |

---

## 2. The pattern data contract

Verified across the 30 YAMLs in `resources/patterns/{core,php,php-laravel}/`. **28 are patterns; 2 are outliers** the loader MUST skip.

### Discriminator (loader invariant)
A real pattern has **both** `verification:` and `examples:`. `preset.yaml` (carries `tools:`/`install_commands:`) and `module.yaml` (carries `label/language/framework/capabilities`) have neither. **Loader rule:** require non-empty `name` + non-empty `verification.rules` (the ported v0 `loader.ts` invariant), and skip any file missing `verification`+`examples`.

### Canonical schema (28 files, 100% consistent — verified)
| Field | Type | Req | Values | Role |
|---|---|---|---|---|
| `name` | string (kebab) | yes | == filename stem | METADATA / id |
| `description` | string | yes | one line | **LLM PROMPT** |
| `category` | enum | yes | `solid` `clean-code` `architecture` `ddd` `framework` `php` | METADATA |
| `layer` | enum | yes | `core`(13) `php`(6) `laravel`(9) — == subdir == `enabled_presets` | METADATA / selection |
| `severity` | enum | yes | `critical`(10) `warning`(14) `suggestion`(4) | **LLM PROMPT + gate threshold** |
| `classification` | enum | yes | `mvp` (only value) | METADATA |
| `detection.signals[]` | list | yes | each `{type, value}` | **SCOPING (pre-filter)** |
| `detection.signals[].type` | enum | yes | `file`(21) `import`(7) `directory`(7) | SCOPING |
| `detection.signals[].value` | string | yes | glob (`**/*.php`), namespace glob (`App\Services\*`), or dir (`app/Services`) | SCOPING |
| `detection.confidence` | enum | yes | `high`(19) `medium`(10) | METADATA |
| `verification.rules[]` | list of string | yes | NL review criteria | **LLM PROMPT (the checklist)** |
| `examples.correct` / `examples.violation` | string block | yes | always exactly these two keys (never `good`/`bad`) | **LLM PROMPT (few-shot)** |
| `related_patterns[]` | list of string | optional (11/28) | refs to other `name`s | METADATA |

**No pattern uses `enabled`, `glob`, `scope`, `prompt`, or `id`.** `id`=`name`; `glob`=`detection.signals[].value`; `scope`=`detection.signals`.

**Field routing (decisive for the prompt):**
- **→ LLM:** `description`, `verification.rules`, `examples.correct`, `examples.violation`, `severity` (optionally `category`/`layer` for framing).
- **Pre-filter (never sent to LLM):** `detection.signals` → resolved to a concrete file set.
- **Pure metadata:** `name`, `category`, `layer`, `classification`, `confidence`, `related_patterns`.

---

## 3. Architecture

New namespace `Henryavila\Codeguard\Analyze\` under `src/Analyze/`, mirroring `src/Gates/`. Greenfield.

### Component breakdown
| Class | Kind | Responsibility | Mirrors |
|---|---|---|---|
| `Analyze/Severity` | enum (string) | `critical\|warning\|suggestion` — prompt weighting + `--fail-on` threshold `compare()`. The one value-object worth day-1. | new |
| `Analyze/Pattern` | `final readonly` DTO | `fromArray()`; all schema fields, `severity:Severity`. | `GateConfig.php` |
| `Analyze/PatternRepository` | interface | `forPresets(list): list<Pattern>`, `has(string): bool`. Interfaced because the prepare/schema-dump sibling is real → grounded reuse. Only this seam is interfaced. | `CommandExecutor` |
| `Analyze/YamlPatternLoader` | `final` | `implements PatternRepository`. Finder+Yaml over the **package's own** `resources/patterns/*` filtered by `enabledPresets`, + `customPaths` + auto-discovered `base_path('.codeguard/patterns')`. Applies the §2 discriminator. | `LayerDecisionStore` |
| `Analyze/FileScopeResolver` | `final` | `--changed-only` (git `diff --name-only HEAD` + `--cached`), `--path=` subtree, or `--all` Finder walk → `list<string>` abs paths. | git usage in hooks |
| `Analyze/PatternMatcher` | `final` | For each scoped file, returns the `list<Pattern>` whose `detection.signals` match (glob via `fnmatch`; `import`/`directory` approximated as path/namespace globs in MVP) → `AnalysisUnit{file, patterns}`. | new |
| `Analyze/FindingSchema` | `final` | **Single source of truth** for the finding contract: the JSON schema sent to constrain the LLM call **and** the rules `PatternMatch::fromArray` validates — request + validation cannot drift. | new |
| `Analyze/LlmClient` | interface | `review(AnalysisUnit, string $systemPrompt): list<array>`. The Node-free transport seam — exact `CommandExecutor`→`ProcessCommandExecutor` precedent. | `CommandExecutor` |
| `Analyze/Drivers/NullLlmClient` | `final` | `implements LlmClient`; returns `[]`. Bound default when no driver configured. | `ProcessCommandExecutor` |
| `Analyze/PatternMatch` | `final readonly` DTO | `fromArray()` is the **trust boundary** (§3.1). Fields `patternKey, file, line, message, severity, confidence`. | `GateRunResult` |
| `Analyze/AnalyzeResult` | `final readonly` | `patternsChecked, matches, durationMs`; `passed(Severity)`/`failed()`. | `GateRunResult.php` |
| `Analyze/AnalyzeRunner` | `final` | Orchestrator. ctor `(Recorder, PatternRepository, FileScopeResolver, PatternMatcher, LlmClient, string $workingDirectory)`. Loads system prompt, loops units, calls `LlmClient`, validates, emits `AnalyzeEnded`. | `GateRunner.php` |
| `Commands/CodeguardAnalyzeCommand` | `final` Command | §5. | `CodeguardCheckCommand` |
| `resources/prompts/system.md` (+ ported `core/php/laravel` rubric) | versioned asset | Frozen system prompt (role + JSON output contract + v0 false-positive/severity rubric). External, not inline → reviewable diffs + future `prompt_version` hashing. | new |

`tests/Support/FakeLlmClient.php` (Closure handler + `public array $calls = []`) mirrors `FakeCommandExecutor.php`.

### 3.1 Trust boundary (anti-hallucination)
`PatternMatch::fromArray()` **drops** any raw finding where:
1. `patternKey` not in the set dispatched for that file **and** not resolvable via `PatternRepository::has()`;
2. `file` ≠ the analyzed file (the file-mismatch check);
3. `severity` outside `critical|warning|suggestion`;
4. `confidence` not numeric / not in `[0,1]`.

The cheapest highest-value correctness graft (all three judges named it). A method, not a separate class.

### 3.2 Prompt assembly
One **frozen system prompt** (cacheable across every file): role + JSON output contract from `FindingSchema` + the v0 false-positive + severity rubric ported verbatim into `resources/prompts/`. Per-unit user block = **file contents + appended `Pattern` blocks** (each = `description` + `verification.rules` + `examples.correct` + `examples.violation` + `severity`; never metadata, never `detection.signals`). **Whole-file, not diff** — structural smells aren't visible in a hunk. **One call per file** (file = expensive shared context, patterns = cheap appended blocks).

### 3.3 Graceful degradation (the most important honesty fix)
With `NullLlmClient` bound (no driver), the command must NOT let empty-list ⇒ SUCCESS masquerade as a clean repo. It prints:
> `LLM driver not configured — set ANTHROPIC_API_KEY or config('codeguard.patterns.driver'). No patterns adjudicated.`

then exits `SUCCESS` (CI never breaks for a missing key) and emits `analyze.ended` with status `Skip`. Notice wording only — NOT a second dual code-path.

### ASCII flow
```
codeguard:analyze --changed-only --fail-on=critical --context=ci
        │  emit command.start{command:'analyze'}
   ┌─────────────────────── AnalyzeRunner.run() ───────────────────────┐
   │  PatternRepository.forPresets(enabledPresets)  ── skip 2 outliers  │
   │  FileScopeResolver  (git diff HEAD + --cached | --path | --all)    │
   │  PatternMatcher  (detection.signals ∩ files) → list<AnalysisUnit>  │
   │  for each unit (ONE call/file):                                    │
   │    systemPrompt + Pattern blocks ──► LlmClient ──► raw findings    │
   │    PatternMatch::fromArray()  ── TRUST BOUNDARY (§3.1) drops bad   │
   │  emit analyze.ended{patterns_checked_count, matches_count}         │
   └─► AnalyzeResult{patternsChecked, matches, durationMs}             ─┘
        ▼ ConsoleFormatter: ✗ critical / ⚠ warning / → suggestion
        ▼ exit = FAILURE iff matches at/above --fail-on else SUCCESS
        ▼ emit command.end{command:'analyze', exit_code}
```

---

## 4. LLM transport (Fork 4) — RECOMMENDATION (user must confirm)

**Recommendation:** Ship the `LlmClient` **interface** + `NullLlmClient` default **now** (MVP, no network, no credentials). Ship the official `anthropic-ai/sdk` adapter (`AnthropicLlmClient`) as a **+1.5d follow-up** behind the seam, declared `suggest` + `require-dev` only (NOT a hard `require`).

### SDK vs CLI
| | `anthropic-ai/sdk` (recommended) | `claude -p` CLI shell-out |
|---|---|---|
| Structured output | native JSON-schema → deterministic shape | `--json-schema` |
| Prompt caching | `CacheControlEphemeral(ttl:'5m')` on system prompt | n/a |
| Determinism | `temperature:0` | `temperature:0` |
| Fragility | none | user's `claude` is a zsh function → must invoke via absolute path/Process |
| Billing risk | per-API-key (`ANTHROPIC_API_KEY`) | from 2026-06-15 subscription `claude -p` draws from a separate Agent SDK credit |

**Honest cost:** `anthropic-ai/sdk` is **v0.x** (pre-1.0 churn). Mitigation: pin it, wrap behind `LlmClient` so a breaking change touches **one adapter file**, declare it `suggest`-only so the package core installs with **zero LLM dependency**.

### Mocked in tests
`FakeLlmClient implements LlmClient` — Closure returns canned raw-finding arrays; records every call in `public array $calls = []` (mirror of `FakeCommandExecutor`). Feature tests swap it via the container + point `Recorder` at a temp `.jsonl`. Assert on **schema + presence + severity**, never exact prose.

---

## 5. `codeguard:analyze` command

```
codeguard:analyze
  {--changed-only : Analyze only git-changed + staged files (DEFAULT scope).}
  {--path=        : Narrow scope to a file or subtree.}
  {--all          : Full scan — CI/manual.}
  {--fail-on=critical : Exit non-zero when a finding at/above this severity exists —
                        critical|warning|suggestion|never.}
  {--context=manual   : Telemetry context — pre-commit|pre-push|ci|manual.}
```

- `final class`, `declare(strict_types=1)`, constructor-less — deps via `handle(CodeguardConfig, AnalyzeRunner, Recorder): int`.
- `resolveContext()` copied verbatim from `CodeguardCheckCommand:93-98`. `--fail-on` parsed into `Severity` (`never` ⇒ report-only).
- Output: findings grouped by severity, glyphs `✗`/`⚠`/`→`, each line `file:line · pattern · message · confidence`.

**Exit semantics**
| Condition | Exit |
|---|---|
| ≥1 match at/above `--fail-on` (default `critical`) | FAILURE (1) |
| Only matches below `--fail-on` | SUCCESS (0), printed |
| `--fail-on=never` | always SUCCESS (observe-first rollout) |
| `NullLlmClient` (no driver) | SUCCESS + degradation notice (§3.3) |

No `--pattern`/`--preset` runtime knobs in MVP — selection stays config-driven; `--fail-on` is the single runtime knob.

---

## 6. Config schema

**No config changes for MVP.** The `patterns` block already exists and is correct (`config/codeguard.php:199-207`, parsed into `CodeguardConfig`):

```php
'patterns' => [
    'enabled_presets' => ['core', 'php', 'php-laravel'], // == layer == subdir
    'custom_paths'    => [],                              // + auto-discovers base_path('.codeguard/patterns')
    'baseline_path'   => base_path('.codeguard/baseline.json'),
],
```

The **only** new key, added when the real driver lands:
```php
    'driver' => env('CODEGUARD_PATTERNS_DRIVER'), // null|'anthropic' → bound LlmClient; null ⇒ NullLlmClient
```

> **Loader path precedence (verified gotcha):** the publish tag `codeguard-patterns` maps `resources/patterns` → `.codeguard/patterns-vendor` (`CodeguardServiceProvider.php:321`), but config auto-discovery + `custom_paths` point at `.codeguard/patterns`. `YamlPatternLoader` must read the PACKAGE's own `resources/patterns/{core,php,php-laravel}` as the primary source, + `customPaths` + auto-discovered `base_path('.codeguard/patterns')` — **never the `-vendor` publish path.** `baseline_path` is unused by this engine (AI findings are never baselined).

---

## 7. Telemetry

Reuses reserved slots with **zero schema change**:
- `EventName::AnalyzeEnded = 'analyze.ended'` (`EventName.php:42`), allowlist schema = exactly `{patterns_checked_count:int, matches_count:int}` (`FieldAllowlist.php:123-126`).
- `'analyze'` already in the `command.start`/`command.end` enum (`FieldAllowlist.php:50,54`).

| Event | Status | Extras |
|---|---|---|
| `command.start` | Ok | `{command:'analyze', preset_flag:null}` |
| `analyze.ended` | Ok\|Fail\|Skip | `{patterns_checked_count:int, matches_count:int}` |
| `command.end` | Ok\|Fail | `{command:'analyze', exit_code:int}` |

`EventStatus::Skip` is emitted for the no-driver degradation path so the dashboard distinguishes "ran clean" from "didn't adjudicate." No free-form strings (privacy gate). `llm_cost_usd` is a deliberate privacy-gated follow-up.

---

## 8. TDD task list (RED → GREEN, shippable increments)

Estimates are **focused days**, grounded in verified 1:1 mirrors (each class has an existing template to copy).

### Increment A — Walking skeleton (load patterns + analyze one file w/ mocked LLM + print one finding) — ~1.5d
1. RED `SeverityTest`: `from('critical')`, `compare()` ordering. GREEN `Severity` enum.
2. RED `PatternTest`: `Pattern::fromArray()` maps a fixture YAML array. GREEN `Pattern`.
3. RED `YamlPatternLoaderTest`: loads N fixture patterns, **skips the 2 outliers**, respects `enabledPresets`, reads package `resources/patterns` not `-vendor`. GREEN `YamlPatternLoader`.
4. RED `tests/Support/FakeLlmClient.php` + `CodeguardAnalyzeCommandTest`: one fixture file + one pattern + Fake returning one canned finding → `artisan('codeguard:analyze',['--path'=>fixture,'--context'=>'ci'])` prints it, exits 0. GREEN minimal `AnalyzeRunner`, `PatternMatch`, `AnalyzeResult`, `LlmClient`, `NullLlmClient`, `FileScopeResolver` (path-only), `PatternMatcher` (glob-only), `CodeguardAnalyzeCommand`, `FindingSchema`, `registerAnalyzeServices()`, registration.

### Increment B — Scope + matching + trust boundary — ~1.5d
5. RED `FileScopeResolverTest`: `--changed-only`, `--path`, `--all`. GREEN.
6. RED `PatternMatcherTest`: file globs match; `import`/`directory` approximated; non-matching excluded. GREEN.
7. RED `PatternMatchTest` (trust boundary §3.1): drop unknown `patternKey`, bad severity, `file` mismatch, bad confidence. GREEN.

### Increment C — Exit semantics + telemetry + degradation — ~1.5d
8. RED `AnalyzeResultTest`: `passed(Severity)`/`failed()` per threshold. GREEN.
9. RED Feature: `--fail-on=critical` → FAILURE on critical; warning-only → SUCCESS; `never` → SUCCESS. GREEN.
10. RED Feature: emits `command.start` → `analyze.ended{...}` → `command.end` (read temp `.jsonl`); asserts **one LLM call per file** (`FakeLlmClient::$calls`); asserts **no baseline written**. GREEN.
11. RED Feature: `NullLlmClient` → degradation notice + exit 0 + `analyze.ended` status `Skip`. GREEN §3.3.
12. Coverage gate: `composer test:coverage --min=80` green.

### Increment D — Real driver (follow-up, behind the seam) — ~1.5d
13. Add `anthropic-ai/sdk` pinned, `suggest` + `require-dev`. RED `AnthropicLlmClientTest` (Mockery on the SDK). GREEN `Drivers/AnthropicLlmClient`: `AnalysisUnit`→native JSON-schema request, `temperature:0`, `CacheControlEphemeral`; bound when `driver='anthropic'`.

| Increment | Days |
|---|---|
| A — walking skeleton | 1.5 |
| B — scope/matching/trust | 1.5 |
| C — exit/telemetry/degradation | 1.5 |
| **MVP total (A–C)** | **4.5** |
| D — real driver (follow-up) | +1.5 |
| **Total incl. transport** | **6.0** |

> **Estimate basis:** every class has a verified 1:1 template (`AnalyzeRunner`←`GateRunner`, `LlmClient`/`Null`/`Fake`←`CommandExecutor`/`Process`/`Fake`, command←`CodeguardCheckCommand`, telemetry slots pre-reserved). Copy-and-adapt + test-first, not net design. Risk buffer is in D (SDK v0.x structured-output mapping).

---

## 9. Open decisions (need the user)

1. **Transport (§4):** official `anthropic-ai/sdk` as default real driver? Or `claude -p` CLI despite shell-function/PATH fragility + June-15 billing caveat? *(Rec: SDK.)*
2. **Bundle a default driver?** *(Rec: no — `NullLlmClient` default + SDK `suggest`-only, core installs with zero LLM dependency.)*
3. **Severity-gate default:** `--fail-on=critical` (block only highest-confidence smells) or `--fail-on=never` (pure observe) for first releases? *(Rec: critical.)*
4. **v0-faithful context-emit driver** (writes `{patterns,context}` JSON for an IDE agent) behind `LlmClient`, or notice-only degradation? *(Rec: notice-only now.)*
5. **`--changed-only` as implicit default** vs explicit scope flag? *(Rec: implicit.)*

---

## 10. Honesty check — which claims become TRUE on ship

After **Increments A–C (MVP, no network):**
- ✅ `codeguard:analyze` exists and runs (wired command, telemetry, exit codes).
- ✅ Loads curated patterns / pre-filters files by `detection.signals`.
- ✅ Pluggable, Node-free LLM transport (interface + Null default; mockable in CI).
- ✅ Gates CI on severity (`--fail-on`).
- ✅ Privacy-safe telemetry for analyze.
- ⚠️ "Reviews code with an LLM / finds god-objects" — **NOT YET TRUE** until a real driver is configured. With `NullLlmClient` the command honestly prints the degradation notice — it does **not** fake a clean repo. **Do not market self-adjudication as shipped until Increment D + a configured key.**

After **Increment D + configured key:**
- ✅ AI-powered review reaching smells AST tools can't.
- ✅ Structured, validated, anti-hallucination findings (trust boundary §3.1).
- ✅ Prompt-cached, deterministic-shape (`temperature:0`).

**Still FALSE / unclaimed after all increments** (be blunt in README): no AI-finding suppression/baseline (report-only by design), no result caching, no per-call cost telemetry, no AST-delegation of the ~12 AST-replaceable patterns, and `import`/`directory` detection signals are glob-approximated.
