# CodeGuard — Revisão de ADRs + Open Questions

**Data**: 2026-04-16
**Propósito**: Revisar decisões arquiteturais fechadas (ADRs) e decisões pendentes (Open Questions) antes de iniciar o **Bloco 1** (criação do `composer.json` + ServiceProvider + DTOs).

**Estrutura por item**:
- **Contexto** — de onde veio a pergunta, por que ela existe
- **Explicação** — o que cada opção/decisão realmente significa + tradeoffs
- **Recomendação Fundamentada** — recomendação com justificativa ancorada em evidência, constraints do projeto e metas reais

**Como revisar**: adicione annotations em pontos específicos com `suggestion`, `question`, `bug`, ou `nitpick`.

---

## Parte A — Architecture Decision Records (ADRs)

Oito decisões fechadas após 3 rounds de review (6 adversarial + 4 steelman). Reabrir apenas com motivo forte.

---

### ADR-001: Pivot Node.js → PHP/Composer

**Status**: ✅ Fechada | **Data**: 2026-04-16

#### Contexto
O projeto CodeGuard nasceu em outubro/2025 como `@henryavila/codeguard` — um npm package "language-agnostic" com um core em Node.js + hook runners que rodavam em qualquer projeto (PHP, TS, Python). A v0.1.1 foi publicada com ~3000 LOC de TypeScript. Quando o usuário (Henry, desenvolvedor Laravel) olhou o package do ponto de vista de **uso real no Arch** (seu projeto PHP/Laravel primário), percebeu que 90% dos patterns, rules e gates que ele queria eram Laravel-specific. O core "agnostic" virou um shell vazio que precisava de adapters para cada linguagem — adapters que não existiriam tão cedo já que ele não programa Python/Go/Rust.

#### Explicação
Três caminhos foram pesados:
1. **Manter o npm + adicionar adapter PHP**: mantém agnosticismo, mas Henry teria que manter dois ecossistemas (npm + Composer) para empurrar mudanças em ambos. O adapter PHP seria sempre "segunda classe" até alguém fora do Henry contribuir. E o peso do `node_modules` (50MB+) em servidores Laravel/Docker já é histórico de dor.
2. **Pivotar 100% para PHP/Composer**: simplifica distribuição (Composer é nativo no target), elimina Node do stack, permite usar idiomaticamente Laravel ServiceProvider/Artisan/Pest. Custo: perde potencial de alcançar devs não-PHP (mas eles **nunca foram target real** — vide user-goals).
3. **Monorepo com ambos**: complexidade extrema, Henry solo developer → manutenção inviável.

Os 10 reviewers convergiram que (1) é "theoretical tax" — você paga custo de manutenção de agnosticismo por um público que não existe. Evidência factual: adoção npm atual = quase zero. Nenhum issue reportado, nenhum PR externo.

#### Decisão
Abandonar `@henryavila/codeguard` npm package (v0.1.1) em favor de `henryavila/codeguard` Composer package.

#### Consequências
- ✅ `v0-npm-archive` branch + `v0-last-npm` tag preservam estado Node (reversível se usuário mudar de ideia)
- ✅ npm registry mantém v0.1.1 publicado (não deprecated formalmente — adoção zero significa zero risco de quebrar consumidores)
- ✅ `main` branch reescrita em PHP puro
- ✅ 28 pattern YAMLs migram para `resources/patterns/` como **data contract** (PHP nativo consegue ler YAML via `symfony/yaml`)
- ✅ Skills migram para `resources/skills/` (integradas via Composer install)
- ⚠️ Perda: usuários potenciais fora do ecossistema PHP — **mas eles nunca foram target explícito** (vide `user-goals.md`: Henry é dev PHP/Laravel, projetos pessoais + dev terceirizado)

#### Recomendação Fundamentada
**Manter decisão.** O pivot alinha stack real (100% PHP no target primário) com ferramenta. Agnosticismo é luxo quando você tem ecossistema alvo claro. Reabrir essa decisão só se surgir evidência de **projeto real não-PHP** do usuário — o que não existe.

#### Aberto a revisar?
Não. Decisão fundante.

---

### ADR-002: 2 Packages, Não 3

**Status**: ✅ Fechada | **Data**: 2026-04-16

#### Contexto
Design inicial propunha arquitetura 3-tier:
1. **npm core** (agnostic): pattern engine TS, AI rules generator, baseline manager
2. **Composer package** (Laravel): wrappers finos chamando o core via shell
3. **Claude plugin** (bash hooks): config-protection, pre-commit nudges

A ideia era "core uma vez, múltiplos wrappers". Mas com ADR-001 eliminando o core npm, ficou a pergunta: **mantém 3 packages** (composer + novo core em PHP separado + hooks) ou **consolida em 2**?

#### Explicação

**Argumento para 3**: separação de concerns. Core em `henryavila/codeguard-core` (lib pura sem Laravel), Laravel adapter em `henryavila/codeguard-laravel`, plugin separado. Beneficia quem usa Symfony puro ou Laravel Zero.

**Argumento para 2**: Henry **não tem projetos Symfony**. Não tem projetos Laravel Zero. Target primário 100% Laravel. Separar core de adapter adiciona:
- Um repo a mais para manter
- Um `composer.json` a mais
- Um CI pipeline a mais
- Um release process a mais
- Riscos de divergência de versão entre core e adapter

Evidência do Round 2: reviewers convergiram que divisão prematura é YAGNI. Pattern `symfony/yaml` + PHP nativo consegue rodar sem Illuminate — então se algum dia alguém precisar rodar sem Laravel, refatora **depois** com demanda real.

#### Decisão
Shippar apenas 2 packages:
1. `henryavila/codeguard` (Composer) — **tudo** PHP/Laravel (commands, patterns, AI rules, assertions, baseline)
2. `henryavila/codeguard-hooks` (Claude plugin) — bash hooks, distribuído via `/plugin install`

#### Explicação Técnica
- Pattern engine roda em PHP nativo com `symfony/yaml` (PatternLoader em PHP puro)
- Skills embutidas em `resources/skills/` (publicadas via `codeguard:install`)
- AI rules generator em PHP puro (sem Laravel facades além de `Str::of()`)
- Baseline manager usa `hash_file()` nativo

Ou seja: **80% do código pode rodar fora de Laravel** se futuramente for necessário extrair. Mas **não extrair preventivamente**.

