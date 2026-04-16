# CodeGuard — Instructions for Claude

Você está no repositório do CodeGuard. Antes de fazer qualquer coisa substantiva:

## Context Loading Obrigatório

1. **Leia `.ai/memory/MEMORY.md`** — índice de toda memória acumulada (goals do usuário, decisões, reviews, problemas)
2. **Leia `docs/specs/2026-04-16-codeguard-v2-architecture.md`** — design atual (v4 pós-10 reviews)
3. **Leia `docs/specs/2026-04-16-pivot-npm-to-composer.md`** — por que abandonamos Node e pivotamos para PHP/Composer

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

Após carregar o contexto, o próximo passo lógico é:
1. Criar `composer.json` inicial
2. Criar `src/CodeguardServiceProvider.php` esqueleto
3. Começar extract do Arch (TestSuiteRunner primeiro)

Veja `.ai/memory/conversation-handoff.md` para onde paramos especificamente.
