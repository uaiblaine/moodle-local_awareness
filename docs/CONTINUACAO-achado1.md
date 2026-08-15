# Prompt — achado 1 da auditoria de performance do local_awareness

> Cole o bloco abaixo inteiro numa sessão nova, a partir de `~/dev/moodle-local_awareness`.

---

Preciso investigar a fundo, confrontar no código e **propor alternativas** para o último achado
aberto de uma auditoria de performance do plugin `local_awareness` (`~/dev/moodle-local_awareness`).
Não quero que você já saia corrigindo: quero o problema entendido de verdade, as opções colocadas
lado a lado com os custos reais de cada uma, e uma recomendação defendida. A implementação vem
depois que eu escolher.

A branch `main` está limpa em `38adb6b`, sem branches abertas. Os PRs #2 a #19 estão mergeados e o
`CHANGELOG.md` registra o que cada um resolveu. O plugin declara `$plugin->supported = [405, 502]`,
então **tudo precisa valer no Moodle 4.5 e no 5.2**.

## O achado

O plugin transforma **toda visualização de página** numa requisição autenticada a mais.

Cadeia, com referências verificadas em `38adb6b`:

- `lib.php:33` — `local_awareness_extend_navigation()` roda em toda página.
- `lib.php:47` — chama `helper::has_candidate_notices()`.
- `classes/helper.php:441` — `has_candidate_notices()` delega para
  `collect_user_notices('', 0, false)`, ou seja, com as regras de página **desligadas**.
- `classes/helper.php:617` — é o `if ($checkpagerules)` que fica desligado, pulando
  `check_path_match()` e `check_filters()`.
- `lib.php:48` — se a sonda responder `true`, carrega o AMD `local_awareness/notice`.
- `amd/src/notice.js:155-160` — o `init()` dispara `local_awareness_getnotices`
  **incondicionalmente**, sem consultar nada antes.

Consequência: a sonda responde "sim" sempre que **qualquer** aviso puder alcançar aquele usuário em
**alguma** página. Um site com um único aviso restrito ao Painel paga um bootstrap completo do
Moodle, via XHR, em toda página do site, para todo usuário logado — para receber uma lista vazia.

O custo cresce com **usuários × páginas vistas**, não com número de avisos. É o maior item de escala
da auditoria.

### O que já foi medido (reproduza antes de confiar)

Com três avisos cujo `pathmatch` é `/my/%`, consultado a partir de `/course/view.php`: a sonda
responde **"carregar o JS"** e o número de avisos a exibir é **0**.

Medição feita com um teste PHPUnit descartável chamando `helper::has_candidate_notices()` e
`helper::retrieve_user_notices('/course/view.php?id=1', 0)` no mesmo estado. Refaça — e note que os
tempos absolutos desta stack não valem nada como número de produção (Xdebug em `develop,debug`;
uma imagem estática leva 0,5–0,8 s). O que transporta é **contagem de requisições e de queries**.

O relatório completo da auditoria, com as outras medições e o que já foi corrigido, está em
`docs/` e no artefato citado no `CHANGELOG.md`.

## O que NÃO é o problema

Já verificado, para você não gastar tempo:

- **FCP não é afetado pelo plugin.** `js_call_amd` acumula em `amdjscode`, emitido por
  `get_amd_footercode()`, chamado de `get_end_code()` — fim do `<body>`. Nada no `<head>`, nenhum
  recurso bloqueante.
