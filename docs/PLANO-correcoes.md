# Plano de correções — `local_awareness` pós-fase 3

Estado inicial: `main` em `9fbfd6a` (PR #25 mergeado), versão `2026081512`, CI verde nos 21 jobs.

Este documento é a lista de trabalho. Marque cada item ao concluir e registre no fim da fase o
commit e o PR que a fecharam. Ele existe porque este repositório já produziu duas vezes a ilusão de
um defeito corrigido: o marcador de campo obrigatório, registrado como resolvido numa versão e que
nunca chegou a aplicar por empate de especificidade, e os achados abaixo marcados como *auditoria*,
que estão no `docs/AUDIT-2026-08.md` desde agosto e continuam no código.

## Como esta lista foi levantada, e o que isso vale

Quatro lentes independentes sobre a `main` mergeada — funcionalidades meio ligadas, achados
documentados nunca corrigidos, correção que os testes não cobrem, e a checklist de segurança e
privacidade da frota — com um verificador adversarial por alegação, instruído a refutá-la. 58
alegações levantadas, 42 confirmadas, 26 distintas depois de deduplicar.

A coluna **conf.** diz como cada item chegou aqui, e é a diferença entre ler e agir:

- **✔** — abri o arquivo e confirmei o mecanismo pessoalmente.
- **~** — sobreviveu ao verificador adversarial, mas não foi reconferido à mão. **Confirme antes de
  corrigir.** Um verificador já errou nesta mesma sessão: refutou e confirmou o mesmo defeito do
  anel de foco em dois vereditos contraditórios, e só a medição no navegador resolveu.

**Progresso:** fases 1 e 2 fechadas — 10 de 27 itens. Fases 3 e 4 por começar.

## Sobre o `docs/AUDIT-2026-08.md`

O cabeçalho daquele documento é explícito: é "o retrato do ponto de partida, não a lista do que
ainda está aberto". São 191 achados; os PRs #2 a #21 corrigiram um subconjunto e **nunca houve
reconciliação do resto contra o código**. Fechar as fases 1 e 2 abaixo não fecha a auditoria — fecha
os sete achados que esta varredura reencontrou. Os outros 184 continuam sem estado conhecido.

---

## Fase 1 — Segurança

Primeiro porque é o único grupo onde a espera tem custo. Nenhum destes é escalada de privilégio: o
ator já precisa de `local/awareness:manage`. São controles que não fazem o que dizem.

- [x] **1.1 · `allow_update` não protege a gravação** — `editnotice.php:81` · conf. **✔** · pequeno

  O ramo de gravação `if ($formdata = $mform->get_data())` corre **antes** do `switch ($action)`, e
  a configuração só é consultada em `case 'edit'` (linha 243), que apenas decide se o formulário é
  *exibido*. A assimetria é a prova: `helper::delete_notice()` reverifica `allow_delete` dentro do
  próprio helper (`helper.php:362`) e retorna cedo; `helper::update_notice()` não verifica nada.
  Com a configuração desligada, um POST válido atualiza o aviso na mesma.

  *Correção:* guarda dentro de `helper::update_notice()`, espelhando o `delete_notice()`.
  *Verificação:* teste PHPUnit que desliga a configuração e chama `update_notice()` esperando
  recusa — e um controle que a liga e confirma que a gravação acontece, senão o teste passa sem
  exercer nada.

- [x] **1.2 · `idnumber` sem escape no relatório de reconhecidos** (auditoria M24) —
  `classes/table/acknowledged_notice.php:310` · conf. **✔** · trivial

  `other_cols()` devolve `null` no ramo final em vez de `return parent::other_cols($column, $row);`.
  É o `parent` que aplica `s()` a `email` e `idnumber` — existe exatamente para isso, idêntico no
  4.5 e no 5.2. A classe declara a coluna `idnumber` e não tem `col_idnumber()`, então o valor cru
  chega ao HTML. O `idnumber` do perfil é editável pelo próprio utilizador em muitos sites.

  *Verificação:* teste que grava markup no `idnumber` e afirma que a célula sai escapada.

- [x] **1.3 · `estimate_audience` aceita cohortid cru** — `classes/external.php:537` · conf. **~** ·
  pequeno

  A criteria JSON vai para `estimator::normalise()`, que só converte ids para inteiros positivos.
  Não há `cohort_get_cohort($id, $context)` em lado nenhum deste caminho, enquanto o menu do próprio
  editor é construído por `cohort_get_all_cohorts()`, essa sim filtrada por capacidade. Quem detém
  `local/awareness:manage` mas não vê uma coorte de categoria pode obter o seu tamanho. É o oráculo
  de coorte oculta que o `CLAUDE.md` da frota descreve.

- [x] **1.4 · Bitmasks de risco subdeclaradas** — `db/access.php:32` · conf. **~** · trivial

  `local/awareness:manage` declara só `RISK_CONFIG`, mas `helper::render_content()` chama
  `format_text(..., ['noclean' => true])` e o conteúdo é `PARAM_RAW` do formulário à persistente, e
  vai para `Modal.setBody()`, que é `innerHTML`. Isso é `RISK_XSS`, e o conteúdo alcança todo
  utilizador autenticado. `viewreports` não declara risco nenhum apesar de expor dados pessoais
  (`RISK_PERSONAL`).

  *Nota:* rever se `noclean => true` é mesmo necessário. Se for, a bitmask tem de dizê-lo; se não
  for, o defeito é maior do que a bitmask.

**Fase 1 fechada** em 2026-08-16, versão `2026081513`, branch `fix/security-phase-1`.
Os quatro itens corrigidos e mutation-testados. O 2.6 (M22) foi puxado para esta fase: o teste
novo da tabela instancia a classe, o `$page` não declarado dispara uma deprecação, e o CI corre
PHPUnit com `--fail-on-warning` — não havia como fechar a fase 1 deixando-o de pé.

---

## Fase 2 — Achados da auditoria nunca corrigidos

Seis dos 191. Cada um tem a sua seção no `docs/AUDIT-2026-08.md` com o mecanismo completo; leia-a
antes de corrigir, e **abra o arquivo** — a seção descreve o código como estava em agosto.

- [x] **2.1 · M3 · o estimador nunca envia `filter_role_context`** — `amd/src/audience_criteria.js`
  (~146) · conf. **~** · pequeno

  `read()` recolhe coortes, papel, curso, categoria, formato, tema, competências e pathmatch, e
  nunca `filter_role_context`, apesar de o formulário declarar o select e de `role_search.js` ler
  esse mesmo campo para os seus fins. `estimator::normalise()` guarda então `0`, o que faz
  `role_scope::sql()` não emitir junção de contexto nenhuma. Uma regra de papel com escopo de curso
  é contada como se valesse em todo o site: o número no editor contradiz o número gravado.

- [x] **2.2 · M12 · `forcelogout` + `reqack=0` prende o utilizador** — `classes/helper.php:1121` ·
  conf. **~** · pequeno

  `check_if_already_acknowledged_by_user()` tem três condições de reexibição e nunca ganhou a quarta
  que `collect_user_notices()` carrega. Reproduzir: aviso com `reqack=0`, `forcelogout=1`; um
  não-admin fecha, é deslogado, volta a entrar, e o aviso reaparece em cada página — com o Aceitar
  virado num no-op silencioso.

- [x] **2.3 · M13 · coorte oculta: ninguém vê, o estimador reporta a coorte inteira** —
  `classes/helper.php:393` · conf. **~** · pequeno

  Três caminhos discordam. `built_cohorts_options()` usa `cohort_get_all_cohorts(0, 0)`, sem filtro
  de visibilidade, e oferece coortes ocultas como alvo. O runtime usa `cohort_get_user_cohorts()`,
  cujo SQL exige `c.visible = 1`. O estimador consulta `{cohort_members}` sem predicado nenhum.
  Resultado: o autor escolhe uma coorte oculta, o painel promete N pessoas, e o aviso não aparece
  para ninguém.

  *Relacionado com 1.3* — decidir a regra de visibilidade uma vez e aplicá-la nos três caminhos.

- [x] **2.4 · M14 · editar o rótulo de um link destrói o histórico de cliques** —
  `classes/helper.php:272` · conf. **~** · pequeno

  `noticelink::create_new_link()` identifica um link por `(noticeid, text, link)`. Corrigir uma gralha
  no rótulo — URL intacto — cria um id novo e aposenta o antigo; `update_hyperlinks()` apaga o
  antigo e nunca toca em `linkhistory`. A contagem cai a zero em todos os relatórios e as linhas
  ficam órfãs para sempre.

- [x] **2.5 · M16 · web service tipado como leitura escreve em tabela do core** —
  `classes/helper.php:1414` · conf. **~** · pequeno

  `get_user_competency_proficiency()` chama `\core_competency\api::get_user_competency_in_course()`,
  que **cria** uma relação `user_competency_course` quando não existe. É alcançado a partir de
  `local_awareness_getnotices`, declarado `'type' => 'read'` em `db/services.php`. Qualquer aluno a
  abrir uma página de curso coberta por um aviso com filtro de competência materializa estado de
  competência.

- [x] **2.6 · M22 · propriedade `$page` não declarada** — `classes/table/acknowledged_notice.php:92`
  e `classes/table/dismissed_notice.php:84` · conf. **~** · trivial

  Nenhuma das classes base do core declara `$page`, nem carrega `#[AllowDynamicProperties]`.
  Deprecação de criação dinâmica de propriedade em cada carregamento no PHP 8.2+.

  *Depende de 4.6:* se as duas páginas de relatório antigas forem removidas, isto vai junto.
  Resolver 4.6 primeiro pode tornar este item vazio.

**Fase 2 fechada** em 2026-08-16, versão `2026081514`, branch `fix/audit-findings-phase-2`.
Os cinco restantes corrigidos e mutation-testados (o 2.6 saiu na fase 1). O M13 foi resolvido
com um resolvedor de pertença partilhado em vez de filtrar o menu, para que uma coorte oculta
continue a ser um alvo legítimo — visibilidade governa quem pode *escolher* a coorte, o que a
fase 1 já garante na gravação, e não quem está *dentro* dela.

---

## Fase 3 — Correção encontrada nesta varredura

- [ ] **3.1 · o filtro de nome não volta para o campo, e o primeiro clique apaga-o** —
  `templates/manage/page.mustache:80` · conf. **~** · pequeno

  `managenotice.php:44` aceita `name` por URL de propósito ("uma lista filtrada pode ser ligada e
  sobrevive a um reload") e `manage_page.php:148` exporta `filters.namevalue` — que nenhum template
  consome. Os dois selects renderizam o seu valor; o input não tem `value=`. Abrir
  `?name=maintenance` filtra no servidor, mostra a caixa vazia, e o primeiro toque no select de
  Status faz `readValues()` ler a caixa vazia e alargar a lista a tudo, sem aviso.

  *Correção:* `value="{{filters.namevalue}}"` no input, e chamar `updateClearButton()` no `init()`
  do `manage_list.js` para que um link profundo mostre a saída. Exige rebuild do AMD e bump de
  versão. *Verificação:* cenário Behat que abre a URL com o parâmetro, afirma o valor do campo, mexe
  no Status e afirma que a contagem se mantém.

- [ ] **3.2 · registar uma estimativa re-exibe o aviso a toda a gente** —
  `classes/audience/notice_audience.php:204` · conf. **~** · pequeno

  `record()` grava com `$notice->update()`, e `core\persistent::update()` põe `timemodified = time()`
  incondicionalmente. Mas `timemodified` é o sinal "este aviso mudou": `collect_user_notices()`
  descarta a visualização registada quando ela é anterior. Um admin que carregue em "Recalcular
  público" re-exibe o aviso a todos os que já o tinham dispensado, e duplica linhas de
  reconhecimento.

- [ ] **3.3 · `attach()` rouba um job de estimativa de outro aviso** —
  `classes/audience/notice_audience.php:176` · conf. **~** · pequeno

  `refresh()` junta-se a um job em curso pelo hash de critérios e chama `attach()`, que sobrescreve
  o `noticeid` sem verificar se já aponta para outro aviso. Dois avisos site-wide sem filtros
  normalizam para `[]` e têm o mesmo hash. O primeiro aviso fica permanentemente por contar.

- [ ] **3.4 · o filtro de tema nunca casa com temas de curso ou categoria** —
  `classes/helper.php:1588` · conf. **~** · pequeno

  `check_filters()` lê `$PAGE->theme->name` dentro do pedido AJAX do web service, onde `$PAGE`
  nunca recebeu `set_course()` — logo `resolve_theme()` salta os ramos de curso e categoria e
  devolve o tema do site. Um aviso filtrado por um tema de curso não aparece nunca.

- [ ] **3.5 · apagar um aviso nunca apaga os ficheiros enviados** — `classes/helper.php:360` ·
  conf. **~** · pequeno

  `delete_notice()` com `cleanup_deleted_notice` ligado remove reconhecimentos, visualizações,
  links e histórico, e nunca chama `delete_area_files()` para as áreas `content` e `bgimage`. Os
  ficheiros ficam em `moodledata` e em `{files}` para sempre — e, como o gate do `pluginfile` recusa
  um aviso que já não existe, ficam inalcançáveis e por apagar ao mesmo tempo.

- [ ] **3.6 · "Remover selecionados" não faz nada** — `classes/report_filter.php:113` · conf. **~** ·
  trivial

  O formulário desenha `removeselected` e `removeall` mais uma checkbox por filtro ativo;
  `remove_filters()` só testa `removeall`. Marcar e submeter recarrega a página com o filtro intacto.

  *Nota:* estes filtros pertencem às páginas de relatório antigas — ver 4.6. Se elas saírem, este
  item sai junto.

- [ ] **3.7 · a lista re-materializa a tabela de coortes por coorte por linha** —
  `classes/helper.php:1045` · conf. **~** · pequeno

  `get_cohort_name()` chama `built_cohorts_options()` a cada invocação, sem memoização, e essa faz
  um COUNT, um SELECT sem limite sobre `{cohort}`, precarga de contextos e
  `cohort_get_invisible_contexts()`. Uma página de 25 avisos com 3 coortes cada faz 75 leituras
  completas da tabela. `get_course_name()` tem a mesma forma.

- [ ] **3.8 · `col_audience` faz a query por linha que o seu próprio comentário diz evitar** —
  `classes/table/all_notices.php:615` · conf. **~** · pequeno

  O comentário diz que a contagem é lida do aviso "em vez de resolver o último job por linha, o que
  numa página de vinte avisos seriam vinte queries extra". As instruções seguintes chamam
  `notice_audience::state_of()`, que corre `audience_job::find_in_flight()` sempre que o hash
  gravado falta ou não bate — o que é o caso de todo aviso anterior ao upgrade `2026081501`.

**Fecho da fase 3:** commit `______` · PR `#____` · data `______`

---

## Fase 4 — Código morto e meio-feito

Por último porque nada aqui está errado em execução — está errado para quem lê. Cada item é uma
promessa que o código faz e não cumpre, e o custo é o tempo do próximo leitor.

Duas decisões de produto precisam de ser tomadas antes de codificar: **4.2** (o autosave e o banner
de obrigatórios existem em desenho — implementar ou remover?) e **4.4** (a altura do modal no
preview — aplicar ou remover?). O resto é remoção pura.

- [ ] **4.1 · cache `user_notices` fantasma** — `db/caches.php:35` · conf. **~** · trivial

  Declarado `MODE_SESSION`, purgado em cada gravação da persistente, com string traduzida em duas
  línguas — e nunca lido nem escrito. O `docs/ACHADO1-decisao.md` **já decidiu removê-lo**, com a
  razão: o comentário "Also purge the session-scoped user notices cache" codifica uma crença falsa
  sobre o alcance de um purge entre sessões, e é uma armadilha armada para quem vier depois. A
  decisão foi escrita e não executada.

- [ ] **4.2 · autosave e banner de obrigatórios** — `classes/output/editor_page.php:167-168` ·
  conf. **✔** · pequeno · *decisão de produto*

  `'autosaved' => ''` e `'requirements' => ''` são literais. O `requirements` alimenta o bloco
  `.la-req-banner` no `shell.mustache`, que por isso nunca renderiza; o `autosaved` não é lido por
  template nenhum, e a sua regra `.la-pagehead-autosaved` não tem markup. As strings
  `editor:requirements`, `editor:autosaved` e `editor:action:saved_local` existem em duas línguas e
  não são referenciadas em lado nenhum.

  Implementar significa: calcular os campos obrigatórios em falta no `export_for_template()`, e um
  autosave de rascunho que hoje não existe em nenhuma forma. Remover significa apagar dois literais,
  um bloco de template, uma regra de CSS e seis strings.

- [ ] **4.3 · `$formid` e `$cancelurl` guardados e nunca exportados** —
  `classes/output/editor_page.php:47` · conf. **✔** · pequeno

  O construtor guarda ambos; o `export_for_template()` não devolve nenhum. Pior, `editnotice.php`
  (~149) existe em boa parte para extrair o id do formulário com um `preg_match` sobre o HTML
  renderizado, para preencher um valor que é deitado fora. É resíduo da barra de ações removida, que
  usava `form="{{actionbar.formid}}"`.

- [ ] **4.4 · `preview.modal_height` exportado e nunca aplicado** —
  `classes/output/editor_page.php:93` · conf. **✔** · pequeno · *decisão de produto*

  O `preview_card.mustache` lista `preview.modal_height` como variável de contexto obrigatória e
  tem-na no exemplo; o único estilo interpolado é o `max-width`. O `live_preview.js` só toca em
  `style.maxWidth`. O modal real **honra** a altura. Quem define "Altura do modal" e carrega em
  Pré-visualizar vê um diálogo idêntico ao anterior — o preview mente exatamente na definição que o
  autor está a testar.

- [ ] **4.5 · eventos `awareness_enabled` e `awareness_disabled` nunca disparados** —
  `classes/helper.php:322` · conf. **~** · pequeno

  `enable_notice()` e `disable_notice()` disparam ambos `awareness_updated`. As duas classes de
  evento estão completas e têm strings mantidas, e aparecem na lista de eventos da administração —
  onde um admin pode construir uma regra que nunca dispara.

- [ ] **4.6 · páginas de relatório antigas, inalcançáveis e com a capacidade errada** —
  `report/acknowledged_report.php:33` e `report/dismissed_report.php` · conf. **~** · médio

  Os botões da lista encaminham para as páginas de report-builder. Nada liga a estas duas; só
  chegam por URL escrito à mão. Chamam `check_manage_capability()` enquanto as suas equivalentes de
  report-builder verificam `viewreports` — a divisão de capacidade está invertida. Mantêm vivas
  ~1000 linhas de código de relatório paralelo, e são elas que arrastam os itens 2.6 e 3.6.

  *Se forem removidas, marcar 2.6 e 3.6 como resolvidos por remoção.*

- [ ] **4.7 · cinco strings `:desc` de seção nunca renderizadas** — `lang/en:110` e pares em
  `lang/pt_br` · conf. **~** · trivial

  As cinco seções do formulário usam só a chave simples; as `:desc` — por exemplo "Who the notice
  will be shown to. Filters combine with AND (intersection)." — não são referenciadas em PHP,
  Mustache ou JS. É exatamente o texto explicativo que a reconstrução em fieldsets colapsáveis
  deveria ter posto à frente do autor: considerar renderizá-las antes de as apagar.

- [ ] **4.8 · três classes CSS órfãs** — `styles.css` · conf. **✔** · trivial

  `la-chip--muted`, `la-pagehead-autosaved` e `la-spinner` não são carregadas por markup nenhum.
  Confirmado que já eram órfãs antes da fase 3. A `la-pagehead-autosaved` sai junto com 4.2.

- [ ] **4.9 · README com alvos de Makefile inexistentes** — `README.md:113` · conf. **~** · trivial

  Manda correr `make ci-awareness-datasource-tests`; não há Makefile no repositório. E a linha 26
  descreve o alvo de público como só coortes, quando o formulário oferece papel, contexto de papel,
  categoria, curso, formato, tema e regras de competência — que é aquilo para que metade da base de
  código existe.

**Fecho da fase 4:** commit `______` · PR `#____` · data `______`

---

## Protocolo por fase

Vale o que já está no `CLAUDE.md` da frota; o que se repete aqui é o que este repositório já fez
falhar.

- **Um branch e um PR por fase.** Nunca commitar na `main`.
- **`mdl ci moodle-local_awareness --branch MOODLE_405_STABLE` e `--branch MOODLE_502_STABLE`**
  antes de propor. As duas pontas divergem de verdade — o phpcs do 5.02 já reprovou o que o 5.01
  deixou passar, e o moodle-cs do 4.05 não vê atributos PHP.
- **Não correr o Behat e o `mdl ci` ao mesmo tempo.** Medido nesta sessão: a suíte passou de 9 para
  110 minutos e deu duas falhas de sessão do WebDriver que não eram defeitos.
- **Depois de bumpar a versão:** `mdl upgrade m501 && mdl behat-init m501`, senão o Behat sai com
  zero cenários e parece verde.
- **Teste de mutação em cada teste novo.** Um teste que afirma que algo *não* aconteceu tem de
  provar que a força que o causaria estava ligada — senão passa sem exercer nada e continua a passar
  depois de a proteção ser apagada.
- **Marque o item aqui no mesmo commit da correção.** Uma lista de trabalho que se atualiza noutro
  momento é uma lista que mente.
- **Ao fechar um item de auditoria**, dizer no `CHANGELOG.md` qual é (M3, M12, …) para que a
  reconciliação que nunca houve comece a existir.
