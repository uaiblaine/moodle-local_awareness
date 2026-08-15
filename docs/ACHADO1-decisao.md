# Achado 1 — a requisição extra por página: mecanismo, alternativas e recomendação

Investigação sobre `main` em `38adb6b`, confrontada nos dois cores (`~/dev/moodle-405`,
`~/dev/moodle-502/public`), com instrumentação nas stacks m405/m502 e reproduções por PHPUnit e por
HTTP no site vivo. Tudo o que está marcado **[medido]** foi executado nesta investigação; o que está
marcado **[não medido]** é dito explicitamente. O documento inteiro passou por um painel adversarial
de três revisores independentes instruídos a refutar; as três quebras que eles encontraram (o layout
`secure` pode exibir aviso hoje via bloco Navegação; `out_as_local_url('/')` devolve `'/'`, não
vazio; o filtro de tema quebraria o fail-open com temas de curso/categoria ligados) estão
incorporadas no texto abaixo, marcadas onde relevante.

## Sumário executivo

O achado está confirmado e reproduzido de ponta a ponta. A recomendação é a **alternativa 4,
implementada na arquitetura que o core já usa para o mesmo problema** (`tool_usertours`):

1. Mover a decisão "carregar o JS?" do callback de navegação para o hook
   `core\hook\output\before_footer_html_generation` (idêntico nos dois branches suportados).
2. Dentro dela, aplicar **no servidor** as regras de página que custam zero query — `pathmatch`,
   filtros de curso/categoria/formato via `$PAGE->course`, tema via `$PAGE->theme` quando temas de
   curso/categoria estão desligados — e tratar todo o resto (papel, competência, acesso ao curso,
   tema com override ligado) como "casa" (fail-open).
3. Não tocar em `notice.js` nem no contrato do web service: o XHR continua existindo e continua
   sendo a filtragem definitiva — ele só deixa de ser disparado nas páginas onde a resposta seria
   comprovadamente vazia.

Com isso, um site cujos avisos são todos segmentados por página (o cenário do achado) deixa de pagar
o XHR em todo o resto do site. Um aviso **sem** regra de página continua custando um XHR por página —
mas esse XHR faz trabalho real (entrega conteúdo ou consome turno da fila), então não é desperdício.

A alternativa 3 (cache de sessão) deve ser **rejeitada** — a análise abaixo mostra que a variante
por página é insalvável por corromper a fila do PR #14, e a variante booleana mira um custo que já é
~zero. A alternativa 2 (padrões no cliente) é dominada pela 4 em todas as dimensões e ainda expõe
metadado. A 5 (adiar o XHR) não resolve o problema, só o esconde. "Não fazer nada" foi considerado e
rejeitado: o custo escala com usuários × páginas e a correção é pequena, com precedente core estável.

---

## 1. O mecanismo, confirmado (com correções de referência)

A cadeia do prompt confere em `38adb6b`:

| Elo | Referência | Status |
|---|---|---|
| `local_awareness_extend_navigation()` roda em toda página | `lib.php:33` | ✔ confirmado (ver abaixo *onde* exatamente) |
| chama `helper::has_candidate_notices()` | `lib.php:47` | ✔ |
| que delega para `collect_user_notices('', 0, false)` | `classes/helper.php:442` (`:441` é a declaração) | ✔ |
| `if ($checkpagerules)` desligado pula `check_path_match()`/`check_filters()` | `classes/helper.php:617` | ✔ |
| `true` → carrega o AMD | `lib.php:48` | ✔ |
| `init()` dispara `local_awareness_getnotices` incondicionalmente | `amd/src/notice.js:155-160` | ✔ (bundle `amd/build` em sincronia, verificado por source map) |

**Onde a sonda de fato roda.** `load_local_plugin_navigation()` está em
`lib/navigationlib.php:1675` e `:1709-1713` no 4.5 e em
`public/lib/classes/navigation/global_navigation.php:443` e `:476-480` no 5.2 — *as linhas `:435` e
`:468` citadas no prompt estão desatualizadas.* O gatilho é incondicional em qualquer página com os
layouts padrão do Boost: `drawers.php` constrói a navegação primária em toda renderização
(`theme/boost/layout/drawers.php:75` no 4.5, `:81` no 5.2), o que puxa
`settingsnav` → `global_navigation::initialise()` → o callback — uma vez por requisição, dentro do
`$OUTPUT->header()`. Não depende de ser admin, de haver bloco de navegação, nem de navegação
secundária.

