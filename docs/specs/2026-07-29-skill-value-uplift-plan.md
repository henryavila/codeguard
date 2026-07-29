# Plan: Skill value uplift (itens 1–4)

**Status:** Implemented · **Date:** 2026-07-29  
**Origin:** Field run Arch (2026-07-27) + audit de findings + ranking de ROI G3  
**Codex review:** `.atomic-skills/reviews/20260729-0549-skill-value-uplift-plan-codex.md` — **5 findings applied below** (F-001…F-005)  
**Goal:** Alinhar a skill `codeguard-review` e o corpus ao ideal G3 — **review de PR de contractor com findings acionáveis**, não inventário de monólito.

---

## Escopo

| # | Item | Em uma frase |
|:-:|------|----------------|
| **1** | Defaults da skill | Default = `contractor` + escopo de **diff/PR**, não full corpus + tree limpa |
| **2** | Report acionável | Saída em camadas **block / request-change / ignore** + checklist de PR |
| **3** | Corpus vs PHPStan | Cortar/rebaixar overlap AST + `mass-assignment` Filament-aware (write-site) |
| **4** | Signals R4 | Detection mais apertada nos 6 patterns de alto impacto |

**Fora de escopo (explícito):**

- Integração GitHub PR comments (ranking #5 — depois)
- Vote×3 policy avançada (ranking #6)
- Driver API metered / CI unattended LLM
- Auto-fix
- Dogfood Arch / release 0.3.0 packaging
- **Content-body signal type / `all_of` matcher** (escopo creep; ver C2 v1)

---

## Princípios (não negociar sem reabrir)

1. **CI AST continua writer-side** — skill é reviewer-side assistido.
2. **Emit e ingest usam o mesmo escopo resolvido** — work order carrega `scope` + focus + min-critique; ingest **reusa** a file list do emit (não re-deriva só por flags).
3. **Trust boundary e voting permanecem no package** — skill não reimplementa filtro.
4. **TDD no package** para qualquer mudança em PHP; YAMLs cobertos por testes de seleção existentes + novos fixtures.
5. **Default muda o ROI**; flags explícitas (`--focus=full --include-hygiene`, `--all`) preservam inventário completo.
6. **Guards que dependem do write path vivem no package**, não só em markdown de skill (F-001).

---

## Estado atual (baseline)

| Peça | Hoje | verified_by |
|------|------|-------------|
| Skill default emit | Sem `--focus` → full; scope default changed-only (falha em tree limpa) | `resources/skills/codeguard-review/SKILL.md` |
| Report CLI | Lista plana `✗/⚠/→` por severity do pattern | `CodeguardAnalyzeCommand::renderFindings` |
| `type-declarations` / `strict-typing` | `**/*.php` — 20/79 survivors no Arch full | field run + YAMLs |
| `mass-assignment` | `app/**/*.php` — gruda em Services; FP em Filament models | YAML + patterns-recall |
| R4 signals | Quase todos `app/**/*.php` (matcher largo → units gordas) | YAMLs |
| Package já tem | `--focus=contractor`, `--min-critique-score`, `AnalyzeOptions::CONTRACTOR_KEYS` | `AnalyzeOptions.php` |
| Work order metadata | `focus`, `min_critique_score` (sem file list/SHAs) | `AnalyzeRunner::buildWorkOrder` |
| Matcher | OR de path/import/directory signals; **sem** content signal | `PatternMatcher` / `DetectionSignal` |

---

## Phase A — Item 1: Defaults skill + escopo PR-diff + parity emit/ingest

**Outcome:** Invocar `/codeguard-review` sem flags extras revisa o **diff relevante** com **focus contractor**, não sobrescreve work order útil com emit vazio, e **ingest reavalia o mesmo file set** que o emit.

### A1. Skill defaults (markdown procedure)

**File:** `resources/skills/codeguard-review/SKILL.md`

| Situação | Comportamento novo |
|----------|-------------------|
| User não especifica focus | `--focus=contractor` + `--critique` (samples=1 default; samples=3 só se user pedir “gate duro” / “voting”) |
| User não especifica scope | Preferir **PR-diff** se detectável (`--base=…`); senão `changed-only` |
| User pede full / inventário | `--focus=full` e/ou `--all` / `--path=…` explícitos |
| Ingest | **Não re-adivinhar scope.** Preferir `work_order.scope` (file list + SHAs). Flags CLI de focus/min-critique só se work order não tiver os campos. |
| Emit vazio (0 units) | Package aborta se `--out` existente tem units>0 (A3); skill reporta opções (`--path`, base branch, full) |

### A2. Escopo PR-diff no package

**Files:**

- `src/Analyze/FileScopeResolver.php` — `againstBase(string $baseRef): array`
- `src/Commands/CodeguardAnalyzeCommand.php` — flag `--base=`
- `tests/Unit/Analyze/FileScopeResolverTest.php`
- `tests/Feature/CodeguardAnalyzeCommandTest.php`

**API (F-002 applied):**

```text
--base=origin/main
```

```php
/**
 * Files = union of:
 *   1) git diff --name-only --diff-filter=ACMR $baseRef...HEAD
 *   2) git diff --name-only --diff-filter=ACMR --cached   (staged)
 *   3) git diff --name-only --diff-filter=ACMR            (unstaged vs HEAD)
 * then filter *.php + file_exists.
 *
 * Rationale (codex F-002): base...HEAD ∪ staged alone drops dirty unstaged
 * edits and produces silently incomplete contractor reviews.
 */
public function againstBase(string $baseRef): array
```

**Optional flag (committed-PR-only):**

```text
{--committed-only : With --base, exclude unstaged/staged working-tree; only base...HEAD}
```

Default **without** `--committed-only`: include unstaged+staged (union above).  
With `--committed-only`: only `base...HEAD` (CI/PR SHA review).

**Resolução de scope na skill (ordem):**

1. User pediu `--path` / `--all` → honrar  
2. User/skill pediu `--base=…` → `againstBase`  
3. Env/git: default branch detectável → `--base=origin/<default>`  
4. Fallback `changed-only`  
5. Se 0 files/units: mensagem clara + opções (não “Working tree clean” como sucesso de review)

**CLI package:**

```text
{--base= : Diff against this git ref (e.g. origin/main). Overrides default changed-only when set.}
{--committed-only : With --base, only committed commits on the branch (ignore dirty worktree).}
```

Prioridade: `--path` > `--all` > `--base` > changed-only.

### A3. Proteção anti-overwrite (**package**, F-001)

**Não** é skill-only. O write path é `CodeguardAnalyzeCommand::handleEmit`.

**Files:**

- `src/Commands/CodeguardAnalyzeCommand.php`
- Feature test

**Comportamento:**

1. Build work order **in memory** first.  
2. If `--out` path exists and decodes as JSON with `count(units) > 0` **and** new work order has `count(units) === 0` **and** `--force` is false → **abort** (exit failure), do **not** write.  
3. Message: existing work order preserved; pass `--force` to overwrite, or fix scope.  
4. Skill documents the behavior; does not reimplement the count check beyond calling emit.

```text
{--force : Allow overwriting an existing non-empty work order with an empty emit}
```

### A4. Work order `scope` object (F-004) — **required for emit/ingest parity**

**Files:**

- `src/Analyze/AnalyzeRunner.php` (`buildWorkOrder`)
- `src/Commands/CodeguardAnalyzeCommand.php` (emit + ingest)
- Unit/feature tests

**Shape (v1):**

```json
{
  "focus": "contractor",
  "min_critique_score": 4,
  "scope": {
    "mode": "base|changed_only|path|all",
    "base_ref": "origin/main",
    "committed_only": false,
    "path": null,
    "head_sha": "abc…",
    "merge_base_sha": "def…",
    "files": ["/abs/app/Services/Foo.php", "…"]
  },
  "units": [ … ]
}
```

**Ingest rules:**

1. If `scope.files` is a non-empty list → use **that** absolute file list (after still-exists filter); do **not** re-run git scope.  
2. If `head_sha` present and current `HEAD` ≠ recorded → **warn**; fail unless `--allow-scope-drift`.  
3. Focus / min_critique: prefer work order fields; CLI may override only with explicit flags.  
4. Skill Step 6: always pass the **same** `--out` request path context; ingest reads scope from request JSON if findings JSON doesn't carry it (or embed scope copy into findings envelope optionally — v1: re-read request file path convention `.codeguard/analyze-request.json`).

```text
{--allow-scope-drift : Ingest even if HEAD SHA differs from work order scope.head_sha}
```

### A5. Config default (unchanged product decision)

**Recomendado: B** — skill defaults contractor; `config` `patterns.focus` permanece `full` até release notes 0.3.0.

### Acceptance A

- [ ] SKILL.md: defaults contractor + critique; samples=3 opt-in  
- [ ] SKILL.md: scope order; ingest uses `scope.files`; documents package anti-overwrite  
- [ ] `againstBase` = base…HEAD ∪ staged ∪ unstaged (default); `--committed-only` tested  
- [ ] Feature tests: committed / staged / **unstaged** PHP under `--base`  
- [ ] Emit aborts overwrite empty→nonempty without `--force`  
- [ ] Work order includes `scope` with files + head_sha  
- [ ] Ingest uses `scope.files`; fails or warns on SHA drift without `--allow-scope-drift`  

### Effort A

~2–2.5d (subiu por A3 package + A4 scope object; codex).

---

## Phase B — Item 2: Report acionável + checklist

**Outcome:** Depois do ingest, o user vê **decisão de PR**, não só lista plana; skill gera checklist copiável.

### B1. Taxonomia de ação no package

**New:** `src/Analyze/FindingAction.php` (enum)

```php
enum FindingAction: string {
    case Block = 'block';
    case RequestChange = 'request_change';
    case Info = 'info';
}
```

**Policy (v1, data-driven, testável):**

| Condição | Action |
|----------|--------|
| pattern ∈ {`raw-sql-injection`, `missing-authorization`, `mass-assignment`} e severity critical | **block** |
| pattern ∈ {`missing-database-transaction`} e critique≥4 ou uncritiqued | **request_change** |
| pattern ∈ {`eloquent-n-plus-one`, `unbounded-query`} e critique≥4 | **request_change** |
| pattern ∈ {`service-layer`, `layer-dependency-direction`, `bounded-contexts`, `no-circular-dependencies`} | **request_change** |
| else | **info** |

Classe pure: `FindingActionClassifier`.

### B2. Enriquecer `AnalyzeResult` / render

**Files:**

- `src/Analyze/AnalyzeResult.php` — opcional: getter grouped by action  
- `src/Commands/CodeguardAnalyzeCommand.php` — `renderFindings()`  
- tests unitários do classifier + feature do output  

**Formato CLI:**

```text
## BLOCK (1) — do not merge until fixed
  ✗ app/Services/Foo.php:109 · raw-sql-injection · … (0.67) [score 8/10]

## REQUEST CHANGE (12)
  ⚠ …

## INFO (0)
  …

Checklist (markdown):
- [ ] **BLOCK** `Foo.php:109` — raw-sql-injection — <one-line fix hint from message>
…

N finding(s) across M checks. block=1 request_change=12 info=0
```

v1: sempre imprime seções + checklist no final.

### B3. Skill Step 7 rewrite

Após ingest:

1. Usar seções do package (não reimplementar policy na skill)  
2. Resumir block / request / info counts  
3. Colar checklist markdown  
4. Oferecer: abrir primeiro BLOCK; re-run **mesmo work order scope** / paths após fix  
5. **Não** propor fix automático em massa  

### B4. Exit code (optional follow-up)

```text
--fail-on-action=block   # exit 1 se qualquer BLOCK
```

v1 mínimo: `--fail-on` severity permanece; checklist é o valor principal.  
**Phase B.1** se sobrar tempo.

### Acceptance B

- [ ] `FindingActionClassifier` + testes de tabela  
- [ ] CLI imprime `## BLOCK` / `## REQUEST CHANGE` / `## INFO`  
- [ ] Checklist markdown com path:line e pattern  
- [ ] SKILL Step 7 referencia o novo report  
- [ ] Feature test captura substrings das seções  

### Effort B

~1–1.5d package + skill.

---

## Phase C — Item 3: Corpus vs PHPStan + mass-assignment Filament

### Outcome (F-003 applied — invariante único)

**v1 invariant (escolhido):**

1. Patterns de overlap PHPStan/clean-code genérico levam `classification: hygiene`.  
2. **`--focus=full` exclui `hygiene` por default** (deixa de afogar em types).  
3. **`--include-hygiene`** restaura inventário completo (types, dry, small-functions, …).  
4. **`--focus=contractor`** continua allowlist G3 (já exclui hygiene por keys).  
5. Atalho opcional `--exclude-hygiene` = no-op quando full já exclui; ou alias documentado.

**Não** deixar “full ainda inclui hygiene + flag opcional exclude” como DoD — isso contradizia o outcome (codex F-003).

### C1. Inventário de overlap PHPStan

| Pattern | Overlap | Ação v1 |
|---------|---------|---------|
| `type-declarations` | PHPStan | `classification: hygiene` |
| `strict-typing` | sniffs / CS | `classification: hygiene` |
| `dry`, `small-functions`, `few-arguments`, `no-constructor-many-params` | clean-code genérico | `classification: hygiene` |
| `no-debug-functions` | disallowed-calls | **não** hygiene — permanece em full |
| `no-superglobals` | PHPStan | manter em full (raro); **não** hygiene v1 |

**Files:**

- YAMLs listados  
- `AnalyzeOptions` / runner filter por `excludeClassifications`  
- Default para `full`: `excludeClassifications = ['hygiene']`  
- CLI `--include-hygiene` limpa essa exclusão  
- config: `patterns.include_hygiene` env opcional  

**Testes:**

- emit full: units **não** contêm `type-declarations` / `strict-typing`  
- emit full + `--include-hygiene`: contêm  
- contractor: inalterado (allowlist)  

### C2. mass-assignment Filament-aware (**write-site v1**, F-005 applied)

**Decisão v1:** **sem** content-body signal / `all_of` matcher (fora de escopo).

**Selection (path OR only):**

```yaml
detection:
  signals:
    - type: file
      value: "app/Http/Controllers/**/*.php"
    - type: file
      value: "app/Livewire/**/*.php"
    - type: file
      value: "app/Filament/**/*.php"
    - type: file
      value: "app/Nova/**/*.php"
    - type: directory
      value: app/Http/Controllers
  # NÃO: app/**/*.php
  # NÃO: app/Models/** no v1 (content-dependent unguard deferred)
```

**verification.rules (adjudicação, não selection):**

```text
- Prefer findings at the write site (create/update/fill with request data), not at model $fillable alone
- In Filament/Nova apps, form schema / Resource fields are the write boundary — do NOT flag absence of issues solely because a model has $fillable
- Do NOT require selecting Model files for $guarded = [] in v1; if unguard appears in a selected controller/Livewire/Filament file, flag that line
```

**Deferred (pós v1 / follow-up plan):** content signal + model `$guarded = []` / `Model::unguard()` selection tests — requires matcher work.

**Acceptance tests v1:**

| Fixture | Match mass-assignment? |
|---------|------------------------|
| Controller `create($request->all())` | **yes** |
| Livewire/Filament action com fill request | **yes** (path signal) |
| Model só `$fillable` / `$guarded = []` em `app/Models` | **no** (not selected) |
| Service sem HTTP write site | **no** (not selected) |

5. Atualizar `docs/patterns-recall.md` — mass-assignment Filament: write-site only; model-level deferred.

### Acceptance C

- [ ] YAMLs hygiene marcados (lista C1)  
- [ ] `full` exclui hygiene por default; `--include-hygiene` restaura  
- [ ] Feature tests full ± include-hygiene  
- [ ] mass-assignment signals write-site only (sem Models/**)  
- [ ] Testes seleção: controller yes; model-only no  
- [ ] patterns-recall atualizado  

### Effort C

~1d YAML + filter + tests.

---

## Phase D — Item 4: Signals R4 mais precisos

**Outcome:** Menos patterns por unit nos hot paths; R4 ainda pega âncoras Arch conhecidas.

### D1. Tabela alvo de signals

| Pattern | Signals atuais | Signals propostos v1 |
|---------|----------------|----------------------|
| `raw-sql-injection` | `app/**`, `database/**` | `app/Services/**`, `app/Http/**`, `app/Actions/**`, `app/Jobs/**`, `database/**` |
| `eloquent-n-plus-one` | `app/**`, Resources | `app/Services/**`, `app/Http/**`, `app/Jobs/**`, `app/Actions/**`, `app/Filament/**`, Resources |
| `missing-database-transaction` | `app/**`, Actions | `app/Services/**`, `app/Actions/**`, `app/Http/Controllers/**`, `app/Jobs/**` |
| `unbounded-query` | `app/**`, Controllers | `app/Services/**`, `app/Http/**`, `app/Jobs/**`, `app/Console/**` |
| `missing-authorization` | Controllers | Controllers + `app/Filament/**` + `app/Livewire/**` + `app/Nova/**` |
| `mass-assignment` | ver C2 | write-site paths only (C2) — **no** Models/** |

### D2. Matcher semantics

Hoje signals são **OR**. v1: **só globs melhores**.  

**Não** content signal / AND groups (F-005 deferred).

### D3. Regression harness

**Files:**

- `tests/Unit/Analyze/PatternSelectionCoverageTest.php`  
- Fixtures sob `tests/Fixtures/patterns/` se necessário  

| Fixture | Deve match |
|---------|------------|
| Service com `DB::select("... {$var}")` | raw-sql-injection |
| Service com query em foreach | eloquent-n-plus-one |
| Service multi-save sem transaction | missing-database-transaction |
| `Model::all()` em service | unbounded-query |
| Controller store sem authorize | missing-authorization |
| Controller `create($request->all())` | mass-assignment |
| Model só `$fillable` | **não** mass-assignment |
| DTO / value object | **não** R4 |

**Acceptance gate:** âncoras `docs/patterns-recall.md` (ElectronicFilling SQL/TX, EmployeeSync N+1) ainda matcham sob `app/Services/...`.

### D4. Skill note

Documentar: contractor + signals apertados = units menores → batches mais baratos; ingest usa `scope.files`.

### Acceptance D

- [ ] 6 YAMLs R4 com signals atualizados  
- [ ] Selection coverage tests verdes  
- [ ] Smoke emit contractor: fixture SQL service ainda anexa raw-sql-injection  
- [ ] patterns-recall nota “signals tightened 2026-07-29”  

### Effort D

~1d (junto com C se mesma PR).

---

## Ordem de implementação (DAG)

```
A1 skill defaults
A2 againstBase (+ unstaged) ──► tests
A3 package anti-overwrite + --force ──► feature test
A4 scope object no work order + ingest reuse ──► feature test
        └── done Phase A

B1 classifier ──► B2 CLI render ──► B3 skill Step 7 ──► done Phase B

C1 hygiene + full excludes by default + --include-hygiene
C2 mass-assignment write-site only
D1–D3 R4 signals + selection tests
        └── done Phase C+D
```

**Merge order:**

1. **PR1:** Phase A (skill + base + anti-overwrite + scope parity)  
2. **PR2:** Phase B (report + checklist)  
3. **PR3:** Phase C+D (corpus)

---

## Test plan global

| Camada | O quê |
|--------|--------|
| Unit | againstBase unions; anti-overwrite logic; scope encode/decode; classifier; hygiene filter; R4 selection fixtures |
| Feature | `--base` unstaged; emit empty abort; ingest uses scope.files; BLOCK section; full ± include-hygiene |
| Manual | Arch path-repo: contractor + base + skill + checklist |
| Recall | patterns-recall defaults + signals + mass-assignment write-site |

**Suite:** pest nos paths; coverage ≥80% se tocar código no gate.

---

## Riscos

| Risco | Mitigação |
|-------|-----------|
| `--base` frágil em shallow clone | Fallback changed-only + mensagem |
| Signals apertados perdem âncora Arch | Fixtures + smoke Services paths |
| hygiene exclusion esconde no-debug | `no-debug-functions` **não** é hygiene |
| Classifier block demais | Só 3 patterns security no block v1 |
| Model-level unguard não detectado no v1 | Aceito (F-005); documentar deferred |
| Scope file list stale se user edita após emit | SHA check + `--allow-scope-drift` |
| Skill-only guards | **Proibido** para anti-overwrite (F-001) |

---

## Definition of Done (1–4)

1. Skill defaults contractor + critique; PR/base-aware; package anti-overwrite; Step 7 checklist.  
2. CLI imprime BLOCK / REQUEST CHANGE / INFO + checklist.  
3. **full** exclui hygiene por default; `--include-hygiene` opt-in; mass-assignment **write-site only**.  
4. R4 signals apertados + testes de âncoras.  
5. Work order `scope` + ingest parity.  
6. Docs: plan status Implemented; patterns-recall + SKILL + README analyze.  
7. Arch dogfood opcional.

---

## Estimativa total

| Phase | Effort |
|-------|--------|
| A | 2–2.5d |
| B | 1–1.5d |
| C+D | 1.5–2d |
| **Total** | **~5–6d** focused |

---

## Codex findings → plan mapping

| ID | Sev | Resolução no plano |
|----|-----|-------------------|
| F-001 | major | A3: anti-overwrite **no package** + `--force` |
| F-002 | major | A2: `againstBase` ∪ unstaged (+ `--committed-only`) |
| F-003 | major | C outcome: **full excludes hygiene by default** + `--include-hygiene` |
| F-004 | major | A4: `scope` object + ingest reuses `files` / SHA check |
| F-005 | critical | C2 v1: **write-site only**; content matcher deferred out of scope |

---

## Checklist de execução

```
[x] Phase A1 — SKILL.md defaults
[x] Phase A2 — againstBase ∪ unstaged + --committed-only + tests
[x] Phase A3 — package anti-overwrite + --force + feature test
[x] Phase A4 — work order scope + ingest reuse + SHA drift
[x] Phase B1 — FindingAction + Classifier + tests
[x] Phase B2 — renderFindings sections + checklist
[x] Phase B3 — SKILL Step 7
[x] Phase C1 — hygiene classification + full default exclude + --include-hygiene
[x] Phase C2 — mass-assignment write-site signals + rules
[x] Phase D  — R4 signals + selection fixtures
[x] patterns-recall + plan status → Implemented
[x] pest suite verde nos paths (536 passed)
```