#### Consequências
- ✅ Manutenção reduzida: **~60h/ano** vs ~120h na arquitetura 3-package (estimativa conservadora: 1 release/trimestre × N packages)
- ✅ Sem `node_modules` no stack consumidor
- ✅ Extensibilidade futura via **companion packages nativos** (`codeguard-symfony`, `codeguard-python`) — se demanda real surgir, não via speculation
- ⚠️ Claude plugin em repo separado exige sincronização manual de versão com o Composer package (resolvido em Q5)

#### Recomendação Fundamentada
**Manter 2 packages.** A regra aqui é YAGNI reforçado: divisão preventiva paga custo de manutenção **hoje** por benefício **hipotético**. Divisão **reativa** (quando alguém de fato pede) paga custo só quando ROI existe. Com Henry como único dev, otimizar para manutenção baixa > otimizar para extensibilidade especulativa.

#### Aberto a revisar?
Não. Mas **onde mora o Claude plugin** (repo separado vs subpasta) está em Q5 ainda aberta.

---

### ADR-003: Option A — Reusar Repo `henryavila/codeguard`

**Status**: ✅ Fechada | **Data**: 2026-04-16

#### Contexto
Pivot Node→PHP implica reescrita essencialmente total. Três caminhos:
- **A**: Pivotar `main` do repo existente (mantém URL, stars, brand)
- **B**: Criar repo novo (`laravel-codeguard`), deprecar o antigo
- **C**: Monorepo com `packages/{npm,composer,hooks}` (abraça ambos mundos)

#### Explicação

**Option A (escolhida)**:
- `main` vira PHP
- `v0-npm-archive` branch preserva estado Node (pronto para cherry-pick se necessário)
- `v0-last-npm` tag marca último commit Node
- `docs/legacy/` preserva README + CHANGELOG npm
- Historia git inteira preservada (quem ler um dia entende o pivot)

**Option B**: repo novo. Custo: URL quebra, stars (se houver) perdidas, quem encontrar o repo antigo não sabe que existe substituto. Ganho: separação conceitual limpa.

**Option C**: monorepo com ambos mundos coexistindo. Ganho: se Henry mudar de ideia, não perde npm. Custo: complexidade imensa (2 package managers, 2 CI matrices, 2 release flows) para um dev solo.

Fato crítico: npm v0.1.1 tem **adoção quase-zero**. Nenhum issue, nenhum PR externo, ~5 downloads totais. Romper essa "compatibilidade" tem risco real = zero. Isso destrava Option A.

#### Decisão
**Option A**. Pivotar `main` do repo existente.

#### Rationale Detalhado
1. **Brand recognition preservado** — `codeguard` name é específico/bom, perder seria desperdício
2. **Histórico git preservado** — futuros devs/reviewers entendem evolução
3. **Links externos não quebram** — README existente tinha link para este repo
4. **`v0-npm-archive` permite reversão** — se Henry mudar de ideia em 6 meses, `git checkout v0-npm-archive` traz tudo de volta
5. **Option B fragmentaria brand** — dois repos com nomes similares confundem
6. **Option C adiciona complexidade prematura** — monorepo sem necessidade real

#### Consequências
- ✅ Continuidade de URL e histórico
- ✅ Preserva capacidade de rollback (baixa entropia)
- ⚠️ Confusão potencial para quem chegar via npm (endereçado em Q10 — README explica pivot)

#### Recomendação Fundamentada
**Manter Option A.** Option B só faria sentido se houvesse base de usuários npm que pudesse ser confundida — não há. Option C só faria sentido se houvesse comprometimento real com multi-ecosystem — não há (ADR-001).

#### Aberto a revisar?
Não.

---

### ADR-004: "Hard Enforcement" → "Best-Effort Nudges"

**Status**: ✅ Fechada | **Data**: 2026-04-16 (pós-reviews)

#### Contexto
Design inicial posicionava Claude hooks plugin como **"hard enforcement"**: hooks `PreToolUse` bloqueariam edits em `phpstan.neon`, `pint.json`, etc. Claim de marketing: "configurações protegidas por design — a IA não consegue burlar". Review adversarial Round 2 (Agent 5, "Security Bypass") listou **12 bypasses concretos**, vários com issues oficiais abertos no Claude Code.

#### Explicação

**O que significava "hard enforcement"**:
- Hook `PreToolUse` com matcher `Edit|Write` bloqueando tools específicos
- Claim: usuário/AI não conseguem alterar configs protegidas sem autorização explícita

**Por que não funciona como "hard"**:

| Bypass | Issue Oficial | Status |
|--------|---------------|--------|
| `Bash(sed/awk/tee/>)` contorna `Edit\|Write` matcher | #6876, #29709 | Confirmed open |
| `git commit --no-verify` ou `HUSKY=0` burla pre-commit | #40117 | Confirmed |
| Task subagents **não herdam** hooks do parent | #27661 | **OPEN** |
| MCP tools contornam matcher por default | #13744 | Confirmed |
| `PostToolUse` exit code **ignorado** em Write/Edit | #6876 | Confirmed |
| Opus 4.6 documentadamente usa `--no-verify` para burlar gates | modelo-level | Reproducible |

Adicionalmente, o **sentinel file** proposto para Stop hook (arquivo vazio criado quando tests passam) é trivialmente spoofável (`touch .codeguard-ok`).

Claim de "hard enforcement" com 12 bypasses conhecidos é **materialmente enganoso** — usuário assume proteção que não existe, fica em falsa segurança.

#### Decisão
Reposicionar Claude hooks plugin como **"best-effort nudges"**. CI é o gate real. Hooks aumentam fricção para comportamento errado, não bloqueiam.

#### Consequências
- ✅ README/docs declaram honestamente: *"hooks são nudges (not enforcement); CI é o gate"*
- ✅ Adicionar `Bash` + `mcp__.*` matchers ao `config-protection` (fecha alguns bypasses parciais)
- ✅ `Stop` hook sentinel usa **git tree-hash** ao invés de empty file (harder to spoof — precisa commit real)
- ⚠️ Marketing claim menos atraente, mas **honestidade > growth viral**. Henry não busca stars.

#### Recomendação Fundamentada
**Manter reposicionamento.** Base factual (issues oficiais abertos) é sólida. Reabrir essa decisão exigiria: (a) issues serem fechados **e** (b) ter certeza de cobertura completa — nenhuma das duas é viável a curto prazo. Melhor entregar ferramenta honesta do que teatro de segurança.

