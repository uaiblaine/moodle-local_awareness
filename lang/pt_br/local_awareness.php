<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Brazilian Portuguese language file
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['all'] = 'Todos';
$string['audience:btn:calculate'] = 'Calcular alcance';
$string['audience:btn:retry'] = 'Tentar novamente';
$string['audience:context_restrictions:hint'] = 'Estas regras restringem quando e onde o aviso aparece, mas não alteram o tamanho do público.';
$string['audience:context_restrictions:title'] = 'Restrições de exibição';
$string['audience:job_not_found'] = 'Trabalho de estimativa não encontrado.';
$string['audience:reach:label'] = 'Alcance estimado';
$string['audience:reach:value'] = '~ {$a} usuários';
$string['audience:rule:andmore'] = '{$a->names} e mais {$a->count}';
$string['audience:rule:cohorts'] = 'Pertence às coortes selecionadas';
$string['audience:rule:filter_category'] = 'Categoria: {$a}';
$string['audience:rule:filter_competency_rules'] = 'Requisito(s) de competência';
$string['audience:rule:filter_course'] = 'Curso: {$a}';
$string['audience:rule:filter_format'] = 'Formato do curso: {$a}';
$string['audience:rule:filter_role'] = 'Possui os papéis selecionados';
$string['audience:rule:filter_theme'] = 'Tema: {$a}';
$string['audience:rule:pathmatch'] = 'Na URL: {$a}';
$string['audience:rule:reqcourse'] = 'Não concluiu o curso obrigatório';
$string['audience:rules_too_many'] = 'Há muitos filtros para estimar automaticamente — clique em "Calcular alcance" para rodar sob demanda.';
$string['audience:state:auto_pending'] = 'Calculando — atualizando conforme você ajusta os filtros…';
$string['audience:state:cached'] = 'Resultado calculado em {$a}.';
$string['audience:state:error'] = 'Falha ao estimar: {$a}';
$string['audience:state:idle'] = 'O alcance ainda não foi calculado.';
$string['audience:state:manual_ready'] = 'Clique em "Calcular alcance" quando estiver pronto.';
$string['audience:state:queued'] = 'Calculando em segundo plano…';
$string['audience:state:timeout'] = 'O cálculo demorou mais do que o esperado. Tente novamente.';
$string['audience:state:wholesite'] = 'Nenhum filtro de público definido, então este é o total de usuários ativos do site. Calculado em {$a}.';
$string['audience:summary:cohorts'] = 'Coortes';
$string['audience:summary:competencies'] = 'Competências';
$string['audience:summary:courses'] = 'Cursos';
$string['audience:summary:role'] = 'Papel';
$string['audience:title'] = 'Estimativa de público';
$string['awareness:manage'] = 'Gerenciar alertas do site';
$string['awareness:viewreports'] = 'Visualizar relatórios de alertas';
$string['booleanformat:false'] = 'Não';
$string['booleanformat:true'] = 'Sim';
$string['button:accept'] = 'Aceitar';
$string['cachedef_enabled_notices'] = 'Lista de alertas habilitados';
$string['cachedef_notice_view'] = 'Lista de alertas visualizados';
$string['cachedef_site_user_count'] = 'Contagem de usuários do site, que decide se a estimativa de público roda de forma interativa';
$string['collision:badge'] = 'Concorrência';
$string['collision:badgetooltip'] = 'Este alerta repetível alcança as mesmas páginas que: {$a}. Como apenas um alerta é exibido por vez, eles vão se revezar interrompendo as mesmas pessoas.';
$string['collision:live'] = 'Atenção: este alerta repetível alcança as mesmas páginas que: {$a}. Como apenas um alerta é exibido por vez, eles vão se revezar interrompendo as mesmas pessoas.';
$string['collision:saved'] = 'Salvo. Este alerta repetível alcança as mesmas páginas que: {$a}. Como apenas um alerta é exibido por vez, eles vão se revezar interrompendo as mesmas pessoas.';
$string['confirmation:deletenotice'] = 'Você realmente deseja excluir o alerta "{$a}"?';
$string['course_search_placeholder'] = 'Escreva para buscar cursos...';
$string['datasource:acknowledgednotices'] = 'Alertas confirmados';
$string['datasource:allnotices'] = 'Todos os alertas';
$string['datasource:dismissednotices'] = 'Alertas dispensados';
$string['datasource:linkhistory'] = 'Histórico de cliques em links';
$string['datasource:noticeviews'] = 'Visualizações de alertas';
$string['download:acknowledged'] = 'Reconhecidos - {$a->title} (aviso {$a->id})';
$string['download:dismissed'] = 'Dispensados - {$a->title} (aviso {$a->id})';
$string['editor:action:preview'] = 'Pré-visualizar';
$string['editor:nav:howitworks'] = 'Como funciona';
$string['editor:nav:howitworks:body'] = 'Os filtros combinam por <b>interseção</b> — todos precisam casar. Coortes e cursos individuais usam <b>união</b> dentro do próprio campo.';
$string['editor:preview:empty'] = 'Este aviso ainda não tem conteúdo.';
$string['editor:preview:title'] = 'Pré-visualização';
$string['editor:saved'] = 'Salvo em {$a}';
$string['editor:section:appearance'] = 'Aparência do modal';
$string['editor:section:appearance:desc'] = 'Tamanho e ajuste visual da janela exibida.';
$string['editor:section:audience'] = 'Público-alvo';
$string['editor:section:audience:desc'] = 'Para quem o aviso será exibido. Os filtros se combinam com E (interseção).';
$string['editor:section:behavior'] = 'Comportamento';
$string['editor:section:behavior:desc'] = 'Como o aviso aparece, repete e é dispensado.';
$string['editor:section:content'] = 'Conteúdo do aviso';
$string['editor:section:content:desc'] = 'O que será exibido no modal para o usuário.';
$string['editor:section:filters'] = 'Filtros de exibição';
$string['editor:section:filters:desc'] = 'Refine onde, na plataforma, o aviso é disparado.';
$string['editor:status:blocked'] = 'Publicado · ninguém está vendo';
$string['editor:status:draft'] = 'Rascunho · não publicado';
$string['editor:status:live'] = 'Ao vivo · em exibição';
$string['editor:subtitle'] = 'Crie um modal contextual que aparecerá para os usuários conforme as regras estabelecidas abaixo.';
$string['editor:title:create'] = 'Cadastrar aviso';
$string['editor:title:edit'] = 'Editar aviso';
$string['editor:unsaved'] = 'Alterações não salvas';
$string['editor:warning:window_expired'] = 'Este aviso deixou de ser exibido em {$a}. Ninguém o verá até que a data de expiração seja movida.';
$string['editor:warning:window_inverted'] = 'A data de início é igual ou posterior à de expiração, então este aviso nunca poderá ser exibido. Corrija as datas em Comportamento.';
$string['entity_acknowledgement'] = 'Confirmação';
$string['entity_linkhistory'] = 'Clique em link';
$string['entity_notice'] = 'Alerta';
$string['entity_noticeview'] = 'Visualização de alerta';
$string['event:acknowledge'] = 'Aviso reconhecido';
$string['event:clicklink'] = 'Link do aviso clicado';
$string['event:create'] = 'Aviso criado';
$string['event:delete'] = 'Aviso excluído';
$string['event:disable'] = 'Aviso desabilitado';
$string['event:dismiss'] = 'Aviso dispensado';
$string['event:enable'] = 'Aviso habilitado';
$string['event:estimateaudience'] = 'Estimativa de público solicitada';
$string['event:reset'] = 'Aviso redefinido';
$string['event:update'] = 'Aviso atualizado';
$string['filter_category'] = 'Categoria';
$string['filter_competency'] = 'Competencias';
$string['filter_competency_add'] = 'Adicionar competencias';
$string['filter_competency_help'] = 'Filtra este aviso com base na proficiência do usuário em competências. Esse filtro funciona apenas em páginas de curso (requer contexto de curso).

