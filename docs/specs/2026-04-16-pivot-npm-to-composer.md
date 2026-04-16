# Spec: Pivot Node/npm → PHP/Composer

**Data**: 2026-04-16
**Status**: Approved — executing
**Autor**: Henry Avila + Claude (Opus 4.7)
**Decisão**: Abandonar `@henryavila/codeguard` npm package em favor de `henryavila/codeguard` Composer package

---

## Resumo Executivo

Após 10 agent reviews (6 adversariais + 4 steelman), o projeto pivota de Node/TypeScript para PHP/Composer como stack principal. O npm package v0.1.1 é preservado (branch + tag + registry) mas descontinuado como foco ativo.

## Contexto

### Por que o pivot?

1. **Stack real do usuário é PHP/Laravel/Vue** — não Node
2. **npm core era "agnostic" em teoria, Laravel-only em uso** — review feedback convergiu
3. **hook runner Node.js (50MB+) sem ganho proporcional** — Composer scripts + symfony/process fazem o mesmo
4. **Metas reais do usuário não requerem Node**:
   - Replicar padrão entre projetos PHP/Laravel
   - Rodar em múltiplas máquinas (containers Alpine, WSL, Windows)
   - Controlar dev terceirizado que não usa IA

### Evidência das reviews

- **Agent 6 (Competitive)**: "Language-agnostic core é DEAD. MegaLinter ships 100+ linters, hermetic. Claiming agnosticism with PHP only is marketing, not architecture."
- **Agent 4 (Adoption)**: "2 installs é dealbreaker. Composer-only = 1 comando."
- **Agent 10 (Steelman Multi-stack)**: "Composer package sozinho entrega 95% do valor para Arch-class projects."
- **Agent 3 (Pattern System)**: "Pattern engine pode ser PHP nativo — `symfony/yaml` faz o loader, zero Node necessário."

## O que muda

### Stack
| Antes (Node) | Depois (PHP) |
|--------------|--------------|
| TypeScript + tsdown | PHP 8.3+ |
| Vitest | Pest 4 |
| Node 20+ runtime | Zero runtime extra (Laravel já tem PHP) |
| `npx codeguard install` | `composer require && php artisan codeguard:install` |
| `src/core/patterns/loader.ts` | `src/Patterns/PatternLoader.php` (symfony/yaml) |
| `bin/codeguard.js` CLI | Artisan commands (`codeguard:*`) |
| Node hook runner | Composer scripts + symfony/process |
| 50MB node_modules | 0 Node dependency |

### Arquitetura
```
ANTES:
@henryavila/codeguard (npm, agnostic core)
  └─ hooks, patterns, CLI, skills, baselines, IDE deployer

DEPOIS:
henryavila/codeguard (Composer, Laravel-first)
  ├─ TestSuiteRunner + assertions + stubs
  ├─ Pattern engine PHP + 28 YAMLs (data contract)
  ├─ AI rules multi-tool generator
  ├─ Schema dump multi-DB (killer feature)
  ├─ Claude skills embarcadas
  └─ Companion packages futuros (symfony, python, etc)

henryavila/codeguard-hooks (Claude plugin)
  └─ bash hooks only (config-protection + pre-commit-phpstan + stop + post-lint)
```

### Preservação do Node era
- **Branch**: `v0-npm-archive` em `github.com/henryavila/codeguard`
- **Tag**: `v0-last-npm` no último commit Node
- **npm registry**: `@henryavila/codeguard@0.1.1` continua publicado (não deprecated formalmente)
- **Assets migrados** para novo layout:
  - `modules/core/patterns/*.yaml` → `resources/patterns/core/`
  - `modules/php/patterns/*.yaml` → `resources/patterns/php/`
  - `modules/php-laravel/patterns/*.yaml` → `resources/patterns/php-laravel/`
  - `skills/*` → `resources/skills/`
  - Node implementation → deletada (recuperável via `v0-npm-archive`)

## O que NÃO muda

- **Nome do projeto**: CodeGuard continua
- **Repo GitHub**: `github.com/henryavila/codeguard` reutilizado
- **Brand**: tagline atualizada mas identidade preservada
- **Licença**: MIT
- **28 patterns YAML**: data contract intacto (migra como resource)
- **AI rules multi-tool**: gerador reescrito em PHP mas mesma functionality
- **Princípio extensibility**: agora via "companion packages nativos" em vez de "agnostic core"

## Extensibilidade Futura (sem Node)

```
Nivel 1 (agora): Laravel + Vue
└── henryavila/codeguard

Nivel 2 (6-12 meses): Outras stacks PHP
├── henryavila/codeguard                  (core + Laravel preset)
├── henryavila/codeguard-symfony          (companion)
├── henryavila/codeguard-wordpress        (companion)
└── henryavila/codeguard-filament         (sub-preset)

Nivel 3 (1-2 anos): Outras linguagens
├── henryavila/codeguard                  (PHP-native)
├── henryavila-codeguard-python (PyPI)   (Python-native, reusa patterns YAML)
├── henryavila-codeguard-node (npm)       (SE houver demanda — plot twist)
└── henryavila-codeguard-rust (cargo)     (SE houver demanda)
```

**Princípio**: "Core é YAML contracts; implementações são nativas a cada linguagem."

## Migration Path para Usuários npm (se existirem)

Há zero usuários públicos conhecidos do v0.1.1 npm package. Mensagem para quem aparecer:

> CodeGuard v1.0 é uma reescrita completa em PHP/Composer. Se você precisa do v0.x Node version, use `@henryavila/codeguard@0.1.1` (continua disponível no npm). Migração automática não é suportada — arquiteturas divergem fundamentalmente.

## Timeline

| Data | Milestone |
|------|-----------|
| 2026-04-16 | Pivot decidido, Option A aprovada, handoff completo |
| 2026-04-17 | Bloco 1: composer.json + ServiceProvider + DTOs |
| 2026-04-18 | Bloco 2: `codeguard:install` skeleton + Arch path repository |
| 2026-04-19-20 | Bloco 3: Extract TestSuiteRunner + assertions |
| 2026-04-21-22 | Bloco 4: Schema dump + pattern engine |
| 2026-04-23-24 | Bloco 5: Claude plugin + segundo projeto smoke |
| 2026-04-25 | v1.0.0-alpha.1 tag |
| 2026-05-01 | v1.0.0 Packagist publish (se Arch + 1 projeto estão felizes) |

## Riscos

| Risco | Mitigação |
|-------|-----------|
| Regressão funcional vs v0 Node | Branch `v0-npm-archive` permite restaurar |
| Usuários npm quebrados | Zero usuários conhecidos; registry preserva v0.1.1 |
| Composer publish rejeitado | `henryavila/codeguard` confirmado livre em Packagist |
| Path repository issues (Arch) | Symlink mode testado; fallback copy mode disponível |
| Divergência Arch inline vs package | Namespaces espelhados desde início minimizam retrabalho |
| Scope creep (querer re-add Node) | ADR-001 e ADR-002 congelam decisão; precisa de ADR-new para reverter |

## Aprovação

- [x] Usuário (Henry Avila) — 2026-04-16
- [x] Arquitetura revista por 10 agentes (6 adversariais + 4 steelman)
- [x] Design doc v4 completo referenciado
- [x] Memory completa persistida em `.ai/memory/`

## Próximas Ações

Ver `.ai/memory/conversation-handoff.md` para próximo passo concreto (Bloco 1).