**Insight bonus**: posicionar como "nudges" também alinha expectativa do usuário — ele sabe que precisa manter CI estrito, não delega enforcement 100% aos hooks.

#### Aberto a revisar?
Não. Base factual é sólida demais.

---

### ADR-005: Pattern System = Rule Distribution + LLM Adjudicator

**Status**: ✅ Fechada | **Data**: 2026-04-16

#### Contexto
Os 28 pattern YAMLs foram a herança mais valiosa da era npm — cada um descreve uma regra de qualidade (ex: "no God classes", "DRY behavioral", "action classes single-purpose"). O formato:

```yaml
name: single-responsibility
severity: warning
verification:
  rules:
    - Class should have one primary reason to change
    - Cohesion: methods operate on shared state
  thresholds:
    max_public_methods: 15  # PHPMD default
    max_fields: 7           # NDepend recommendation
false_positives:
  - Builder/Fluent APIs (many methods by design)
```

Review adversarial Round 2 (Agent 3) rotulou isso como **"fraude semântica — prompts em YAML disfarçados de analyzer"**, argumentando que sem AST analysis verdadeira, qualquer "detecção" é LLM hand-waving.

#### Explicação

**Round 3 steelman** classificou os 28 patterns:

| Categoria | Count | % | Significado |
|-----------|:-----:|:-:|-------------|
| **AST-replaceable** | 12 | 43% | PHPStan/phpat/Deptrac já resolvem — redundância |
| **Hybrid** | 13 | 46% | AST detecta candidato, LLM valida intent |
| **Pure semantic** | 3 | 11% | Só LLM consegue (judgment-heavy) |

**16 de 28 patterns** codificam judgment que AST **não captura**:
- `single-responsibility` — cohesion semantics
- `dry behavioral` — similar mas não idêntico
- `value-objects` — domain-meaningful, não primitivos "disfarçados"
- `action-classes` — orquestração vs lógica
- `no-logic-in-blade` — "logic" vs "conditional rendering"
- `no-god-object` — múltiplas responsabilidades
- `bounded-contexts` — DDD boundaries

AST detecta sintaxe. **Judgment sobre intent** precisa LLM ou human.

**Verification rules** nos YAMLs (ex: "methods operate on shared state") servem como **calibration anchors** — o LLM não inventa critérios, usa âncoras baseadas em PHPMD/SonarQube/NDepend thresholds conhecidos. Isso **reduz variance** do LLM significativamente.

**False-positive carve-outs** (ex: "Builder/Fluent APIs OK") reduzem noise.

**Custo** que reviewer estimou ($200/dev/mês) assumia batch full-repo scanning. Design real é **on-demand via skill** — roda quando Henry revisa um PR. Custo real: ~$5/mês (estimativa pessimista).

#### Decisão
Reposicionar patterns: **NÃO** são "static analyzer". **SÃO** "structured prompt distribution + LLM adjudicator onde AST não alcança".

Tagline: **"AI review where AST can't reach"**.

#### Consequências
- ✅ Clareza conceitual: patterns = LLM-adjudicated, não determinístico
- ✅ 12 AST-replaceable patterns delegam para phpat/pest-arch/PHPMD/Deptrac (reduz redundância)
- ✅ Keep pattern YAMLs como data contract em `resources/patterns/`
- ✅ Skill `codeguard-run` lê YAMLs e prompta LLM com verification rules
- ⚠️ Complexidade conceitual: usuário precisa entender paradigma (solved via docs)

#### Recomendação Fundamentada
**Manter reposicionamento.** A crítica "prompts em YAML" é **procedente mas não invalidante** — o valor do sistema não está em "detection determinística", está em **distribuir critério + LLM adjudicar consistently**. É complementar a AST, não substituto.

**Critério de revisão futura**: se em 6 meses os 16 "hybrid/semantic" patterns não gerarem findings úteis em uso real, pivotar (manter só YAMLs, mover execução para AST puro). Mas isso é decisão data-driven pós-uso, não especulativa agora.

#### Aberto a revisar?
Não reopen decision, mas **Q1** (patterns customizados por projeto) e **Q6** (preset selection) ainda precisam resolução.

---

### ADR-006: Default Preset = Minimal

**Status**: ✅ Fechada | **Data**: 2026-04-16

#### Contexto
Design inicial propunha 3 presets (Minimal, Standard, Full) **sem definir default**. Review adversarial Round 2 (Agent 4, "Adoption Friction") apontou: se default for Full, `codeguard:install` publica **12 arquivos root** no projeto consumidor → fricção catastrófica.

#### Explicação

**Evidência (JetBrains State of PHP 2025)**:

| Tool | Adoption (devs PHP) |
|------|:-------------------:|
| Zero tools | 42% |
| PHPStan | 36% |
| Pint | 30% |
| Rector | 10% |
| Deptrac | <5% (noise floor) |
| Infection | <5% |
| jscpd | <5% |

**Target real do CodeGuard**: os 36% que já usam PHPStan + Pint e querem mais (não os 42% zero-tools — esses não vão adotar qualquer coisa). Persona é "dev que já paga custo básico de tooling".

**Presets Finais**:

| Preset | Contém | Arquivos root | Default? | Persona |
|--------|--------|:---:|:---:|---------|
| **Minimal** | Pint + PHPStan | 2 | ✅ | 36% que já usam o básico |
| Standard | + Deptrac + Infection + Husky | 7 | | 10% power users |
| Full | + jscpd + Insights + TestQualityTest | 12 | | <5% enterprise |

Default Minimal significa: `codeguard:install` sem flag publica apenas `phpstan.neon.dist` + `pint.json` — **2 arquivos**. Adoption friction mínima.

#### Decisão
`codeguard:install` default = **Minimal** (Pint + PHPStan only). Standard/Full atrás de opt-in flag (`--preset=standard`, `--preset=full`).

#### Consequências
- ✅ Adoption friction baixa para 90% dos consumidores
- ✅ Power users podem escalar via flag (`codeguard:install --preset=full`)
- ✅ Alinhado com reality check do ecossistema PHP
- ⚠️ Nome "Minimal" pode desanimar marketing (OK — honestidade > hype)