A sonda **também** roda num lugar que o achado não citou: `lib/ajax/getnavbranch.php` (expansão de
galho do bloco Navegação clássico) instancia `global_navigation_for_ajax`, que chama o mesmo
callback (4.5 `lib/navigationlib.php:3656`; 5.2 `global_navigation_for_ajax.php:158`). Ali o
`js_call_amd()` é **engolido** — o renderer AJAX tem `footer()` vazio — então é sonda pura perda:
custo sem nenhuma possibilidade de entrega.

E a sonda **nunca** roda em: página de login, `lib/ajax/service.php`, servidores WS, CLI/cron,
interstitials de `redirect()`, `pluginfile.php`, e layouts popup/print/embedded/maintenance sob
temas da família Boost. Consequência que vale registrar: **hoje um aviso não pode aparecer nessas
páginas** — nenhum JS é injetado nelas. Exceção verificada pela revisão adversarial: o layout
**secure** renderiza blocos reais (`theme/boost/layout/secure.php:27`) e emite o token de fim de
corpo (`secure.mustache:84`) — num site com um bloco Navegação fixado, a sonda roda e o aviso
**pode** aparecer dentro de uma tentativa de quiz em securewindow hoje. Isso importa para a
denylist da recomendação (seção 5).

### Reproduções **[medido]**

**PHPUnit, nos dois cores** (teste descartável, já removido; 3 avisos `pathmatch = '/my/%'`, usuário
comum):

| Stack | `has_candidate_notices()` | `retrieve_user_notices('/course/view.php?id=1')` | controle `('/my/index.php')` |
|---|---|---|---|
| m405 (4.5.13) | `true` | **0 avisos** | 3 avisos |
| m502 (5.2.2) | `true` | **0 avisos** | 3 avisos |

O controle prova que o vazio na página de curso é a regra de página funcionando, não fixture
quebrada. `probereads=2` com cache frio (enabled_notices + lastview), coerente com PR #18/#19.

**HTTP no site vivo (m502)**, com um aviso `/my/%` semeado: o HTML de `/course/view.php?id=2`
carrega o módulo no rodapé (`require(['local_awareness/notice'] … amd.init()`), e o XHR que esse
`init()` dispara responde `{"status":true,"notices":"[]"}` — um bootstrap autenticado completo do
Moodle para entregar uma lista vazia. O mesmo XHR a partir de `/my/` entrega o aviso. É exatamente a
requisição que escala com usuários × páginas, e ela ainda **serializa com a navegação do próprio
usuário** no lock de sessão.

*Observação lateral surgida na demo:* o `get_notices()` serializa `to_record()` inteiro — o payload
dos avisos exibidos carrega `pathmatch`, `filtervalues`, `cohorts`, janelas e `usermodified` (um id
de usuário) que o cliente nunca lê. Fica registrado como tarefa separada; não é parte deste achado.

---

## 2. A armadilha do `$PAGE->url`, medida

### O que o prompt dizia, e o que o código diz

| Afirmação do prompt | Veredicto |
|---|---|
| `magic_get_url()` não lança; emite `debugging()` DEVELOPER e cai em `$FULLME` | ✔ Confirmado, corpo byte-idêntico nos dois cores. Correção de linha: no 5.2 é `public/lib/pagelib.php:701-710`; `:681` é a linha **do 4.5** (`lib/pagelib.php:681-690`). |
| Logo o `catch (\coding_exception)` do fallback (`helper.php:1409-1412`) é código morto | **Parcialmente.** Morto **pelos chamadores atuais** (nenhum caminho de produção chama `check_path_match()` com `$pageurl` vazio — verificado, inclusive o `collision.php`). Mas vivo **como API**: `out_as_local_url()` lança `coding_exception` para URL não-local (`lib/classes/url.php:814-825` no 4.5; `:861-872` no 5.2), e isso acontece **sempre** em CLI/cron/PHPUnit (`$FULLME = null` → URL vazia → não-local; verificado executando nos containers dos dois cores) e na web sob mismatch de scheme. Qualquer futuro chamador server-side sem URL cai nesse caminho — e o `return true` do catch é exatamente o fail-open desejado. |
| O debugging vira ruído em DEVELOPER e falha Behat/PHPUnit | **Behat: sim, nos dois cores** (o hook pós-passo varre o DOM por `div[data-rel='debugging']` — mas só o DOM da página; debugging dentro de resposta XHR passa). **PHPUnit: só no 4.5.** PHPUnit 9.6 converte o `E_USER_NOTICE` em falha (`convertNoticesToExceptions`); PHPUnit 11.5 no 5.1/5.2 **sai com exit 0** — `failOnNotice` não está setado e o `--fail-on-warning` do CI não cobre notice (medido no stack: `OK, but there were issues!`, EXIT=0). Ou seja: a rede de proteção contra esse ruído é **assimétrica entre os branches** — mais um motivo para nunca depender dela. |
| Em produção (debug < DEVELOPER) | Silencioso: o gate de nível retorna antes de formatar (weblib, ambos os cores). Única exceção: usuários listados em `$CFG->debugusers`. |

