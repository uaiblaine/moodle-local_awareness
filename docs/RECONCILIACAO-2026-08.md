# Reconciliação da auditoria — `local_awareness`

Estado de cada um dos **198 achados** do [`AUDIT-2026-08.md`](AUDIT-2026-08.md) contra a `main` em
`9e3bc72`, versão `2026081603`. A auditoria descrevia o commit `896dfc2` (versão `2026080700`); entre
os dois correram os PRs #2 a #30.

Este documento existe porque o cabeçalho da auditoria sempre disse que ela era "o retrato do ponto de
partida, não a lista do que ainda está aberto", e essa lista nunca foi feita. Os 191 achados numerados
mais os 7 do crítico de completude passam a ter estado conhecido.

## Como foi levantado

Duas passagens independentes por lote, com o código aberto nas duas:

1. **Reconciliação** — 16 agentes, um por área, cada um obrigado a citar caminho e linha do código
   **atual** (ou o comando cujo resultado vazio prova a ausência). O `CHANGELOG.md` foi tratado como
   pista para localizar a correção, nunca como prova de que ela existe.
2. **Refutação** — 16 agentes adversariais, atacando cada veredito nos **dois** sentidos: provar que
   um achado dado por fechado continua no código, e provar que um dado por aberto já foi corrigido.

Os dois passes concordaram em 196 dos 198. As duas divergências (M6 e M8) foram medidas à mão e
resolvidas a favor do refutador.

Catorze achados de maior peso — H1, H2, H5, H6, M10, M15, M18, M19, C3, C4, TPL-03, PRIV-04, REPO-02,
REPO-12 — foram medidos à mão, de forma independente e **antes** de os agentes devolverem. Doze bateram
exatamente. **Dois não bateram, e nos dois o erro foi meu:** dei o C3 e o C4 por corrigidos porque medi
o mecanismo principal — o conteúdo do aviso passou a ser guardado como escrito e filtrado no render, os
convidados deixaram de escrever linhas partilhadas — e não fui procurar o resto. Os agentes foram: os
**títulos** continuam sem filtro em dois caminhos, e o `forcelogout` continua a despejar um convidado
para o login. Ambos são *parcial*, não *corrigido*. Fica registado porque é o modo de falha que este
repositório já produziu duas vezes — medir a correção e não medir o que ficou ao lado dela.

**Vocabulário.** *corrigido* — o defeito saiu do código. *sem objeto* — o código que o continha foi
apagado e nada o herdou. *parcial* — parte fechada, parte não, com o que falta nomeado. *aberto* — o
defeito continua lá.

## Placar

| Severidade | corrigido | sem objeto | parcial | aberto | total |
|---|---:|---:|---:|---:|---:|
| Alto | 10 | 0 | 0 | 0 | **10** |
| Médio | 26 | 4 | 3 | 0 | **33** |
| Crítico de completude | 5 | 1 | 1 | 0 | **7** |
| Baixo | 81 | 9 | 3 | 3 | **96** |
| Informativo | 41 | 7 | 0 | 4 | **52** |
| **Total** | **163** | **21** | **7** | **7** | **198** |

**Os dez bloqueadores estão todos fechados**, e não por remoção: os dez são *corrigido*, nenhum é
*sem objeto*. Somando corrigido e sem objeto, **184 dos 198 estão encerrados; 14 continuam a merecer
uma decisão** — e **nenhum achado Alto ou Médio continua aberto**: os quatro Médios que restam são
parciais, com o que falta nomeado.

> **Atualizado em 2026-08-16 pela fase 5** (versão `2026081604`, branch `fix/phase-5-residue-and-coverage`).
> Oito achados passaram a *corrigido* nessa fase: **C6** (`\Throwable` no gancho de rodapé), **C4**
> (títulos filtrados nos dois caminhos que faltavam), **X1-01** (os eventos `awareness_enabled` e
> `awareness_disabled` finalmente disparam), **PRIV-02** e **PRIV-03** (a erasure passou a respeitar
> quem o aprovador aprovou), **M20** e **TEST-01** (os testes que faltavam), e **TPL-03** (o exemplo
> de contexto do painel de público). Os números acima já os contam.
>
> **Atualizado de novo pela fase 6** (versão `2026081605`, branch `test/phase-6-coverage`). Dez
> achados passaram a *corrigido*: **M11**, **M26**, **M27**, **M28**, **M29**, **M30** (os buracos
> de cobertura), **M31**, **M32**, **M33** (os pontos cegos do `bootstrap_compat_test`) e
> **SQL-04** (o convidado excluído por um id literal). Alargar o observador encontrou de imediato
> dois defeitos reais que ninguém procurava — ver abaixo.
>
> **Atualizado pela fase 7** (versão `2026081606`, branch `fix/phase-7-functional`). Catorze
> achados passaram a *corrigido*: **WS-03** (o seletor de papéis não encontrava papéis padrão pelo
> rótulo que mostra), **BIZ-04** (uma linha de conformidade por recusa, não por pessoa),
> **BIZ-07** (o padrão de caminho sem âncora inicial), **TEST-07**, e a infraestrutura do
> repositório — **REPO-03**, **REPO-05**, **REPO-06**, **REPO-09**, **REPO-12**. Mais cinco que já
> estavam fechados por fases anteriores e continuavam a ler-se como abertos por serem duplicados:
> **TPL-04**, **TPL-07**, **RB-05**, **WS-11**, **DB-05**.
>
> **Atualizado pela fase 8** (versão `2026081607`, branch `fix/phase-8-killswitch`): **WS-08**
> fechado. A primeira tentativa pôs a verificação dentro dos helpers de entrega e partiu 34 testes;
> a lição não era emendar 34 testes, era que o sítio estava errado. O interruptor pertence a cada
> **ponto de entrada** — que é onde o `should_load_on()` já o verifica para o gancho — e não à
> lógica de domínio. No sítio certo o raio de ação caiu para 14 testes, todos no ficheiro que
> testa exatamente essa fronteira.
>
> **Atualizado pela fase 9** (versão `2026081608`, branch `fix/phase-9-javascript`): **JS-02** e
> **JS-03** fechados, com o `amd/build` reconstruído no mesmo commit. Nenhum dos dois é alcançável
> pelos testes que esta frota corre — o PHPUnit não carrega JavaScript e o Behat não reproduz uma
> resposta que chega fora de ordem — por isso o observador é um contrato sobre a fonte, como o
> `criteria_contract_test` e o `bootstrap_compat_test` ao lado. Uma das asserções passou com a
> guarda apagada até o teste de mutação a apanhar; a correção está escrita no próprio ficheiro.
>
> **Atualizado pela fase 10** (versão `2026081609`, branch `fix/phase-10-privacy`): **PRIV-01** e
> **PRIV-04** fechados, com catorze chaves novas nos dois packs.
>
> **Atualizado pela fase 11** (versão `2026081610`, branch `fix/phase-11-behavioural`), e essa fase
> começou por uma correção ao resumo da anterior. Escrevi que depois da fase 10 "não restava nada
> substantivo"; **era falso**, e o censo nunca o disse — eu é que colapsei *severidade Baixa* com
> *cosmético*. Uma releitura do conjunto aberto encontrou oito candidatos com comportamento em
> jogo. Investigados aos pares, **seis eram reais e dois não**: fechados **LANG-01** (a ajuda do
> campo "É perpétuo" existia nos dois packs e nunca chegava ao autor), **RB-02** (a coluna
> `reqcourse` somava ids de curso), **M4** (escrever um título re-enfileirava uma tarefa adhoc a
> cada pausa), **WS-14** (listas de critérios sem limite a chegar ao `get_in_or_equal`) e
> **TEST-09** (um job falhado gravava contagem 0 e carimbava o hash, tornando a falha pegajosa e
> com ar de resultado); refutados **WS-13** e **BIZ-09**.
>
> **Atualizado pela fase 12** (versão `2026081611`, branch `fix/phase-12-biz08-and-cleanup`).
> **BIZ-08 foi decidido pelo dono do produto pela segunda opção** e está fechado: a cláusula
> `reqcourse = 0` saiu, o `reqcourse` passou a ser a regra de público que o resto do código já
> assumia, e as duas metades caíram — o intervalo de reexibição passa a valer e um aviso aceite
> deixa de voltar. A string de ajuda foi reescrita nos dois packs. Mais onze itens fechados nesta
> primeira vaga de limpeza e onze confirmados como já corrigidos por fases anteriores, que só se
> liam como abertos por o censo ter sido escrito antes delas.
>
> **Atualizado pela fase 13** (versão `2026081612`, branch `fix/phase-13-htmlwriter`): **zero
> `html_writer` em código de plugin** — TPL-01, LANG-06 e X3-01 fechados. As células da tabela de
> gestão passaram a seis templates Mustache. Um teste de contagem de queries reprovou no processo e
> a medição mostrou porquê: o primeiro `render_from_template()` de um pedido custa nove leituras de
> uma vez, e zero por linha depois disso — o teste media a inicialização do core e atribuía-a a esta
> coluna. Aquecido antes de contar, as dez linhas custam **zero**, portanto o comportamento por
> linha melhorou em vez de piorar.
>
> **Atualizado pela fase 14** (versão `2026081613`, branch `fix/phase-14-pluginfile-audience`), e
> esta é a correção mais séria de toda a sessão. O **SEC-05** era real e é de **segurança**: o
> `local_awareness_pluginfile()` verificava apenas o flag `enabled`, portanto o anexo de um aviso
> dirigido a uma coorte era servido a qualquer utilizador autenticado que adivinhasse o id — ao
> mesmo tempo que o corpo do próprio aviso lhe era corretamente negado pelo `get_notices()`. O
> modelo de segurança do plugin trata o público como fronteira de confidencialidade; o callback de
> ficheiros não tratava. **Exploit reproduzido, não deduzido**: com o portão antigo o teste imprime
> o conteúdo do anexo e o processo termina dentro do `send_stored_file()`.
>
> E outra vez a mesma lição, agora ao contrário: eu tinha descrito os 42 restantes como
> "apresentação ou higiene" na mensagem anterior. Sete não eram, e um deles era isto.
>
> **Decisão sobre o REPO-10 (2026-08-17): continua aberto de propósito.** O `version.php` declara
> `MATURITY_STABLE` e o repositório não tem nenhuma tag; o dono do produto optou por continuar assim
> por agora. Fica registado porque o PR #40 corrige uma divulgação de ficheiros e, sem tag, não há
> versão publicada para onde apontar quem corra o plugin a partir de um zip. Reavaliar quando
> houver utilizadores externos — não é dívida de papelada enquanto não houver.
>
> **Atualizado pela fase 15** (versão `2026082300`, branch `fix/phase-15-functional`). Seis achados
> fechados: **WS-06**, **WS-10**, **RB-03**, **RB-06**, **SQL-05** e **LANG-05**. Os oito candidatos
> foram investigados aos pares — um agente a propor a correção, um adversarial a tentar refutá-la — e
> **três das oito propostas não sobreviveram à refutação**, cada uma por uma razão que teria passado
> em revisão:
>
> - A do **WS-10** instrumentava só o web service. As linhas de job nascem em **dois** sítios, e o
>   segundo é o `notice_audience::refresh()` — por onde passam o gravar de um aviso e o botão
>   Recalcular. O log resultante teria mostrado as pré-visualizações especulativas do editor e
>   nenhuma recalculação deliberada, debaixo de um docblock a afirmar o contrário.
> - A do **RB-01** reabria, pelo download em PDF, exatamente o buraco que o PR #40 fechou: o writer
>   de PDF do core lê ficheiros por hash de caminho sem verificação nenhuma, portanto resolver os
>   `@@PLUGINFILE@@` na coluna do relatório entrega os bytes do anexo a quem estiver no público do
>   relatório. Foi levado ao dono do produto em vez de ser resolvido em silêncio, e **decidido na
>   fase 16 pela correção completa, com a consequência do PDF documentada**.
> - A do **RB-03** propunha `?int` no callback. Medido, não deduzido: o `avg()` é compatível com uma
>   coluna inteira e entrega um float sob strict types, portanto `?int` rebenta.
>
> **Uma correção ao método, não só ao código.** A varredura do `test_no_event_class_is_unreachable`
> lia **um único ficheiro**, o `helper.php`. É a mesma forma de erro que a frota já registou com
> listas de inclusão de diretórios: o primeiro gatilho a aparecer noutro sítio seria dado como código
> morto, e a reparação óbvia seria acrescentar um segundo nome de ficheiro em vez de reparar na forma
> do erro. Passou a varrer a raiz do plugin com lista de exclusão, com guarda de não-vacuidade sobre
> a contagem de ficheiros lidos.
>
> **E uma mutação minha que não mutou nada.** Ao verificar o teste do RB-06 corri um `sed` cujo
> padrão não casou; o teste passou e eu quase registei isso como "a guarda não é necessária". Refeita
> a sério, a mutação É apanhada — sem o `manager::reset_caches()` os dois relatórios devolvem o mesmo
> nome, porque as instâncias são cacheadas por `<reportid>:<userid>` e os parâmetros não entram na
> chave. Um teste de mutação que não altera o ficheiro é indistinguível de um teste que passa.