Quando competências são selecionadas, cada regra verifica se o usuário é proficiente ou não em determinada competência dentro do curso atual. No modo padrão, o status de proficiência do usuário deve corresponder exatamente à configuração definida em cada regra.

Quando a opção “Proficiente em todas” está habilitada, o usuário deve ser proficiente em todas as competências selecionadas, independentemente das configurações individuais de cada regra.';
$string['filter_competency_picker_addselected'] = 'Adicionar selecionadas';
$string['filter_competency_picker_framework'] = 'Quadro de competencias';
$string['filter_competency_picker_loaderror'] = 'Não foi possível carregar a lista de competências.';
$string['filter_competency_picker_nocompetencies'] = 'Nenhuma competencia encontrada.';
$string['filter_competency_picker_noframeworks'] = 'Nenhum quadro de competencias disponivel.';
$string['filter_competency_picker_title'] = 'Selecionar competencias';
$string['filter_competency_proficient'] = 'Proficiente';
$string['filter_competency_remove'] = 'Remover';
$string['filter_competency_requireall'] = 'Proficiente em todas as competencias selecionadas';
$string['filter_competency_requireall_help'] = 'Quando habilitado e houver mais de uma competencia selecionada, o alerta sera exibido apenas se o usuario for proficiente em todas as competencias selecionadas.';
$string['filter_competency_rules_error'] = 'Não foi possível exibir as competências selecionadas.';
$string['filter_course'] = 'Cursos';
$string['filter_courseformat'] = 'Formato do curso';
$string['filter_role'] = 'Papel';
$string['filter_role_context'] = 'Contexto do papel';
$string['filter_role_context:category'] = 'Categoria de curso';
$string['filter_role_context:course'] = 'Curso';
$string['filter_role_context:system'] = 'Sistema';
$string['filter_theme'] = 'Tema';
$string['filters'] = 'Filtros';
$string['manage:empty:filtered'] = 'Nenhum aviso corresponde a estes filtros.';
$string['manage:empty:none'] = 'Nenhum aviso foi cadastrado ainda.';
$string['manage:filter:clear'] = 'Limpar filtros';
$string['manage:filter:name'] = 'Pesquisar por nome';
$string['manage:filter:nameplaceholder'] = 'Pesquisar por nome…';
$string['manage:filter:status:all'] = 'Todos';
$string['manage:filter:status:clash'] = 'Com conflito';
$string['manage:filter:validity:all'] = 'Todas';
$string['manage:lede'] = 'Avisos modais exibidos aos usuários quando as regras coincidem.';
$string['manage:resultcount'] = 'Avisos encontrados: {$a}';
$string['manage:stat:clash'] = 'Conflitos';
$string['manage:stat:draft'] = 'Rascunhos';
$string['manage:stat:live'] = 'Avisos ativos';
$string['manage:stat:reach'] = 'Alcance somado';
$string['manage:table:caption'] = 'Avisos do site';
$string['message:audience_ready:body'] = 'A estimativa de público de "{$a->title}" terminou. Ela alcança cerca de {$a->count} usuários.';
$string['message:audience_ready:subject'] = 'Estimativa de público pronta: {$a->title}';
$string['messageprovider:audience_estimate_ready'] = 'Estimativa de público concluída';
$string['modal:checkboxtext'] = 'Li e compreendi o alerta.';
$string['modal:checkboxtext_logout'] = 'Li e compreendi o alerta (fechar este alerta fará logout do site).';
$string['modal:checkboxtext_nologout'] = 'Li e compreendi o alerta.';
$string['notice:activefrom'] = 'Ativo desde';
$string['notice:activefrom_help'] = 'Data e hora a partir da qual a mensagem estará ativa.';
$string['notice:audience'] = 'Público-alvo';
$string['notice:audience:cohorts'] = 'Coortes: {$a}';
$string['notice:audience:computed'] = 'Calculado em {$a}';
$string['notice:audience:never'] = 'Não calculado';
$string['notice:audience:pending'] = 'Calculando…';
$string['notice:audience:queued'] = 'A estimativa de público de "{$a}" está rodando em segundo plano. Você será notificado quando terminar.';
$string['notice:audience:recalculate'] = 'Recalcular público';
$string['notice:audience:recalculated'] = 'A estimativa de público de "{$a}" está atualizada.';
$string['notice:audience:stale'] = 'Filtros mudaram desde {$a}';
$string['notice:audience:value'] = '~ {$a} usuários';
$string['notice:behaviour'] = 'Comportamento';
$string['notice:behaviour:none'] = 'Sem comportamento especial';
$string['notice:behaviour:repeat'] = 'Repete a cada {$a}';
$string['notice:bgimage'] = 'Imagem de fundo';
$string['notice:bgimage_help'] = 'Envie uma imagem para ser exibida como plano de fundo do modal de alerta. A imagem cobrirá toda a área de conteúdo do modal.';
$string['notice:cohort'] = 'Coorte';
$string['notice:cohort:all'] = 'Todos os usuários';
$string['notice:content'] = 'Conteúdo';
$string['notice:create'] = 'Criar novo alerta';
$string['notice:delete'] = 'Excluir alerta';
$string['notice:disable'] = 'Desabilitar alerta';
$string['notice:enable'] = 'Habilitar alerta';
$string['notice:expiry'] = 'Expiração';
$string['notice:expiry_help'] = 'Data e hora em que a mensagem expira e não será mais exibida aos usuários.';
$string['notice:forcelogout'] = 'Forçar logout';
$string['notice:forcelogout_help'] = 'Se habilitado, o usuário será desconectado após fechar o alerta. Esta configuração não afeta o administrador do site.';
$string['notice:info'] = 'Informações do alerta';
$string['notice:modal_dimension_invalid'] = 'Valor inválido. Use um número seguido de px, %, vw ou vh (ex: 600px, 80%, 50vw).';
$string['notice:modal_height'] = 'Altura do modal';
$string['notice:modal_height_help'] = 'Altura personalizada do modal de alerta. Formatos aceitos: pixels (ex: 400px), porcentagem (ex: 70%) ou altura da viewport (ex: 50vh). Deixe em branco para tamanho padrão.';
$string['notice:modal_width'] = 'Largura do modal';
$string['notice:modal_width_help'] = 'Largura personalizada do modal de alerta. Formatos aceitos: pixels (ex: 600px), porcentagem (ex: 80%) ou largura da viewport (ex: 50vw). Deixe em branco para tamanho padrão.';
$string['notice:notice'] = 'Alerta';
$string['notice:outsideclick'] = 'Dispensar ao clicar fora';
$string['notice:outsideclick_help'] = 'Se habilitado, o usuário pode fechar o alerta clicando fora do modal. Se desabilitado, o usuário deve usar o botão fechar ou aceitar.';
$string['notice:pathmatch:anywhere'] = 'Em todo o site';
$string['notice:perpetual'] = 'É perpétuo';
$string['notice:perpetual_help'] = 'Quando definido como sim, o alerta sempre será exibido (a menos que desabilitado). Se definido como não, um intervalo de data e hora deve ser especificado.';
$string['notice:preview'] = 'Pré-visualizar o modal';
$string['notice:reqack'] = 'Requer reconhecimento';
$string['notice:reqack_help'] = 'Se habilitado, o usuário precisa marcar a caixa de reconhecimento antes que o botão Aceitar
fique disponível, e o alerta não pode ser dispensado clicando fora dele nem pressionando Esc. Quem fecha o alerta em vez de
aceitá-lo volta a vê-lo até aceitar. Não desconecta o usuário do site.';
$string['notice:reqcourse'] = 'Requer conclusão do curso';
$string['notice:reqcourse_help'] = 'Mostra o alerta apenas a quem ainda não concluiu o curso selecionado. É uma regra de público, não uma frequência de exibição: com que frequência o alerta reaparece é definido pelo intervalo de reexibição, e quem conclui o curso deixa de o ver.';
$string['notice:reset'] = 'Redefinir alerta';
$string['notice:resetinterval'] = 'Redefinir a cada';
$string['notice:resetinterval_help'] = 'O alerta será exibido ao usuário novamente após o período especificado.';
$string['notice:status'] = 'Status';
$string['notice:status:draft'] = 'Rascunho';
$string['notice:status:live'] = 'Ativo';
$string['notice:title'] = 'Título';
$string['notice:validity'] = 'Vigência';
$string['notice:validity:current'] = 'Vigente';
$string['notice:validity:expired'] = 'Expirado';
$string['notice:validity:permanent'] = 'Permanente';
$string['notice:validity:scheduled'] = 'Agendado';
$string['notification:nodeleteallowed'] = 'Exclusão de alerta não permitida';
$string['notification:noticedoesnotexist'] = 'O alerta não existe';
$string['notification:noupdateallowed'] = 'Atualização de alerta não permitida';
$string['pathmatch'] = 'Aplicar à correspondência de URL';
$string['pathmatch_help'] = 'Alertas serão exibidos em qualquer página cuja URL corresponda a este valor.