- **O custo de servidor da sonda já está baixo.** Com o cache quente são 0 leituras (achado 2
  corrigido no PR #18) e o N+1 de conclusão saiu do TTFB (achado 7, PR #19).

O que sobra é a **requisição extra em si**, não o trabalho que a sonda faz.

## A armadilha central, e ela é diferente do que eu supunha

A saída óbvia é aplicar `pathmatch` no servidor dentro da sonda. `check_path_match()`
(`classes/helper.php:1399`) já tem um fallback para `$PAGE->url` quando o `pageurl` vem vazio
(`classes/helper.php:1404-1412`).

**Mas o `catch (\coding_exception)` desse fallback é código morto.** Em
`public/lib/pagelib.php:681`, `magic_get_url()` não lança exceção quando `set_url()` não foi
chamado: ele emite `debugging(...)` em nível DEVELOPER e cai em `$FULLME`. Ou seja, o fallback
funciona — mas ao custo de um aviso de debugging em toda página onde `set_url()` ainda não rodou,
o que num site em DEVELOPER é ruído constante e no Behat/PHPUnit **é falha de teste**.

Some-se a isso quando o hook roda: `global_navigation::initialise()` chama
`load_local_plugin_navigation()` (`public/lib/classes/navigation/global_navigation.php:435` e `:468`),
e `initialise()` é lazy — dispara quando o tema toca a navegação pela primeira vez. Na prática isso é
durante a renderização, quando `set_url()` quase sempre já rodou. **"Quase sempre" é exatamente o
que precisa ser medido, não assumido.**

Confronte isso no código dos dois cores (`~/dev/moodle-405`, `~/dev/moodle-502`) e, de preferência,
instrumentando as stacks — não só lendo.

## Alternativas para confrontar

Não são uma lista fechada nem uma ordem de preferência. Quero cada uma confrontada no código, com
custo, risco e o que ela quebra:

1. **Aplicar `pathmatch` no servidor dentro da sonda**, com guarda para o caso de não haver URL
   confiável. Quanto do tráfego isso realmente elimina depende de quantos avisos têm `pathmatch` — e
   um aviso sem `pathmatch` continua disparando XHR em toda página. Meça o ganho antes de defender.

2. **Passar os padrões para o cliente** via os argumentos do `js_call_amd`, deixando o JS decidir se
   vale chamar. Evita a armadilha do `$PAGE->url` inteiramente. **Mas expõe o `pathmatch` de avisos
   a todo usuário logado** — metadado de segmentação. Este plugin já teve um PR de divulgação de
   conteúdo (#9); trate isso como restrição de verdade, não detalhe.

3. **Cachear a resposta por usuário e por página na sessão.** Existe uma definição
   `user_notices` (`MODE_SESSION`) em `db/caches.php` que é declarada, expurgada em
   `awareness::purge_caches()`, tem string nas duas language packs — e **nunca é lida nem escrita**
   (é o achado 4 da auditoria). Repare que a resposta é por página, então o desenho da chave é o
   problema difícil aqui, não a implementação.

4. **Não carregar o módulo AMD** nas páginas onde nada poderia aparecer — variação de (1), mas que
   corta antes do JS em vez de antes do XHR.

5. **Adiar o XHR** para ocioso (`requestIdleCallback` ou similar). Não reduz carga de servidor, só
   tira do caminho crítico do cliente. Diga francamente se isso resolve o problema que eu tenho ou
   só o disfarça.

6. **Qualquer coisa que eu não listei.** Se houver um caminho melhor — um hook diferente, algo que o
   core já ofereça, uma mudança no contrato entre a sonda e o cliente — quero ouvir.

## Restrição que não pode ser violada

Em qualquer desenho, **a incerteza tem que falhar carregando o JS**, nunca calando o plugin. Se a
sonda não souber dizer com segurança que nada apareceria, ela carrega. O modo de falha aceitável é
"pagou uma requisição à toa"; o inaceitável é "o aviso não apareceu para quem devia ver".

Relacionado: o PR #14 mudou o plugin para exibir **um aviso por vez**
(`helper::select_for_display()`, `classes/helper.php:475`), e a página onde o login cai já consome um
turno da fila. Leia esse método antes de propor qualquer coisa que mexa em quando o cliente pergunta.

## Como trabalhar neste repositório

- **Não commite nem faça push sem eu pedir.** Se estiver na `main`, crie branch antes.
- Rode `mdl ci moodle-local_awareness --branch MOODLE_502_STABLE --behat` e
  `mdl ci moodle-local_awareness --branch MOODLE_405_STABLE` antes de propor PR. Os dois precisam dar
  `ALL STEPS PASSED`. A leg `--behat` do 4.5 **não sobe o servidor neste ambiente** e falha igual na
  `main` intocada — para cobertura Behat no 4.5 use `mdl behat m405 "@local_awareness"`.
- **Teste de mutação em todo teste novo, e imprima o estado mutado antes de rodar.** Nesta auditoria
  três cenários passaram sem testar nada e só a mutação revelou.
- **Não descarte o Behat como frágil.** Nesta sequência ele pegou: um `require_once` faltando que o
  PHPUnit não pegava; a página de gestão morrendo num site sem avisos; e nove cenários quebrando
  porque o cache passou a ser honrado. Nenhum desses aparecia no PHPUnit.
- Verde não é evidência até você saber o que o gate leu.
- `lang/en` e `lang/pt_br` andam em lockstep, ordenados alfabeticamente.
- Bump de `version.php` + entrada no `CHANGELOG.md` no mesmo commit quando a mudança exigir (JS
  reconstruído, `db/services.php`, banco).
- **`#[DataProvider]` não funciona neste plugin**: o Moodle 4.5 traz PHPUnit 9.6.34, anterior a
  metadados por atributo, e o teste roda sem argumentos. Use um laço.

## O que eu espero de volta

Um documento que eu consiga ler e decidir em cima: o mecanismo confrontado no código, o ganho real
de cada alternativa **medido ou explicitamente marcado como não medido**, os riscos de cada uma, e
uma recomendação que você defenda — inclusive dizendo se a recomendação é "não fazer nada", caso o
custo de qualquer correção supere o do problema.

Leia o código antes de aceitar qualquer afirmação acima. Todas as referências `arquivo:linha` foram
verificadas em `38adb6b`, mas confirme.