> **Atualizado pela fase 16** (versão `2026082301`, branch `fix/phase-16-rb01-biz11`). Os dois
> achados que a fase 15 levou a decisão de produto foram decididos e fechados: **RB-01** pela
> correção completa, com a consequência do PDF escrita no CHANGELOG e aqui em vez de descoberta mais
> tarde por alguém; e **BIZ-11** pela regra "um limite a zero é ilimitado desse lado".
>
> **A lição desta fase é sobre testes que modelam em vez de medir.** O `window_test` avalia o
> prefiltro cacheado escrevendo em PHP o que o SQL *quer dizer*. Quando a mutação tornou o prefiltro
> simétrico — que é o defeito real, porque o cache não tem TTL e um aviso agendado deixaria de
> aparecer — o `window_test` **passou**, e só o `enabled_notices_window_test`, que corre a query
> verdadeira contra a base, a apanhou. Um modelo do código não é o código. Onde o defeito vive na
> fronteira com a base de dados, o teste tem de atravessar essa fronteira.

> **Atualizado pela fase 17** (versão `2026082302`, branch `fix/phase-17-remaining`). Dezanove
> achados encerrados: dezassete corrigidos — JS-06, JS-08, JS-09, JS-10, JS-11, TPL-05, TPL-06,
> TPL-08, TPL-09, TEST-02, TEST-03, TEST-05, RB-14, RB-16, WS-12, WS-18, LANG-18 — e dois *sem
> objeto*: **LANG-09**, que é o WS-17 redigido noutra secção, e **DB-06**, que é a mesma alegação do
> BIZ-09 e foi refutada pela segunda vez.
>
> **O que NÃO foi feito, e porquê.** Os seis grupos foram investigados aos pares e **os seis
> veredictos adversariais vieram negativos** — nenhum plano passou intacto. Três consequências:
>
> - O **WS-01** não é entregável como foi desenhado: passar o payload de um blob JSON `PARAM_RAW`
>   para uma estrutura real parte 20 pontos de chamada de testes e introduz um modo de perda
>   silenciosa que nenhum dos testes propostos veria. Fica *parcial*, com o mecanismo nomeado.
> - O **WS-17** (partir o `external.php` monolítico em nove ficheiros) é mecânico mas é churn puro;
>   fica aberto de propósito, e o LANG-09 fecha por ser o mesmo achado.
> - O plano dos **templates** teria produzido **CSS inválido**: os intervalos de linha estavam
>   errados por uma linha e a substituição literal deixaria um `*/` órfão e uma regra por fechar.
>
> **A lição desta fase é sobre varreduras que só veem o que já está certo.** O
> `test_data_api_attributes_are_paired` lê zero linhas — o plugin não liga nada ao data-API do
> Bootstrap — portanto a regra não podia falhar. E o meu próprio teste novo tinha a mesma forma: um
> rótulo escrito à mão no formulário passava, porque a varredura só inspecionava atributos **já
> escritos na forma correta**. Uma varredura que casa o padrão bom e depois o verifica é cega
> exatamente ao caso que existe para apanhar; enumerar tudo primeiro e só então classificar é a
> diferença.
>
> **E um erro meu, já mergeado.** As fases 15 e 16 apagaram dois cabeçalhos `###` deste documento —
> o script que virava o veredito de um achado delimitava-o pelo próximo marcador, e um achado que
> era o **último da sua secção** engolia o cabeçalho seguinte. Repostos aqui, e o delimitador passou
> a parar no que vier primeiro.

> *Texto original da decisão, mantido por registo:*
>
> ~~**Continua aberto e é decisão de produto: BIZ-08.**~~ Um aviso com curso obrigatório ignora a
> visualização registada, portanto o `resetinterval` não tem efeito sobre ele e o aviso volta a
> cada sessão. Pior, na combinação `reqcourse > 0` + `reqack = 1` + `resetinterval = 0`, carregar
> em Aceitar **não grava nada**. As duas opções não são equivalentes: acrescentar
> `OR resetinterval > 0` ao predicado é a mudança mínima e fecha metade; retirar a cláusula
> `reqcourse = 0` inteira faz do `reqcourse` a regra de público que os outros seis pontos do código
> já assumem e fecha as duas metades, ao custo de reescrever a string de ajuda. Fica por decidir
> por quem manda no produto, não por quem lê o código.

## O que sobrevive, e por quê

O padrão é nítido, e não é o que se esperaria de uma lista por corrigir: **o código foi corrigido, os
testes que o guardam não foram escritos.** Dos 13 achados Médios ainda abertos ou parciais, nove são
buracos de cobertura (M11, M26, M27, M28, M29, M30) ou pontos cegos do próprio
`bootstrap_compat_test` (M31, M32, M33).

O provedor de privacidade era o caso extremo, e é o que mostra por que este padrão importa: H5, H6,
M18 e M19 tinham sido corrigidos com cuidado — as quatro tabelas entram nos quatro caminhos e a
erasure purga o cache MUC — e **nada disso tinha teste**. O teste de conformidade do core verifica
que uma tabela com `userid` está *declarada*; nunca chama `export_user_data()` nem método de
eliminação nenhum, portanto qualquer uma das quatro correções podia ser revertida com a suíte verde.
A fase 5 escreveu esses testes (M20) e, ao escrevê-los, encontrou mais dois defeitos no mesmo
ficheiro — PRIV-02 e PRIV-03, a erasure a ignorar quem o aprovador tinha aprovado. **Foi o teste que
os achou, não a leitura.**

A fase 6 fechou o resto e repetiu o padrão pela terceira vez. Alargar o `bootstrap_compat_test`
para varrer a raiz do plugin em vez de três diretórios nomeados fez aparecer, no primeiro
arranque, que **`report/acknowledged_systemreport.php` e `report/dismissed_systemreport.php` nunca
chamavam `bootstrap::mark_page()`** — o polyfill do Bootstrap 4 está preso à classe de body que
essa chamada acrescenta, portanto as duas páginas renderizavam sem estilo no Moodle 4.5 com todos
os gates verdes. O teste antigo não podia vê-las: fazia `glob('*.php')` só na raiz. **Três fases
seguidas em que o teste encontrou o que a leitura não encontrou** é o resultado mais transferível
deste trabalho todo.

Os restantes agrupam-se em três temas de dívida, todos de baixa severidade e alta contagem: o
`html_writer` (que **cresceu** de 4 para 24 usos, todos em `all_notices.php` e nos dois
`*_systemreport.php`), os ficheiros de template da frota que faltam ao repositório, e a deriva entre
docblocks de Mustache e o que os templates realmente leem.

### O que a fase 5 fechou, e o que isso ensina

1. **C6 — `catch (\Exception)` num gancho que corre em todas as páginas.** Apanhava `\Exception`, não
   `\Throwable`, à volta de `helper::has_candidate_notices()`. Qualquer `Error` — um setter tipado a
   receber null, um argumento errado a chegar a `completion_info` — seria fatal em cada página do
   site para cada utilizador autenticado, recuperável só desativando o plugin pela base de dados. O
   resto do plugin já usava `\Throwable` em sete sítios (`page_probe` ×4, `estimate_audience` ×2,
   `helper` ×2); este tinha ficado para trás. Uma palavra.

2. **C4 (resto) — os títulos nunca eram filtrados.** O corpo do aviso já tinha a arrumação certa
   (guardado como escrito, `format_text()` no render); o **título** continuava no caminho antigo, cru
   do `to_record()` para o payload do modal e cru para a célula da tabela e o seu atributo `title`.
   Uma marcação multilang aparecia literal no cabeçalho, por cima de um corpo que resolvia bem. Os
   dois caminhos passam agora por `format_string()`, e o `pathmatch` ao lado — também `PARAM_RAW`,
   também emitido por `html_writer::tag()` — passa por `s()`.

3. **X1-01 — `awareness_enabled` e `awareness_disabled` nunca tinham disparado**, e este é o achado
   que mais devia incomodar. `enable_notice()` e `disable_notice()` criavam ambos `awareness_updated`,
   debaixo de comentários que diziam "Log enabled event" e "Log disable event". As duas classes
   estavam completas, com strings mantidas nos dois packs, e aparecem na referência de eventos da
   administração — onde um admin podia construir uma regra de monitorização que nunca dispararia.
   **O item 4.5 do `PLANO-correcoes.md` dá isto por fechado**, e o `git log` das duas classes mostra
   que nada lhes tocou desde uma normalização de cabeçalhos. É a quarta vez que este repositório
   produz a ilusão de um defeito corrigido, e a primeira dentro do próprio plano escrito para a
   evitar.

4. **PRIV-02 e PRIV-03 — a erasure ignorava o aprovador.** `delete_data_for_users()` tirava o userid
   do contexto e ignorava a lista aprovada, portanto um utilizador que o aprovador do pedido tinha
   retirado era apagado na mesma: uma recusa transformada em eliminação. **Nenhum destes dois foi
   encontrado a ler o código — foram encontrados a escrever o teste do M20**, que é o argumento
   inteiro para fechar os buracos de cobertura que restam.

5. **TPL-03 — o exemplo de contexto do `audience_panel` usava uma chave que o template não lê.**
   Docblock e exemplo diziam `labelkey`; o template lê `{{label}}` e `{{key}}`. O lint renderizava o
   laço com `<dt>` e `<dd>` vazios — exatamente o modo de falha que o `mustache-continue-on-error`
   escondia até o H1 o ter removido.

### Uma correção ao próprio plano

O achado registado no fecho da fase 4 como "`rule_describer::describe()` não sabe nomear o
`filter_role_context`" **não se sustenta**. O `filter_role_context` nunca chega ao `describe()`: é um
*modificador* do `filter_role` ([`estimator.php:239`](../classes/audience/estimator.php)) e não consta
de `AUDIENCE_FIELDS` nem de `CONTEXT_FIELDS`, portanto nunca é passado como chave de regra. E as cinco
chaves que o `describe()` trata são **exatamente** as cinco strings `audience:rule:*` que carregam um
`{$a}`; o `ruleLabel()` descarta o `display` quando o rótulo não tem marcador
([`audience_estimator.js:252`](../amd/src/audience_estimator.js)). A função está alinhada com os seus
consumidores; apagar a string órfã `audience:rule:filter_role_context` foi a decisão certa.

O que existe é mais estreito, e é decisão de produto: o chip do papel diz "Has selected roles" tanto
para uma regra de site inteiro como para uma limitada a um curso, e a fase 2 fez desse escopo algo que
muda a contagem. Dois avisos com chips idênticos podem mostrar números diferentes, com razão.

### M6 e M8 — as duas divergências entre os passes

Ambos os passes concordaram que a correção existe; discordaram sobre se está completa. O refutador
tem razão e a medição à mão confirma-o: `is_notice_available_to_user()` fecha os alvos por coorte,
papel, `reqcourse`, desativado e não-iniciado, mas **não** reaplica os filtros que dependem da página
(curso, categoria, formato, tema, competências), e o `user_matches_role_filter()` devolve `true`
quando `filter_role` está vazio ([`helper.php:1813`](../classes/helper.php)). Um aviso cujo único alvo
seja um curso continua a poder ser reconhecido ou pré-dispensado por qualquer utilizador autenticado
que enumere o id.

O próprio código diz isto em voz alta ([`helper.php:845-852`](../classes/helper.php)): um pedido de
escrita não tem uma origem de confiança para a URL da página. É uma escolha documentada, não um
descuido — o que faz disto um item de decisão, não um defeito a fechar em silêncio.

---

## Censo completo

Um item por achado, na ordem da auditoria. Para os que continuam abertos ou parciais, a linha *Falta*
diz o que resta e onde.

### Bloqueadores (H1–H10) — 0 de 10 em aberto

- **H1** · corrigido — mustache-continue-on-error disables the Mustache gate on all four CI legs for a failure that is a redundant attribute
  <br>`.github/workflows/ci.yml` (read in full, 45 lines) declares four jobs — ci-502, ci-501, ci-500, ci-405 — and none carries `mustache-continue-on-error`. `grep -rn 'mustache-continue-on-error' .` matches only CHANGELOG.md:1172/1211 and docs/AUDIT-2026-08.md.
- **H2** · corrigido — Manage-notices page dies with a fatal TypeError once a targeted cohort is deleted or is not visible to the current manager
  <br>classes/helper.php:1139 now reads `return $cohorts[$cohortid] ?? '-';`, preceded by the comment at :1136-1138 naming both causes (notice outliving the cohort, cohort_get_all_cohorts() returning only visible cohorts).
- **H3** · corrigido — On a site with no cohorts, every notice saved through the form stores the literal '_qf__force_multiselect_submission' as its cohort and is hidden from all users
  <br>classes/helper.php:238-241 (inside `sanitise_data()`) now maps submitted cohorts through `self::allowed_cohorts($submitted)`, and `sanitise_data()` is called on both write paths — create_new_notice at helper.php:118 and update_notice at helper.php:199.
- **H4** · corrigido — Unaliased COUNT() aggregate is read as ->count — the link-click report is blank on MariaDB
  <br>classes/persistent/linkhistory.php:83 reads `SELECT h.hlinkid, l.text, l.link, COUNT(h.hlinkid) AS clickcount`, with the two-line comment at :81-82 stating the PostgreSQL-vs-MariaDB naming reason.
