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
3. **Shippado nesta sessão** (branch `feat/patterns-engine-foundation`, PR #1, 6 commits):
   - `4c662a0` Fase 1 — assertion traits reais (`AntiPatternScanner`); eram landmine que lançava exception.
   - `0dfb953` Patterns engine MVP (`src/Analyze/*` + `codeguard:analyze`, Thin Adjudicator).
   - `18c4492` context-emit (`--emit`/`--ingest` + skill `codeguard-review`; removeu 3 skills Node-era → fechou R11).
   - `abfce20` **trust threshold (Tier 0+1)** — ver abaixo. ⚠️ **não pushed ainda**.
   - + 2 commits docs.
   - Suite **452 verdes / 1090 assertions**, Pint clean, PHPStan level 5 No errors.

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

## PRÓXIMO (em ordem)

1. **`git push`** (`abfce20` não pushed → atualiza PR #1).
2. **Validação de campo**: rodar `/codeguard-review` num projeto real, preencher `docs/patterns-recall.md`. ⚠️ **A qualidade do julgamento NÃO é testável em CI** (assinatura, sem API metered) — só com sessão Claude Code real. Toda melhoria de precisão (Tier 2) só se valida à mão.
3. **Tier 2 — profundidade (~10d, "genuinamente alto valor")**, nesta ordem (cada um reusa infra do anterior):
   - **R1 voting multi-sample** (~2,5d): emitir k=3, manter findings ≥2/3, derivar confiança de vote-share (NÃO da confiança verbalizada — é miscalibrada). Default `--samples=1`, opt-in `--samples=3` (Fork A).
   - **R2 critique pass** (~2d): 2º subagente re-pontua 0–10, dropa 0. `verified_score` no FindingSchema.
   - **R3 grafo namespace→layer** (~3d): parsear `use` edges (reusa PhpFileInspector) num mapa de adjacência; emitir no work order; ligar de verdade bounded-contexts/layer-dependency-direction/no-circular-dependencies (hoje excluídos). `related_file` opcional no FindingSchema.
   - **R4 corpus de alto impacto** (~3d): N+1, mass-assignment (`Model::create($request->all())`), missing transaction, SQL cru/`DB::raw` interpolado, missing authz em writes, `->get()` sem limite. Invisível ao AST, no centro da meta G3.
4. **Backlog menor**: `coverage_percent -1` (`CodeguardTestCommand.php:102`); config morto `ai_rules`/`prepare`; Fase 3 (schema dump + ai-rules generator).

## NÃO construir (decidido)
API metered como caminho default · embeddings p/ dry · calibrador de confiança (derive de voto) · cache de resultado · `--format=github` (sem CI confirmado) · auto-fix · UI de config por-pattern. Re-scope agressivo dos patterns Laravel "invertidos" = adiado (risco de FP, precisa campo).

## Docs de referência
- `docs/specs/2026-06-03-patterns-engine-design.md` — design Thin Adjudicator.
- Roadmap de completude (Tier 0+1 + Tier 2 + forks A/B/C) — saiu do workflow `patterns-engine-completeness` desta sessão; o essencial está resumido acima.