Você pode usar o caractere % como curinga para significar qualquer coisa.
Alguns exemplos de valores incluem:

* /my/% - para corresponder ao Painel
* /course/view.php?id=2 - para corresponder a um curso específico
* /mod/forum/view.php% - para corresponder à lista de discussão do fórum
* /user/profile.php% - para corresponder à página de perfil do usuário

Se você deseja exibir um alerta na página inicial do site, você pode usar o valor: "FRONTPAGE".';
$string['pluginname'] = 'Alertas';
$string['privacy:metadata:action'] = 'Se o aviso foi dispensado (0) ou reconhecido (1)';
$string['privacy:metadata:breakdown'] = 'Detalhamento por regra da estimativa de público';
$string['privacy:metadata:criteria'] = 'Critérios de público enviados para a estimativa';
$string['privacy:metadata:criteriahash'] = 'Hash dos critérios de público, usado para reaproveitar uma estimativa idêntica';
$string['privacy:metadata:errormsg'] = 'Mensagem de erro registrada quando a estimativa falhou';
$string['privacy:metadata:firstname'] = 'Primeiro nome';
$string['privacy:metadata:hlinkid'] = 'ID do hiperlink que foi clicado';
$string['privacy:metadata:idnumber'] = 'Número de ID';
$string['privacy:metadata:jobid'] = 'Identificador do trabalho de estimativa de público';
$string['privacy:metadata:lastname'] = 'Sobrenome';
$string['privacy:metadata:local_awareness'] = 'Avisos do site, registrando o usuário que criou ou editou cada um por último';
$string['privacy:metadata:local_awareness_ack'] = 'Reconhecimento de alerta';
$string['privacy:metadata:local_awareness_audience_jobs'] = 'Trabalhos de estimativa de público';
$string['privacy:metadata:local_awareness_hlinks_his'] = 'Rastreamento de links';
$string['privacy:metadata:local_awareness_lastview'] = 'Última visualização do alerta';
$string['privacy:metadata:noticeid'] = 'ID do aviso a que o registro se refere';
$string['privacy:metadata:noticetitle'] = 'Título do aviso no momento do reconhecimento';
$string['privacy:metadata:resultcount'] = 'Número de usuários alcançados pela estimativa';
$string['privacy:metadata:status'] = 'Estado do trabalho de estimativa de público';
$string['privacy:metadata:timecompleted'] = 'Momento em que a estimativa terminou';
$string['privacy:metadata:timecreated'] = 'Momento em que o registro foi criado';
$string['privacy:metadata:timemodified'] = 'Momento da última alteração do registro';
$string['privacy:metadata:userid'] = 'ID do usuário';
$string['privacy:metadata:usermodified'] = 'ID do usuário que criou ou editou o aviso por último';
$string['privacy:metadata:username'] = 'Nome de usuário';
$string['report:acknowledge_desc'] = 'Lista de usuários que reconheceram o alerta.';
$string['report:acknowledged'] = 'Alertas confirmados para: {$a}';
$string['report:button:ack'] = 'System report de reconhecimento de alerta';
$string['report:button:dis'] = 'System report de dispensa de alerta';
$string['report:dismissed'] = 'Alertas dispensados para: {$a}';
$string['report:dismissed_desc'] = 'Lista de usuários que dispensaram o alerta.';
$string['report_ack:action'] = 'Ação';
$string['report_ack:action_acknowledged'] = 'Confirmado';
$string['report_ack:action_dismissed'] = 'Dispensado';
$string['report_ack:firstname'] = 'Nome';
$string['report_ack:idnumber'] = 'Número de identificação';
$string['report_ack:lastname'] = 'Sobrenome';
$string['report_ack:noticetitle'] = 'Título do alerta (instantâneo)';
$string['report_ack:timecreated'] = 'Data';
$string['report_ack:username'] = 'Nome de usuário';
$string['report_lh:linktext'] = 'Texto do link';
$string['report_lh:linkurl'] = 'URL do link';
$string['report_lh:timecreated'] = 'Data do clique';
$string['report_notice:ack_count'] = 'Total de confirmações';
$string['report_notice:content'] = 'Conteúdo';
$string['report_notice:dismiss_count'] = 'Total de dispensas';
$string['report_notice:enabled'] = 'Habilitado';
$string['report_notice:forcelogout'] = 'Forçar logout';
$string['report_notice:reqack'] = 'Requer confirmação';
$string['report_notice:reqcourse'] = 'Requer conclusão do curso';
$string['report_notice:resetinterval'] = 'Intervalo de redefinição';
$string['report_notice:timecreated'] = 'Data de criação';
$string['report_notice:timeend'] = 'Expiração';
$string['report_notice:timemodified'] = 'Data de modificação';
$string['report_notice:timestart'] = 'Ativo a partir de';
$string['report_notice:title'] = 'Título do alerta';
$string['report_nv:action'] = 'Última ação';
$string['report_nv:timecreated'] = 'Primeira visualização';
$string['report_nv:timemodified'] = 'Última visualização';
$string['setting:allow_delete'] = 'Permitir exclusão de alerta';
$string['setting:allow_deletedesc'] = 'Permitir que o alerta seja excluído';
$string['setting:allow_update'] = 'Permitir atualização de alerta';
$string['setting:allow_updatedesc'] = 'Permitir que o alerta seja atualizado';
$string['setting:audience_sync_limit'] = 'Limite para estimativa de público interativa';
$string['setting:audience_sync_limitdesc'] = 'Em sites com no máximo esta quantidade de usuários a estimativa de público é interativa: o editor a atualiza conforme você muda os filtros, e "Calcular alcance" responde na hora. Acima disso nada disso ocorre — a estimativa roda apenas quando solicitada, em segundo plano, e depende do cron estar rodando. Só aumente este valor depois de cronometrar a estimativa no seu próprio site com o conjunto de filtros mais pesado: ela varre uma linha por usuário, então o custo cresce com o tamanho do site. Use 0 para nunca estimar de forma interativa.';
$string['setting:cleanup_deleted_notice'] = 'Limpar informações relacionadas ao alerta excluído';
$string['setting:cleanup_deleted_noticedesc'] = 'Requer "Permitir exclusão de alerta".
Se habilitado, outros detalhes relacionados ao alerta sendo excluído, como links, histórico de links, reconhecimento,
última visualização do usuário também serão excluídos';
$string['setting:enabled'] = 'Habilitado';
$string['setting:enableddesc'] = 'Habilitar alertas do site';
$string['setting:linkhistory_lifetime'] = 'Manter o histórico de cliques em links por';
$string['setting:linkhistory_lifetimedesc'] = 'Por quanto tempo é mantido o registo de um leitor que seguiu um link dentro de um aviso. O padrão é manter para sempre, então a atualização não descarta nada; escolher um período inicia uma limpeza noturna de tudo o que for mais antigo.';
$string['setting:managenotice'] = 'Gerenciar alerta';
$string['setting:settings'] = 'Configurações';
$string['task_estimate_audience'] = 'Estimar o público de um alerta';
$string['task_purge_audience_jobs'] = 'Expurgar trabalhos de estimativa de público já consumidos';
$string['task_purge_link_history'] = 'Limpar histórico antigo de cliques em links';
$string['timeformat:resetinterval'] = '%a dia(s), %h hora(s), %i minuto(s) e %s segundo(s)';
