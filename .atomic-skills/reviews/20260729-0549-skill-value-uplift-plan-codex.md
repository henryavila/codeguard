# Cross-model plan review: skill-value-uplift

- **Provider:** codex
- **Model:** gpt-5.5 / gpt-5-codex
- **Mode:** codex (sealed envelope, 2-pass)
- **Artifact:** docs/specs/2026-07-29-skill-value-uplift-plan.md
- **Allow dirty:** yes
- **Date:** 2026-07-29

## Pass 2 (informed) — final

---
verdict: needs_changes
counts: {blocker: 0, critical: 1, major: 4, minor: 0, nit: 0}
reviewer: gpt-5-codex
pass: informed
schema_version: "1.0"
---

## Summary
The plan still has substantive gaps after applying the external constraints. The largest risk is that the mass-assignment requirements cannot be implemented with the current OR-only, path-based signal matcher while also satisfying the requested model-level exceptions. The remaining issues are around scope fidelity: `--base` omits unstaged work, emit/ingest cannot reliably replay the same file set, anti-overwrite is assigned to markdown procedure instead of the package write path, and hygiene filtering contradicts the stated full-focus outcome.

## Findings

### F-001 [major] viability — docs/specs/2026-07-29-skill-value-uplift-plan.md:110-118

**Evidence:**
```md
Decisão: **skill-only no MVP desta phase** (cheaper); package `--force` se sobrar tempo.
```

**Claim:** Anti-overwrite is missing from the package write path, so the guard cannot reliably evaluate the existing work order and the newly generated unit count at the point `--out` is overwritten.

**Impact:** A non-empty work order can still be replaced by an empty emit through direct CLI use, stale skill instructions, or any caller that bypasses the markdown procedure.

**Recommendation:** Implement the guard in the package emit path: build the new work order in memory, decode existing `--out`, abort before writing when existing `units>0` and new `units=0`, and require `--force` to override; add a feature test for that case.

**Confidence:** high

---

### F-002 [major] coverage — docs/specs/2026-07-29-skill-value-uplift-plan.md:81-108

**Evidence:**
```md
--base=origin/main   # opcional; se setado, files = git diff --name-only --diff-filter=ACMR <base>...HEAD + staged
// ∪ staged (cached)
```

**Claim:** `--base` omits unstaged working-tree changes because it overrides changed-only but unions the base diff with only staged files.

**Impact:** A local PR review can silently skip modified PHP files that are neither committed nor staged, producing an incomplete contractor review while reporting a valid scoped run.

**Recommendation:** Define `againstBase()` as `base...HEAD ∪ cached diff ∪ unstaged diff`, or fail with a clear dirty-worktree message unless the user passes an explicit committed-PR-only flag; add temp-git tests for committed, staged, and unstaged PHP changes.

**Confidence:** high

---

### F-003 [major] contradiction — docs/specs/2026-07-29-skill-value-uplift-plan.md:257-317

**Evidence:**
```md
**Outcome:** Full focus deixa de afogar em types; mass-assignment não spamma Models Filament-safe.
--focus=full ainda inclui hygiene
--exclude-hygiene   # drops classification=hygiene
```

**Claim:** Phase C states that full focus will stop drowning in type findings, but the concrete v1 behavior preserves hygiene findings in `--focus=full` and makes exclusion optional.

**Impact:** Phase C can be marked done while the default full inventory remains dominated by PHPStan-overlap findings, leaving two incompatible interpretations for implementers and tests.

**Recommendation:** Make the v1 invariant explicit: either make full focus exclude `classification: hygiene` by default with an `--include-hygiene` override, or change the outcome and DoD to require only an optional `--exclude-hygiene` review mode with tests for that mode.

**Confidence:** high

---

### F-004 [major] dependency — docs/specs/2026-07-29-skill-value-uplift-plan.md:63-68

**Evidence:**
```md
| Ingest | **Sempre** repetir as mesmas flags de focus/critique-score/scope do emit (ler do JSON do work order se presente: `focus`, `min_critique_score`) |

Work order já emite `focus` + `min_critique_score` (pós-calibragem) — skill deve **preferir esses campos no ingest** em vez de re-adivinhar.
```

**Claim:** Emit/ingest scope parity is under-specified because the work order metadata stores `focus` and `min_critique_score`, but not the resolved scope needed to replay the same file set.