#### Recomendação Fundamentada
**Manter Minimal como default.** Escolha é data-driven (JetBrains stats), não especulativa. Reabrir só se houver evidência de que target real está nos 10% Deptrac users (não está, vide user-goals).

**Insight adjacente**: ter presets escalonáveis é mais valioso que tentar convencer usuário a usar Full. Deixar upgrade natural acontecer com demanda ("depois de 1 mês, quero Deptrac → `codeguard:install --preset=standard --force`").

#### Aberto a revisar?
Não.

---

### ADR-007: Dual-Track Development

**Status**: ✅ Fechada | **Data**: 2026-04-16

#### Contexto
Meta 1 + Meta 2 + Meta 3 (vide `user-goals.md`) exigem que Henry tenha **quality gates consolidados no Arch rapidamente**. Arch já tem 770 LOC de `TestSuiteRunner` + assertions em produção. Se Henry esperar o package ficar "pronto" antes de usar, são 2 semanas sem progresso no Arch — inaceitável.

#### Explicação

**Caminhos avaliados**:
1. **Waterfall**: package pronto → Arch consome. 2 semanas de atraso no Arch.
2. **Only Arch**: consolidar inline no Arch, package depois. Arch fica acoplado, extract vira refactoring caro (namespaces divergem).
3. **Dual-track**: Arch recebe consolidação inline HOJE usando **estrutura de namespaces do package** (`Henryavila\Codeguard\*`). Package desenvolve em paralelo via `composer path repository` (symlink). Extract gradual.

**Setup Técnico (dual-track)**:
```json
// ~/arch/composer.json
"repositories": [
  {"type": "path", "url": "/home/henry/codeguard", "options": {"symlink": true}}
],
"require-dev": {"henryavila/codeguard": "@dev"}
```

Assim o Arch já roda com símbolos do package (mesmo que o package seja quase vazio no Bloco 1). Conforme package ganha classes, Arch deixa de ter duplicata inline — remove código local e usa o do package.

#### Decisão
**Dual-track**. Arch recebe consolidação inline HOJE. Package desenvolve em paralelo. Extract gradual.

#### Consequências
- ✅ Arch valida package em uso real desde dia 1 (**dogfooding contínuo**)
- ✅ Namespaces no Arch desde início espelham package (`App\Testing\Concerns\*` → `Henryavila\Codeguard\Assertions\*`)
- ✅ Find/replace único quando migra (reduz custo de extract)
- ✅ Primeiro projeto consumidor é laboratório de testes
- ⚠️ Symlink quebra em Windows nativo (OK — usuário usa WSL, vide Q9)
- ⚠️ `composer update` no Arch pode pegar mudanças breaking no package (OK — Henry controla ambos)

#### Recomendação Fundamentada
**Manter dual-track.** É a **única estratégia** que resolve simultaneamente:
- Urgência do Arch (Meta 1 não pode esperar)
- Qualidade do package (testar em uso real, não em isolation)
- Velocidade de desenvolvimento (AI-assisted code + real-world feedback loop apertado)

Reabrir só se symlink der problemas sérios (ex: composer autoload cache stale). Nesse caso, fallback é `"type": "vcs"` + branch local.

#### Aberto a revisar?
Não.

---

### ADR-008: Timeline AI-Assisted ~1–2 Semanas

**Status**: ✅ Fechada | **Data**: 2026-04-16

#### Contexto
Estimativas anteriores (pre-AI) sugeriam 4–6 semanas para MVP. Com Claude Code + dual-track + escopo refinado (Minimal default), estimativa foi recalibrada.

#### Explicação

**Por que AI-assisted muda timeline**:
- Código **formulaico** (extract + stubs + wizard + docs) é **5–10× mais rápido**:
  - `ServiceProvider` com `register()/boot()/publishes()`: 30min AI-assisted vs 2h manual
  - DTOs imutáveis: 15min vs 1h
  - Config file com defaults: 15min vs 45min
- Código **idiomático** (Commands Artisan, Pest tests, Eloquent) é bem coberto pelo training data do Claude
- Code review e typing catch bugs cedo

**Human bottleneck permanece** (~30% overhead):
- Decisões de API (naming, signatures, retornos)
- Verificação (run tests, check behavior)
- Edge cases (Windows, SQL Server, `:memory:`)

#### Estimativa Realista (AI-assisted)

| Fase | Horas focadas | Dias úteis |
|------|:-------------:|:----------:|
| Composer package MVP (Blocos 1-3) | 15–25h | 2–4 |
| Pattern engine + 28 YAMLs | 8–12h | 1–2 |
| Claude plugin | 6–10h | 1–2 |
| Arch migra (substitui inline por package) | 3–5h | 0.5–1 |
| 2º projeto smoke test | 4–8h | 0.5–1 |
| Buffer debugging/polish | 8–12h | 1–2 |
| **Total** | **~45–70h** | **~6–11 dias** |

**Calendar**: 1.5–2.5 semanas (assumindo ~6h focadas/dia).

#### Decisão
Target: **v1.0.0 em 2 semanas calendar** a partir de 2026-04-16. Milestone flexível.

#### Consequências
- ✅ Expectativa calibrada (nem pessimista pre-AI nem otimista AI-utópica)
- ✅ Alinha com dogfooding contínuo (Arch vê progresso a cada dia)
- ⚠️ Timeline pode escorregar para 3 semanas se Q5 (plugin repo strategy) e Q3 (AGENTS.md vs per-tool) virarem rabbit holes
- ⚠️ Pressão de tempo não deve comprometer qualidade — melhor atrasar que shipar código ruim

#### Recomendação Fundamentada
**Manter target ~2 semanas, com flexibilidade.** Estimativa é ancorada em decomposição por fase, cada fase com entregável testável. Caso Q5 ou outro rabbit hole aparecerem, **escopo flex** (empurrar Fase 2 pra depois) é preferível a **timeline flex**.

Milestone hard: **Arch rodando `composer codeguard:check` em 1 semana**. Isso valida Bloco 1 + Bloco 2 + primeira feature real.

#### Aberto a revisar?
Não, mas checkpoint semanal faz sentido — reavaliar se em 7 dias não tiver MVP rodando no Arch.

---

## Parte B — Open Questions (Q1–Q12)

Decisões pendentes. Algumas afetam o Bloco 1, outras só Fase 2.

---

### Q1 — Extensibilidade de patterns customizados por projeto

