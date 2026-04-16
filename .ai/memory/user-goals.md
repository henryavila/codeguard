---
name: User Real Goals
description: As 3 metas concretas do usuário para o projeto CodeGuard
type: user
---

# Metas Reais do Projeto (2026-04-16)

O usuário é **Henry Avila** — desenvolvedor Laravel/PHP que trabalha com IA intensivamente. Projeto principal: **Arch** (`/home/henry/arch`), sistema interno CRCMG em Laravel 12 + Filament v4 + Vue 3 + SQL Server + MongoDB.

## As 3 Metas Reais (priorizadas)

### Meta 1 — Replicar Padrão de Qualidade Entre Seus Projetos
- Tem múltiplos projetos PHP/Laravel (Arch + outros)
- Quer **uma instalação** que plante o mesmo padrão em todos
- Evitar divergência manual (cada projeto com configs ligeiramente diferentes)
- Quando evolui o padrão, quer **propagar via `composer update`** (não cherry-pick manual)

### Meta 2 — Funcionar em Múltiplas Máquinas
- Trabalha em mais de uma máquina (desktop, notebook, etc)
- Package deve rodar em qualquer ambiente com PHP + Composer
- **Não depender de Node.js** (containers Alpine, setup simples)
- Lockfile garantir mesmas versões de tools

### Meta 3 — Controlar Qualidade de Dev Terceirizado (sem IA)
- Contratou/vai contratar dev externo que **NÃO usa IA**
- Workflow: dev faz PR → **Henry revisa com IA** → merge ou pede mudanças
- Dev precisa de **CI gates** + **Husky pre-commit** como defesa writer-side
- Henry usa **AI rules + pattern analysis** como defesa reviewer-side

## Implicações Arquiteturais

### Writer-side (dev humano)
- CI GitHub Actions com todos quality gates
- Husky pre-commit forçando Pint + PHPStan local
- Branch protection + CODEOWNERS no repo
- Documentação clara em `AGENTS.md` / `CLAUDE.md` / `README`

### Reviewer-side (Henry com IA)
- AI rules path-triggered carregam quando Claude revisa PR
- `codeguard:analyze --scope=diff:main` roda patterns sobre o diff
- Claude hooks (config-protection) ativos quando Henry edita em resposta
- Pattern semantic analysis via skill `codeguard-run`

## Anti-Goals (o que NÃO é objetivo)

- **Não** é objetivo virar produto OSS viral (se virar, ótimo, mas não é meta)
- **Não** é objetivo suportar todas linguagens (PHP/Laravel/Vue é suficiente por ora)
- **Não** é objetivo competir com CodeRabbit/MegaLinter/Trunk (categoria diferente)
- **Não** é objetivo ter adoption 10k+ stars (niche do Henry basta)

## Timeline Desejada

- **Não pode esperar 2-3 semanas** para ter quality gates no Arch
- Solução: **dual-track** — Arch recebe consolidação inline HOJE via `composer require --dev henryavila/codeguard:@dev` com path repository, package desenvolve em paralelo
- Package v1.0.0 estável em ~2 semanas (com 2 projetos reais consumindo)

## Sinais de Sucesso

- [ ] Arch rodando `composer codeguard:check` em <1 semana
- [ ] Segundo projeto pessoal do Henry consumindo package em ~2 semanas
- [ ] Dev terceirizado fazendo PRs com CI gates bloqueando qualidade ruim
- [ ] Henry revisando PRs com AI + patterns carregados automaticamente
- [ ] Zero Node.js no stack do Henry
