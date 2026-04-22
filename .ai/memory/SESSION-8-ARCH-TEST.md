---
name: Session 8 — Arch test script
description: Copy-paste commands + expected prompts when you come back from lunch
type: project
---

# Teste de Sessão 8 no Arch — script pós-almoço

Meta: validar interativamente que o install no Arch respeita customizações sem mais "surpresas" tipo deptrac.yaml sobrescrito. Roteiro abaixo já incorpora o fix `fb63ed3` (wizard respeita `stub-overrides.yaml`) shipped automaticamente enquanto você estava fora.

## Estado pré-teste (o que está no repo agora)

- CodeGuard HEAD: `fb63ed3` (working tree limpo, 50 commits ahead de origin)
- Suite: 323 tests / 783 assertions
- Arch: deptrac.yaml 30-layer restaurado; phpstan.neon sentinels `#` corrigidos; pint.json + dead-code providers ajustados (das 2 fixes pré-existentes corrigidas na sessão 7)
- Arch branch: `chore/fix-composer-quality-debt`, 9 files modified (merges sessão 6 + 7)

## Passo-a-passo

### 1. Sincronizar CodeGuard path repo no Arch (já está atualizado, mas por garantia)

```bash
cd /home/henry/codeguard
git log --oneline -3
# Esperado: fb63ed3 ... (wizard respects stub-overrides)

cd /home/henry/arch
composer update henryavila/codeguard --no-interaction 2>&1 | tail -5
# Esperado: "Nothing to modify in lock file" OU "Updating dependencies" com nenhum erro
```

### 2. Pré-semear `.codeguard/stub-overrides.yaml` no Arch (decisão sua quais proteger)

Esse passo **depende da sua intenção**. Opções:

**(a) Máxima proteção** — proteger TUDO que você customizou na sessão 6:
```bash
cd /home/henry/arch
mkdir -p .codeguard
cat > .codeguard/stub-overrides.yaml <<'YAML'
overrides:
  - deptrac.yaml         # 30-layer config — NÃO deixar wizard sobrescrever
  - phpstan.neon         # level 10 + baseline + Nova excludes customizados
  - infection.json5      # Nova excludes específicos + timeout 30
  - pint.json            # public excludes + Nova
  - .jscpd.json          # threshold 10 + Nova ignores
  - tests/Arch/TestQualityTest.php  # hybrid trait + allowlists Arch-específicas
YAML
```

**(b) Proteção mínima** — só o que é crítico (deptrac 30-layer):
```bash
cd /home/henry/arch
mkdir -p .codeguard
cat > .codeguard/stub-overrides.yaml <<'YAML'
overrides:
  - deptrac.yaml
YAML
```

**(c) Nenhuma proteção** — deixar install perguntar em cada arquivo (vai testar UX da 4ª opção "Keep + remember"). Pule este passo.

> Escolha **(a)** se a meta é só "não perder nada"; **(c)** se a meta é testar ergonomia do novo prompt.

### 3. Rodar install interativo

```bash
cd /home/henry/arch
php artisan codeguard:install
```

### O que esperar em cada prompt (na ordem)

| Momento | Prompt/output esperado | O que você faz / valida |
|---|---|---|
| Início | `CodeGuard — Laravel quality gates installer` header | — |
| Detect env | PHP/Composer/Node versions + `package.json` detection | Arch deve aparecer como "full" candidate (tem Node) |
| Recomendação | "Recommended preset: codeguard-full ⭐" | — |
| Preset prompt | `Which preset?` (seletor 2 opções) | Escolhe `codeguard-full` (default) |
| Legacy files detected | Header `Legacy files detected` | **Se `lefthook.yml` não existe** (sessão 6 removeu), esta seção não aparece. **Se aparecer**, o prompt pergunta `Delete lefthook.yml? It was replaced by captainhook.json.` — responde **Y** |
| PHPStan extensions | Multiselect com `Larastan`, `PHPStan-PHPUnit`, `Peststan`, `Cognitive Complexity`, `Dead Code Detector`, `Disallowed Calls`, `Test Quality Kit` | **Peststan deve aparecer pré-selecionado** (Pest está no composer.json do Arch). Confirma com Enter. |
| Gate plan | Lista de gates + "Estimated total config" | — |
| `Proceed with install?` | Sim/não | **Y** |
| Publishing stubs | Para cada stub: `created` / `unchanged` / `kept custom` / `kept custom (remembered)` / `overwritten` / diff prompt | **Valida**: arquivos em overrides.yaml mostram `kept custom (remembered)` silenciosamente |
| Diff prompt (só para arquivos NÃO em overrides que diferem) | `File X differs. Options: Keep / Keep + remember / Overwrite / Show diff` | **Testa a 4ª opção**: escolhe `Keep + remember` em 1 arquivo qualquer e vê se é adicionado ao yaml |
| Stubs in stub-overrides (skipped) | Só aparece se algum override foi honrado | — |
| phpstan.neon extensions active | Lista das extensions ativadas | **Valida**: inclui `Peststan` |
| Deptrac layer detection | Se `deptrac.yaml` em overrides: `deptrac.yaml is listed in .codeguard/stub-overrides.yaml — wizard skipped.` Senão: wizard interativo | **Valida**: se você protegeu, skip silencioso; senão, wizard pergunta camada de cada namespace |
| CaptainHook setup | `captainhook install` | Deve mostrar `installed (.git/hooks registered)` com 3 hooks |
| Install summary | Warnings agregados | — |
| Next steps | 5 comandos + docs URL | — |