- **H5** · corrigido — local_awareness_audience_jobs holds a userid but is completely absent from the privacy provider
  <br>classes/privacy/provider.php:274-282 declares the fourth table `local_awareness_audience_jobs` (userid, criteria, timecreated); provider.php:232 deletes from it inside the shared `delete_all_data_for_userid()` that all three delete entry points now call (:144, :164, :214); provider.php:105-107 and :…
- **H6** · corrigido — get_contexts_for_userid only looks at local_awareness_lastview, so users with only link-click or acknowledgement rows are invisible to export and erasure
  <br>classes/privacy/provider.php:55-70 replaces the lastview-only join with `SELECT c.id FROM {context} c WHERE c.contextlevel = :contextuser AND c.instanceid = :userid AND (EXISTS … lastview OR EXISTS … ack OR EXISTS … hlinks_his OR EXISTS … audience_jobs)`, each placeholder bound once at :72-75 so fix…
- **H7** · corrigido — Report Builder text columns emit raw database values — core's user entity escapes every one of them with s()
  <br>Every column the finding enumerated now carries an escaping callback: acknowledgement.php:92 (username), :103 (firstname), :114 (lastname), :125 (idnumber) all use `static fn($value): string => s((string) ($value ?? ''))`; acknowledgement.php:136 (noticetitle) and notice.php:88 (title) use format_st…
- **H8** · corrigido — acknowledgement:action typed ?int fatals under any numeric aggregation — reproduced, affects both system reports
  <br>classes/reportbuilder/local/entities/acknowledgement.php:151 now declares the callback `static function ($value): string` — untyped — with the comment at :152-159 naming reportbuilder/classes/local/aggregation/base.php's strict_types as the reason, and it falls through to `format_float((float) $valu…
- **H9** · corrigido — Editing a notice silently deletes every file embedded in its content
  <br>The draft area moved into the form: classes/form/notice_form.php:522-552 `get_default_data()` reads `$noticeid = (int) $this->get_persistent()->get('id')`, calls `file_prepare_draft_area(..., 'content', $noticeid > 0 ? $noticeid : null, helper::get_file_editor_options(), $content['text'])` and sets…
- **H10** · corrigido — The five test_stress_datasource tests are gated behind PHPUNIT_LONGTEST, which moodle-plugin-ci never sets — they hide three real failures
  <br>`grep -rn 'PHPUNIT_LONGTEST' tests/ .github/` returns no `markTestSkipped` — only five docblocks stating the gate was removed: all_notices_test.php:191, acknowledged_notices_test.php:186, dismissed_notices_test.php:185, link_history_test.php:171, notice_views_test.php:176.

### Importantes (M1–M33) — 3 de 33 em aberto

- **M1** · corrigido — A stale Claude Code worktree is committed as a gitlink (submodule entry) with no .gitmodules, breaking every git submodule command
  <br>`git ls-files -s \| awk '$1=="160000"'` returns 0 rows and `git ls-files -s .claude` returns nothing; `git submodule status` now exits 0 with empty output. /Users/uaiblaine/dev/moodle-local_awareness/.gitignore lines 14-17 add `.claude/worktrees/` with a comment naming this exact gitlink incident.
- **M2** · corrigido — No .gitattributes — the released zip ships .github/, .gitignore and .claude/ onto production Moodle sites
  <br>/Users/uaiblaine/dev/moodle-local_awareness/.gitattributes exists and is tracked (`git ls-files .gitattributes`), export-ignoring /.claude, /.github, /.gitignore, /docs, phpcs.xml etc. while keeping tests/.
- **M3** · corrigido — The audience estimator always evaluates role rules with 'any context' because the client never sends filter_role_context
  <br>/Users/uaiblaine/dev/moodle-local_awareness/amd/src/audience_criteria.js:177 now sets `criteria.filter_role_context = parseInt(readSingleValue('filter_role_context'), 10) \|\| 0;` inside the `if (roles.length)` branch (comment at 169-176 cites audit finding M3), matching estimator::normalise()'s own…
- **M4** · corrigido — The estimator never compares the criteria it just read against the previous ones, so unrelated typing re-queues an ad-hoc audience job every debounce cycle
  <br>Fechado na fase 11 (2026-08-17): o `trigger()` compara os critérios com os anteriores ANTES de qualquer efeito colateral e sai cedo; os botões passam `force` para que um clique peça sempre.
- **M5** · sem objeto — live_preview binds to TinyMCE only via the AddEditor event and only if window.tinymce already exists, so the content pane never updates while the author types
  <br>amd/src/live_preview.js and amd/build/live_preview.min.js{,.map} were deleted in commit 0181eae (`git log --diff-filter=D --name-only -- amd/src/live_preview.js`), and `ls amd/src` shows no successor live pane.