**Bloqueia Bloco 1?** ❌ Não (afeta `PatternLoader`, Fase 2)

#### Contexto
Os 28 patterns nativos cobrem PHP genérico + Laravel. Mas cada projeto tem convenções próprias: Arch tem `App\Services\*` com regras específicas, outro projeto pode ter `App\UseCases\*`. Usuário vai querer criar patterns próprios.

Pergunta: **onde eles vivem, como são carregados**?

#### Explicação das Opções

| Opção | Descrição | Prós | Contras |
|-------|-----------|------|---------|
| **A** | Auto-discovery em `base_path('.codeguard/patterns/**/*.yaml')` | Zero config, convenção-sobre-configuração, easy onboarding | Scan I/O em toda execução (minor perf), discovery pode pegar arquivo não-intencional |
| **B** | Config explícita `config('codeguard.patterns.custom_paths')` | Performance previsível, usuário controla exato | Boilerplate: usuário precisa configurar mesmo para caso trivial |
| **C** | **Ambos** (auto-discovery default + config override) | Zero friction para 90%, flex para 10% edge cases | Dois caminhos de código (minor complexity) |

#### Recomendação Fundamentada
**Opção C**. Justificativa:
- Auto-discovery (A) resolve **convenção comum** (`base_path('.codeguard/patterns/')`) sem fricção — alinha com Laravel idiom (services, migrations auto-discovered)
- Config override (B) resolve **edge cases**:
  - Monorepo: `base_path('../shared/patterns/')`
  - Environment-specific: patterns extras só em `staging`
  - Custom preset sharing entre projetos
- Custo de suportar ambos é **baixo** (~20 linhas no `PatternLoader`), ROI alto
- Seguir Laravel convention: `config()` sempre permite customizar, auto-discovery reduz onboarding

Implementação sugerida:
```php
// PatternLoader::loadCustomPatterns()
$paths = config('codeguard.patterns.custom_paths', []);
if (empty($paths)) {
    $paths = [base_path('.codeguard/patterns')]; // default convention
}
```

#### Impacto no Bloco 1
Nenhum. `composer.json` e ServiceProvider não tocam isso. Config pode já ter chave `'custom_paths' => []` como placeholder.

---

### Q2 — Como Claude skills distribuídas no Composer package instalam?

**Bloqueia Bloco 1?** ❌ Não (afeta `codeguard:install`, Fase 2)

#### Contexto
Skills vivem em `resources/skills/codeguard-*/SKILL.md` dentro do package. Claude Code detecta skills em **dois lugares**:
- Global: `~/.claude/skills/`
- Projeto: `.claude/skills/` (relative to cwd)

Como fazer skills do package chegarem ao local detectável no projeto consumidor?

#### Explicação das Opções

| Opção | Descrição | Prós | Contras |
|-------|-----------|------|---------|
| **A** | `codeguard:install` **copia** `resources/skills/*` → `.claude/skills/*` | Funciona Windows/Linux/Mac, commit-able pelo projeto | Skill desatualiza no `composer update` (não re-publish automático) |
| **B** | **Symlink** `vendor/henryavila/codeguard/resources/skills/*` → `.claude/skills/*` | Sempre atualizada com `composer update` | Quebra em Windows nativo, `.claude/skills/` vira symlink (weird no git) |
| **C** | Documentar manual (user edita `~/.claude/settings.json` para apontar caminho) | Zero tooling | Fricção alta, cada projeto precisa isso |

#### Recomendação Fundamentada
**Opção A com flag `--symlink` opcional**. Justificativa:
- **Copy é universal** — Windows, WSL, Linux, Mac todos funcionam
- **Copy é commit-able** — time pode versionar `.claude/skills/` e ter mesma versão em todo mundo (importante para Meta 3 do user-goals: controlar dev terceirizado)
- **Desatualização é gerenciável** — `codeguard:install --update-skills` força re-publish
- **Symlink para power users** — quem quiser "sempre atualizada" pode usar `--symlink` (Henry em WSL)

Implementação:
```php
// CodeguardInstallCommand
if ($this->option('symlink') && !$this->isWindows()) {
    $this->symlinkSkills();
} else {
    $this->copySkills();
}
```

**Nota**: seguir mesmo padrão para `resources/rules/` (markdown rules). Consistência.

#### Impacto no Bloco 1
Nenhum. Afeta apenas `CodeguardInstallCommand` quando implementado.

---

### Q3 — AI rules: AGENTS.md vs per-tool files

**Bloqueia Bloco 1?** ❌ Não (afeta `RulesGenerator`, Fase 2)

#### Contexto
Ecossistema de AI coding tools está fragmentado:
- **Claude Code** lê `.claude/rules/*.md` + `CLAUDE.md` + path-triggered frontmatter
- **Cursor** lê `.cursor/rules/*.mdc` (globs, MDC format)
- **Copilot** lê `.github/copilot-instructions.md` (único arquivo, 4000 char limit)
- **AGENTS.md** (proposta comunitária, unified, sem path-triggering)

Comunidade (DeployHQ, keboca, outros) converge em **AGENTS.md** como standard. Mas AGENTS.md **não tem path-triggering** — rules carregam sempre, cresce contexto.

#### Explicação das Opções

| Opção | Descrição | Prós | Contras |
|-------|-----------|------|---------|
| **A** | Gerar **ambos** (AGENTS.md canonical + per-tool files) | Path-triggering preservado onde funciona, future-proof para AGENTS.md standard, universal fallback | Duplicação de conteúdo, risco de divergência (AGENTS.md e `.claude/rules/` divergirem) |
| **B** | Só **AGENTS.md** | Simplicidade, segue tendência comunitária | Perde path-triggering (Cursor globs, Claude frontmatter) — contexto sempre cheio |
| **C** | Só **per-tool** | Força path-triggering onde útil, performance ótima | Fragmentação, cada tool precisa rebuilding, AGENTS.md skippers perdem coverage |

#### Recomendação Fundamentada
**Opção A durante 2026. Re-avaliar 2027**. Justificativa:
- **AGENTS.md é standard emergente** — skipar agora é apostar contra tendência
- **Per-tool path-triggering é real benefício hoje** — Cursor/Claude têm isso, perder é regressão
- **Gerar ambos a partir de source canônico** — `resources/rules/*.md` é canonical, generator cria AGENTS.md + per-tool files a partir dele (sem duplicação manual)
- **Risco de divergência mitigado por design**: generator tem source-of-truth único
- **Future-proof**: quando AGENTS.md ganhar path-triggering, basta descartar per-tool generators

