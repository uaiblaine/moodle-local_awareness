# Prompt — Fase 3 do redesenho das telas administrativas

Continuação do PR #25 (`refactor/admin-surfaces-theme-tokens`). As fases 0, 1 e 2 estão
entregues e verdes; este documento é o que falta e o que você precisa saber para não
refazer investigação já paga.

## Estado atual

Branch `refactor/admin-surfaces-theme-tokens`, versão `2026081511`. CI completo verde em
`MOODLE_405_STABLE` e `MOODLE_502_STABLE`; 194 testes PHPUnit e 23 cenários Behat (252
passos) no m501 com as checagens de acessibilidade ligadas.

As duas telas já estão reconstruídas sobre o Bootstrap do tema:

- **`managenotice.php`** — seis colunas, barra de filtros (nome, status incluindo "com
  conflito", vigência), 25 por página, tudo por AJAX via `\core_table\dynamic` e o web
  service do core. Contagem de resultados e estado vazio são renderizados **pela própria
  tabela**, dentro da região que o refresh substitui.
- **`editnotice.php`** — coluna única, seções colapsáveis nativas do moodleform (o
  formulário declara os próprios `header`), preview e público em painéis abaixo do
  formulário.

## Tarefa 1 — preview em modal

Hoje o preview é um painel abaixo do formulário (`templates/editor/preview_card.mustache`,
renderizado dentro de `.la-editor-panels` pela `shell.mustache`). O modelo aprovado pede um
**modal**, aberto pelo botão "Pré-visualizar" que já existe no cabeçalho da página com
`data-action="preview-fullscreen"`.

O que fazer:

- Usar `core/modal` — **não** um modal próprio. É o que traz foco preso, `Esc` para fechar e
  devolução do foco ao botão que abriu; o protótipo em `docs/mockups/edit-notice.html` não
  faz nada disso e diz isso no comentário.
- `amd/src/live_preview.js` (331 linhas) escreve nos slots de `.la-preview`. Ele precisa
  passar a escrever no corpo do modal, ou o modal precisa receber o mesmo markup. Prefira
  mover o markup para dentro do modal e manter os mesmos `data-slot`, para não reescrever a
  lógica de preenchimento.
- Manter as abas Desktop/Celular do mockup.

Cuidado registrado: o painel atual **funciona em qualquer largura**, que já era o ponto
principal — o rail antigo sumia abaixo de 1280 px de viewport. Virar modal é refinamento;
não regride a acessibilidade se o `core/modal` for usado, mas regride se for um modal
artesanal.

## Tarefa 2 — passada de acessibilidade nas páginas ao vivo

O verificador que escrevi rodou nos **mockups**, não nas páginas renderizadas. Ele mede
contraste por nó de texto contra o fundo efetivo, nome acessível de cada controle, tamanho
de alvo (24×24 da WCAG 2.2), ordem de títulos, semântica de tabela, ligação ARIA e ids
duplicados.

Para rodar nas páginas de verdade sem login: force um faildump do Behat (cenário temporário
que falha de propósito), sirva o HTML numa origem só — **as fontes passam por CORS contra a
origem do documento, então CSS vindo de outra porta deixa todo glifo do Font Awesome como
caixa** — e injete o script. O harness que fiz isso está descrito abaixo.

Achados conhecidos que já **não** devem aparecer: contraste do painel de público, alvos de
27×15 px na lista, `h1` duplicado, campos focáveis invisíveis. Os que devem sobrar são do
core: barra de status do TinyMCE e o id duplicado `yui3-css-stamp` (que vem do
`date_time_selector`, não do plugin — o plugin tem zero YUI).

## Tarefa 3 — o marcador de obrigatório, se incomodar

Resolvido sobrescrevendo `margin-left` em `.form-label-addon`. A saída oficial do core é
`set_display_vertical()`, que adiciona `.full-width-labels` — **não usei de propósito**:
no CSS compilado do stack, várias regras `.mform.full-width-labels` vêm do
`mod_interactivevideo` montado ali e desenham bordas e fundos com as variáveis daquele
plugin. Se for reconsiderar, confira o efeito num stack sem esse plugin antes.

## Como trabalhar neste repositório

- `mdl ci moodle-local_awareness --branch MOODLE_405_STABLE` e `--branch MOODLE_502_STABLE`
  antes de qualquer push. As duas pontas divergem: o phpcs do 5.02 reprovou um comentário
  começando em minúscula que o 5.01 deixou passar.
- Depois de bumpar `version.php`: `mdl upgrade m501 && mdl behat-init m501`, senão o Behat
  sai com zero cenários e **parece verde**.
- `mdl behat m501 @local_awareness` — seleção por caminho de arquivo **não funciona** neste
  setup; use a tag.
- O passo do gerador é `the following site notices exist`, **sem dois-pontos**. Com
  dois-pontos o Behat trata como passo diferente e reclama de step ausente.
- **Leia a string de idioma antes de escrever a asserção.** Errei o rótulo três vezes nesta
  sessão ("Path match" era "Apply to URL match", "Width" era "Modal width"), a ~4 minutos de
  Behat cada.

### Harness para ver as páginas renderizadas

Não é possível logar no stack por automação (não digito senha). O caminho que funcionou:

1. Force um faildump: cenário temporário em `tests/behat/`, `mdl behat-init m501`, rode a
   suíte pela tag, pegue o `.html` e o `.png` em `~/dev/moodle-dev/data/m501/faildumps/`.
   **Apague o cenário temporário depois.**
2. O `.png` pode ser lido direto como imagem — foi assim que encontrei o editor de conteúdo
   em meia coluna e o rótulo "Content" quebrado em duas linhas.
3. Para interagir, reescreva `http://webserver/` para caminhos relativos e sirva o arquivo
   por um proxy que repassa o que não achar para `localhost:8501`. Mesma origem para tudo,
   ou as fontes quebram.

## Armadilhas que já custaram tempo — não repita

- **`repeat(auto-fit, minmax(19rem, 1fr))` não limita colunas**: num card de 1083 px entrega
  três. Limitar pede `minmax(max(19rem, 45%), 1fr)`, que o stylelint do Moodle **recusa**
  (`Invalid value for "grid-template-columns"`), assim como recusa `@container` e
  `container-type`. A saída válida é flex com base percentual.
- **Seletor por markup interno de widget não casa**: `:has(.editor_tiny_wrapper)` era chute
  e não casava com nada, silenciosamente. Os campos largos são listados por `id`.
- **`flexible_table::$totalrows` é público** — declarar uma propriedade com esse nome na
  subclasse é erro fatal. A classe base já guarda o total filtrado via `pagesize()`.
- **`\core_table\dynamic` constrói a tabela só com `$uniqueid`** — todo argumento seguinte do
  construtor precisa ser opcional, e isso falha **apenas** por AJAX.
- **Um debounce é invisível para a espera do Behat.** Registre `core/pending` em volta da
  janela, não só da requisição.
- **Texto em `.visually-hidden` é texto da página.** Asserções `should not see` de página
  inteira perto do selo de conflito são não confiáveis — asserte pela contagem de resultados.
- **O lint de Mustache valida partials isolados**: um `form="..."` apontando para um
  formulário fora do partial reprova a validação de HTML.