**Impact:** If the base branch advances, tracking changes, or the working tree changes between emit and ingest, findings can be evaluated against different units or check counts.

**Recommendation:** Add a `scope` object to the work order containing mode, base ref, merge-base SHA, head SHA, and resolved file list; require `--ingest` to use that file list by default and fail on SHA mismatch unless an explicit rescope flag is passed.

**Confidence:** high

---

### F-005 [critical] contradiction — docs/specs/2026-07-29-skill-value-uplift-plan.md:331-383

**Evidence:**
```md
- Models: `app/Models/**` só se rules falarem em `$guarded = []` / `Model::unguard`
```

**Claim:** The mass-assignment selection requirement is infeasible with the current OR-only signal matcher and no content-body signal type because it requires selecting model files based on file contents.

**Impact:** The planned implementation cannot satisfy both requirements: excluding Filament-safe models with only `$fillable` while still selecting dangerous model-level `$guarded = []` or `Model::unguard()` cases.

**Recommendation:** Add an explicit content signal type and grouped matching for model-level mass-assignment signals, or remove model-level `$guarded = []` / `Model::unguard()` selection from v1 and update acceptance tests accordingly.

**Confidence:** high

## Questions (non-findings)

- None

## Out of scope

- GitHub PR comment integration
- Vote×3 advanced policy beyond opt-in documentation
- Metered LLM API driver / unattended CI LLM gate
- Auto-fix of findings
- Arch dogfood / packaging release 0.3.0
- Style, naming, and formatting polish

## Pass 2 reconciliation

### Dropped from blind pass

- _(none)_

### Maintained

- F-001-blind → F-001-final [major] — same
- F-002-blind → F-002-final [major] — same
- F-003-blind → F-003-final [major] — same
- F-004-blind → F-004-final [major] — same
- F-005-blind → F-005-final [critical] — severity changed: was major, now critical

### Emerged

- _(none)_
## Pass 1 (blind) — archive

---
verdict: needs_changes
counts: {blocker: 0, critical: 0, major: 5, minor: 0, nit: 0}
reviewer: gpt-5-codex
pass: blind
schema_version: "1.0"
---

## Summary
The plan has several substantive execution risks: scope metadata is not reproducible across emit and ingest, `--base` can silently drop working-tree changes, anti-overwrite is assigned to the skill even though the package owns the destructive write, hygiene filtering contradicts the stated outcome, and mass-assignment selection asks for content-dependent behavior while forbidding matcher changes needed to support it.

## Findings

### F-001 [major] viability — docs/specs/2026-07-29-skill-value-uplift-plan.md:110-118

**Evidence:**
```md
### A3. Proteção anti-overwrite

**Skill only (A1)** + opcional flag package:

```text
--out=... already exists with units>0 and new emit would write 0 → abort unless --force
```

Decisão: **skill-only no MVP desta phase** (cheaper); package `--force` se sobrar tempo.
```

**Claim:** Anti-overwrite cannot be reliably enforced as skill-only because the abort condition depends on the new work order’s unit count at the exact point where the package writes `--out`.

**Impact:** A useful existing work order can still be replaced by an empty emit through direct CLI use, stale skill state, or any package path the markdown procedure does not intercept.

**Recommendation:** Implement the guard in the package emit path: build the new work order in memory, decode existing `--out`, abort before writing when existing `units>0` and new `units=0`, and require `--force` to override; add a feature test for that case.

**Confidence:** high

---

### F-002 [major] coverage — docs/specs/2026-07-29-skill-value-uplift-plan.md:81-108

**Evidence:**
```md
```text
--base=origin/main   # opcional; se setado, files = git diff --name-only --diff-filter=ACMR <base>...HEAD + staged
```

Ou método:

```php
public function againstBase(string $baseRef): array
// git diff --name-only --diff-filter=ACMR $baseRef...HEAD
// ∪ staged (cached)
// filter *.php, existing files only
```

**Resolução de scope na skill (ordem):**

1. User pediu `--path` / `--all` / changed → honrar  
2. Env/git: branch tracking + `gh pr view` / `git merge-base` com default branch se disponível  
3. `--base=origin/<default>` se default branch detectável  
4. Fallback `changed-only`  
5. Se 0 units: mensagem clara + opções (não “Working tree clean” como se fosse sucesso de review)
```

**Claim:** `--base` drops unstaged working-tree changes because it overrides changed-only but unions the base diff with only staged files.

**Impact:** A local PR review can silently omit modified PHP files that are neither committed nor staged, producing an incomplete contractor review while reporting a valid scoped run.

**Recommendation:** Define `againstBase()` as `base...HEAD ∪ cached diff ∪ unstaged diff`, or fail with a clear dirty-worktree message unless the user passes an explicit committed-PR-only flag; add temp-git tests for committed, staged, and unstaged PHP changes.

**Confidence:** high

---

### F-003 [major] contradiction — docs/specs/2026-07-29-skill-value-uplift-plan.md:257-317

**Evidence:**
```md
**Outcome:** Full focus deixa de afogar em types; mass-assignment não spamma Models Filament-safe.
```

**Claim:** Phase C does not actually specify the stated full-focus noise reduction because later steps preserve hygiene in `--focus=full` and make `--exclude-hygiene` optional.

**Impact:** Two implementers can ship incompatible behavior, and the default CLI may remain dominated by PHPStan-overlap findings despite Phase C being marked done.

**Recommendation:** Make the v1 rule explicit: default analyze excludes `classification: hygiene`, add `--include-hygiene` for inventory runs, and add acceptance tests proving `type-declarations` and `strict-typing` are excluded by default but included with the override.

**Confidence:** high

---

### F-004 [major] dependency — docs/specs/2026-07-29-skill-value-uplift-plan.md:63-68

**Evidence:**
```md
| User não especifica focus | `--focus=contractor` + `--critique` (samples=1 default; samples=3 só se user pedir “gate duro” / “voting”) |
| User não especifica scope | Preferir **PR-diff** se detectável; senão `changed-only`; se 0 files → **não** re-emitir se já existe request com units>0 **sem** confirmação; oferecer `--path` / base branch |
| User pede full / inventário | `--focus=full` e/ou `--all` / `--path=…` explícitos |
| Ingest | **Sempre** repetir as mesmas flags de focus/critique-score/scope do emit (ler do JSON do work order se presente: `focus`, `min_critique_score`) |

Work order já emite `focus` + `min_critique_score` (pós-calibragem) — skill deve **preferir esses campos no ingest** em vez de re-adivinhar.
```

**Claim:** Emit/ingest scope parity is under-specified because ingest is required to repeat scope, but the work order metadata named here stores only `focus` and `min_critique_score`.

**Impact:** If the base branch advances, tracking changes, or the working tree is clean by ingest time, real findings from the emitted PR diff can be rejected by the trust boundary or reported against different check counts.

**Recommendation:** Add a `scope` object to the work order containing mode, base ref, merge-base SHA, head SHA, and resolved file list; require `--ingest` to use that file list by default and fail on SHA mismatch unless an explicit rescope flag is passed.

**Confidence:** high

---

### F-005 [major] contradiction — docs/specs/2026-07-29-skill-value-uplift-plan.md:331-383

**Evidence:**
```md
1. **Signals** (Phase D overlap — fazer junto):  
   - Preferir `app/Http/Controllers/**`, `app/Livewire/**`, `app/Filament/**` Actions, **não** `app/**/*.php` genérico  
   - Models: `app/Models/**` só se rules falarem em `$guarded = []` / `Model::unguard`  
```

**Claim:** The mass-assignment selection requirement needs content-dependent matching, but Phase D explicitly limits matcher changes to narrower OR-ed globs.

**Impact:** The planned tests cannot cover both “model with only `$fillable` is not selected” and “dangerous model/global unguard is selected”; implementation will either preserve Filament false positives or drop real unguard findings.

**Recommendation:** Add a content signal type plus grouped `all_of` matching for model-level mass-assignment signals, then require tests for `$fillable` negative, `$guarded = []` positive, and `Model::unguard()` positive cases.

**Confidence:** high

## Questions (non-findings)

- None

## Out of scope

- GitHub PR comment integration
- Vote×3 advanced policy beyond opt-in documentation
- Metered LLM API driver / unattended CI LLM gate
- Auto-fix of findings
- Arch dogfood / packaging release 0.3.0
- Style, naming, and formatting polish