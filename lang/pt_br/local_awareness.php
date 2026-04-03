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
 * @package local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['all'] = 'Todos';
$string['awareness:manage'] = 'Gerenciar alertas do site';
$string['booleanformat:false'] = 'Não';
$string['booleanformat:true'] = 'Sim';
$string['button:accept'] = 'Aceitar';
$string['button:close'] = 'Fechar';
$string['cachedef_enabled_notices'] = 'Lista de alertas habilitados';
$string['cachedef_notice_view'] = 'Lista de alertas visualizados';
$string['cachedef_user_notices'] = 'Alertas do usuário em cache para a sessão atual';
$string['confirmation:deletenotice'] = 'Você realmente deseja excluir o alerta "{$a}"?';
$string['course_search_placeholder'] = 'Escreva para buscar cursos...';
$string['event:acknowledge'] = 'reconhecer';
$string['event:create'] = 'criar';
$string['event:delete'] = 'excluir';
$string['event:disable'] = 'desabilitar';
$string['event:dismiss'] = 'dispensar';
$string['event:enable'] = 'habilitar';
$string['event:reset'] = 'redefinir';
$string['event:timecreated'] = 'Hora';
$string['event:update'] = 'atualizar';
$string['filter_category'] = 'Categoria';
$string['filter_competency'] = 'Competencias';
$string['filter_competency_add'] = 'Adicionar competencias';
$string['filter_competency_proficient'] = 'Proficiente';
$string['filter_competency_remove'] = 'Remover';
$string['filter_competency_requireall'] = 'Proficiente em todas as competencias selecionadas';
$string['filter_competency_requireall_help'] = 'Quando habilitado e houver mais de uma competencia selecionada, o alerta sera exibido apenas se o usuario for proficiente em todas as competencias selecionadas.';
$string['filter_course'] = 'Cursos';
$string['filter_courseformat'] = 'Formato do curso';
$string['filter_role'] = 'Papel';
$string['filter_theme'] = 'Tema';
$string['filters'] = 'Filtros';
$string['modal:acceptbtntooltip'] = 'Por favor, marque a caixa de seleção acima.';
$string['modal:checkboxtext'] = 'Li e compreendi o alerta (fechar este alerta fará logout do site).';
$string['modal:checkboxtext_logout'] = 'Li e compreendi o alerta (fechar este alerta fará logout do site).';
$string['modal:checkboxtext_nologout'] = 'Li e compreendi o alerta.';
$string['notice:activefrom'] = 'Ativo desde';
$string['notice:activefrom_help'] = 'Data e hora a partir da qual a mensagem estará ativa.';
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
$string['notice:hlinkcount'] = 'Contagem de links';
$string['notice:info'] = 'Informações do alerta';
$string['notice:modal_dimension_invalid'] = 'Valor inválido. Use um número seguido de px, %, vw ou vh (ex: 600px, 80%, 50vw).';
$string['notice:modal_height'] = 'Altura do modal';
$string['notice:modal_height_help'] = 'Altura personalizada do modal de alerta. Formatos aceitos: pixels (ex: 400px), porcentagem (ex: 70%) ou altura da viewport (ex: 50vh). Deixe em branco para tamanho padrão.';
$string['notice:modal_width'] = 'Largura do modal';
$string['notice:modal_width_help'] = 'Largura personalizada do modal de alerta. Formatos aceitos: pixels (ex: 600px), porcentagem (ex: 80%) ou largura da viewport (ex: 50vw). Deixe em branco para tamanho padrão.';
$string['notice:notice'] = 'Alerta';
$string['notice:outsideclick'] = 'Dispensar ao clicar fora';
$string['notice:outsideclick_help'] = 'Se habilitado, o usuário pode fechar o alerta clicando fora do modal. Se desabilitado, o usuário deve usar o botão fechar ou aceitar.';
$string['notice:perpetual'] = 'É perpétuo';
$string['notice:perpetual_help'] = 'Quando definido como sim, o alerta sempre será exibido (a menos que desabilitado). Se definido como não, um intervalo de data e hora deve ser especificado.';
$string['notice:redirectmsg'] = 'Curso obrigatório não concluído. Não é permitido enviar tarefa.';
$string['notice:report'] = 'Ver relatório';
$string['notice:reqack'] = 'Requer reconhecimento';
$string['notice:reqack_help'] = 'Se habilitado, o usuário precisará aceitar o alerta antes de continuar a usar o site.
Se o usuário não aceitar o alerta, ele será desconectado do site.';
$string['notice:reqcourse'] = 'Requer conclusão do curso';
$string['notice:reqcourse_help'] = 'Se selecionado, o usuário verá o alerta até que o curso seja concluído.';
$string['notice:reset'] = 'Redefinir alerta';
$string['notice:resetinterval'] = 'Redefinir a cada';
$string['notice:resetinterval_help'] = 'O alerta será exibido ao usuário novamente após o período especificado.';
$string['notice:timemodified'] = 'Hora de modificação';
$string['notice:title'] = 'Título';
$string['notice:view'] = 'Visualizar alerta';
$string['notification:noack'] = 'Não há reconhecimento para este alerta';
$string['notification:nodeleteallowed'] = 'Exclusão de alerta não permitida';
$string['notification:nodis'] = 'Não há dispensa para este alerta';
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
$string['privacy:metadata:firstname'] = 'Primeiro nome';
$string['privacy:metadata:idnumber'] = 'Número de ID';
$string['privacy:metadata:lastname'] = 'Sobrenome';
$string['privacy:metadata:local_awareness_ack'] = 'Reconhecimento de alerta';
$string['privacy:metadata:local_awareness_hlinks_his'] = 'Rastreamento de links';
$string['privacy:metadata:local_awareness_lastview'] = 'Última visualização do alerta';
$string['privacy:metadata:userid'] = 'ID do usuário';
$string['privacy:metadata:username'] = 'Nome de usuário';
$string['report:acknowledge_desc'] = 'Lista de usuários que reconheceram o alerta.';
$string['report:acknowledged'] = 'alerta_reconhecido_{$a}';
$string['report:button:ack'] = 'Relatório de reconhecimento de alerta';
$string['report:button:dis'] = 'Relatório de dispensa de alerta';
$string['report:dismissed'] = 'alerta_dispensado_{$a}';
$string['report:dismissed_desc'] = 'Lista de usuários que dispensaram o alerta.';
$string['report:timecreated_server'] = 'Hora do servidor';
$string['report:timecreated_spreadsheet'] = 'Timestamp da planilha';
$string['report:timeformat:sortable'] = '%Y-%m-%d %H:%M:%S';
$string['setting:allow_delete'] = 'Permitir exclusão de alerta';
$string['setting:allow_deletedesc'] = 'Permitir que o alerta seja excluído';
$string['setting:allow_update'] = 'Permitir atualização de alerta';
$string['setting:allow_updatedesc'] = 'Permitir que o alerta seja atualizado';
$string['setting:cleanup_deleted_notice'] = 'Limpar informações relacionadas ao alerta excluído';
$string['setting:cleanup_deleted_noticedesc'] = 'Requer "Permitir exclusão de alerta".
Se habilitado, outros detalhes relacionados ao alerta sendo excluído, como links, histórico de links, reconhecimento,
última visualização do usuário também serão excluídos';
$string['setting:enabled'] = 'Habilitado';
$string['setting:enableddesc'] = 'Habilitar alertas do site';
$string['setting:managenotice'] = 'Gerenciar alerta';
$string['setting:settings'] = 'Configurações';
$string['timeformat:resetinterval'] = '%a dia(s), %h hora(s), %i minuto(s) e %s segundo(s)';
