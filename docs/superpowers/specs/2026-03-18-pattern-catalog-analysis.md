# CodeGuard Pattern Catalog — Deep Analysis

**Date:** 2026-03-18
**Author:** Henry + Claude (Superpowers Brainstorming)
**Status:** Approved during brainstorming session

---

## Purpose

This document defines the complete pattern catalog for CodeGuard, organized by layer (universal, PHP, Laravel), with classification for MVP vs Roadmap based on two criteria:

1. **AI Verifiability** — Can an AI agent reliably detect violations?
2. **Impact** — How much value does enforcement add to code quality?

---

## Research Sources

- [Are We SOLID Yet? — LLM Detection of SOLID Violations (2025)](https://arxiv.org/html/2509.03093)
- [ArchUnit — Architectural Fitness Functions](https://www.archunit.org/)
- [Fitness Functions for Architecture — InfoQ](https://www.infoq.com/articles/fitness-functions-architecture/)
- [DDD Tactical Patterns — Microsoft](https://learn.microsoft.com/en-us/azure/architecture/microservices/model/tactical-ddd)
- [Clean Code Summary — Robert C. Martin](https://gist.github.com/wojteklu/73c6914cc446146b8b533c0988cf8d29)
- [PHP Design Patterns — Refactoring Guru](https://refactoring.guru/design-patterns/php)
- [Laravel DDD Patterns](https://lorisleiva.com/conciliating-laravel-and-ddd)

---

## Classification Criteria

- **MVP** = AI-verifiable with high confidence + high impact on quality
- **Roadmap** = Verifiable but medium confidence, OR high impact but hard to detect
- **Discarded** = Low impact or too subjective

---

## SOLID Principles

Research (2025) tested LLMs detecting SOLID violations with the following results:

| Principle | AI Detectable | Best Accuracy (F1) | Impact | Classification |
|---|---|---|---|---|
| Single Responsibility (SRP) | High | 99.7% | High — class/method doing too much is root of many problems | **MVP** |
| Open/Closed (OCP) | Medium | 74.5% | Medium — relevant but abstract | **Roadmap** |
| Liskov Substitution (LSP) | Low | ~50% | Medium — subtypes breaking contracts | **Roadmap** |
| Interface Segregation (ISP) | Medium | 71.1% | Medium — fat interfaces | **Roadmap** |
| Dependency Inversion (DIP) | Very low | ~10% | High — but AI cannot detect well | **Roadmap** |

**Key insight:** SRP is the only SOLID principle that AI detects with high confidence. Others need very specific prompt engineering. For MVP, focus on SRP and treat others as evolution.

---

## Clean Code (Robert C. Martin)

| Principle | AI Verifiable | How to verify | Impact | Classification |
|---|---|---|---|---|
| Small functions | High | Count lines, cyclomatic complexity | High | **MVP** (via thresholds) |
| Few arguments | High | Count function params | Medium | **MVP** |
| DRY | Medium | Detect duplicate logic (not visual structure) | High | **MVP** |
| Meaningful names | Medium | Detect `$x`, `$temp`, `$data` generics | Medium | **Roadmap** |
| No side effects | Low | Hard to detect without runtime | Medium | **Roadmap** |
| Unnecessary comments | Medium | Detect comments that repeat code | Low | **Discarded** |
| Consistent error handling | Medium | Detect empty catch, generic Exception | High | **MVP** |
| Separation of concerns | High | Detect mixed layers | High | **MVP** (via layer patterns) |

---

## DDD Tactical Patterns

| Pattern | AI Verifiable | How to verify | Impact | Classification |
|---|---|---|---|---|
| Bounded Contexts | High | Module A accessing Model/Service from module B | High | **MVP** |
| Entities vs Value Objects | Medium | Class that should be VO but is Entity | Medium | **Roadmap** |
| Aggregates | Low | Direct access to child entity without aggregate root | High but hard to detect | **Roadmap** |
| Repositories | High | Queries scattered outside repositories | High | **MVP** (for stacks that use them) |
| Domain Events | Medium | Coupled side effects vs decoupled events | Medium | **Roadmap** |
| Domain Services | High | Domain logic in wrong places (controller, model) | High | **MVP** (via Service Layer) |
| Anti-Corruption Layer | Medium | Direct dependency on external service without adapter | Medium | **Roadmap** |
| Ubiquitous Language | Low | Inconsistent naming with domain | Medium | **Roadmap** |

**Key insight:** Bounded Contexts and Domain Services are the most verifiable and impactful. Aggregates are important but hard for AI to detect.

---

## GoF Design Patterns (Gang of Four)

Most GoF patterns are **implementation patterns** — it doesn't make sense to govern "you must use Strategy". What makes sense is detecting **anti-patterns that indicate a GoF pattern should be used**:

| Detectable anti-pattern | GoF pattern that solves it | Verifiable | Impact | Classification |
|---|---|---|---|---|
| Long switch/if chain on type | Strategy / State | High | High | **MVP** |
| Constructor with too many params | Builder | High | Medium | **MVP** (via threshold) |
| `new` scattered in business logic | Factory | High | Medium | **Roadmap** |
| God Object class | Facade + decomposition | High | High | **MVP** (via SRP) |
| Callback hell / manual observer | Observer / Event | Medium | Medium | **Roadmap** |
| Deep inheritance | Composition over Inheritance | High | High | **MVP** |
| Direct coupling to implementation | Adapter / Interface | Medium | Medium | **Roadmap** |

**Key insight:** Don't govern "use Strategy pattern". Govern "switch with 8 cases on type is a code smell — consider Strategy". AI detects the problem, suggests the solution.

---

## Architectural Fitness (inspired by ArchUnit)

ArchUnit allows writing rules as tests. For CodeGuard, the AI fills the same role:

| Rule | Verifiable | Impact | Classification |
|---|---|---|---|
| Layer dependency direction (Controller → Service → Repository, never reverse) | High | High | **MVP** |
| No circular dependencies between modules | High | High | **MVP** |
| Classes in package X must inherit/implement Y | High | Medium | **Roadmap** |
| No class outside `app/Http/` should use Request | High | High | **MVP** (Laravel) |
| No class outside `config/` should use env() | High | High | **MVP** (Laravel) |

**Key insight:** Architectural fitness functions are highly verifiable and high impact. They are declarative rules that AI can check deterministically.

---

## PHP-Specific

| Pattern/Anti-pattern | Verifiable | Impact | Classification |
|---|---|---|---|
| Strict typing (`declare(strict_types=1)`) | High | High | **MVP** |
| No HTML in PHP strings | High | High | **MVP** |
| No debug functions (dd, dump, var_dump, ray) | High | High | **MVP** |
| Type declarations on params and returns | High | High | **MVP** |
| No mixed without justification | Medium | Medium | **Roadmap** |
| No raw SQL when ORM available | Medium | Medium | **Roadmap** |
| Exception handling (no empty catch, no generic Exception) | High | High | **MVP** |
| No superglobals ($_GET, $_POST directly) | High | High | **MVP** |

---

## Laravel-Specific

| Pattern | Verifiable | Impact | Classification |
|---|---|---|---|
| Service Layer | High | High | **MVP** |
| DTOs (LaravelData) | High | High | **MVP** |
| Form Requests for validation | High | High | **MVP** |
| Action Classes | High | Medium | **MVP** |
| Value Objects | Medium | Medium | **MVP** |
| Resource Controllers | High | Medium | **MVP** |
| Policies for authorization | High | Medium | **MVP** |
| No env() outside config/ | High | High | **MVP** |
| No logic in Blade (@php with calculations) | High | High | **MVP** |
| Queue jobs for heavy processing | Medium | Medium | **Roadmap** |
| Events for decoupling | Medium | Medium | **Roadmap** |
| Middleware for cross-cutting | Medium | Low | **Roadmap** |

---

## Summary: MVP vs Roadmap

### MVP — 28 patterns

| Layer | Patterns | Count |
|---|---|---|
| **Core (universal)** | SRP, DRY, Small Functions, Few Arguments, Consistent Error Handling, Separation of Concerns, No Long Switch/If Chain, No Constructor With Too Many Params, No God Object, No Deep Inheritance, Layer Dependency Direction, No Circular Dependencies, Bounded Contexts | 13 |
| **PHP** | Strict Typing, No HTML in PHP, No Debug Functions, Type Declarations, Exception Handling, No Superglobals | 6 |
| **Laravel** | Service Layer, DTOs, Form Requests, Action Classes, Value Objects, Resource Controllers, Policies, No env() outside config, No Logic in Blade | 9 |

**Note on Repositories pattern:** Classified as "MVP (for stacks that use them)" in the DDD section above. Excluded from the Laravel module because Eloquent serves the Repository role. Included as MVP for stacks that use explicit Repositories (e.g., Symfony).

### Roadmap — 18 patterns

| Layer | Patterns | Count |
|---|---|---|
| **Core** | OCP, LSP, ISP, DIP, Meaningful Naming, Factory Pattern, Observer/Events, Adapter/Interface, Entities vs Value Objects, Aggregates, Domain Events, Anti-Corruption Layer, Ubiquitous Language | 13 |
| **PHP** | No Mixed Without Justification, No Raw SQL | 2 |
| **Laravel** | Queue Jobs, Events, Middleware | 3 |

---

## Pattern YAML Schema

Each pattern in the catalog follows this structure:

```yaml
name: Service Layer
description: Controllers delegate business logic to Services
category: architecture        # architecture | clean-code | solid | ddd | php | framework
layer: laravel                # core | php | laravel
severity: critical            # critical | warning | suggestion
classification: mvp           # mvp | roadmap

detection:
  signals:
    - directory: app/Services
    - controllers_import: App\Services\*
  confidence: high             # high | medium | low

verification:
  rules:
    - controllers must not access Eloquent models directly
    - controllers must not contain business logic
    - services must not return HTTP responses
    - services must not access Request object

examples:
  correct: |
    // Controller delegates to Service
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $result = $this->orderService->create(
            OrderData::from($request)
        );
        return response()->json($result);
    }
  violation: |
    // Controller accesses model directly
    public function store(Request $request): JsonResponse
    {
        $order = Order::create($request->all());
        return response()->json($order);
    }

related_patterns:
  - dto
  - form-requests
  - action-classes

references:
  - "Clean Code — Robert C. Martin"
  - "Domain-Driven Design — Eric Evans"
```

---

## How Patterns Are Used

| Consumer | What it reads | Purpose |
|---|---|---|
| `/codeguard-setup` skill | All pattern YAMLs from detected module layers | Present to dev for selection during setup |
| `/codeguard-run` skill | Active patterns from codeguard.yaml | AI analyzes code against verification rules |
| `/codeguard-health` skill | Active patterns + findings history | Overview of compliance status |
| CODEGUARD.md | Active patterns | Generated as AI guide for code generation |
| Pest arch tests | Structural rules from patterns | Deterministic enforcement of layer/naming/dependency rules |
| Hook runner | Does NOT read patterns directly | Runs tools + Pest arch tests, no semantic pattern knowledge |

## Three-Layer Enforcement Model

Patterns are enforced through three complementary layers:

| Layer | Tool | What it catches | Precision |
|---|---|---|---|
| Deterministic tools | Larastan, Pint, PHPMD | Type errors, formatting, code smells | 100% |
| Architectural rules | Pest arch testing | Structural: layer deps, naming, class types | 100% |
| Semantic analysis | CodeGuard AI (skills) | Missing DTOs, logic in wrong place, pattern drift | Probabilistic |

During `/codeguard-setup`, the AI generates `tests/Architecture/CodeGuardArchTest.php` with Pest arch() expectations based on the active patterns. Structural rules go to Pest (deterministic), semantic rules stay with AI (probabilistic).

## Related Research

- [Laravel Tools Deep Research](2026-03-18-laravel-tools-research.md) — Pint, Larastan, Pest, PHPMD, Rector complete analysis