### A janela do `set_url()`, instrumentada **[medido]**

`lib.php` foi instrumentado temporariamente para registrar, em cada disparo do callback de navegação
(`NAV`) e no shutdown da requisição (`END`), se `$PAGE->has_set_url()` era verdadeiro. Corpus de
~18 páginas representativas por stack, logado como admin:

| Stack | Renders com URL definida no momento do hook | Exceção | URL definida no fim da requisição |
|---|---|---|---|
| m405 | 17/18 | `/contentbank/index.php` | 18/18 |
| m502 | 16/17 | `/contentbank/index.php` | 17/17 |

O "quase sempre" medido: **~94% no momento do hook de navegação; 100% no momento do footer.** A
exceção é real, está no core, e é idêntica nos dois branches: `contentbank/index.php` chama
`\core_contentbank\helper::get_page_ready()` (cujo ramo de contexto de sistema chama
`$PAGE->navigation->find('contentbank', …)` — `contentbank/classes/helper.php:67` —, e
`global_navigation::find()` inicializa a navegação preguiçosamente) **antes** do `set_url()` da
linha 51. Ou seja: a
classe de página "navegação antes da URL" existe hoje, e qualquer desenho ancorado no callback de
navegação precisa da guarda — mas no momento do **footer** a URL estava definida em 100% do corpus,
o que é um dos argumentos para trocar de âncora (seção 3).

A guarda correta existe e é gratuita nos dois cores: `moodle_page::has_set_url()`
(4.5 `lib/pagelib.php:2271-2273`; 5.2 `:2277-2279`) — leitura pura de `_url`, sem debugging, sem
inicialização preguiçosa.

### Subdiretório: as duas representações de URL divergem

`out_as_local_url()` remove o prefixo do wwwroot (inclusive o caminho); `window.location.pathname`
o mantém. Num site em `https://exemplo.com/moodle`:

- Os tokens `FRONTPAGE`/`MY`/`MYCOURSES` do `check_path_match()` **já estão quebrados hoje** para
  esses sites: `$target === '/'` e `strpos($target, '/my/') === 0` (`helper.php:1417-1419`) nunca
  casam com `/moodle/…`.
- Padrões comuns toleram o prefixo **por acidente**: a regex final não é ancorada no início
  (`helper.php:1434-1441`), então `/my/index.php` casa com `/moodle/my/index.php`.

(Uma suspeita levantada durante a investigação — de que `set_url('/')` faria `out_as_local_url()`
devolver string vazia na frontpage — foi **refutada** executando nos dois containers: devolve `'/'`;
a string vazia só surge quando a URL é igual ao wwwroot sem barra final, o que `set_url('/')` nunca
produz.)

Qualquer matching server-side deve, portanto, detectar os landmarks com
`moodle_url::compare(URL_MATCH_BASE)` — como o usertours faz, robusto a subdiretório e à
equivalência `/my/` ≡ `/my/index.php` — e aceitar **as duas** representações
de caminho (local e com prefixo) antes de dizer "não casa", para não suprimir padrões que o admin
autorou contra a forma do cliente. Incerteza casa; só o "não" das duas formas suprime.

---

## 3. O precedente que o core já oferece: `tool_usertours`

O usertours resolve o mesmo problema — UI condicional por página, casada por padrão de URL, por
usuário — e resolve **do jeito que este achado pede**. Maquinaria md5-idêntica entre 4.5 e 5.2
(única exceção: `manager.php`, cujas diferenças são de UI de gestão; `get_matching_tours()` é
idêntico):

