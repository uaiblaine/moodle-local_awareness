# Fase 3 do redesenho das telas administrativas — entregue

Continuação do PR #25 (`refactor/admin-surfaces-theme-tokens`). As fases 0 a 3 estão entregues.
Este documento deixou de ser uma lista de pendências: é o registro do que a fase 3 fez, do que
sobrou (e por quê), e do harness que mediu isso — que é a parte cara de redescobrir.

O que mudou, item a item, está no `CHANGELOG.md` sob a versão `2026081512`. Aqui fica só o que não
cabe num changelog.

## O que sobrou, e é do core

A sonda de acessibilidade roda contra o DOM renderizado das três superfícies (lista, editor,
diálogo de pré-visualização). Depois das correções, tudo o que ela ainda aponta pertence ao core:

- o interruptor de modo de edição na barra de navegação (30×15);
- três itens da barra de status do TinyMCE (o caminho do elemento, a contagem de palavras e a
  assinatura), todos com 17 px de altura;
- o botão de fechar do cabeçalho do modal do core, 16×31.

O último merece uma nota, porque parece nosso: o critério 2.5.8 da WCAG 2.2 aceita um **controle
equivalente na mesma página**, e o botão Fechar do rodapé do diálogo — que este plugin acrescentou
ao pedir `buttons: {cancel: …}` ao `core/modal_cancel` — é exatamente isso. Não é uma falha do
diálogo; é o motivo de haver um botão no rodapé em vez de confiar só no × do cabeçalho.

O id duplicado `yui3-css-stamp`, previsto na rodada anterior, não apareceu: o formulário de criação
não renderiza `date_time_selector` nas seções expandidas por padrão.

## O harness — como repetir a medição

Não dá para logar no stack por automação. O caminho, em quatro passos:

1. **Force um faildump.** Um arquivo temporário em `tests/behat/` com uma cena por superfície, cada
   uma terminando numa asserção que não pode valer. Tag própria (por exemplo `@la_a11y_dump`) e
   **sem** a tag `@local_awareness`, para não entrar na suíte normal. Depois `mdl behat-init m501`
   — um arquivo `.feature` novo não existe para o Behat sem isso — e `mdl behat m501 @la_a11y_dump`.
   Os `.html` e `.png` saem em `~/dev/moodle-dev/data/m501/faildumps/<timestamp>/`. **Apague o
   arquivo temporário e rode `mdl behat-init` de novo quando terminar.**

2. **Sirva o dump pela própria origem do stack.** O `wwwroot` do site de Behat é
   `http://webserver`, que só resolve dentro da rede do compose. Reescreva para caminhos relativos,
   remova os `<script>` (o dump já contém o que o JavaScript construiu; reexecutar só faz a página
   sair do estado capturado) e grave em `~/dev/moodle-501/public/_a11y/<nome>.html`. O Apache serve
   arquivo existente direto — o `RewriteCond %{REQUEST_FILENAME} !-f` do roteador não intercepta —
   então a página abre em `http://localhost:8501/_a11y/<nome>.html` com CSS e fontes na **mesma
   origem**. Isso não é detalhe: `@font-face` passa por CORS contra a origem do documento, e uma
   folha vinda de outra porta transforma todo glifo do Font Awesome em caixa. Um proxy também
   resolve, mas precisa reescrever o corpo do CSS além do HTML; o webroot não precisa de nada.

3. **Purgue os caches antes de medir.** `mdl behat-init` reconstrói o CSS do *site de Behat*; a
   porta 8501 continua servindo o CSS compilado do site normal. Sem
   `admin/cli/purge_caches.php` a medição é feita sobre a folha antiga — e o sintoma é o pior
   possível, porque a página abre e parece plausível.

4. **Injete a sonda.** `XMLHttpRequest` síncrono + `eval` sobre um `.js` no mesmo diretório servido.
   Ela mede, contra os estilos computados: contraste por nó de texto sobre o fundo efetivo (com
   composição de alfa, e recusando-se a dar número quando há imagem de fundo), nome acessível de
   cada controle, tamanho de alvo (24×24 da 2.5.8), ordem de títulos, semântica de tabela,
   referências ARIA que não resolvem e ids duplicados.

**A sonda também erra.** A primeira versão acusou três botões do moodleform de não terem nome
acessível: para um `input` do tipo submit/button o nome vem do atributo `value`, e `textContent` é
sempre vazio. Um verificador com uma classe de falso positivo é pior do que nenhum — ensina a
passar os olhos pela saída. Mutation-teste cada regra antes de confiar nela.

## Armadilhas novas — não repita

- **Custom properties não alcançam um `core/modal`.** Elas herdam pela árvore do DOM, e o core
  anexa o modal a um elemento próprio em `document.body` — irmão da página, não descendente. Um
  bloco de tokens declarado em `.local-awareness-editor` deixa todo `var(--la-*)` dentro do diálogo
  sem valor, e valor ausente **não** cai para o literal do `var()`: invalida a declaração inteira no
  tempo de valor computado. O herói ficou sem cor, o palco sem fundo e "Got it" virou texto branco
  sobre nada. Vale para a regra de `:focus-visible` pelo mesmo motivo — foi o segundo lugar onde
  isso mordeu, depois de corrigido o primeiro.
- **Escrever a regra não é aplicá-la.** O marcador de obrigatório estava registrado como resolvido
  na versão anterior e nunca funcionou: `.mform:not(.full-width-labels) .col-form-label
  .form-label-addon` do core pontua (0,4,0) — `:not()` conta o argumento — e a sobrescrita
  pontuava igual, o que deixa a ordem decidir; na folha compilada a regra do core cai depois. Só a
  captura de tela mostrou. Meça no CSS compilado (`curl .../theme/styles.php/<tema>/1/all`) quando
  uma sobrescrita "não pega".
- **`mdl grunt` local não mostra o que o `mdl ci` reprova.** Um `no-nested-ternary` passou pelo
  grunt local sem imprimir uma linha e derrubou o leg do 5.02. Rode `mdl ci` antes de concluir que
  o front-end está limpo.
- **Um estado pressionado sobrevive ao fechamento do diálogo.** Com `removeOnClose: false` o modal
  é escondido, não destruído. Se a largura for decidida em dois lugares — o clique no botão e o
  `sync()` da reabertura — o `aria-pressed` continua num viewport que não está na tela. Uma função
  decide, a partir de um estado só.
