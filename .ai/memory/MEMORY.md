# CodeGuard Memory Index

Memória persistente do projeto — leia antes de qualquer trabalho substantivo.

## User & Goals
- [User Goals](user-goals.md) — 3 metas reais: replicar padrão multi-projeto, multi-máquinas, controlar dev terceirizado sem IA

## Architecture Decisions
- [Architecture Decisions](architecture-decisions.md) — Option A (reusa repo), Node-free, 2 packages, dual-track
- [Pivot Rationale](../../docs/specs/2026-04-16-pivot-npm-to-composer.md) — por que abandonamos Node

## Design Evolution (ordem cronológica)
- [CodeGuard v2 Design](codeguard-v2-design.md) — decisões chave consolidadas
- [Design Doc v4 (Arch)](../../docs/specs/2026-04-16-codeguard-v2-architecture.md) — design completo pós-reviews
- [Reviews Consolidated](reviews-consolidated.md) — 10 agentes (6 adversariais + 4 steelman)

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
- Stack: PHP 8.3+ / Laravel 11+ / Composer (sem Node)
- 2 packages: `henryavila/codeguard` (Composer) + `henryavila/codeguard-hooks` (Claude plugin bash)
- Namespace: `Henryavila\Codeguard\*`
- Commands: `codeguard:*` (install, check, test, prepare, analyze, baseline)
- Default preset: **Minimal** (Pint + PHPStan only)
- Pattern engine: PHP nativo (symfony/yaml), não Node

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