- **Âncora**: hook `before_footer_html_generation` via `db/hooks.php`
  (`admin/tool/usertours/db/hooks.php:27-33`), despachado no topo de `core_renderer::footer()`
  (4.5 `lib/classes/output/core_renderer.php:986-988`; 5.2 `:965-967`) — **antes** da substituição
  do token de fim de corpo, então `js_call_amd()` feito ali ainda entra no rodapé AMD da página
  (substituição em 4.5 `:1034` / 5.2 `:1013`). Confirmado nos dois cores.
- **Decisão de carregar o JS 100% server-side** contra `$PAGE->url`
  (`manager::get_matching_tours()`), no momento em que a URL está tão definida quanto jamais estará.
  Zero JS e **zero XHR** em página sem tour. (Um último filtro clientside — `cssselector`, que
  precisa do DOM — decide o *start* nas páginas com match; o awareness não tem filtro que precise do
  DOM, então nem essa camada é necessária.)
- **Sintaxe de padrão**: o `check_path_match()` do plugin é cópia quase literal de
  `tool_usertours\cache::get_matching_tourdata()` (`cache.php:72-108`) — mesmos tokens
  `FRONTPAGE`/`MY`/`MYCOURSES`, mesmo `%`→`.*`, mesma ancoragem. **O plugin copiou a sintaxe, mas
  não a arquitetura.** Diferença única no matching: usertours detecta landmarks com
  `moodle_url::compare(URL_MATCH_BASE)` (robusto a subdiretório), o plugin compara strings do
  cliente.
