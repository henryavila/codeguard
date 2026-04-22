# CodeGuard Memory Index

Memória persistente do projeto — leia antes de qualquer trabalho substantivo.

## ⭐ Status (canonical, always-on)
- **[PROJECT-STATUS.md](PROJECT-STATUS.md)** — snapshot único do estado (sprint atual, próxima ação, scorecard, riscos). **Ler primeiro, atualizar ao fim de cada commit de escopo.**

## User & Goals
- [User Goals](user-goals.md) — 3 metas reais: replicar padrão multi-projeto, multi-máquinas, controlar dev terceirizado sem IA

## Architecture Decisions
- [Architecture Decisions](architecture-decisions.md) — 10 ADRs: pivot PHP, 2 packages, dual-track, 2 presets, timeline, stub evolution, hook runner (Lefthook→CaptainHook)
- [Pivot Rationale](../../docs/specs/2026-04-16-pivot-npm-to-composer.md) — por que abandonamos Node

## Design Evolution (ordem cronológica)
- [Design Doc v5 ACTIVE](../../docs/specs/2026-04-16-codeguard-v2-architecture.md) — **canonical spec** (2 packages, codeguard/codeguard-full presets, Lefthook, install híbrido)
- [Spec 2026-04-17 CaptainHook + Telemetry](../../docs/specs/2026-04-17-captainhook-migration-and-telemetry.md) — Phase A β **COMPLETA** 2026-04-20; Phase C + B pendentes (ver handoff)
- [CodeGuard v2 Internal Design](codeguard-v2-design.md) — decisões consolidadas (pré-preset redesign)
- [Reviews Consolidated](reviews-consolidated.md) — 10 agentes (6 adversariais + 4 steelman)
- [Preset Design Evolution](preset-design-evolution.md) — jornada 3 presets → 2 presets + Node auto-detect + install híbrido (sessão 2, 2026-04-16)

## State & Handoff
- [Conversation Handoff](conversation-handoff.md) — onde paramos, próximo passo concreto
- [Open Questions](open-questions.md) — decisões pendentes
- [Legacy npm v0](../../docs/legacy/npm-v0-README.md) — estado do repo Node preservado

## Quick Reference

### Estado do repo
- Branch atual: `main` (PHP pivot)
- Branch preservada: `v0-npm-archive` + tag `v0-last-npm`
- npm registry: `@henryavila/codeguard@0.1.1` continua publicado (não deprecated formalmente)

### Decisões Fixas (não reabrir sem razão forte)
- Stack: PHP 8.3+ / Laravel 11+ / Composer (core PHP-native; preset Full referencia jscpd/Node)
- 2 packages: `henryavila/codeguard` (Composer) + `henryavila/codeguard-hooks` (Claude plugin bash)
- Namespace: `Henryavila\Codeguard\*`
- Commands: `codeguard:*` (install, check, test, prepare, analyze, baseline)
- Presets: **`codeguard`** (default, PHP-native: Pint + PHPStan + Deptrac + Infection + CaptainHook) e **`codeguard-full`** (adds jscpd + Insights + TestQualityTest — requires Node, auto-detected)
- Pre-commit: **CaptainHook** (PHP puro + Composer-native) — ADR-010 reverteu decisão inicial de Lefthook
- Pattern engine: PHP nativo (symfony/yaml), não Node
- Stub evolution: dogfood → distill → redistribute (ADR-009)

### Dependências chave (futuras)
- `illuminate/console ^11.0|^12.0`
- `illuminate/support ^11.0|^12.0`
- `symfony/process ^7.0`
- `symfony/yaml ^7.0`

### Valor real defensável (pós-reviews)
1. **Composer package** (7/10 justo após steelman)
2. **Schema dump multi-DB** (SQL Server + `:memory:` + Windows — killer feature)
3. **AI config-protection hook** (único no mercado)
4. **Test anti-pattern kit** (7 checks packaged)
5. **Pattern YAMLs semantic/hybrid** (16 de 28 complementam AST)