Implementação conceitual:
```php
// RulesGenerator
$canonical = PatternLoader::loadRules(); // from resources/rules/
$claudeFiles = ClaudeFormatter::format($canonical); // adds frontmatter
$cursorFiles = CursorFormatter::format($canonical); // MDC + globs
$agentsMdFile = AgentsMdFormatter::format($canonical); // single concat
```

#### Impacto no Bloco 1
Nenhum.

---

### Q4 — Semver v0.x vs v1.0 threshold

**Bloqueia Bloco 1?** ⚠️ Leve (afeta versão inicial no `composer.json`)

#### Contexto
`composer.json` pode ou não declarar versão. **Path repositories** ignoram campo `version` e usam `dev-main`. Packagist publication exige versão ou tag. Quando publicar v1.0.0?

#### Explicação

**Critério proposto para v1.0.0**:
- [ ] Arch consumindo em produção por **2 semanas** sem bugs críticos (validação de estabilidade)
- [ ] **Segundo projeto** do Henry consumindo OK (validação de reusabilidade — Meta 1 de user-goals)
- [ ] `codeguard:install` testado em 3+ cenários (fresh Laravel, projeto legado, Laravel 11 + 12)
- [ ] README completo com exemplos
- [ ] CI matrix **PHP 8.3/8.4 × Laravel 11/12** verde

**Trajetória de versões**:
1. `dev-main` (hoje, path repo, só Arch consome)
2. `1.0.0-alpha.N` (após Bloco 1-3 estabilizarem, ainda path repo mas já tagged)
3. `1.0.0-beta.N` (após 2º projeto consumir, publish no Packagist)
4. `1.0.0` (após 2 semanas prod sem bugs críticos)

#### Recomendação Fundamentada
**Começar `dev-main`**. Justificativa:
- Path repos ignoram `version` no `composer.json` — não há benefício em declarar versão cedo
- Evita "versionitis" (bumping version antes de haver estabilidade real)
- Permite iteração fast no começo sem pressão semver
- Tag `1.0.0-alpha.1` quando `codeguard:install` der primeiro `composer codeguard:check` verde no Arch

**Critério concreto para alpha.1**: `codeguard:install` + `codeguard:check` funcionam end-to-end no Arch. ETA: 3-5 dias.

#### Impacto no Bloco 1
`composer.json` **omite campo `version`**. Adicionar só quando for publicar alpha.

---

### Q5 — Claude plugin repo strategy

**Bloqueia Bloco 1?** ❌ Não (afeta `henryavila/codeguard-hooks`, fora escopo Composer package)

#### Contexto
Claude plugin é distribuído via `/plugin install <url>`. É uma entidade separada do Composer package com ciclo de vida próprio. Pergunta: **onde mora o código**?

#### Explicação das Opções

| Opção | Descrição | Prós | Contras |
|-------|-----------|------|---------|
| **A** | **Repo separado** (`henryavila/codeguard-hooks`) | Cleanness, segue padrão Claude plugin marketplace, ciclo de vida independente, URL específica para `/plugin install` | Dois repos para manter, sincronizar versões manualmente |
| **B** | **Subpasta** `claude-plugin/` no repo `codeguard` | Menos overhead de repos, mesma git tag versiona ambos | Confusão: distribui via `/plugin install`, não Composer. `/plugin install` precisa URL específica (subpasta é awkward). |

#### Recomendação Fundamentada
**Opção A — repo separado**. Justificativa:
- **Ciclo de vida diferente**: Composer package evolui com releases Packagist; Claude plugin evolui com Claude Code feature changes (ex: novos hooks types)
- **Marketplace expectations**: plugin marketplace do Claude assume 1 repo = 1 plugin
- **`/plugin install` syntax**: `claude /plugin install https://github.com/henryavila/codeguard-hooks` é clean; subpasta seria `https://github.com/henryavila/codeguard/tree/main/claude-plugin` (ugly)
- **Descoberta independente**: GitHub topics/README otimizado para Claude hooks users (persona diferente de Composer users)
- **Sincronização manual** é OK porque hooks são pequenos (~300 LOC bash shell) e estáveis (mudam pouco)

Sync strategy: release notes do Composer package mencionam plugin companion version (ex: "requires codeguard-hooks ^0.1 for AI protection").

#### Impacto no Bloco 1
Nenhum. Claude plugin é trabalho de outro bloco.

---

### Q6 — Pattern presets: como consumidor seleciona?

**Bloqueia Bloco 1?** ⚠️ Leve (afeta `config/codeguard.php` default)

#### Contexto
Três pattern presets:
- `core` (13 patterns): language-agnostic (no god objects, DRY, SRP, etc)
- `php` (6 patterns): PHP-specific (no globals, type declarations, etc)
- `php-laravel` (9 patterns): Laravel-specific (no eloquent in blade, service layer, etc)

Projetos diferentes querem presets diferentes:
- Laravel: todos 3
- Symfony: `core + php` (+ futuro `php-symfony`)
- Vanilla PHP: `core + php`

#### Explicação
Duas abordagens possíveis:

**Static config** (usuário edita):
```php
'patterns' => [
    'enabled_presets' => ['core', 'php', 'php-laravel'],
],
```

**Auto-detect** (install wizard detecta):
- `composer show laravel/framework` succeeds → habilita `php-laravel`
- `composer show symfony/framework-bundle` succeeds → futuro `php-symfony`
- Fallback: `core + php`

#### Recomendação Fundamentada
**Auto-detect no `codeguard:install`, sobrescrevível via config**. Justificativa:
- **Auto-detect reduz fricção** — 95% dos usuários nunca tocam isso, funciona out-of-box
- **Config override preserva controle** — edge cases (multi-framework project) editam manualmente
- **Alinha com Laravel philosophy** — convention + configuration

Implementação:
1. `codeguard:install` detecta frameworks e escreve `config/codeguard.php` com presets apropriados
2. Usuário pode editar o arquivo depois se precisar

#### Impacto no Bloco 1
Config default **assume Laravel** (target primário): `['core', 'php', 'php-laravel']`. Auto-detect logic vem no Bloco 2 (`CodeguardInstallCommand`).