- **Cache**: uma chave de aplicação com o conjunto inteiro de tours ativos, invalidada por evento de
  escrita (`notify_tour_change()`), match recomputado por requisição. O plugin já tem o equivalente
  (`enabled_notices`, PR #18). Nada por-usuário-por-página é cacheado.
- **Guardas**: `!isloggedin() || isguestuser()` e
  `pagelayout in ['maintenance', 'print', 'redirect']` (`helper.php:506-544`).
- **Direção de tolerância**: no usertours um tour perdido é aceitável — usuário sem setup completo
  (`awaiting_action()`) não recebe tour nenhum, e uma URL não definida usa o palpite do
  `magic_get_url()` (que pode não casar nada). O awareness tem a restrição oposta — a incerteza deve
  falhar **aberto** — e é a única adaptação de direção necessária.

Uma decisão do usertours que **não** deve ser copiada: ele lê `$PAGE->url` sem guarda — aceita o
palpite do `magic_get_url()` e o debugging que vem junto. Para o awareness, `has_set_url()` +
try/catch com fail-open é mais barato que o ruído (e o Behat do 4.5 é a única rede que pegaria o
ruído; a do 5.x não pega, como medido acima).

---

## 4. As alternativas, lado a lado

### A1 — aplicar `pathmatch` no servidor, dentro da sonda atual

Funciona, e a guarda necessária existe (`has_set_url()`, fail-open). Mas tem três problemas:

1. **Âncora errada.** No callback de navegação a janela sem URL existe (contentbank, ~6% do corpus);
   no footer ela foi 0% no corpus. A mesma mudança ancorada no footer é a A4 — estritamente melhor.
2. **Custo escondido se for além do pathmatch.** `check_filters()` completo no servidor põe queries
   no TTFB: a regra de papel custa 1 SQL por aviso com filtro de papel (`user_matches_role_filter`),
   `can_access_course()` custa, competência custa. É exatamente o tipo de custo que o PR #19 acabou
   de tirar do TTFB. A versão viável aplica só regras zero-query (ver A4).
3. **Ganho dependente do dado.** Um aviso sem `pathmatch` continua disparando XHR em toda página.
   **[não medido]** — a fração de avisos com `pathmatch` em produção é do site; as fixtures de
   display do próprio plugin usam `pathmatch` em 1 de 11 avisos, mas fixture não é evidência de uso
   real.

**Veredicto: correta em espírito, superada pela A4 na âncora.**

### A2 — passar os padrões para o cliente via `js_call_amd`

Rejeitar, por quatro razões independentes:

1. **Expõe metadado de segmentação de todos os avisos** — inclusive os que aquele usuário nunca
   verá — a todo usuário logado. Este plugin já teve divulgação de conteúdo por `pageurl` omitido
   (PR #9, versão 2026081300); segmentação é o metadado que diz *quem a instituição quer atingir*.
   Restrição de verdade, como pedido.
2. **Poda menos que a A4**: só `pathmatch` é avaliável no cliente; filtros de curso/categoria/
   formato/tema exigiriam despachar mais metadado ainda, e papel/competência são impossíveis.
3. **O precedente core faz o contrário** (usertours: matching no servidor; só o veredicto viaja).
4. **A representação do cliente diverge da do servidor** em subdiretório (seção 2) — o problema que
   esta alternativa dizia evitar volta por outra porta.

A única vantagem alegada (evitar `$PAGE->url`) é neutralizada por `has_set_url()` + âncora no
footer. **Dominada pela A4.**

### A3 — cachear a resposta na sessão (a definição `user_notices` órfã)

Dois candidatos de conteúdo, e os dois falham por razões diferentes:

**(b) A resposta do `getnotices` por usuário+página — insalvável em qualquer chave/TTL.**
`select_for_display()` **muta** `$USER->awarenessshown` (`helper.php:513-515`): entregar um aviso É
consumir seu turno na fila do PR #14. A resposta não é um valor, é uma transição de estado. Replay
de resposta cacheada produz: modal fantasma do aviso já dispensado (até o cache expirar — o re-ack é
no-op; purgar a própria sessão no dismiss/ack conserta esse eixo, mas nenhum dos eixos entre
usuários), **supressão estrutural do próximo da fila** (a direção proibida, sem precisar de corrida
rara), e a contabilidade de tiers para de decair. E o regime de hit é ~zero: resposta não-vazia muda
o estado do qual a próxima resposta depende. A chave honesta
(usuário+sessão+página+query+courseid+língua+tema+fingerprint da fila) tem cardinalidade ilimitada
dentro de `$SESSION` — bloat serializado a cada requisição, sob o lock de sessão.

A variante mais forte — **cachear só a resposta vazia** por página, que escapa do argumento da
mutação (resposta vazia não consome turno) — também cai, por dois golpes independentes: (i) todos
os eixos de invalidação sem evento (aviso novo criado/habilitado não alcança as outras sessões no
store default, `timestart` abrindo, `resetinterval` vencendo, cohort/conclusão/papel mudando)
forçam um TTL, e **qualquer TTL converte staleness em supressão limitada de um aviso devido — a
direção proibida por definição**; e (ii) o cache mora na sessão do servidor, então **o XHR — o
achado — continua sendo disparado do mesmo jeito**; usar o vazio cacheado para nem injetar o JS
exigiria saber a página no momento do render, que é exatamente a premissa da A4 — e aí avaliar as
regras zero-query diretamente (A4) dá a mesma supressão com zero staleness.

**(a) O booleano por usuário — salvável, mas mira o custo errado.** Seria seguro só com: eventos de
invalidação (a linha atual `\cache::make('local_awareness','user_notices')->purge()` em
`awareness.php:162` **só limpa a sessão do gerente que salvou** no store default —
`cachestore_session` é uma referência ao `$SESSION` da requisição corrente
(`cache/stores/session/lib.php:56-68`, `purge()` em `:464-469`); as sessões dos outros usuários são
inalcançáveis *por esse `purge()`* — o único canal cross-session do MUC é `invalidationevents`, que
a definição não declara) + TTL para os eixos de relógio que não têm evento (`timestart` abrindo,
`resetinterval` vencendo, cohort/conclusão mudando). E **nos stacks dev o `mdl redis` mapeia o MUC
de sessão para um hash Redis compartilhado por definição, onde `purge()` é global de verdade — um
teste local "provaria" uma invalidação que não existe num site com store default.** Tudo isso para
economizar o trabalho da sonda, que com cache quente custa 0 leituras **quando nenhum aviso ativo
tem cohort ou curso obrigatório** (a classe medida no PR #18); com cohort é 1 leitura por página
(`cohort_get_user_cohorts()` não é cacheada) e com `reqcourse` ~1 por curso distinto por página
(PR #19) — custos já pagos hoje em toda página, que a variante (a) até pouparia em site pesado de
cohort. Mas o achado é a **requisição**, não o trabalho da sonda.

**Veredicto: rejeitar como correção do achado 1.** E o achado 4 (a definição fantasma) deve ser
fechado **removendo** a definição, o purge e as strings — o comentário "Also purge the
session-scoped user notices cache" codifica uma crença falsa sobre alcance de purge entre sessões, e
é uma armadilha armada para quem vier depois.

### A4 — não carregar o AMD onde nada pode aparecer (RECOMENDADA)

A arquitetura do usertours, com a direção de falha invertida. Desenho na seção 5.

### A5 — adiar o XHR para ocioso

Resposta franca, como pedido: **disfarça, não resolve.** Sendo exato sobre o que compraria: elimina
uma fração pequena e dependente de dados das requisições (páginas abandonadas antes do idle
callback) e desloca a contenção do lock de sessão para fora da janela de carregamento — mas o custo
estrutural, que cresce com usuários × páginas, fica intacto; o XHR já está fora do caminho crítico
do cliente (rodapé, assíncrono, FCP não afetado, como o próprio prompt estabelece); e
`requestIdleCallback` atrasaria a aparição do modal — regressão de produto para aviso urgente.
Rejeitar.

### A6 — outros caminhos considerados

- **Renderizar o modal no servidor (zero XHR até em página com match).** Rejeitada por ora: move a
  marcação de turno (`select_for_display`) e a renderização (`format_text` por leitor) para dentro
  do TTFB de página com match; toda a fiação JS (dismiss/ack/tracklink/forcelogout/próximo-da-fila)
  continua necessária de qualquer forma; e mexe na semântica de fila que o PR #14 acabou de assentar.
  Só reconsiderar se, depois da A4, o XHR em página com match ainda incomodar.
- **Veredicto inline + payload adiado** (o split do usertours): para o awareness equivale à A4 — o
  payload já é pequeno e o XHR em página com match é trabalho real. Sem ganho adicional.

---

## 5. A recomendação, defendida

**A4 na arquitetura usertours.** Concretamente (para a próxima sessão — nada foi implementado):

1. **Âncora**: `db/hooks.php` registrando `before_footer_html_generation` →
   `classes/local/hook_callbacks.php`; remover `local_awareness_extend_navigation()` (ele não
   adiciona nós de navegação — só pegava carona). Bump de `version.php` + `CHANGELOG.md` no mesmo
   commit (hooks só registram com bump). Mecanismo idêntico nos dois cores; o hook existe desde o
   4.4 e é byte-idêntico entre 4.5 e 5.2.
2. **Guardas, na ordem da casa**: denylist de `pagelayout` — `maintenance`, `print`, `redirect`
   (como usertours) **mais** `embedded`, `popup`, `secure`. Para popup/print/embedded/redirect isso
   preserva a cobertura atual (nenhum JS é injetado neles hoje; sem a guarda o hook os alcançaria —
   os templates emitem o token de fim de corpo, então o JS *funcionaria* lá). Para **`secure` é uma
   decisão de produto, não preservação**: a revisão adversarial mostrou que hoje, num site com bloco
   Navegação fixado, a sonda roda e um aviso pode aparecer dentro de uma tentativa de quiz em
   securewindow — a denylist suprime esse caso raro e dependente de configuração. Defendo suprimir
   (um modal de aviso, com `forcelogout` possível, dentro de uma janela segura de prova é
   comportamento acidental, não desejado), mas fica registrado como mudança. Denylist, nunca
   allowlist: layout desconhecido carrega (fail-open). Depois `isloggedin()` + `enabled`, como hoje.
3. **Sonda page-aware de custo zero**: `has_candidate_notices()` passa a receber o contexto de
   página — URL local (`has_set_url() ? out_as_local_url() em try/catch : null`), `$PAGE->course`,
   `$PAGE->theme->name`. Por candidato, aplicar apenas regras **zero-query**:
   - `pathmatch` (string/regex sobre padrões já em memória; landmarks via
     `moodle_url::compare(URL_MATCH_BASE)`; em caso de padrão literal, testar as duas representações
     de caminho — local e com prefixo de subdiretório — e suprimir só se ambas negarem);
   - `filter_course` / `filter_category` / `filter_format` contra `$PAGE->course` (já carregado,
     zero query — sem `can_access_course`: o check de acesso protege *conteúdo*, e a sonda não
     entrega conteúdo; o WS revalida). Duas regras de equivalência obrigatórias: replicar o
     `$courseid > 1` do `check_filters()` (`helper.php:1486`) — `$PAGE->course` devolve o curso do
     site em toda página não-curso, e o site **não** conta como página de curso; e `M.cfg.courseId`
     é `$PAGE->course->id` por construção (`page_requirements_manager`), então sonda e WS veem o
     mesmo valor. Uma propriedade ausente no record (um `set_course()` de terceiro com lista de
     campos estreita) conta como **match**, nunca como negativa;
   - `filter_theme`: **só quando `$CFG->allowcoursethemes` e `$CFG->allowcategorythemes` estão
     desligados**, contra `$PAGE->theme->name` (zero query). Com qualquer um ligado, contar como
     match: a revisão adversarial mostrou que a requisição de página resolve o tema do curso/
     categoria enquanto a requisição do WS resolve sessão/usuário/site — avaliar na sonda com o tema
     do curso suprimiria permanentemente um aviso de tema do site dentro de cursos com tema forçado
     (a direção proibida).
   - `filter_role`, competência e acesso ao curso: **não avaliar** (custam SQL/TTFB) — contam como
     match. Cohort e conclusão já são avaliados hoje na sonda e ficam como estão.
4. **Escada de fail-open explícita**: URL indisponível → carrega; exceção em qualquer avaliação →
   carrega; regra cara → carrega; tema com course/category themes ligados → carrega; propriedade
   ausente → carrega; layout desconhecido → carrega. Só um "não" barato, seguro e duplo (nas duas
   representações de caminho) suprime o JS. A incerteza falha carregando, como a restrição exige.
5. **Cliente e WS intocados**: `notice.js` não muda (nenhum rebuild de AMD), o contrato do
   `getnotices` não muda, `select_for_display()` e a marcação de turno continuam no WS — a página
   onde o login cai continua consumindo o turno da fila exatamente como `display_queue.feature`
   pina. O `pageurl` do cliente continua sendo validado no servidor como hoje (ele é parâmetro de
   segurança desde o PR #9, não dica).
6. **Testes que a mudança inverte de propósito**: `tests/helper_test.php:273-293` pina hoje que a
   sonda **ignora** as regras de página — esse contrato é exatamente o que muda; reescrever
   deliberadamente (sonda continua superset da exibição, mas agora por página). Behat:
   `page_filtering.feature` ganha o lado negativo (na página sem match, além de "não vejo o modal",
   o módulo nem carrega). Mutação obrigatória: derrubar a guarda `has_set_url` deve quebrar
   exatamente um teste; derrubar a denylist de layout, outro; derrubar a guarda de
   `allowcoursethemes` no filtro de tema, outro; imprimir o estado mutado antes de rodar.

### Ganhos

- **Estrutural [medido por equivalência]**: zero XHR e zero AMD nas páginas onde nenhum candidato
  passa nas regras de página — a mesma garantia que o usertours entrega hoje em página sem tour
  (verificado no código dos dois cores). No cenário do achado (avisos só de Painel), o site inteiro
  fora de `/my/` deixa de pagar a requisição.
- **De carona**: a sonda pura-perda no `getnavbranch.php` desaparece (o hook não dispara lá).
- **[não medido]**: a fração do tráfego real eliminada depende de quantos avisos de produção têm
  regra de página. O desenho garante o teto (nenhum desperdício estrutural) sem depender desse dado.

### Custos e riscos

| Risco | Direção | Mitigação |
|---|---|---|
| Bump de versão esquecido → hook nunca registra → **nenhum** JS em lugar nenhum | Supressão total | O risco é de processo, não de desenho; a suíte Behat de display inteira fica vermelha (força que já provou pegar exatamente esta classe: os nove cenários do cache no PR #18) |
| Denylist de layout errada | Supressão em layout raro | Denylist (não allowlist) + o corpus deste documento como referência do que cada layout faz hoje |
| Padrão autorado contra a forma com subdiretório | Supressão nesses sites | Matching duplo fail-open (item 3). Nota de escopo: como o WS não muda, a exibição em si não melhora em site nenhum — a mudança só remove XHRs; o objetivo do matching duplo é **não introduzir supressão nova** (os tokens do `check_path_match` já estão quebrados hoje nesses sites, e continuam — defeito pré-existente, fora deste escopo) |
| Inversão do contrato do `helper_test` | — | Mudança consciente e documentada, não regressão silenciosa |
| Gerador Behat insere via `$DB` e purga na mão (`behat_local_awareness.php:63-72`) | Cenários às cegas | Nenhum cache novo é introduzido; se a implementação criar um, purgar lá também |
| TTFB | — | Zero queries novas por construção (item 3); o trabalho novo é `preg_match` sobre padrões já cacheados |

### Por que não "não fazer nada"

O custo é a classe que cresce com o produto usuários × páginas — a única do plugin. A correção não
mexe no cliente, não mexe no contrato do WS, não mexe na fila, tem precedente core idêntico nos dois
branches suportados, e o modo de falha de cada incerteza é o barato. O único cenário em que "não
fazer nada" venceria é um site cujos avisos nunca têm regra de página — e nesse site a mudança não
custa nada além do bump, porque a sonda responde igual a hoje.

---

## 6. Apêndice — medições e correções de referência

### Corpus da instrumentação (resumo)

Páginas visitadas logado como admin (novo login por stack): login (deslogado), frontpage, `/my/`,
`/my/courses.php`, curso, fórum, perfil, preferências, busca admin, índice de cursos, calendário,
mensagens, badges, edição de curso, grader, banco de questões, contentbank, XHR
`core_fetch_notifications`, `theme/image.php`. Resultado por stack na seção 2. Particularidades:
no m502 `/?redirect=0` e `/my/courses.php` redirecionam para `/my/` (comportamento 5.2 do
`public/index.php:76-91`, que ignora `redirect=0` com `enablemyhome` vazio — a memória da frota já
registra o efeito disso no Behat); `/message/index.php` respondeu 500 no m502 (não relacionado ao
plugin; não investigado aqui).

### Correções ao enunciado do achado (para o registro)

| Enunciado | Correção |
|---|---|
| `public/lib/pagelib.php:681` (5.2) | `:701-710` no 5.2; `:681-690` é o 4.5 |
| `global_navigation.php:435` e `:468` (5.2) | `:443` e `:476-480` |
| "o fallback… no Behat/PHPUnit é falha de teste" | Behat: sim (só DOM; debugging em resposta XHR passa). PHPUnit: **só no 4.5**; no 5.x sai 0 (medido) |
| "catch é código morto" | Morto pelos chamadores atuais; vivo como API (`out_as_local_url()` lança para URL não-local — sempre, em CLI/PHPUnit) |
| "na prática o hook roda durante a renderização, quando `set_url()` quase sempre já rodou" | Medido: ~94% no hook de navegação (exceção: contentbank, nos dois cores), 100% no footer |

### Status de implementação (2026-08-14) — ENCERRADO

A alternativa 4 foi implementada e **mergeada**: PR
[#20](https://github.com/uaiblaine/moodle-local_awareness/pull/20) (commit `d64a272`), exatamente
no desenho da seção 5 — incluindo as três correções do painel adversarial (denylist com `secure`
documentado como decisão de produto; guarda de tema pelos dois configs; matching duplo de URL).
`mdl ci` com `ALL STEPS PASSED` nas duas legs locais; matriz completa do GitHub (21 jobs:
4.05/5.00/5.01/5.02, PHP 8.1–8.4, pgsql+mariadb) verde; PHPUnit 147/147 e Behat 14/14 nos dois
cores; sete mutações aplicadas, impressas e mortas, cada uma por seu teste designado. `notice.js`,
o contrato do WS e a fila do PR #14 intactos.

De carona, a observação lateral da seção 1 (o payload do `getnotices` embarcava `to_record()`
inteiro) virou o PR [#21](https://github.com/uaiblaine/moodle-local_awareness/pull/21), também
**mergeado**: allowlist dos nove campos que o modal lê, com teste de conjunto exato e mutação
morta. `main` em `0ed026d`; com isso, o último achado aberto das auditorias está fechado.

### O que foi tocado nas stacks durante a investigação (tudo revertido)

Instrumentação temporária em `lib.php` (revertida; árvore limpa em `38adb6b` + `docs/` untracked),
teste PHPUnit descartável (removido), um aviso semeado no m502 (removido; `enabled` do plugin
restaurado a `0`), upgrades pendentes executados em m405/m502 e `phpunit-init` re-rodado nos dois
(manutenção normal das stacks, não revertida de propósito).