### 4. Validar arquivos finais

```bash
cd /home/henry/arch
git status
# Esperado: os arquivos protegidos em stub-overrides.yaml NÃO devem aparecer
# como M se você escolheu (a); só os não-protegidos podem mudar.

# Arquivos-chave:
git diff phpstan.neon       # Deve ter Carbon + Peststan (pré-aplicados sessão 7/merges); nenhuma regressão
cat .codeguard/stub-overrides.yaml  # Se você testou "Keep + remember" (c), deve ter entrada adicional
wc -l deptrac.yaml           # Deve ser 687 linhas (30-layer intacto) se protegido
ls lefthook.yml 2>&1         # Deve ser "No such file" (sessão 6 removeu, LegacyStubCleaner protege)
```

### 5. Quality gates

```bash
cd /home/henry/arch
vendor/bin/pint --test | head -3
# Esperado: fail (Arch tem débito de formatação) — mas erro tipo
# "unknown fixer _rule_docs" NÃO deve aparecer (bug cc3e776 fix)

vendor/bin/phpstan analyse --memory-limit=2G --no-progress 2>&1 | head -5
# Esperado: "Invalid configuration" NÃO deve aparecer (bug d936b43 fix)
# Pode ter ~1130 file_errors — débito Arch, não CodeGuard

vendor/bin/deptrac analyse --no-progress 2>&1 | tail -5
# Esperado: "Allowed 5804, Warnings 0, Errors 0" (30-layer intacto)
```

## Critérios de sucesso

- [ ] Install completa sem exception stack trace
- [ ] Peststan aparece pré-selecionado no multiselect
- [ ] Arquivos em `stub-overrides.yaml` NÃO viram `M` no git status
- [ ] 4ª opção "Keep + remember" adiciona entrada ao yaml
- [ ] Wizard do Deptrac faz **skip silencioso** quando `deptrac.yaml` está em overrides (msg: "listed in .codeguard/stub-overrides.yaml — wizard skipped.")
- [ ] `lefthook.yml` continua ausente (e se existir, o LegacyStubCleaner oferece deletar)
- [ ] PHPStan e Pint rodam sem erro de config (só débito próprio do Arch)
- [ ] Deptrac passa com 5804/0

## Se algo quebrar

**Restaurar deptrac.yaml se acidentalmente sobrescrito**:
```bash
cd /home/henry/arch
git checkout -- deptrac.yaml
wc -l deptrac.yaml  # deve ser 687
```

**Desfazer install inteiro** (nuclear):
```bash
cd /home/henry/arch
git status --short
# para cada arquivo listado como M:
git checkout -- <file>
rm -f captainhook.json captainhook.json.README.md
rm -rf .codeguard
```

## Após validar

Atualizar `.ai/memory/PROJECT-STATUS.md` — mover sprint 8 para "Opção A (TestSuiteRunner extract)" ou decidir por Opção C. Anotar no handoff quaisquer UX papercuts descobertos no teste interativo.

## Commits shipados enquanto você estava no almoço

- `fb63ed3` fix(install): wizard respects .codeguard/stub-overrides.yaml (close session 7 design gap) — 2 tests novos

Push para origin: **NÃO feito** (shared-state, aguarda sua aprovação explícita).