---

### Q7 — Pint vs CS Fixer vs ECS

**Bloqueia Bloco 1?** ❌ Não (afeta stubs, Fase 2)

#### Contexto
Arch usa **Laravel Pint** (wrapper oficial sobre PHP-CS-Fixer). Mas alguns Laravel devs preferem:
- `friendsofphp/php-cs-fixer` (mais flex, mais rules)
- `symplify/easy-coding-standard` (ECS) (runs Fixer + PHPCS)

#### Explicação
Três posturas possíveis:

1. **Opinionated**: só Pint (default), sem opção
2. **Flexível**: default Pint, mas `codeguard:install --formatter=cs-fixer` troca stub
3. **Agnostic gate**: gate `pint` no config aponta para qualquer binário

#### Recomendação Fundamentada
**Opinionated (Pint) + Agnostic gate**. Justificativa:
- **Target primário é Laravel** — Pint é oficial Laravel, default óbvio
- **Stubs Pint por default** reduz complexity interna (um stub padrão)
- **Gate agnostic permite override** no projeto:

```php
'gates' => [
    'pint' => [
        'enabled' => true,
        'command' => './vendor/bin/pint --test', // swap por 'php-cs-fixer --dry-run'
    ],
],
```

- **Upgrade path**: se demanda aparecer, futuro `codeguard:install --formatter=cs-fixer` pode publicar stub alternativo (não-default)

#### Impacto no Bloco 1
`config/codeguard.php` default usa Pint nas gates — alinhado com ADR-006 Minimal preset.

---

### Q8 — CodeguardTestCommand progress output

**Bloqueia Bloco 1?** ❌ Não (afeta `CodeguardTestCommand`, Bloco 3+)

#### Contexto
Arch's `RunTestsCommand` (271 LOC) tem lógica custom para **parsear output de Pest + ParaTest** e mostrar progress. Lógica conta "ticks" (dots do Pest) + "parallel dots" (ParaTest). Funciona, mas é feio.

#### Explicação das Opções

| Opção | Descrição | Prós | Contras |
|-------|-----------|------|---------|
| **A** | Manter lógica atual (port direto do Arch) | Funciona, battle-tested em Arch | Código feio, string parsing frágil |
| **B** | `symfony/console` ProgressBar | API estável, bem documentada | Não captura nuances de parallel (ParaTest output não é linear) |
| **C** | Laravel 12 `components->spinner()` | UX mais moderna (spinners, colored output) | Exige PHP 8.3+ (ok), Laravel 12 (mais restritivo), ainda não resolve ParaTest parsing |

#### Recomendação Fundamentada
**Opção A por ora — manter port direto**. Justificativa:
- **Battle-tested** — roda em prod no Arch hoje
- **Port direto = zero risco** de introduzir bugs na reescrita
- **Refactor é cheap depois** — se virar incômodo (feedback real), migrar para C com PHP 8.3+ + Laravel 12
- **YAGNI**: polish de progress output não é bloqueador de Meta 1/2/3

**Criterio de revisão**: se em 1 mês de uso alguém reclamar, refatorar para C.

#### Impacto no Bloco 1
Nenhum. Mas informa que `TestSuiteRunner` extract (Bloco 3) deve preservar lógica atual sem tentar "melhorar" sem necessidade.

---

### Q9 — Windows compatibility

**Bloqueia Bloco 1?** ⚠️ Leve (afeta decisões que tocam filesystem/shell)

#### Contexto
Arch roda em WSL2 (Linux dentro do Windows). Henry trabalha também em máquinas Windows nativo? Composer package precisa rodar Windows nativo?

#### Explicação

**Três tiers de compatibilidade**:

| Tier | Ambiente | Nível de suporte |
|------|----------|------------------|
| **Tier 1** | Linux / macOS / WSL2 | Suporte total, teste em CI |
| **Tier 2** | Windows nativo (cmd/PowerShell) | Best-effort, PHP code roda, shell scripts não |
| **Tier 3** | Docker Alpine (PHP container) | Best-effort, sem bash-specific hooks |

**Componentes e compatibilidade**:
- **Composer package (PHP)**: Tier 1 + Tier 2 OK (PHP é cross-platform)
- **Claude plugin (bash hooks)**: Tier 1 only (bash required)
- **Path repository symlink**: Tier 1 only (Windows symlinks exigem admin)
- **`codeguard:install` copy-mode**: Tier 1 + Tier 2 OK

#### Recomendação Fundamentada
**Tier 1 primário, Tier 2 best-effort, documentar limites**. Justificativa:
- **Target primário WSL2** (usuário real) → Tier 1
- **PHP é cross-platform por natureza** → Tier 2 comes for free se evitar shell-specific code
- **Bash hooks limitados a Tier 1** — documentar que Windows nativo não tem hooks (mas CI GitHub Actions Linux runners sim)
- **Evitar**:
  - `shell_exec` com shell=true em commands
  - Paths hardcoded com `/`
  - Assumir `$HOME` (usar `Str::of(env('HOME', env('USERPROFILE')))`)

**Convention**:
- Usar `DIRECTORY_SEPARATOR` ou `Path::join()` (symfony/filesystem)
- `symfony/process` sem `shell=true` sempre que possível

#### Impacto no Bloco 1
- `composer.json` não tem impacto
- `ServiceProvider::publishes()` já usa arrays de paths — Laravel cuida de separators
- **Decision aplicável**: em utilities que manipulam paths, usar constantes PHP ou `Path::join()` — **nenhuma decisão urgente no Bloco 1**

---

### Q10 — README: pivot vs PHP novo

**Bloqueia Bloco 1?** ❌ Não (README já existe, minor edit)

#### Contexto
README atual (criado durante pivot) já menciona pivot. Pergunta: qual tom/estrutura manter para v1.0?

#### Explicação das Opções

| Opção | Descrição | Prós | Contras |
|-------|-----------|------|---------|
| **A** | README principal foca **PHP package**. Seção pequena "coming from npm" no fim com link para `docs/legacy/` | Clear target audience (PHP devs), legacy users encontram o link | Assume adoção atual é zero (é) |
| **B** | README principal é **sobre o pivot**, depois explica PHP | Transparency-first | Confunde primeiro contato — visitor chega querendo saber como usar, vê "pivot history" primeiro |
| **C** | README curto, detalhes em `docs/` | Clean | Exige cliques extras para info básica |

