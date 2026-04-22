# CodeGuard — Instructions for Claude

Você está no repositório do CodeGuard. Antes de fazer qualquer coisa substantiva:

## ⭐ Fonte canônica de estado

**LEIA PRIMEIRO**: [`.ai/memory/PROJECT-STATUS.md`](.ai/memory/PROJECT-STATUS.md) — snapshot sincrônico do projeto (sprint atual, próxima ação concreta, scorecard, riscos). **Atualize esse arquivo ao terminar qualquer commit que mude escopo.** Protocolo de update está no rodapé do próprio arquivo.

Em caso de conflito entre `PROJECT-STATUS.md` e outro arquivo de memória, **o status ganha**. Corrija o outro arquivo pra alinhar; não edite o status pra "concordar com" dado stale.

## Context Loading Obrigatório

Depois de ler o status, carregar conforme necessidade:

1. **`.ai/memory/MEMORY.md`** — índice de toda memória acumulada (goals, decisões, reviews, problemas)
2. **`docs/specs/2026-04-16-codeguard-v2-architecture.md`** — design arquitetural v5 (canônico)
3. **`docs/specs/2026-04-16-pivot-npm-to-composer.md`** — por que abandonamos Node e pivotamos para PHP/Composer
4. **`docs/specs/2026-04-17-captainhook-migration-and-telemetry.md`** — Phases A/B/C + schema telemetria (para trabalho de hooks/telemetry)

## Contexto Resumido

- **Estado atual**: repo pivotou de Node/TypeScript (v0.1.1 npm package) para **PHP/Laravel Composer package**
- **Estado Node preservado em**: branch `v0-npm-archive` + tag `v0-last-npm` + npm registry `@henryavila/codeguard@0.1.1`
- **Meta do usuário**: padronizar quality gates em múltiplos projetos PHP/Laravel, rodar em múltiplas máquinas, controlar qualidade de dev terceirizado que NÃO usa IA
- **Decisão fundamental**: 2 packages (não 3), Node-free, Composer + Claude plugin
- **Approach**: dual-track — Arch (projeto real) já consome package via path repository durante desenvolvimento

## Estrutura

```
~/codeguard/
├── .ai/memory/           → context persistente (LEIA SEMPRE)
├── docs/
│   ├── specs/            → design docs (arquitetura + decisões)
│   └── legacy/           → artefatos da era Node (preservados)
├── resources/
│   ├── patterns/         → 28 YAMLs portados da v0 (data contract)
│   ├── rules/            → canonical AI rules markdown
│   ├── skills/           → Claude skills (setup, run, health)
│   └── stubs/            → PHPStan/Pint/Deptrac stubs (a criar)
├── src/                  → código PHP (a criar via extract do Arch)
├── tests/                → Pest tests (a criar)
└── composer.json         → a criar
```

## Regras Universais

- **NUNCA reintroduza Node.js** — decisão arquitetural após 10 reviews. Se precisar de JS/Node para algo, questione primeiro.
- **Trabalhe com o Arch como laboratório** — `/home/henry/arch` é o primeiro consumidor real; use path repository
- **Declare `declare(strict_types=1)`** em todo arquivo PHP
- **PHP 8.3+** como mínimo (match, readonly, enum)
- **Pest 4** para testes
- **Composer scripts** para tooling (não bash ad-hoc)
- **Semver** rigoroso — v0.x para dev, v1.0 quando estável em 2+ projetos reais

## Quando continuar a conversa

**Próxima ação sempre vem de `.ai/memory/PROJECT-STATUS.md` → seção "Próxima ação concreta".** Esse é o campo autoritativo — não tente re-derivar do spec ou do handoff.

Complementos narrativos: `.ai/memory/conversation-handoff.md` (log cronológico por sessão).
