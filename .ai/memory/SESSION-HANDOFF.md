---
name: Session handoff (2026-06-03)
description: Onde paramos e como continuar o Patterns engine numa sessão nova. Ler DEPOIS do PROJECT-STATUS.md.
type: project
---

# Handoff — 2026-06-03 (sessão "audit → replan → Patterns engine")

> Leia `PROJECT-STATUS.md` primeiro (estado canônico). Este arquivo é o **plano + narrativa** desta sessão pra continuar sem perder contexto.

## O que rolou (de "repo parou no meio" até aqui)

1. **Audit profundo** (workflow multi-agente): a memória canônica estava errada em 4 fatos load-bearing (já corrigidos). O package era um "encanamento sem nada confiável passando".
2. **Replan colaborativo** + decisões do usuário:
   - **Constraint dura**: NÃO tocar no Arch (projeto grande em dev lá). Tudo package-side; integração Arch = última fase.
   - **A primeiro** (Patterns engine = o diferencial).
   - **Transporte LLM = context-emit** (assinatura Claude Code, SEM API metered). `claude -p` está **fora** (vira API metered no próximo mês). `anthropic-ai/sdk` está fora (metered).
   - **Reverter o "AI findings never baselined"** (explícito + auditável).
3. **Shippado nesta sessão** (branch `feat/patterns-engine-foundation`, PR #1, **pushed e sincronizado** em `ddc8d61`):
   - `4c662a0` Fase 1 — assertion traits reais (`AntiPatternScanner`); eram landmine que lançava exception.
   - `0dfb953` Patterns engine MVP (`src/Analyze/*` + `codeguard:analyze`, Thin Adjudicator).
   - `18c4492` context-emit (`--emit`/`--ingest` + skill `codeguard-review`; removeu 3 skills Node-era → fechou R11).
   - `abfce20` **trust threshold (Tier 0+1)** — atribuição exata, use-parsing real, baseline.
   - `a3202fb` `f8a7e0e` `cdca3b5` `ddc8d61` — **Tier 2 R1–R4** (ver seção dedicada abaixo).
   - + commits docs.
   - Suite **493 verdes / 1175 assertions**, Pint clean, PHPStan level 5 No errors.

## Arquitetura do Patterns engine (já construída)

Package = harness determinístico; Claude Code (assinatura) = cérebro.
`codeguard:analyze` modos: review síncrono (NullLlmClient → aviso de degradação honesto) · `--emit` (work order JSON) · `--ingest=<file>` (valida findings no trust boundary + gate `--fail-on`) · `--accept` (baseline).
Fluxo real = skill `codeguard-review`: emit → fan-out de subagentes **em lotes** (decisão do usuário) → merge → ingest.
Classes em `src/Analyze/`: Severity, Pattern, DetectionSignal, PatternRepository, YamlPatternLoader, FileScopeResolver, PatternMatcher, **PhpFileInspector** (use-parsing), FindingSchema, **PatternMatch** (trust boundary), AnalyzeResult, AnalyzeRunner, **AnalyzeBaseline**, LlmClient + NullLlmClient.

## Tier 0+1 (trust threshold) — FEITO em `abfce20`

Incorpora as correções do crítico adversarial do workflow `patterns-engine-completeness`:
- **T5 atribuição exata** — `findUnit` casa path absoluto exato; basename só se inequívoco (dois `User.php` não cruzam mais).
- **T2 use-parsing real** — `PhpFileInspector` (regex no head, zero-dep) → sinais `import` casam os `use` reais (namespace-glob). `import: **/*` (os 3 patterns arquiteturais) **excluído** da seleção per-file até o grafo (R3). Patterns de estrutura de classe gated a arquivos com classe.
- **T4 baseline** — `AnalyzeBaseline`, `--accept`, mostra "N suprimidos". Fingerprint = `sha1(pattern_key + arquivo_relativo)` — **sem mensagem, sem linha** (correção do crítico: senão o LLM reformula e o finding ressurge).
- **Teste de cobertura de seleção** (parte honesta automatizável) + `docs/patterns-recall.md` (recall manual).

## Tier 2 — TODOS shippados (mesma sessão, 4 commits)

Construído depois do trust threshold; PR #1 atualizado e pushed (`origin == HEAD ddc8d61`). Mecânica 100% testada em CI; **qualidade do julgamento NÃO**.

- **R1 voting multi-sample** (`a3202fb`) — `FindingVoter` agrega k samples (identidade `pattern_key|file|line`; dup no mesmo sample = 1 voto), mantém ≥`ceil(2k/3)`, confiança = vote-share (NÃO a verbalizada). `--samples=k` (cap 1–9) no emit; ingest detecta envelope `{samples:[[...]]}` vs `{findings:[...]}` legado (backward-compat). `ingestSamples()` valida cada sample no trust boundary ANTES de votar.
- **R2 critique pass** (`f8a7e0e`) — `verified_score` 0–10 opcional no FindingSchema/PatternMatch; `surviveCritique()` dropa score 0 em `finish()` (uniforme aos 3 paths). `--critique` flag → `critique:true` no work order. Compõe com voting (vota → dropa 0). Display `[score N/10]`.
- **R3 grafo namespace→layer** (`cdca3b5`) — `NamespaceGraph` parseia use-edges first-party (vendor fora) → adjacência + cycle detection (DFS back-edge). `PhpFileInspector::fqcn()`. `matcher->graphLevel()/isGraphLevel()` (catch-all import = arquitetural). Work order emite `graph{nodes,edges,cycles}` + `architecture.patterns`. Ingest cria architectural unit por class-file scoped sem per-file unit → atribui os 3 patterns arquiteturais via trust boundary. `related_file` opcional.
- **R4 corpus alto-impacto** (`ddc8d61`) — 6 YAMLs em `resources/patterns/php-laravel/`: mass-assignment, raw-sql-injection, missing-authorization (critical/gate), eloquent-n-plus-one, missing-database-transaction, unbounded-query (warning/report). Signals file/directory (nunca catch-all import → ficam per-file). Corpus 28→34.

Skill `codeguard-review` atualizada com Steps de voting (4 + envelope samples), critique (5b) e architecture (4b).

## PRÓXIMO (em ordem)

1. **Validação de campo** — o ÚNICO gap restante do Track A. Rodar `/codeguard-review` num projeto real (idealmente `--samples=3 --critique` + `--all` pro grafo) e preencher `docs/patterns-recall.md` (já tem tabela R4 priorizada). ⚠️ recall/precision NÃO é testável em CI — só sessão Claude Code com assinatura.
2. **Revisar/mergear PR #1** (decisão do usuário).
3. **Backlog**: `coverage_percent -1` (`CodeguardTestCommand.php:102`); config morto `ai_rules`/`prepare`; Fase 3 (schema dump + ai-rules generator); Fase 4 (Arch, quando liberado).

## NÃO construir (decidido)
API metered como caminho default · embeddings p/ dry · calibrador de confiança (derive de voto) · cache de resultado · `--format=github` (sem CI confirmado) · auto-fix · UI de config por-pattern. Re-scope agressivo dos patterns Laravel "invertidos" = adiado (risco de FP, precisa campo).

## Docs de referência
- `docs/specs/2026-06-03-patterns-engine-design.md` — design Thin Adjudicator.
- Roadmap de completude (Tier 0+1 + Tier 2 + forks A/B/C) — saiu do workflow `patterns-engine-completeness` desta sessão; o essencial está resumido acima.