- **M6** · **parcial** — acknowledge_notice accepts any notice id — a user can forge an acknowledgement for a notice they were never shown
  <br>/Users/uaiblaine/dev/moodle-local_awareness/classes/external.php:132-139 now fetches the notice and calls `helper::is_notice_available_to_user($notice)` before helper::acknowledge_notice(). That helper (classes/helper.php:859-890) re-checks enabled + has_started() (:862), cohort membership (:866-871), the role filter via user_matches_role_filter() on the stored filtervalues (:875-878) and the reqcourse completion rule (:880-887); its docblock at :841-852 states which display checks it deliberately omits (the page-d…
- **M7** · **parcial** — track_link writes an unvalidated, unbounded row per call — click history can be fabricated and the table flooded
  <br>Validation added: classes/helper.php:1070-1078 loads the link with `noticelink::get_record(['id' => $linkid])`, returns status=false when absent, loads the parent notice and returns false unless `self::is_notice_available_to_user($notice)`; :1061-1063 short-circuits guests. classes/external.php:176-181 still delegates straight to helper::track_link(), which is fine now that the helper validates.
  <br>*Falta:* Impact (a) of the finding — fabricating clicks on arbitrary link ids — is closed. Impact (b), the unbounded row flood, remains for any link on a notice the caller is genuinely served: is_notice_available_to_user() does not apply the page-dependent filters, so a plain site-wide enabled notice is 'available' to every authenticated user, who can loop track_link on its links and insert unlimited {local_awareness_hlinks_his} rows, inflating their own click count.
- **M8** · **parcial** — dismiss_notice accepts any notice id — notices can be pre-dismissed before they are ever displayed
  <br>/Users/uaiblaine/dev/moodle-local_awareness/classes/external.php:74-81 fetches the notice and gates on `helper::is_notice_available_to_user($notice)` before helper::dismiss_notice(), with the comment at :76-78 naming the client-supplied-id reason. The gate (classes/helper.php:859-890) rejects disabled notices and notices whose timestart has not arrived (:862 via has_started(), :810-812), so the pre-dismissal of not-yet-enabled or future-scheduled notices described in the finding no longer writes a lastview row.
- **M9** · corrigido — get_notices decides audience from a client-supplied courseid, so course/category-targeted notices leak to users with no access to that course
  <br>/Users/uaiblaine/dev/moodle-local_awareness/classes/helper.php:1671-1700 now resolves the client-supplied courseid and then discards it unless the caller may enter: `if ($course && !can_access_course($course, null, '', true)) { $course = null; }` at :1694, with $onlyactive = true deliberately chosen…
- **M10** · corrigido — No external function rejects the guest user, so one guest dismissal hides a notice from every subsequent guest
  <br>Solved by session-scoping rather than by rejection, and the stated impact is gone. classes/helper.php:912-937 add_to_viewed_notices() takes a $sessiononly flag; dismiss_notice() (:970-994) passes isguestuser() into it and skips create_new_acknowledge_record() for guests (:978); acknowledge_notice()…
- **M11** · corrigido — get_estimate() and search_courses() capability checks have no test; the local/awareness:viewreports capability has zero coverage anywhere
  <br>Fechado na fase 6 (2026-08-16): `tests/reportbuilder/systemreports_test.php` (viewreports, os dois relatórios, triplo plain/manage-só/viewreports-só), `tests/external/search_courses_external_test.php` e um teste negativo para `get_estimate()`.
- **M12** · corrigido — forcelogout + reqack=0 traps the user: after one dismissal, Accept becomes a silent no-op and the modal returns on every page load
  <br>The two drifted predicates were collapsed into one: classes/helper.php:1245-1257 `must_reshow()` carries all four conditions, including `($dismissed && (int) $notice->get('forcelogout') === 1 && !is_siteadmin())` at :1256.
- **M13** · corrigido — A notice targeted at a hidden cohort is never shown to anyone, while the estimator reports the full cohort size
  <br>One shared membership resolver now exists: classes/helper.php:489-493 `user_cohort_ids()` reads `{cohort_members}` with `$DB->get_fieldset_select('cohort_members', 'cohortid', 'userid = ?', ...)` and no `visible` predicate.
- **M14** · corrigido — Editing a notice's link text or URL silently destroys that link's click history and leaves orphan rows
  <br>Both halves are closed. Link identity no longer includes the anchor text: classes/persistent/noticelink.php:114-117 looks the record up by `['noticeid' => …, 'link' => …]` only and updates the stored label in place at :125-128, so a typo fix keeps the id and its history.
- **M15** · corrigido — Notice content is stored after format_text() and pluginfile-URL rewriting, and gains a full HTML-4 document wrapper on every save
  <br>Storage keeps the authored form: classes/helper.php:53-67 `process_content()` passes the `file_save_draft_area_files()` output straight to `update_hyperlinks()`, which only DOM-annotates anchors — no `file_rewrite_pluginfile_urls()` and no `format_text()` on the save path (grep shows those two calls…
- **M16** · corrigido — The competency filter writes rows into core's competency_usercompcourse table from a read-only web service
  <br>The record-creating core API call is gone: classes/helper.php:1569-1573 now reads the row directly with `$DB->get_field('competency_usercompcourse', 'proficiency', ['userid' => …, 'courseid' => …, 'competencyid' => …])`, a missing row meaning not proficient, and the blanket `catch (\Exception)` was…
- **M17** · corrigido — linkhistory::count_clicked_links selects an unaliased COUNT(), so the property it reads back exists only on PostgreSQL
  <br>classes/persistent/linkhistory.php:83 now reads `SELECT h.hlinkid, l.text, l.link, COUNT(h.hlinkid) AS clickcount`, with the cross-engine reason recorded at :81-82. The two former readers (classes/table/acknowledged_notice.php:277/:299) were deleted in commit 2c2e787 and no current consumer reads `-…
- **M18** · corrigido — get_users_in_context only looks at local_awareness_lastview, so delete_data_for_users is never invoked for affected users
  <br>classes/privacy/provider.php:184-199 builds the userlist SQL with an OR of four EXISTS subqueries over local_awareness_lastview, local_awareness_ack, local_awareness_hlinks_his and local_awareness_audience_jobs, added via `$userlist->add_from_sql('userid', $sql, $params)` at :201, so a user whose on…
- **M19** · corrigido — Erasure bypasses the noticeview persistent, leaving the deleted user's view records in the notice_view MUC application cache
  <br>All three deletion entry points now funnel through classes/privacy/provider.php:226-237 `delete_all_data_for_userid()`, whose last statement is `noticeview::purge_user_cache($userid)` (provider.php:236).
- **M20** · corrigido — The privacy provider's request and userlist implementations (export and all three delete methods) have no test
  <br>Fechado na fase 5 (2026-08-16): `tests/privacy/provider_test.php`, 16 testes, cada tabela semeada isoladamente, todos mutation-testados.
- **M21** · corrigido — Report builder column 'noticeview:action' is typed TYPE_INTEGER over a CHAR column — Sum/Average aggregation is a hard PostgreSQL error
  <br>classes/reportbuilder/local/entities/noticeview.php:95 now calls `->set_type(column::TYPE_TEXT)` on the 'action' column, with the reason recorded at :89-94. TYPE_TEXT is rejected by core_reportbuilder's sum::compatible()/avg::compatible(), so the numeric aggregations are no longer offered over the c…
- **M22** · sem objeto — Dynamic property creation on PHP 8.2+: $this->page is assigned on two table classes that never declare it
  <br>Both carrier classes were deleted in commit 2c2e787 (`git log --diff-filter=D --name-only -- classes/table/acknowledged_notice.php classes/table/dismissed_notice.php`), replaced by report builder system reports (report/acknowledged_systemreport.php, report/dismissed_systemreport.php) which construct…
- **M23** · sem objeto — Report cell built by string-concatenating unescaped database values into HTML
  <br>classes/table/acknowledged_notice.php was deleted in commit 2c2e787 (git log --diff-filter=D --name-only -- classes/table/ lists it), and `grep -rn '<a href' --include='*.php' classes/ report/ *.php` returns nothing — no raw anchor concatenation survives anywhere.
- **M24** · sem objeto — acknowledged_notice table overrides other_cols() without calling parent, dropping core's mandatory escaping of the idnumber column
  <br>`grep -rn "other_cols" --include='*.php' .` returns zero hits in the whole repo — the override is gone with classes/table/acknowledged_notice.php (deleted in 2c2e787).
- **M25** · corrigido — local_awareness_hlinks_his declares no index and no foreign key on hlinkid or userid, yet every query filters on them
  <br>db/install.xml:79-83 now declares both keys on local_awareness_hlinks_his: `<KEY NAME="hlinkid" TYPE="foreign" FIELDS="hlinkid" REFTABLE="local_awareness_hlinks" REFFIELDS="id"/>` (line 81) and the userid foreign key (line 82).
- **M26** · corrigido — local_awareness_pluginfile()'s capability gate on files of disabled notices has no test
  <br>Fechado na fase 6 (2026-08-16): `tests/lib_test.php` cobre o gate do `local_awareness_pluginfile()`. Removê-lo faz o callback **servir o ficheiro**, que é o que o caso apanha.
- **M27** · corrigido — test_estimate_excludes_deleted_and_suspended_users never puts the deleted user in the cohort — the `u.deleted = 0` predicate is untested
  <br>Fechado na fase 6 (2026-08-16): os membros passam a ser adicionados à coorte ANTES de serem marcados. Remover `u.deleted = 0`, `u.suspended = 0`, `u.confirmed = 1` ou os dois predicados de convidado torna o teste vermelho.
- **M28** · corrigido — The plugin's only write-capability gate (check_manage_capability, 6 call sites) has no negative test — deleting it turns nothing red
  <br>Fechado na fase 6 (2026-08-16): `tests/helper_capability_test.php`, os seis pontos de escrita, recusa mais controlo positivo via `assign_capability()`.
- **M29** · corrigido — Six of the eight registered external functions have no test, and the two that do bypass call_external_function()/clean_returnvalue()
  <br>Fechado na fase 6 (2026-08-16): `search_courses` ganhou testes e as duas funções de público passaram a fazer round-trip por `call_external_function()`, aplicando os `execute_returns()`.
- **M30** · corrigido — helper::check_filters() — 184 lines and six targeting branches — has one test, which only reaches the early return; check_path_match() has none
  <br>Fechado na fase 6 (2026-08-16): `tests/check_filters_test.php` — 17 casos diretos de `check_path_match()` e asserções false para as quatro ramificações que não as tinham.
- **M31** · corrigido — bootstrap_compat_test scans only templates/, amd/src/ and classes/, and its entry-point scan globs only the plugin root — the four report/ pages are invisible to it
  <br>Fechado na fase 6 (2026-08-16): `markup_files()` percorre a raiz do plugin com lista de exclusão, e a varredura de entry points reutiliza-a. Encontrou de imediato dois defeitos reais nas páginas `report/*_systemreport.php`.
- **M32** · corrigido — bs5_only_utilities() guards 5 of the ~20 BS5-only families named in the house standard, and its regexes miss siblings within the families it does list
  <br>Fechado na fase 6 (2026-08-16): o mapa ganhou `ratio*`, `vr`, `fst-normal`, `top-*`/`bottom-*` e os irmãos `form-select-lg`, `gap-{bp}-*`, `translate-middle-x/-y`.
- **M33** · corrigido — bootstrap_compat_test knows 5 of the ~25 Bootstrap 5-only families, so almost any new BS5 utility ships green
  <br>Fechado na fase 6 (2026-08-16): mesmo mapa, mesmas adições — ver M32.

### Crítico de completude (C1–C7) — 1 de 7 em aberto

- **C1** · corrigido — Web service local_awareness_search_roles has no capability check — any authenticated user can enumerate every role on the site
  <br>classes/external.php:389-391 — search_roles() now does `$syscontext = \context_system::instance(); self::validate_context($syscontext); require_capability('local/awareness:manage', $syscontext);` before validate_parameters, with the comment naming the enumeration risk.
- **C2** · corrigido — Manage-notices page re-reads the entire cohort table once per cohort per row — an N+1 of full table scans
  <br>classes/table/all_notices.php:48 declares `protected $cohortnames = null;` and :702 fills it once per render (`$this->cohortnames ??= helper::built_cohorts_options();`), passing the resolved list into helper::get_cohort_name((int)$cohortid, $options) at :706 — the option list is now built at most on…
- **C3** · **parcial** — Guest users are never rejected: all guest sessions share one userid, so the first guest dismissal permanently hides the notice from every later guest
  <br>The cross-guest leak is gone: classes/helper.php:912-932 gives guests a session-only marker (`$sessiononly` writes only $USER->viewednotices and returns before noticeview::add_notice_view), dismiss_notice skips the acknowledgement row for a guest (:974-976, :994) and acknowledge_notice writes no row either (:1016-1024), track_link returns early for guests (:1057-1062). Pinned with a control at tests/external/notice_external_test.php:137 and :162 (a later guest session still receives the notice).
  <br>*Falta:* Guests are still not rejected, and the forcelogout half of the audit's impact is untouched: a guest who dismisses or acknowledges a forcelogout notice still runs require_logout() and is redirected to /login/index.php (classes/helper.php:996-1000 and :1044-1048 — `!is_siteadmin()` is true for a guest). The module is also still loaded for guests by design (classes/local/hook_callbacks.php:73 guards only on !isloggedin(), and tests/local/hook_callbacks_test.php:141 test_guests_still_load_the_module deliberately pins that).
- **C4** · corrigido — Notice content is filtered at save time, so multilang and every other text filter is frozen to the author's language for all users; titles are never filtered at all
  <br>Fechado na fase 5 (2026-08-16): o título passou a `format_string()` no payload do WS (`external.php`) e no `col_title()` da tabela; o `pathmatch` adjacente passou a `s()`.
- **C5** · corrigido — Both MUC caches store an empty array, which is falsy, so the query re-runs on every request in the common empty case
  <br>classes/persistent/awareness.php:219 — `if (($result = self::get_enabled_notices_cache()->get('records')) === false)`, and classes/persistent/noticeview.php:159 — `if (($result = self::get_cache()->get($USER->id)) === false)`; both carry comments naming the empty-array-is-falsy trap.
- **C6** · corrigido — The site-wide navigation callback catches Exception only, so any Error thrown while resolving notices produces a fatal on every page of the site
  <br>Fechado na fase 5 (2026-08-16): `hook_callbacks.php:79` passou a apanhar `\Throwable`.
- **C7** · sem objeto — Report filter state is stored under an unprefixed global session key
  <br>classes/report_filter.php was deleted in commit 2c2e787 (`git log --oneline --diff-filter=D -- classes/report_filter.php` returns that commit), together with report/acknowledged_report.php and report/dismissed_report.php. No replacement carries the defect: `grep -rn 'SESSION' .

### Padrões de código / lang — 1 de 19 em aberto

- **LANG-01** · corrigido — The 'Is perpetual' form field has a help string but no addHelpButton call, so the help text never reaches the user
  <br>Fechado na fase 11 (2026-08-17): `addHelpButton('perpetual', …)` no `notice_form.php`, com um teste que percorre o pack de idioma e exige que todo campo renderizado com `_help` mostre o texto na página.
- **LANG-02** · corrigido — @param string $action documents an argument whose values are the int constants ACTION_DISMISSED / ACTION_ACKNOWLEDGED
  <br>Fechado na fase 12: a assinatura e o docblock passaram a `int $action`, que é o que as constantes são.
- **LANG-03** · corrigido — @return \stdClass[] on a method that returns persistent objects
  <br>Já corrigido antes desta reconciliação: o `@return` é `self[]`.
- **LANG-04** · **parcial** — Ten language strings are defined in both en and pt_br but referenced nowhere in the plugin
  <br>Recomputed the unreferenced set at 896dfc2 and on HEAD (extract keys from lang/en, grep every non-lang/non-docs/non-amd-build file, then discount the keys required by convention: _help paired with an addHelpButton, cachedef_* named in db/caches.php, capability and messageprovider names).
  <br>*Falta:* `event:timecreated` survives unreferenced (lang/en/local_awareness.php:119, lang/pt_br:118), as does the orphan `notice:perpetual_help` (lang/en:207 — see LANG-01). Four new dead strings were introduced since: `editor:action:cancel` (lang/en:79), `editor:action:save_draft` (:81), `editor:action:save_publish` (:82) and `notice:timemodified` (:220) — the only apparent hit for the last one is the distinct key `report_notice:timemodified` at classes/reportbuilder/local/entities/notice.php:181. Six dead strings today against ten then.
- **LANG-05** · corrigido — Event get_name() returns lowercase verbs instead of readable event names
  <br>Fechado na fase 15 (2026-08-23): os nomes passam a legíveis (`Notice dismissed` em vez de `dismiss`) nos dois packs. Cada uma destas strings tem exatamente um consumidor, a classe de evento correspondente, portanto nada mais se mexe.
- **LANG-06** · corrigido — html_writer used in plugin code, against the zero-html_writer rule
  <br>Fechado na fase 13 (2026-08-17): zero `html_writer` em código de plugin. As células da tabela de gestão passaram a seis templates Mustache (`manage/cell_status`, `cell_chips`, `cell_validity`, `cell_audience`, `cell_title`, mais `resultcount` e `backlink`), e o `use html_writer` saiu do ficheiro.
- **LANG-07** · corrigido — tests/local/bootstrap_compat_test.php carries copied local_dimensions references to files, classes and a commit that do not exist in this repository
  <br>Sem objeto: os artefactos copiados do local_dimensions saíram; resta uma menção em prosa num comentário.
- **LANG-08** · corrigido — File header violates the fleet standard in 66 of 68 PHP files: forbidden @author, yearless @copyright, and untagged "Forked and adapted by" prose…
  <br>`grep -rln '@author' --include='*.php' .` and `grep -rln 'Forked and adapted' --include='*.php' .` both return zero files. A loop over all 92 PHP files checking for `@package`, `@copyright 20xx Anderson Blaine` and the GPL v3 license line printed no misses.
- **LANG-09** · sem objeto — Web services are implemented as one monolithic classes/external.php rather than one class per file under classes/external/
  <br>É o mesmo achado que o WS-17, redigido noutra secção. Fica encerrado por duplicação, não por correção — o `classes/external.php` continua monolítico.
- **LANG-10** · corrigido — @return mixed on a method with a string return type
  <br>Já corrigido antes desta reconciliação: o `@return` é `string`.
- **LANG-11** · corrigido — @package tag alignment inconsistent across the codebase (three different spacings)
  <br>`grep -rh '@package' --include='*.php' . \| sort \| uniq -c` returns a single line: 93 occurrences of ` * @package local_awareness`. The same command against the audit commit (`git grep -h '@package' 896dfc2 -- '*.php'`) returned three variants: 48 with four spaces, 2 with three, 19 with one — so th…
- **LANG-12** · sem objeto — @var string on a property that only ever holds an int, in both report table classes
  <br>classes/table/acknowledged_notice.php and classes/table/dismissed_notice.php were deleted in 2c2e787 (`git log --diff-filter=D --name-only` confirms). Their replacements, classes/reportbuilder/local/systemreports/acknowledged_notice.php and dismissed_notice.php, hold no noticeid property at all — th…
- **LANG-13** · sem objeto — @return string on a column callback that returns a float, and @param int on a column-name argument that is a string
  <br>Both methods the finding described are gone with their files (deleted in 2c2e787): `grep -rn 'spreadsheet\\|DAY_SECS_SPREADSHEET_DIFF' --include='*.php' .` and `grep -rn 'other_cols' --include='*.php' .` each return nothing in the current tree.
- **LANG-14** · sem objeto — Class opening brace on its own line, inconsistent with Moodle style and with every other class in the plugin
  <br>classes/table/dismissed_notice.php (whose line 37 held the lone `{` under `class dismissed_notice extends table_sql implements renderable`) was deleted in 2c2e787. A tree-wide scan for a class declaration followed by a brace-only line (`grep -rn -A1 -E '^\s*(final \|abstract )?class [A-Za-z_]+' --in…
- **LANG-15** · corrigido — db/install.xml VERSION attribute is four years stale relative to version.php and the schema it describes
  <br>db/install.xml:2 now reads `VERSION="20260815"` (it was `20220321` at 896dfc2, against version.php 2026080700). The last commit touching db/install.xml is f1d5b1e, which added the upgrade step whose savepoint is 2026081501 (db/upgrade.php:242) — date 2026-08-15, matching the attribute.
- **LANG-16** · corrigido — renderer.php has no file-level docblock: the header block sits after the use statements and documents the class
  <br>Fechado na fase 12: docblock de ficheiro acrescentado antes dos `use`.
- **LANG-17** · corrigido — No-op statement left in a test purely to silence a linter
  <br>Já corrigido: a instrução no-op saiu com a reescrita do teste do estimador na fase 6.
- **LANG-18** · corrigido — Test methods lack docblocks in three test files while the plugin's other test files document every one
  <br>Fechado na fase 17: 12 métodos em três ficheiros, com texto escrito a partir do código. O agente propôs, para o `test_estimate_cohort_only`, um docblock que descrevia um "segundo passe" do estimador que **não existe** — é uma só instrução com `SUM(CASE…)` por regra; a refutação apanhou-o e o texto foi reescrito.
- **LANG-19** · corrigido — Behat step carries a copied comment describing forum discussions
  <br>Fechado na fase 12: o comentário copiado sobre fóruns foi substituído.

### Web services — 2 de 18 em aberto

- **WS-01** · **parcial** — get_notices returns the entire notice DB record as a JSON blob in PARAM_RAW, defeating the execute_returns allowlist and leaking targeting…
  <br>The record-wide serialisation is gone: classes/external.php:253 copies a fixed 7-key allowlist out of to_record() (id, title, reqack, forcelogout, modal_width, modal_height, outsideclick) plus content/bgimageurl, and tests/external/notice_external_test.php:560-589 asserts the exact key set.
  <br>*Falta:* The targeting leak is closed; the mechanism the finding names is not. 'notices' remains a PARAM_RAW JSON string at classes/external.php:290 instead of an external_multiple_structure of external_single_structure, so clean_returnvalue() cannot enforce the allowlist — only the hand-written loop at :253 and the one PHPUnit assertion do. Smallest fix: declare the payload as a real multiple/single structure and drop the json_encode().
- **WS-02** · corrigido — search_roles has no capability check — any authenticated user (or guest) can enumerate every role on the site
  <br>classes/external.php:389-391 — search_roles now does $syscontext = \context_system::instance(); self::validate_context($syscontext); require_capability('local/awareness:manage', $syscontext); with the comment "Without this any authenticated user could enumerate every role defined on the site".
- **WS-03** · corrigido — search_roles matches only role.name/role.shortname, so standard roles cannot be found by the localised name the picker displays
  <br>Fechado na fase 7 (2026-08-16): o filtro passou para PHP e compara o rótulo do `role_get_names()`, o `role.name` guardado e o `shortname`; o corte de 50 passou a ser aplicado DEPOIS de filtrar.
- **WS-04** · corrigido — estimate_audience queues a duplicate job and a duplicate ad-hoc task on every call while a job is pending, and nothing ever deletes the rows
  <br>Duplicate queueing: classes/external.php:580-587 now joins an in-flight job — `if ($inflight = audience_job::find_in_flight($hash))` returns before any create()/queue_adhoc_task(), backed by classes/persistent/audience_job.php:142-153 (criteriahash + STATUS_PENDING + PENDING_WINDOW) and covered by t…
- **WS-05** · corrigido — local_awareness_audience_jobs stores a userid but is invisible to the privacy provider
  <br>classes/privacy/provider.php covers local_awareness_audience_jobs on every required path: metadata at :274-281 (add_database_table with userid/criteria/timecreated and key privacy:metadata:local_awareness_audience_jobs), get_contexts_for_userid at :68-70 (EXISTS on job.userid), get_users_in_context…
- **WS-06** · corrigido — search_courses returns course fullname unformatted into a triple-stash autocomplete template
  <br>Fechado na fase 15 (2026-08-23): `format_string()` no `search_courses()` — a grafia ESCAPADA, que é a que o triple stash do `form_autocomplete_suggestions.mustache` precisa. **Deliberadamente não** `\core_external\util::format_string()`: esse helper respeita o `external_settings`, cujo construtor só liga `filter` fora de AJAX, e esta função só é alcançada por AJAX — o helper do core deixaria o multilang por resolver. E o picker tem duas metades: o `notice_form` (reqcourse e filter_course, via `course_label()`) e o `helper::get_category_options()` emitem o mesmo tipo de nome para o `element-autocomplete.mustache`, também triple stash, e foram corrigidos no mesmo commit. O LIKE continua a comparar o nome CRU, de propósito.
- **WS-07** · corrigido — get_notices ships notice content that was filtered once at save time, never at output
  <br>Save-time filtering removed: classes/helper.php:251-271 (update_hyperlinks) now loads the raw content into DOMDocument, with the comment at :256-263 stating the format_text()/file_rewrite_pluginfile_urls() pass was moved out because it froze multilang "into whichever language the author happened to…
- **WS-08** · corrigido — The web services stay live when the plugin's own 'enabled' kill switch is off
  <br>Fechado na fase 8 (2026-08-16): `helper::is_delivery_enabled()` partilhado, chamado nos quatro pontos de entrada de leitura do `external.php` — `get_notices`, `dismiss_notice`, `acknowledge_notice`, `track_link`.
- **WS-09** · corrigido — local/awareness:manage lacks RISK_XSS although the web service pipes unfiltered notice HTML into jQuery .html() on every user's page
  <br>db/access.php:43 — `'riskbitmask' => RISK_CONFIG \| RISK_XSS,` for local/awareness:manage, with the comment at :29-39 explaining the exact chain the finding described (PARAM_RAW content, helper::render_content() with 'noclean' => true, and the result reaching core's Modal.setBody(), i.e. innerHTML).
- **WS-10** · corrigido — Two web services declared 'write' fire no event, and dismissals of non-reqack notices are unlogged
  <br>Fechado na fase 15 (2026-08-23), nas três metades. Duas classes novas — `awareness_link_clicked` e `awareness_audience_estimated` — e o gatilho do `awareness_dismissed` saiu do ramo `reqack`, mantendo a guarda de convidado. O evento de estimativa dispara na CRIAÇÃO do job, a partir de `audience_job::trigger_created_event()`, porque as linhas nascem em **dois** sítios: o web service e o `notice_audience::refresh()`, que é por onde passam o gravar de um aviso e o botão Recalcular. Instrumentar só o web service teria registado as pré-visualizações debounced do editor e perdido todas as recalculações deliberadas.
- **WS-11** · corrigido — Six of the eight external functions have no tests, and two capability gates are not mutation-covered
  <br>Sem objeto desde a fase 6: duplicado do M29, já corrigido.
- **WS-12** · corrigido — validate_context() is called before validate_parameters() in all eight external functions
  <br>Fechado na fase 17 nas nove funções. Inerte por construção: o contexto é sempre `\context_system::instance()` e nunca derivado de um parâmetro.
- **WS-13** · refutado — get_estimate does not check that the polled job belongs to the caller
  <br>Refutado na fase 11 (2026-08-17): investigado nos dois sentidos e concluído que não é defeito.
- **WS-14** · corrigido — estimate_audience accepts criteria lists of unbounded length, which reach get_in_or_equal unchecked
  <br>Fechado na fase 11 (2026-08-17): `helper::cap_criteria_lists()` corta cada lista em 500 na fronteira do web service — deliberadamente fora do `normalise()`, que também corre sobre avisos já guardados.
- **WS-15** · corrigido — track_link_returns declares a redirecturl key the implementation never returns
  <br>Fechado na fase 12: `redirecturl` saiu do `track_link_returns()` e do retorno antecipado — nunca foi devolvido.
- **WS-16** · corrigido — Web service files carry @author tags and a non-standard @copyright, against the fleet header standard
  <br>Já corrigido: nenhum ficheiro PHP do plugin tem `@author`.
- **WS-17** · **aberto** — All eight external functions live in one monolithic classes/external.php instead of classes/external/<name>.php
  <br>`ls classes/external*` returns a single file, classes/external.php (27204 bytes) — there is no classes/external/ directory. `grep -c "public static function" classes/external.php` returns 27, i.e. nine functions × (parameters, implementation, returns) in the one class `local_awareness\external` declared at classes/external.php:41. db/services.php:28-107 registers all nine against 'classname' => 'local_awareness\external'.
  <br>*Falta:* Worse than August, not better: check_collision was added to the same monolith after the audit (classes/external.php:305-363), taking it from eight functions to nine. The fleet standard is one class per file under classes/external/<name>.php extending \core_external\external_api. Smallest fix is mechanical — split into nine files and update the nine 'classname' entries in db/services.php, with a version.php bump so the service definitions reinstall.
- **WS-18** · corrigido — db/services.php declares no 'capabilities' key for the functions that require one
  <br>Fechado na fase 17: `'capabilities' => 'local/awareness:manage'` nas cinco entradas que exigem a capacidade em runtime. As outras quatro ficam sem, de propósito — não exigem nenhuma.
### Report Builder — 0 de 16 em aberto

- **RB-01** · corrigido — notice:content column dumps the raw stored notice HTML with no format_text() and no pluginfile rewriting
  <br>Fechado na fase 16 (2026-08-23), **com uma decisão de produto registada**. A coluna passa a renderizar como o modal, por um `helper::render_content_parts()` partilhado. Resolver os `@@PLUGINFILE@@` é o que faz as imagens funcionarem — e também significa que um download em **PDF** de um relatório com esta coluna embute os ficheiros direto do armazenamento: o writer de PDF do core procura-os por hash de caminho, sem verificação de capacidade nem de público. Quem estiver no público do relatório recebe bytes que o `local_awareness_pluginfile()` lhe recusaria. O ecrã e o dataformat html continuam a passar pelo portão do plugin; o PDF não. **Um relatório que carregue `notice:content` tem de ter o público definido como se carregasse os anexos, porque carrega.** Duas armadilhas de ramo estão guardadas no código: o callback devolve o valor cru quando os campos extra faltam (o `countdistinct` do 4.5 estende `base` e corre callbacks sem reconstruir os campos), e o `content` tem de ser o PRIMEIRO campo — o DML devolve tudo como string, portanto a ordem errada falha em silêncio.
- **RB-02** · corrigido — notice:reqcourse is typed TYPE_BOOLEAN over a course-id column, so the report cannot say which course is required
  <br>Fechado na fase 11 (2026-08-17): a coluna normaliza em SQL (`CASE WHEN reqcourse > 0 THEN 1 ELSE 0 END`), mantendo o tipo booleano e as agregações guardadas válidas.
- **RB-03** · corrigido — notice:resetinterval renders the raw second count instead of a human-readable interval
  <br>Fechado na fase 15 (2026-08-23): `format::format_time()`, com célula vazia para zero — que é o que a tabela de gestão já faz. O tipo fica `TYPE_INTEGER` (sob `TYPE_TEXT` as agregações guardadas rebentariam na visualização, a armadilha que o RB-02 já contornou) e o callback recebe `?float`, não `?int`, porque o `avg()` é compatível com uma coluna inteira e entrega o float sob strict types.
- **RB-04** · corrigido — Every in-scope file carries an @author tag and an undated @copyright, contrary to the fleet header standard
  <br>`grep -rn '@author' classes/reportbuilder classes/table report tests/reportbuilder tests/table` exits 1 with no output. Every in-scope file now carries the dated house tag, e.g.
- **RB-05** · corrigido — Neither system report has any PHPUnit coverage — the can_view() capability gate and both base conditions are untested
  <br>Sem objeto desde a fase 6: duplicado do M11, já corrigido — `tests/reportbuilder/systemreports_test.php`.
- **RB-06** · corrigido — System report downloads are all named after the datasource, so files for different notices are indistinguishable
  <br>Fechado na fase 15 (2026-08-23): o nome do ficheiro passa a levar o id e o título do aviso. O id é o que distingue — dois avisos podem partilhar título — e o título vai com `'escape' => false`, porque o destino é um cabeçalho `Content-Disposition` em texto simples e a grafia escapada deixaria um `amp;` literal depois de o `clean_filename()` tirar o `&`. O teste precisa de `manager::reset_caches()` entre as duas construções ou passa em vazio.
- **RB-07** · sem objeto — col_hlinkcount builds HTML by string concatenation with unescaped link text and URL
  <br>classes/table/acknowledged_notice.php (and dismissed_notice.php) were deleted in commit 2c2e787 (`git log --diff-filter=D --name-only -- 'classes/table/*'`). `grep -rn 'hlinkcount' .` finds no PHP source hit outside CHANGELOG/docs.
- **RB-08** · corrigido — Unaliased COUNT() in count_clicked_links() makes $count->count undefined on MySQL/MariaDB
  <br>classes/persistent/linkhistory.php:83 now reads `SELECT h.hlinkid, l.text, l.link, COUNT(h.hlinkid) AS clickcount`, with lines 81-82 recording why the alias is required.
- **RB-09** · sem objeto — report:acknowledged / report:dismissed are used with two incompatible meanings for their {$a} placeholder
  <br>In August the string had two meanings: `git grep -n "report:acknowledged'" 896dfc2` shows classes/table/acknowledged_notice.php:99 passing a timestamp as {$a} for the download name, and report/acknowledged_systemreport.php:44-45 passing $notice->title. The table class was deleted in 2c2e787.
- **RB-10** · sem objeto — A crafted tsort on a hyperlink-id column produces a positional ORDER BY that PostgreSQL rejects
  <br>The carrier is gone: classes/table/acknowledged_notice.php enabled sorting (`git show 896dfc2:classes/table/acknowledged_notice.php` line 158 `$this->sortable(true, 'username', SORT_DESC);`, line 183-184 appending get_sql_sort()) over numeric hyperlink-id column names (other_cols(), `is_numeric($col…
- **RB-11** · corrigido — The manage-notices table orders disabled notices to the top because the DESC only applies to the last sort field
  <br>classes/table/all_notices.php:203 now reads `$sql = "SELECT * FROM $table WHERE $where ORDER BY enabled DESC, timemodified DESC, id DESC";` — an explicit direction per field.
- **RB-12** · sem objeto — Orphaned legacy report pages are still live and gated on local/awareness:manage, not local/awareness:viewreports
  <br>report/acknowledged_report.php and report/dismissed_report.php were deleted in commit 2c2e787 (`git log --diff-filter=D --name-only -- 'report/*'`); `ls report/` now returns only acknowledged_systemreport.php and dismissed_systemreport.php.
- **RB-13** · sem objeto — The legacy report pages never set a page title or heading, producing an empty <title>
  <br>Same deletion as RB-12: the August pages set only set_url/set_context/navbar and never set_title (`git show 896dfc2:report/acknowledged_report.php`, lines 42-49), and both were removed in 2c2e787.
- **RB-14** · corrigido — Both system reports register the notice entity but never use a column or filter from it
  <br>Fechado na fase 17: a entidade `notice` e o seu LEFT JOIN saíram dos dois relatórios. Nenhum usava coluna ou filtro dela — o relatório já está limitado a um aviso por condição de base, portanto os campos do aviso seriam o mesmo valor em todas as linhas.
- **RB-15** · sem objeto — dismissed_notice opens its class brace on the following line, unlike every other class in the plugin
  <br>classes/table/dismissed_notice.php was deleted in 2c2e787; `git show 896dfc2:classes/table/dismissed_notice.php` confirms it declared `class dismissed_notice extends table_sql implements renderable` with `{` alone on the next line.
- **RB-16** · corrigido — Datasource tests depend on the \core_reportbuilder_testcase alias deprecated since Moodle 5.0
  <br>Fechado na fase 17: os cinco `use` passam a `core_reportbuilder\tests\core_reportbuilder_testcase`. O 4.5 já declara a classe com esse namespace, portanto não havia bloqueio nenhum enquanto o 405 for suportado.
### Repositório / CI / docs — 1 de 13 em aberto

- **REPO-01** · corrigido — mustache-continue-on-error disables the Mustache gate on all four CI legs, while only two lines in one template actually fail it
  <br>`grep -rn "mustache-continue-on-error" . --exclude-dir=.git` returns hits only in CHANGELOG.md:1172/1211 and docs/AUDIT-2026-08.md — no hit in .github/workflows/ci.yml.
- **REPO-02** · corrigido — ci.yml lacks the workflow_dispatch trigger the fleet standard requires
  <br>.github/workflows/ci.yml:13 declares `workflow_dispatch:` (with the push/main + tags/v* and pull_request triggers at lines 6-12, and a comment at lines 3-5 explaining the escape hatch).
- **REPO-03** · corrigido — CHANGELOG claims .stylelintrc.json was added, but the file is absent and .gitignore actively prevents committing it
  <br>Fechado na fase 7 (2026-08-16): o `.gitignore` deixou de bloquear o `.stylelintrc.json`, que passou a existir.
- **REPO-04** · corrigido — .gitignore is missing the entries that would have prevented the committed .claude gitlink
  <br>.gitignore:15-18 now carries `.claude/worktrees/` with a comment recording exactly the gitlink incident the finding describes ("One of these was once committed as a bare gitlink (mode 160000) with no .gitmodules entry").
- **REPO-05** · corrigido — README and CHANGELOG document Makefile targets that do not exist — there is no Makefile in the repo
  <br>Fechado na fase 7 (2026-08-16): os alvos de Makefile inexistentes saíram do README, substituídos pelos comandos `mdl ci` reais das duas pontas.
- **REPO-06** · corrigido — README documents audience targeting as cohort-only; the notice form also filters by role, role context, category, course, course format, theme and…
  <br>Fechado na fase 7 (2026-08-16): o README passou a nomear os sete critérios de público e a estimativa assíncrona.
- **REPO-07** · corrigido — $plugin->release is stale — it still names 2026061600 while $plugin->version is 2026080700
  <br>version.php:30 now reads `$plugin->release = 'v1.0';` — it no longer mirrors a stale version stamp (at 896dfc2 `git show 896dfc2:version.php` had `$plugin->release = '2026061600';` against `$plugin->version = 2026080700`). The current version is 2026081603 (version.php:29).
- **REPO-08** · corrigido — Every file header carries a sole new copyright holder; no original Catalyst/upstream copyright notice survives in the derivative work
  <br>Já corrigido: os cabeçalhos foram normalizados e o copyright a montante restaurado em da8e710.
- **REPO-09** · corrigido — .github/workflows/moodle-release.yml has no trailing newline
  <br>Fechado na fase 7 (2026-08-16): newline final no `moodle-release.yml`.
- **REPO-10** · **aberto** — CHANGELOG has no released section while version.php declares MATURITY_STABLE, and no release tag exists
  <br>`grep -n "^## " CHANGELOG.md` returns exactly one heading: CHANGELOG.md:7 `## [Unreleased]`. `git tag -l \| wc -l` → 0, so no `v*` tag exists and the moodle-release workflow has never fired. version.php:33 still declares `$plugin->maturity = MATURITY_STABLE;` and version.php:30 now names a release `'v1.0'` that has no tag behind it.
  <br>*Falta:* Everything the finding described stands, and REPO-07's fix widened it: version.php:30 now claims release `v1.0` while no v1.0 tag and no released CHANGELOG section exist. Smallest fix: cut a `## [1.0.0]` section from the Unreleased body and tag `v1.0.0`, or drop the maturity to MATURITY_RC until a tag is cut.
- **REPO-11** · corrigido — Five commits changed amd/src without the required version.php bump, so the AMD cache revision did not move
  <br>Every commit touching amd/src between 896dfc2 and HEAD also changed version.php — 13 of 13, checked by iterating `git log --format=%H 896dfc2..HEAD -- amd/src` and testing each commit's `--name-only` output for `^version.php$` (0181eae, 2c2e787, 79a7b15, d0cebfc, 22b9f05, 91a32ec, 98c47a4, a6862a7,…
- **REPO-12** · corrigido — Five fleet-template repo files are missing: phpcs.xml, .phpcsignore, .moodle-plugin-ci.yml, the PR template and a per-repo CLAUDE.md
  <br>Fechado na fase 7 (2026-08-16): `phpcs.xml`, `.phpcsignore`, `.moodle-plugin-ci.yml`, `.stylelintrc.json` e o template de PR instalados a partir de `~/dev/moodle-dev/templates/`, mais um `CLAUDE.md` próprio. Todos já estavam previstos no `.gitattributes`.
- **REPO-13** · corrigido — version.php header carries a banned @author tag, a copyright without a year, and prose wedged between tags
  <br>Já corrigido: `version.php` não tem `@author`.

### Schema XMLDB / upgrade — 4 de 13 em aberto

- **DB-01** · **parcial** — CHANGELOG.md carries no entry for the new DB table, upgrade step, or two new web service functions
  <br>`rg -n "local_awareness_estimate_audience\|local_awareness_get_estimate" CHANGELOG.md` returns nothing; `rg -n "audience_jobs" CHANGELOG.md` returns only CHANGELOG.md:1005-1006 (the purge-task entry) and :1163 (the privacy entry). The commit that created the table and its upgrade step (fd7907c, now db/upgrade.php:106-131, savepoint 2026051401) still has no CHANGELOG entry of its own and none was backfilled.
  <br>*Falta:* The table itself and the estimate feature are now described (CHANGELOG.md:599-728, 1003-1011, 1163), so the worst of the gap is closed. Still missing: any entry recording the creation of `local_awareness_audience_jobs`, its `2026051401` upgrade step, and the two web-service functions `local_awareness_estimate_audience` / `local_awareness_get_estimate` — neither name appears anywhere in CHANGELOG.md. Smallest fix: one backfilled `### Added` entry naming the table, the savepoint and the two WS functions.
- **DB-02** · corrigido — local/awareness:manage declares only RISK_CONFIG although it lets its holder inject unfiltered HTML into every user's screen
  <br>db/access.php:43 now declares `'riskbitmask' => RISK_CONFIG \| RISK_XSS,` for `local/awareness:manage`, with db/access.php:29-39 explaining the noclean/format_text/Modal.setBody chain that makes RISK_XSS correct. db/access.php:55 additionally gives `local/awareness:viewreports` RISK_PERSONAL.
- **DB-03** · corrigido — notice_view is a MODE_APPLICATION cache holding per-user viewing history that the privacy erasure path never clears
  <br>The cache is still MODE_APPLICATION (db/caches.php:32-34), but erasure now clears it: classes/persistent/noticeview.php:81-83 `purge_user_cache(int $userid)` deletes the user's key, and classes/privacy/provider.php:236 calls it from `delete_all_data_for_userid()` (classes/privacy/provider.php:226-23…
- **DB-04** · **aberto** — local_awareness_lastview.action is char(1333) while it stores the same 0/1 enum that local_awareness_ack.action stores as int(1)
  <br>db/install.xml:90 still declares `<FIELD NAME="action" TYPE="char" LENGTH="1333" NOTNULL="true" ... COMMENT="Action"/>` on local_awareness_lastview, while db/install.xml:48 stores the identical 0/1 enum on local_awareness_ack as `TYPE="int" LENGTH="1"`. The value written is an int: classes/helper.php:935 calls `noticeview::add_notice_view($id, $USER->id, $action)`, whose signature is `int $action` (classes/persistent/noticeview.php:123); the persistent types it `PARAM_RAW_TRIMMED` (classes/persistent/noticeview.php…
  <br>*Falta:* The column type is unchanged. Smallest fix: an upgrade step changing local_awareness_lastview.action to int(1) (with a cast of existing rows) plus the matching install.xml edit and PARAM_INT on the persistent property.
- **DB-05** · corrigido — local_awareness_audience_jobs grows without bound — no delete path anywhere, no cleanup task, and no index to support one
  <br>Sem objeto desde a fase 5/6: existe `classes/task/purge_audience_jobs.php`, agendada em `db/tasks.php`, com teste.
- **DB-06** · sem objeto — Four persistent-backed tables omit the timemodified/usermodified columns core\persistent always writes, so those values are silently discarded
  <br>**Não é defeito**, e é a mesma alegação do BIZ-09, que já tinha sido refutado na fase 11. Investigado de novo aos pares na fase 17 e refutado outra vez.
- **DB-07** · **aberto** — local_awareness_lastview declares no foreign keys, leaving noticeid-only lookups unindexed
  <br>db/install.xml:94-96 — local_awareness_lastview's KEYS block still holds only `<KEY NAME="primary" TYPE="primary" FIELDS="id"/>`; no foreign key to user or local_awareness. Its only index (db/install.xml:98) is `user_notice_uq` on `userid, noticeid`, whose leading column is userid, so noticeid-only lookups such as `noticeview::delete_notice_view()` (classes/persistent/noticeview.php:145-148, `delete_records(TABLE, ['noticeid' => $noticeid])`, called from classes/helper.php:422) cannot use it.
  <br>*Falta:* Smallest fix: mirror the 2026081103 step for local_awareness_lastview — add foreign keys on `noticeid` and `userid` (Moodle emits them as indexes) in install.xml plus an upgrade step.
- **DB-08** · corrigido — The estimate_audience ad-hoc task has no lang string, so its name is untranslatable in the ad-hoc queue
  <br>Fechado na fase 12: `task_estimate_audience` acrescentada aos dois packs.
- **DB-09** · corrigido — install.xml VERSION attribute is stale at 20220321 while the file gained a whole new table
  <br>db/install.xml:2 now reads `<XMLDB PATH="local/awareness/db" VERSION="20260815" ...>`; the stale 20220321 is confirmed gone (`git show 896dfc2:db/install.xml` line 2 carried it). Bumped in commit f1d5b1e ("perf: make the audience estimate fit sites with 200k users").
- **DB-10** · **aberto** — ack_action indexes the two-value action column alone, while every query filters on (noticeid, action)
  <br>db/install.xml:57 still carries `<INDEX NAME="ack_action" UNIQUE="false" FIELDS="action"/>` as local_awareness_ack's only index, on a column declared `int(1)` with two values (db/install.xml:48). `rg -n "ack_action\|local_awareness_ack" db/upgrade.php` returns nothing, so no upgrade step ever recomposed it.
  <br>*Falta:* The index is unchanged. Note the finding's rationale is only half right and should not be copied forward verbatim: classes/reportbuilder/local/entities/notice.php:217 and :230 do filter `(noticeid, action)`, but the system reports filter on action alone with local_awareness_ack as the main table (classes/reportbuilder/local/systemreports/acknowledged_notice.php:51-63, dismissed_notice.php:51-63).
- **DB-11** · corrigido — The contentformat column is written on every save but never read by any rendering path
  <br>classes/helper.php:1363 reads it: `return format_text($content, (int) $notice->get('contentformat'), ['noclean' => true, 'context' => \context_system::instance()]);` inside `render_content()` (classes/helper.php:1353).
- **DB-12** · corrigido — Every in-scope db/ file and version.php carries an @author tag and a year-less @copyright, against the fleet header standard
  <br>`rg -n "@author" db/ version.php` returns no hits (exit 1); repo-wide the tag survives only in amd/src JS files, which is a different finding (audit line 1294). db/upgrade.php:20-23 and version.php:20-23 now read @package / @copyright Catalyst IT / @copyright 2026 Anderson Blaine / @license, and the…
- **DB-13** · corrigido — $plugin->release was left behind when $plugin->version was bumped
  <br>version.php:30 now reads `$plugin->release = 'v1.0';` against `$plugin->version = 2026081603` (version.php:29). At the audited commit it was `$plugin->release = '2026061600';` with version 2026080700 (`git show 896dfc2:version.php`) — a version number in the release field, dated before the version i…

### Templates / Bootstrap 4-5 — 0 de 13 em aberto

- **TPL-01** · corrigido — html_writer used in plugin code in four places
  <br>Fechado na fase 13 (2026-08-17): zero `html_writer` em código de plugin. As células da tabela de gestão passaram a seis templates Mustache (`manage/cell_status`, `cell_chips`, `cell_validity`, `cell_audience`, `cell_title`, mais `resultcount` e `backlink`), e o `use html_writer` saiu do ficheiro.
- **TPL-02** · corrigido — Two report pages re-require styles.css, which Moodle already aggregates
  <br>`grep -rn "styles.css\\|requires->css" classes report templates amd lib.php renderer.php editnotice.php managenotice.php settings.php tests` returns no $PAGE->requires->css() anywhere in the tree (only prose mentions in classes/local/bootstrap.php:35 and test code reading the file).
- **TPL-03** · corrigido — audience_panel example context uses a key the template does not read, so the lint renders the loop empty
  <br>Fechado na fase 5 (2026-08-16): docblock e exemplo de contexto passaram a `{key, label, value}`; o lint do 4.05 e do 5.02 renderiza o laço.
- **TPL-04** · corrigido — The compat test scans only templates/, amd/src/ and classes/ — report/, renderer.php and the entry-point PHP files are outside every assertion
  <br>Sem objeto desde a fase 6: duplicado do M31, já corrigido — `markup_files()` percorre a raiz do plugin.
- **TPL-05** · corrigido — test_data_api_attributes_are_paired asserts over an empty set and has no non-vacuity guard
  <br>Fechado na fase 17. O detetor foi extraído para `data_api_offences()` e fixado com fixtures, porque a varredura lê **zero** linhas hoje — o plugin não liga nada ao data-API do Bootstrap. Uma guarda de contagem seria vermelha numa árvore sem defeito; fixtures provam que a regra ainda sabe falhar.
- **TPL-06** · corrigido — The badge assertion accepts text-muted and text-body as a valid colour for saturated backgrounds
  <br>Fechado na fase 17: a cor exigida passa a ser comparada **exatamente**, em vez de aceitar qualquer de `text-white|dark|body|muted`. Medido primeiro: os seis badges vivos já carregavam a cor certa, portanto apertar a regra fechou um buraco sem defeito ativo.
- **TPL-07** · corrigido — test_entry_points_mark_the_bootstrap_version skips the four report/ pages, none of which marks the Bootstrap version
  <br>Sem objeto desde a fase 6: duplicado do M31, já corrigido — a varredura de entry points reutiliza `markup_files()`.
- **TPL-08** · corrigido — styles.css header comment claims a scoping guarantee the file does not provide
  <br>Fechado na fase 17, depois de medir: **105 dos 153 seletores** do ficheiro não estão sob `.local-awareness-editor`, e 65 dos 114 da própria secção que fazia a promessa. O que protege o resto do Moodle é o *prefixo*, não um ancestral — é isso que o cabeçalho passa a dizer.
- **TPL-09** · corrigido — Inline !important in a template style attribute, outside stylelint's reach
  <br>Fechado na fase 17 removendo o conflito em vez de o forçar: o `.px-2` saiu da linha, porque era ele que obrigava ao `!important` (o Bootstrap 5 gera as utilidades de espaçamento com `!important`, o 4 não). Uma declaração inline já ganha a uma classe nos dois ramos.
- **TPL-10** · corrigido — shell.mustache and preview_card.mustache docblocks drift from the variables the templates use
  <br>templates/editor/preview_card.mustache was deleted in 0181eae (`git log --diff-filter=D --name-only -- templates/`), and templates/editor/shell.mustache's docblock (lines 22-30) now lists exactly the variables the body uses: pagetitle/subtitle (54-55), statuslabel + statusislive/statusisblocked (58-…
- **TPL-11** · sem objeto — sidenav example context never sets the per-item flag the template branches on
  <br>templates/editor/sidenav.mustache was deleted in 98c47a4 (`git log --diff-filter=D --name-only -- templates/`); `ls templates/editor` now shows only audience_panel.mustache and shell.mustache, and shell.mustache no longer includes any sidenav partial.
- **TPL-12** · corrigido — modal_notice.mustache uses the Bootstrap 4-only .close name where .btn-close resolves on both branches
  <br>Fechado na fase 12: `class="close"` passou a `btn-close`, que resolve nos dois ramos.
- **TPL-13** · corrigido — Compat test carries dead exception lists copied from another plugin
  <br>Sem objeto: ver LANG-07.

### AMD JavaScript — 0 de 12 em aberto

- **JS-01** · corrigido — Context-rule chips request their language strings with `param: ''`, which deletes the {$a} placeholder and makes the value-substitution branch dead…
  <br>`grep -rn "param:" amd/src/` returns nothing (exit 1). At 896dfc2 the list carried `param: ''` on pathmatch/filter_category/filter_course/filter_format/filter_theme and `param: '{$a}'` on cached/error/reach:value; the current list at amd/src/audience_estimator.js:100-121 has no `param` key at all, a…
- **JS-02** · corrigido — Poll responses are applied without checking they belong to the current job, and trigger() leaves the previous poll running — a late response can…
  <br>Fechado na fase 9 (2026-08-16): contador monotónico `state.sequence`, capturado no envio e comparado nos dois ramos (`.then` e `.catch`) do `pollOnce()` e do `trigger()`, mais `stopPolling()` no início do `trigger()`. Mesma grafia do `collision_warning.js`, que já trazia o padrão.
- **JS-03** · corrigido — Dismiss/acknowledge web-service failures are swallowed to the browser console and the modal is hidden before the result is known
  <br>Fechado na fase 9 (2026-08-16): o `modal.hide()` saiu dos dois handlers de clique e passou a existir num único sítio — o ramo de fila vazia do `nextNotice()`. Guarda `inflight` nos dois caminhos de escrita, libertada no `.always()`, porque era o `hide()` que tornava um segundo clique impossível.
- **JS-04** · corrigido — Four AMD modules miss the GPL header and @module tag and carry a forbidden @author line
  <br>Fechado na fase 12: os cinco módulos AMD ganharam o bloco GPL e a etiqueta `@module`, e perderam o `@author`.
- **JS-05** · corrigido — The AMD config passed from editnotice.php is discarded: notice_editor.init() takes no argument and calls AudienceEstimator.init() with none
  <br>The mismatch was removed from the caller side. At 896dfc2 editnotice.php:55-60 passed `js_call_amd('local_awareness/notice_editor', 'init', [['formSourceId' => ..., 'threshold' => editor_page::RULE_THRESHOLD, 'pollIntervalMs' => ..., 'pollMax' => ...]])`; today editnotice.php:53-55 passes no third a…
- **JS-06** · corrigido — Competency picker renders hardcoded English error text instead of language strings
  <br>Fechado na fase 17: as duas frases inglesas passam a chaves de lang lidas de atributos `data-*`, o padrão que o módulo já usava para os outros sete rótulos.
- **JS-07** · corrigido — showAction() ignores its `visible` argument for the calculate button, so its JSDoc is wrong and the button stays clickable while a job is queued
  <br>Fechado na fase 12 pela documentação, não pela mudança: o botão Calcular fica sempre visível de propósito — é o controlo manual do autor — e era o JSDoc que estava errado.
- **JS-08** · corrigido — Loaded-but-unused language string and a wrong @param type on the poll job id
  <br>Fechado na fase 17. O `@param` passa a `{String}`. As três strings mortas saíram — mas **não** por remoção da lista: eram lidas por posição (`s[0]`..`s[20]`) e tirar entradas re-etiquetaria em silêncio todos os chips seguintes, por isso o mapeamento passou a ser por chave e a classe de erro desapareceu com ele.
- **JS-09** · corrigido — DOM hooks are scattered class/id strings instead of a SELECTORS map of data-* selectors, and modal_notice's SELECTORS entries are partly dead
  <br>Fechado na fase 17: `TOOL_TIP_WRAPPER` e os dois stubs vazios saíram, e o gancho de fechar passa pelo `SELECTORS.CLOSE_BUTTON` nos três sítios. O botão carrega o id **e** o `data-action`, verificado no template antes de trocar — os dois seletores não eram equivalentes.
- **JS-10** · corrigido — Server-provided label text is concatenated into innerHTML in the competency picker
  <br>Fechado na fase 17, e **mais largo do que o achado dizia**. Além dos dois `innerHTML`, a refutação encontrou outros dois sinks de HTML cru a receber os mesmos rótulos: `Modal.setTitle()`, que acaba em `.html()`, e `Notification.addNotification()`, que rende por triple stash — ambos idênticos no 4.5 e no 5.2, confirmado antes de escapar.
- **JS-11** · corrigido — preview.js has no rejection path on the modal promise chain
  <br>Fechado na fase 17: `.catch(Notification.exception)` fecha a cadeia.
- **JS-12** · corrigido — version.php was not bumped in the commits that shipped amd/src + amd/build changes
  <br>Every commit touching amd/src or amd/build since the audit also bumped $plugin->version: 83c8b60→2026081100, e9af3df→2026081200, 163ab38→2026081305, b52f8d4→2026081402, f1d5b1e→2026081501, a6862a7→2026081508, 98c47a4→2026081509, 91a32ec→2026081510, 22b9f05→2026081512, d0cebfc→2026081514, 79a7b15→202…

### Lógica de negócio — 0 de 11 em aberto

- **BIZ-01** · corrigido — Audience-estimate jobs accumulate forever and one ad-hoc task is queued per typing pause
  <br>Both halves are closed. Accumulation: classes/task/purge_audience_jobs.php:42,60-84 deletes every job with timecreated older than DAYSECS, registered as a scheduled task in db/tasks.php:29-36 with lang key present (lang/en/local_awareness.php:302).
- **BIZ-02** · corrigido — The per-rule breakdown re-runs each rule with its scoping dropped, so the role chip can exceed the total
  <br>classes/audience/estimator.php:237-251 (isolate_rule) now carries filter_role_context, filter_category and filter_course into the isolated criteria for filter_role, and classes/audience/estimator.php:189 passes [$rule] as the APPLIED set so those keys scope the role without counting as their own rul…
- **BIZ-03** · corrigido — Notice scheduling is hard-capped at the year 2030 by the date selectors
  <br>classes/form/notice_form.php:149 computes $stopyear = (int) date('Y') + 10 and both selectors use it (:150 timestart, :155 timeend), with a comment at :145-148 explaining why a literal was wrong.
- **BIZ-04** · corrigido — Every dismissal writes another acknowledgement row for the same user and notice — no dedupe, no unique key
  <br>Fechado na fase 7 (2026-08-16): `dismiss_notice()` só insere se ainda não houver linha para (aviso, utilizador, ação). O evento continua a disparar em cada recusa — é a linha de conformidade que não pode duplicar.
- **BIZ-05** · corrigido — track_link stores click history for any integer, with no check that the link exists or is reachable by the caller
  <br>classes/helper.php:1070-1078: track_link() now loads the noticelink by id and returns ['status' => false] when it does not exist, then loads the owning notice and returns false unless is_notice_available_to_user() accepts it (that gate is defined at classes/helper.php:859-895).
- **BIZ-06** · corrigido — Deleting a notice never removes its uploaded files, even with cleanup enabled
  <br>classes/helper.php:413-416 deletes the 'content' and 'bgimage' file areas for the notice id via get_file_storage()->delete_area_files(), and it sits BEFORE the cleanup_deleted_notice early return at :418-420, so the files go regardless of the cleanup setting (comment at :406-412 states exactly that)…
- **BIZ-07** · corrigido — check_path_match compiles the pattern without a start anchor, so a path rule matches any URL that merely ends with it
  <br>Fechado na fase 7 (2026-08-16): o padrão passou a ser ancorado nas duas pontas, e o alvo é testado também sem o segmento de caminho do `wwwroot`, para não matar as regras em instalações em subdiretório.
- **BIZ-08** · corrigido — Notices with a required course ignore the recorded view entirely, so resetinterval has no effect on them
  <br>Fechado na fase 12 (2026-08-17), pela segunda opção decidida pelo dono do produto: a cláusula `reqcourse = 0` saiu do `get_user_viewed_notice_records()`, tornando o `reqcourse` a regra de público que os outros seis pontos do código já assumiam.
- **BIZ-09** · refutado — Persistent classes declare timemodified/usermodified for tables that have no such columns, so those writes are silently discarded
  <br>Refutado na fase 11 (2026-08-17): investigado nos dois sentidos e concluído que não é defeito.
- **BIZ-10** · corrigido — libxml internal error handling is switched on globally and never restored
  <br>Fechado na fase 12: `libxml_use_internal_errors()` guarda e repõe o valor anterior.
- **BIZ-11** · corrigido — get_enabled_notices() and retrieve_user_notices() disagree on half-open scheduling windows (latent — not reachable through the form)
  <br>Fechado na fase 16 (2026-08-23): os dois predicados passam a três projeções de uma tabela-verdade em `local\window`, e **um limite a zero significa ILIMITADO desse lado** — a convenção do core para inscrições e a que o `audience\estimator` já usava aqui. A divergência medida era "sem início, expira em Y", descartado pela query, e "com início e sem expiração", alcançável no editor e nunca exibido — o plugin carregava um aviso, `editor_state::WINDOW_OPEN_ENDED`, cujo próprio docblock dizia existir por causa deste defeito; saiu, com as duas strings e o cenário Behat, agora invertido. **A assimetria entre o SQL e o PHP é deliberada**: o cache é `MODE_APPLICATION` sem TTL, purgado só por escrita, portanto a query só pode carregar condições MONÓTONAS — `now >= timestart` é uma transição para visível e deixaria um aviso agendado permanentemente fora do conjunto cacheado. Fixado duas vezes, e a segunda não é redundante: o `window_test` compara com um modelo PHP do prefiltro, o `enabled_notices_window_test` corre a query REAL. Tornar o prefiltro simétrico só é apanhado pelo segundo.
### Testes — 1 de 10 em aberto

- **TEST-01** · corrigido — No test asserts that any of the eight events fires, so the "every write fires an event" rule is unverified
  <br>Fechado na fase 5 (2026-08-16): `tests/event/events_test.php`, 8 testes, um por verbo mais a distinção entre eles.
- **TEST-02** · corrigido — Dead cohort branch in awareness_test::test_create_notices would silently drop the cohort if the provider ever supplied one
  <br>Fechado na fase 17 apagando o ramo: o provider nunca forneceu `cohorts`, e o ramo atribuía um escalar onde os quatro laços irmãos atribuem `[id]` — código morto que discordava dos vizinhos sobre a forma do valor.
- **TEST-03** · corrigido — find_reusable()'s dedup predicates (status = ready, timecompleted within DEDUP_WINDOW) are untested — either can be deleted with the suite green
  <br>Fechado na fase 17: `tests/persistent/audience_job_dedup_test.php`, com controlo positivo e um negativo por predicado. Apagar o teste de `status` ou o da janela `DEDUP_WINDOW` fica vermelho — verificado por mutação nos dois.
- **TEST-04** · corrigido — tests/external/audience_external_test.php declares namespace local_awareness while living in tests/external/
  <br>Fechado na fase 12: o namespace passou a `local_awareness\external`, a condizer com o diretório.
- **TEST-05** · corrigido — Two of the five bootstrap_compat assertions have no "found nothing" guard, unlike the third which explicitly added one
  <br>Fechado na fase 17. Guardas de não-vacuidade nas varreduras que as não tinham, e a lista `$structural` do polyfill — dez entradas — foi **medida**: nove nomeavam classes que o `styles.css` não define, e uma (`local-dimensions-central-page`) é de outro plugin. Ficou a única real, a classe-portão.
- **TEST-06** · corrigido — bootstrap_compat_test carries copy-paste leftovers from local_dimensions: a whitelisted class and an exception file that do not exist in this plugin
  <br>Sem objeto: ver LANG-07.
- **TEST-07** · corrigido — @covers claims coverage of \local_awareness\local\bootstrap but no test executes either of its methods
  <br>Fechado na fase 7 (2026-08-16): dois testes que EXECUTAM `bootstrap::is_bs4()` e `mark_page()` através da fronteira 405/499/500/502, com `$PAGE` isolado e reposto.
- **TEST-08** · **aberto** — No plugin data generator — five test files each hand-build local_awareness rows with 15 literal fields, bypassing the persistent
  <br>`ls tests/generator` → No such file or directory; the only lib.php in the repo is ./lib.php (`find . -name lib.php`), and no test calls `get_plugin_generator('local_awareness')` (every get_plugin_generator hit in tests/ names core_competency or core_reportbuilder). Five files still hand-build rows via `$DB->insert_record('local_awareness', …)`: tests/reportbuilder/datasource/acknowledged_notices_test.php:49 (14 literal fields, lines 49-64), all_notices_test.php (5 call sites), dismissed_notices_test.php (2), notice…
  <br>*Falta:* No tests/generator/lib.php exists. Smallest fix: a `local_awareness_generator extends component_generator_base` with `create_notice(array $record)` routed through the awareness persistent, replacing the five private create_notice() helpers.
- **TEST-09** · corrigido — The estimate_audience task's error path and get_estimate's job-level error response are never exercised
  <br>Fechado na fase 11 (2026-08-17): `notice_audience::record()` recusa um job que não esteja `ready`; um job falhado deixou de gravar contagem 0 e de carimbar o hash, o que tornava a falha pegajosa e com ar de resultado.
- **TEST-10** · corrigido — test_execute_with_unknown_jobid_does_not_throw asserts assertTrue(true)
  <br>tests/task/estimate_audience_test.php:105-118 now reads: `$this->assertFalse(audience_job::get_record(['jobid' => $jobid]));` as a stated precondition, then captures mtrace output and asserts `assertStringContainsString('not found', $output)` against classes/task/estimate_audience.php:45.

### Segurança (entry points) — 0 de 9 em aberto

- **SEC-01** · sem objeto — "Remove selected" on the report filter never removes anything
  <br>`classes/report_filter.php`, `classes/form/active_filter_form.php` and `classes/form/add_filter_form.php` were all deleted in 2c2e787 (`git log --diff-filter=D --name-only`).
- **SEC-02** · corrigido — local/awareness:manage grants stored XSS against every site user but does not declare RISK_XSS
  <br>db/access.php:42 now reads `'riskbitmask' => RISK_CONFIG \| RISK_XSS` for `local/awareness:manage`, with a block comment at db/access.php:29-38 naming the exact chain (PARAM_RAW form field -> `helper::render_content()` with `format_text(..., 'noclean' => true)` -> `Modal.setBody()` innerHTML) that m…
- **SEC-03** · corrigido — local/awareness:viewreports exposes usernames and ID numbers with no riskbitmask
  <br>db/access.php:54 now reads `'riskbitmask' => RISK_PERSONAL` for `local/awareness:viewreports`, preceded by the comment at db/access.php:47-50 ("reports name users and carry their email and idnumber"). In August the capability declared no riskbitmask at all.
- **SEC-04** · corrigido — allow_update is enforced only on the form-display branch, so a crafted POST updates notices with the setting off
  <br>The gate moved to the write path: classes/helper.php:162 `if (!get_config('local_awareness', 'allow_update')) { return ...STATE_NONE; }` sits inside `update_notice()` before any DB write, so a crafted POST cannot update.
- **SEC-05** · corrigido — Notice files are served to any user regardless of the notice's audience, and to auto-logged-in guests
  <br>Fechado na fase 14 (2026-08-17): o `local_awareness_pluginfile()` passou a resolver o público com `helper::is_notice_available_to_user()`, o mesmo portão que as escritas do web service usam.
- **SEC-06** · corrigido — Notice title echoed into HTML with neither format_string() nor escaping
  <br>The raw echo is gone: the August code was `echo $output->heading($notice->title);` at report/acknowledged_report.php:80 (`git show 896dfc2:report/acknowledged_report.php`), and `core_renderer::heading()` wraps via `html_writer::tag()`, which does not escape.
- **SEC-07** · corrigido — The two table-based report pages set neither page title nor heading
  <br>report/acknowledged_systemreport.php:43-44 sets `$PAGE->set_title(...)` and `$PAGE->set_heading(...)`; report/dismissed_systemreport.php:43-44 does the same. Both also set `$PAGE->set_pagelayout('report')` at line 45.
- **SEC-08** · corrigido — File headers in the scoped files carry @author and a non-standard @copyright
  <br>Já corrigido: nenhum dos ficheiros abrangidos tem `@author`.
- **SEC-09** · corrigido — styles.css is explicitly requested although Moodle already compiles it into the theme
  <br>`rg -n 'requires->css' --glob '*.php' .` returns zero hits (exit 1) across the whole plugin. The August call `$PAGE->requires->css('/local/awareness/styles.css');` lived at report/acknowledged_report.php:49 (`git show 896dfc2:report/acknowledged_report.php`); that file is deleted and its replacement…

### Privacidade / LGPD-GDPR — 1 de 6 em aberto

- **PRIV-01** · corrigido — Declared metadata under-states the columns that export_user_data actually ships
  <br>Fechado na fase 10 (2026-08-16): as quatro tabelas declaram agora todas as colunas que o `export_user_data()` envia (18 entradas novas), com um teste que compara a declaração contra as colunas REAIS da base, não contra uma lista escrita à mão.
- **PRIV-02** · corrigido — delete_data_for_users ignores the approved userlist and deletes by context instanceid instead
  <br>Fechado na fase 5 (2026-08-16): `delete_data_for_users()` passou a iterar os ids aprovados, com o contexto a limitar o alcance.
- **PRIV-03** · corrigido — delete_data_for_user processes only the first context and derives the userid from the context rather than the contextlist's user
  <br>Fechado na fase 5 (2026-08-16): `delete_data_for_user()` percorre todos os contextos aprovados e tira o userid do contextlist.
- **PRIV-04** · corrigido — local_awareness.usermodified (the notice author) is user-linked but is not declared, exported or considered anywhere in the provider
  <br>Fechado na fase 10 (2026-08-16): a tabela `local_awareness` é declarada pelo seu `usermodified` e deliberadamente nunca exportada nem apagada — um aviso é configuração do site, e apagar a coluna reescreveria quem o publicou. O teste afirma as duas metades.
- **PRIV-05** · **aberto** — export_user_data ignores the context it is exporting for and ships raw unix timestamps and internal ids
  <br>classes/privacy/provider.php:87-131 — the loop `foreach ($contextlist->get_contexts() as $context)` never uses $context in any of the four queries (provider.php:93-107 filter on :userid only), so every context in the list receives the identical, full payload; and the exported records are raw rows (`lv.*` etc.) so timecreated/timemodified go out as unix integers and noticeid/hlinkid/jobid as internal ids, with no transform anywhere between provider.php:113 and the export at provider.php:129.
  <br>*Falta:* Unchanged. Smallest fix: transform timestamps with \core_privacy\local\request\transform::datetime() and resolve notice ids to titles before the export_data() call at provider.php:129 (the context loop is harmless once only one user context can ever be present, but the raw payload is what the finding names).
- **PRIV-06** · corrigido — Provider file header carries a banned @author tag and a @copyright without the required year
  <br>Já corrigido: o provedor não tem `@author`.

### SQL / portabilidade — 0 de 5 em aberto

- **SQL-01** · corrigido — Audience-estimate breakdown drops the role scoping keys, so the per-rule chip counts a site-wide role instead of the scoped one
  <br>classes/audience/estimator.php:189 builds each breakdown column from `self::isolate_rule($criteria, $rule)`, and isolate_rule() at classes/audience/estimator.php:237-251 keeps filter_role_context, filter_category and filter_course alongside filter_role (and filter_competency_requireall alongside the…
- **SQL-02** · sem objeto — Report tables declare sortable columns that are not database columns, so a crafted tsort produces an invalid ORDER BY
  <br>Both tables that carried it were deleted in commit 2c2e787 (`git log --diff-filter=D --name-only -- classes/table/acknowledged_notice.php classes/table/dismissed_notice.php`); the cited line 126 was `$cols['timecreated_spreadsheet'] = …`, a non-column defined next to `$cols[$link->id]` under `sortab…
- **SQL-03** · corrigido — ORDER BY on the manage-notices table applies DESC only to the second column, listing disabled notices first
  <br>classes/table/all_notices.php:203 now spells the direction on every key: `ORDER BY enabled DESC, timemodified DESC, id DESC`. The August code was `awareness::get_records([], 'enabled, timemodified', 'DESC', …)` (git show 896dfc2:classes/table/all_notices.php:110), which persistent::get_records conca…
- **SQL-04** · corrigido — Guest user is excluded by a hard-coded id of 1 instead of $CFG->siteguest
  <br>Fechado na fase 6 (2026-08-16): `estimator::base_predicate()` liga `guestid` a `$CFG->siteguest` em vez do literal 1.
- **SQL-05** · corrigido — Context id and context levels are string-concatenated into SQL instead of being bound as placeholders
  <br>Fechado na fase 15 (2026-08-23): os três valores passam a parâmetros nomeados, com o mesmo `$suffix` que os prefixos do `get_in_or_equal` ao lado já carregam — o estimador emite este fragmento várias vezes na mesma instrução e o Moodle conta OCORRÊNCIAS de placeholder.
### core business-logic correctness — 0 de 1 em aberto

- **X1-01** · corrigido — awareness_enabled and awareness_disabled events are dead code — enable and disable both log 'notice updated'
  <br>Fechado na fase 5 (2026-08-16): `enable_notice()` e `disable_notice()` disparam agora `awareness_enabled` e `awareness_disabled`, pinado por `tests/event/events_test.php`.

### XMLDB schema / version discipline — 0 de 1 em aberto

- **X2-01** · corrigido — The user_notices cache definition is dead — it is purged but never read or written
  <br>db/caches.php now defines exactly three caches — 'enabled_notices' (line 29), 'notice_view' (line 32), 'site_user_count' (line 38); the 'user_notices' => MODE_SESSION definition present in August (git show 896dfc2:db/caches.php) is gone, removed in commit 2c2e787.

### Report Builder / aba legada — 0 de 1 em aberto

- **X3-01** · corrigido — html_writer used in the system report pages and the manage table, against the fleet's zero-html_writer rule
  <br>Fechado na fase 13 (2026-08-17): zero `html_writer` em código de plugin. As células da tabela de gestão passaram a seis templates Mustache (`manage/cell_status`, `cell_chips`, `cell_validity`, `cell_audience`, `cell_title`, mais `resultcount` e `backlink`), e o `use html_writer` saiu do ficheiro.