#### Recomendação Fundamentada
**Opção A**. Justificativa:
- **Primeiro contato importa** — visitor Packagist/GitHub quer saber: "o que é, como instalo, o que faz"
- **Pivot é story relevante apenas para legacy users** — que são ~0 pessoas
- **Link para `docs/legacy/`** permite curiosos explorarem história sem poluir README principal
- **Alinha com README conventions** — H1 (título), badges, installation, usage, contributing

Estrutura sugerida:
```markdown
# CodeGuard — Laravel Quality Gates That Survive Your AI Agent

[badges]

## Features
...

## Installation
composer require --dev henryavila/codeguard

## Usage
php artisan codeguard:install
php artisan codeguard:check

## Documentation
...

## History
This package was previously distributed as `@henryavila/codeguard` on npm.
See [legacy/](docs/legacy/) for v0 artifacts.
```

#### Impacto no Bloco 1
README atual está próximo disso. Minor edit depois do Bloco 1, sem blocker.

---

### Q11 — Changelog strategy

**Bloqueia Bloco 1?** ❌ Não

#### Contexto
CHANGELOG.md do npm package tinha v0.1.0, v0.1.1. Manter ou reset?

#### Explicação

**Opção Reset**: CHANGELOG.md começa com "1.0.0-alpha.1 — 2026-04-16 — Initial Composer release". Histórico npm em `docs/legacy/CHANGELOG-v0-npm.md`.

**Opção Continue**: CHANGELOG.md preserva v0.1.0/v0.1.1 + adiciona v1.0.0 "Complete rewrite to PHP".

#### Recomendação Fundamentada
**Reset**. Justificativa:
- **Ecossistemas diferentes** — v0.x era npm, v1.x é Composer. Mesmos números de versão não são continuidade real
- **Changelog readers esperam coerência** — versões não-sequenciais pulando de Node para PHP confundem
- **Preservação histórica mantida** — `docs/legacy/CHANGELOG-v0-npm.md` continua completo
- **Semver claim clean** — v1.0.0 start fresh = sem breaking changes contraditórias

#### Impacto no Bloco 1
Criar `CHANGELOG.md` placeholder vazio ou só com "1.0.0-alpha.1 — TBD". Não bloqueia.

---

### Q12 — Breaking changes entre v0 (npm) e v1 (Composer)

**Bloqueia Bloco 1?** ❌ Não

#### Contexto
v0 (npm) e v1 (Composer) são sistemas completamente diferentes:
- Linguagem diferente (TS vs PHP)
- Package manager diferente (npm vs Composer)
- API diferente (CLI Node vs Artisan commands)
- Configuração diferente (npm scripts vs Composer scripts)

Existe caminho de migração?

#### Explicação
**Realidade**: tudo é breaking. Não há migração programática possível.

**Opções de comunicação**:
1. **Silent** — README não menciona
2. **Honest** — README declara: "v1.0 is a complete rewrite. No migration from v0.x."
3. **Polite** — "For Composer users, start fresh with v1.0. Node users: continue with v0.1.1 on npm."

#### Recomendação Fundamentada
**Opção 3 — polite e claro**. Justificativa:
- **Honestidade** evita expectativa enganada (alguém tenta `codemod` que não existe)
- **Pragmatismo** — "continue with v0.1.1 on npm" é opção real (package ainda publicado)
- **Upgrade path não-obrigatório** — npm v0 continua funcionando para quem usar (adoção ~0, mas porta aberta)

Texto sugerido para README:
> **Migrating from npm v0.x?**
> v1.0 is a complete rewrite from Node to PHP/Composer with no programmatic migration path.
> - **If you use PHP/Laravel**: install v1.0 fresh and re-configure (see Installation)
> - **If you use Node**: continue with `@henryavila/codeguard@0.1.1` on npm (no further updates planned)

#### Impacto no Bloco 1
Nenhum. Texto vai em README após Bloco 1.

---

## Parte C — Status para Bloco 1

### Questões que bloqueiam Bloco 1
**Nenhuma em bloqueio hard.** Todas podem ser resolvidas durante ou depois.

### Questões com influência leve no Bloco 1

| Questão | Impacto concreto no Bloco 1 | Recomendação |
|---------|-----------------------------|--------------|
| Q4 (versão) | `composer.json` campo `version` | **Omitir** (path repos ignoram) |
| Q6 (presets config default) | `config/codeguard.php` default key | **Assumir Laravel** → `['core', 'php', 'php-laravel']` |
| Q7 (Pint vs alternativas) | stubs references | **Default Pint**, gate config permite override |
| Q9 (Windows) | filesystem utilities | **Usar `DIRECTORY_SEPARATOR`**, evitar `shell=true` em `symfony/process` |

### ADRs Relevantes para Bloco 1

| ADR | Influência direta no código |
|-----|-----------------------------|
| ADR-001 (PHP-only) | `composer.json` require `"php": "^8.3"`, sem deps npm |
| ADR-002 (2 packages) | Namespace `Henryavila\Codeguard\`, zero referência a npm |
| ADR-006 (Minimal default) | `config/codeguard.php` gates: só `pint` + `phpstan` enabled |
| ADR-007 (dual-track) | Path repository setup vem no Bloco 2, não Bloco 1 — apenas preparar estrutura |

---

## Parte D — Perguntas para o Revisor

Por favor, anote opiniões sobre:

1. **Alguma ADR deve ser reaberta?** (ex: "2 packages" — você prefere 3? "Minimal default" — prefere Standard?)
2. **Alguma Open Question tem opção adicional** que não listei?
3. **As recomendações default** estão razoáveis para o início do Bloco 1?
4. **Prioridade**: devo endereçar alguma Open Question **antes** do Bloco 1, ou seguir com defaults recomendados?
5. **Missing decisions**: tem alguma decisão **não capturada** aqui que você quer discutir?

---

## Apêndice — Referências

- Design doc v4 (completo): `docs/specs/2026-04-16-codeguard-v2-architecture.md`
- Pivot rationale: `docs/specs/2026-04-16-pivot-npm-to-composer.md`
- Reviews consolidados: `.ai/memory/reviews-consolidated.md`
- Conversation handoff (próximos passos concretos): `.ai/memory/conversation-handoff.md`
- User goals: `.ai/memory/user-goals.md`
