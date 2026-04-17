---
name: Preset Design Evolution
description: How Minimal/Standard/Full became 2 presets with auto-detect and hybrid install
type: project
---

# Preset Design — Evolution (2026-04-16)

## Starting Point (pre-conversation)
3 presets: **Minimal** (Pint + PHPStan), **Standard** (+ Deptrac + Infection + Husky), **Full** (+ jscpd + Insights + TestQualityTest). Minimal was default.

## Challenge 1 — Discovery Problem
User pointed out: "pode manter minimal como default, mas precisa já exibir as outras opções, senão usuário nem sabe que tem mais".
**Resolution**: install prompt must surface all presets visibly with concrete benefits, not just default to Minimal silently.

## Challenge 2 — "Why Pick Less Protection?"
User asked the killer question: "se o Full dá mais proteção e qualidade, por que alguém instalaria só o Min?"
This exposed a design flaw: if Full is strictly better at zero cost, Minimal is irrational. Had to acknowledge: **Full is not zero-cost** — config time, CI time, false-positive rate, and runtime dependencies all scale with preset complexity.

## Challenge 3 — Hidden Node.js Dependency
Analysis revealed that Husky (Node-based) and jscpd (Node-based) silently violated ADR-001 (PHP-only package core). User was willing to accept Node **if the tools were materially better**.

Tool-by-tool evaluation:
- **Husky vs Lefthook**: Lefthook wins on technical merit (Go binary, zero runtime, parallel execution, ~10-50ms cold start vs Husky ~300ms). Not about avoiding Node — about better engineering.
- **jscpd vs PHP alternatives**: phpcpd archived Dec 2020; phpmd CPD weak; no competitive PHP-native option. jscpd clearly better despite Node dep.

**Resolution**: Husky → Lefthook (PHP-path preserved). jscpd kept but moved to opt-in preset with Node as documented requirement.

## Challenge 4 — Inflated Estimates
User called out estimates "1-2h config" and "2-4h config" with "com base em que você estimou isso?". The numbers were inflated by ~2x with no justification.

Real calibrated numbers (per tool):
- Pint: 0 (Laravel preset)
- PHPStan: ~15min
- Deptrac: ~30min (layers via guided suggestion + first analyse)
- Infection: ~20min (srcDir auto-detect + baseline)
- Lefthook: ~10min
- jscpd: ~5min
- Insights: 0
- TestQualityTest: ~15min

**Total `codeguard`**: ~1h 15min (not 1-2h)
**Total `codeguard-full`**: ~1h 45min (not 2-4h extra)

## Challenge 5 — Simplification Win
User proposed: "pq não simplificar, unir minimum com recommended. Só 2 opções, sem e com node". This was the right call — the real decision axis is binary (Node or no Node), not a 3-level progression.

**Resolution**: 2 presets.

## Challenge 6 — Auto-Detection
User: "o installer já detecta se tem node instalado. Se tiver, já seleciona o Full".
**Resolution**: installer checks for `node_modules/`, `package.json`, then global `node` binary. Pre-selects appropriate preset; user can override.

## Final Design

### 2 Presets

| Preset | Tools | Node? | Auto-select when |
|--------|-------|:---:|------------------|
| **`codeguard`** (default) | Pint + PHPStan + Deptrac + Infection + Lefthook | ❌ | No package.json, no node_modules |
| **`codeguard-full`** | + jscpd + Insights + TestQualityTest | ✅ | package.json or node_modules present |

### Hybrid Install (3 Layers)

1. **Smart stubs** for 7/8 gates — commented inline, auto-filled from composer.json PSR-4
2. **Guided setup** only for Deptrac — scan `app/*` namespaces, propose layer structure, user confirms/edits/skips
3. **Post-install next-steps report** — per-gate concrete next action + docs link

### Override Flags

```bash
php artisan codeguard:install                    # auto-detect
php artisan codeguard:install --preset=full      # force full
php artisan codeguard:install --preset=default   # force PHP-only
php artisan codeguard:install --no-interactive   # CI mode, use detection
php artisan codeguard:install --refresh-stubs    # update stubs, preserve customizations
```

## Lessons Captured

1. **Never inflate estimates without evidence** — user spots it instantly
2. **Design without a "why pick less?" answer is broken** — if Full is strictly better at zero cost, simpler options are marketing, not design
3. **Simpler decision axes beat progressive levels** when the progression isn't real (Minimal/Standard/Full → codeguard/codeguard-full collapsed 3 to 2 cleanly)
4. **Constraints (PHP-only) aren't dogma** — test alternatives on merit; accept exceptions when documented and opt-in
5. **Auto-detection beats decision fatigue** — users don't want to think about "do I have Node?" when installer can check
