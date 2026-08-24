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

namespace local_awareness\form;

use local_awareness\helper;
use local_awareness\persistent\awareness;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form to create new notice
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notice_form extends \core\form\persistent {
    /** @var string Persistent class name. */
    protected static $persistentclass = 'local_awareness\persistent\awareness';

    /** @var array Fields to remove from the persistent validation. */
    protected static $foreignfields = [
        'perpetual', 'cohorts', 'filter_role_context', 'filter_role', 'filter_category',
        'filter_course', 'filter_format', 'filter_theme', 'filter_competency_rules',
        'filter_competency_requireall', 'bgimage',
    ];

    /**
     * Form definition.
     */
    public function definition() {
        global $CFG, $DB;
        $mform =& $this->_form;

        /*
         * Collapsible short-forms back ON, which is what makes each section an accordion and gets
         * the expand/collapse-all control for free. They were disabled because the editor used to
         * hide this whole form and move its rendered rows into cards with JavaScript: core's
         * collapsesections JS never settled on a hidden form. The form is rendered in place now,
         * so the reason is gone — and with it the defect that hack produced, where any field the
         * relocation map forgot stayed inside a 1x1 clipped container: reachable by keyboard and
         * announced by a screen reader, but painted nowhere. filter_role_context and the competency
         * label had been invisible that way.
         */
        $mform->setDisableShortforms(false);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // Needed throughout: several sections pre-load defaults from the saved notice.
        $persistent = $this->get_persistent();

        // Content.
        $mform->addElement('header', 'header_content', get_string('editor:section:content', 'local_awareness'));
        /*
         * Each section says what it is for, in the author's language. The strings were written
         * with the collapsible-fieldset rebuild and then never rendered, so five explanations
         * sat in two language packs helping nobody. A static element is how a moodleform carries
         * prose — no markup is injected and no row is relocated.
         */
        $mform->addElement(
            'static',
            'header_content_desc',
            '',
            get_string('editor:section:content:desc', 'local_awareness')
        );
        $mform->setExpanded('header_content', true);

        $mform->addElement('text', 'title', get_string('notice:title', 'local_awareness'));
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'editor',
            'content',
            get_string('notice:content', 'local_awareness'),
            [],
            helper::get_file_editor_options()
        );
        $mform->setType('content', PARAM_RAW);
        $mform->addRule('content', get_string('required'), 'required', null, 'client');

        // Background Image.
        $mform->addElement(
            'filepicker',
            'bgimage',
            get_string('notice:bgimage', 'local_awareness'),
            null,
            [
                'maxbytes' => $CFG->maxbytes,
                'accepted_types' => ['image'],
                'maxfiles' => 1,
            ]
        );
        $mform->addHelpButton('bgimage', 'notice:bgimage', 'local_awareness');

        // Display.
        $mform->addElement('header', 'header_behavior', get_string('editor:section:behavior', 'local_awareness'));
        $mform->addElement(
            'static',
            'header_behavior_desc',
            '',
            get_string('editor:section:behavior:desc', 'local_awareness')
        );
        $mform->setExpanded('header_behavior', true);

        $mform->addElement('selectyesno', 'enabled', get_string('notice:enable', 'local_awareness'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('duration', 'resetinterval', get_string('notice:resetinterval', 'local_awareness'));
        $mform->addHelpButton('resetinterval', 'notice:resetinterval', 'local_awareness');
        $mform->setDefault('resetinterval', 0);

        /*
         * One question, three answers, where there used to be three yes/no questions whose eight
         * combinations included two that made no sense together and one that did nothing. The
         * author is choosing how insistent the notice is, and that is a single ordered decision;
         * awareness::get_insistence() is where the levels are defined and mapped back to storage.
         */
        $mform->addElement(
            'select',
            'insistence',
            get_string('notice:insistence', 'local_awareness'),
            [
                awareness::INSISTENCE_INFORMATIONAL => get_string('notice:insistence:informational', 'local_awareness'),
                awareness::INSISTENCE_BLOCKING => get_string('notice:insistence:blocking', 'local_awareness'),
                awareness::INSISTENCE_ACKNOWLEDGE => get_string('notice:insistence:acknowledge', 'local_awareness'),
            ]
        );
        $mform->addHelpButton('insistence', 'notice:insistence', 'local_awareness');
        $mform->setDefault('insistence', awareness::INSISTENCE_INFORMATIONAL);

        $mform->addElement('selectyesno', 'perpetual', get_string('notice:perpetual', 'local_awareness'));
        $mform->addHelpButton('perpetual', 'notice:perpetual', 'local_awareness');
        $mform->setDefault('perpetual', 1);

        /*
         * Computed, not a literal: a hardcoded stopyear silently stops accepting dates once it
         * arrives, and the whole non-perpetual scheduling path goes with it.
         */
        $stopyear = (int) date('Y') + 10;
        $activeoptions = ['startyear' => date("Y"), 'stopyear' => $stopyear];
        $mform->addElement('date_time_selector', 'timestart', get_string('notice:activefrom', 'local_awareness'), $activeoptions);
        $mform->addHelpButton('timestart', 'notice:activefrom', 'local_awareness');
        $mform->hideIf('timestart', 'perpetual', 'eq', 1);

        $expiryoptions = ['startyear' => date("Y"), 'stopyear' => $stopyear, 'defaulttime' => time() + HOURSECS];
        $mform->addElement('date_time_selector', 'timeend', get_string('notice:expiry', 'local_awareness'), $expiryoptions);
        $mform->addHelpButton('timeend', 'notice:expiry', 'local_awareness');
        $mform->hideIf('timeend', 'perpetual', 'eq', 1);

        // Audience.
        $mform->addElement('header', 'header_audience', get_string('editor:section:audience', 'local_awareness'));
        $mform->addElement(
            'static',
            'header_audience_desc',
            '',
            get_string('editor:section:audience:desc', 'local_awareness')
        );
        $mform->setExpanded('header_audience', true);

        // Context / Filter fields.
        $mform->addElement(
            'select',
            'filter_role_context',
            get_string('filter_role_context', 'local_awareness'),
            [
                0 => get_string('all', 'local_awareness'),
                CONTEXT_SYSTEM => get_string('filter_role_context:system', 'local_awareness'),
                CONTEXT_COURSECAT => get_string('filter_role_context:category', 'local_awareness'),
                CONTEXT_COURSE => get_string('filter_role_context:course', 'local_awareness'),
            ]
        );
        $mform->setDefault('filter_role_context', 0);

        $filterroledefaults = [];
        if ($persistent && $persistent->get('id') > 0 && !empty($persistent->get('filtervalues'))) {
            $existingfilters = json_decode($persistent->get('filtervalues'), true);
            if (!empty($existingfilters['filter_role'])) {
                $roleids = array_map('intval', $existingfilters['filter_role']);
                $allroles = helper::get_role_options();
                foreach ($roleids as $rid) {
                    if (isset($allroles[$rid])) {
                        $filterroledefaults[$rid] = $allroles[$rid];
                    }
                }
            }
        }

        // Role.
        $mform->addElement(
            'autocomplete',
            'filter_role',
            get_string('filter_role', 'local_awareness'),
            $filterroledefaults,
            [
                'multiple' => true,
                'noselectionstring' => get_string('all', 'local_awareness'),
                'ajax' => 'local_awareness/role_search',
                'showSuggestions' => true,
            ]
        );

        $mform->addElement(
            'autocomplete',
            'cohorts',
            get_string('notice:cohort', 'local_awareness'),
            helper::built_cohorts_options(),
            ['noselectionstring' => get_string('notice:cohort:all', 'local_awareness'), 'multiple' => true, 'id' => 'id_cohorts']
        );

        $mform->setDefault('cohorts', 0);

        // AJAX autocomplete for course requirement.
        // Only pre-load the currently selected course (if editing), not all courses.
        $reqcourseoptions = [0 => get_string('booleanformat:false', 'local_awareness')];
        if ($persistent && $persistent->get('id') > 0 && $persistent->get('reqcourse') > 0) {
            $selcourse = $DB->get_record('course', ['id' => $persistent->get('reqcourse')], 'id, fullname');
            if ($selcourse) {
                $reqcourseoptions[$selcourse->id] = self::course_label($selcourse);
            }
        }

        $mform->addElement(
            'autocomplete',
            'reqcourse',
            get_string('notice:reqcourse', 'local_awareness'),
            $reqcourseoptions,
            [
                'multiple' => false,
                'ajax' => 'local_awareness/course_search',
                'noselectionstring' => get_string('booleanformat:false', 'local_awareness'),
                'showSuggestions' => false,
                'placeholder' => get_string('course_search_placeholder', 'local_awareness'),
            ]
        );
        $mform->setType('reqcourse', PARAM_INT);
        $mform->addHelpButton('reqcourse', 'notice:reqcourse', 'local_awareness');
        $mform->setDefault('reqcourse', 0);

        /*
         * The competency rules belong to the audience, not to the display restrictions they used to
         * sit under: they say WHO sees the notice, the same question the cohorts and roles above
         * answer. Its static label is what carries the help button, and it was one of the two
         * elements the old JS relocation left behind in the hidden form.
         */
        if (helper::is_competency_filter_enabled()) {
            $existingrules = [];
            if ($persistent && $persistent->get('id') > 0 && !empty($persistent->get('filtervalues'))) {
                $existingfilters = json_decode($persistent->get('filtervalues'), true);
                $existingrules = helper::normalise_competency_rules($existingfilters['filter_competency_rules'] ?? []);

                if (!empty($existingrules)) {
                    $ids = array_map(function (array $rule): int {
                        return (int) ($rule['id'] ?? 0);
                    }, $existingrules);
                    $names = helper::get_competency_names($ids);

                    foreach ($existingrules as $index => $rule) {
                        if (empty($existingrules[$index]['name']) && !empty($names[$rule['id']])) {
                            $existingrules[$index]['name'] = $names[$rule['id']];
                        }
                    }
                }
            }

            $mform->addElement('static', 'filter_competency_label', get_string('filter_competency', 'local_awareness'), '');
            $mform->addHelpButton('filter_competency_label', 'filter_competency', 'local_awareness');
            $mform->addElement(
                'html',
                '<div id="awareness-competency-filter" class="mb-3"
                    data-contextid="' . (int) \context_system::instance()->id . '"
                    data-proficient-label="' . s(get_string('filter_competency_proficient', 'local_awareness')) . '"
                    data-yes-label="' . s(get_string('booleanformat:true', 'local_awareness')) . '"
                    data-no-label="' . s(get_string('booleanformat:false', 'local_awareness')) . '"
                    data-remove-label="' . s(get_string('filter_competency_remove', 'local_awareness')) . '"
                    data-rules-error="' . s(get_string('filter_competency_rules_error', 'local_awareness')) . '"
                    data-picker-title="' . s(get_string('filter_competency_picker_title', 'local_awareness')) . '"
                    data-picker-framework="' . s(get_string('filter_competency_picker_framework', 'local_awareness')) . '"
                    data-picker-search="' . s(get_string('search')) . '"
                    data-picker-noframeworks="' . s(get_string('filter_competency_picker_noframeworks', 'local_awareness')) . '"
                    data-picker-nocompetencies="' . s(get_string('filter_competency_picker_nocompetencies', 'local_awareness')) . '"
                    data-picker-loading="' . s(get_string('loading', 'admin')) . '"
                    data-picker-loaderror="' . s(get_string('filter_competency_picker_loaderror', 'local_awareness')) . '"
                    data-picker-addselected="' . s(get_string('filter_competency_picker_addselected', 'local_awareness')) . '">
                    <button type="button" id="id_awareness_add_competencies" class="btn btn-secondary">' .
                        s(get_string('filter_competency_add', 'local_awareness')) . '
                    </button>
                    <div id="id_awareness_competency_rules" class="mt-2"></div>
                </div>'
            );

            $mform->addElement('hidden', 'filter_competency_rules', json_encode($existingrules));
            $mform->setType('filter_competency_rules', PARAM_RAW);

            $mform->addElement(
                'selectyesno',
                'filter_competency_requireall',
                get_string('filter_competency_requireall', 'local_awareness')
            );
            $mform->setDefault('filter_competency_requireall', 0);
            $mform->addHelpButton('filter_competency_requireall', 'filter_competency_requireall', 'local_awareness');

            if ($persistent && $persistent->get('id') > 0 && !empty($persistent->get('filtervalues'))) {
                $existingfilters = json_decode($persistent->get('filtervalues'), true);
                if (!empty($existingfilters['filter_competency_requireall'])) {
                    $mform->setDefault('filter_competency_requireall', 1);
                }
            }
        }

        // Display restrictions.
        $mform->addElement('header', 'header_filters', get_string('editor:section:filters', 'local_awareness'));
        $mform->addElement(
            'static',
            'header_filters_desc',
            '',
            get_string('editor:section:filters:desc', 'local_awareness')
        );

        // Path Match.
        $mform->addElement('text', 'pathmatch', get_string('pathmatch', 'local_awareness'));
        $mform->setType('pathmatch', PARAM_RAW);
        $mform->addHelpButton('pathmatch', 'pathmatch', 'local_awareness');

        // Fields moved to header_audience.

        // Category.
        $mform->addElement(
            'autocomplete',
            'filter_category',
            get_string('filter_category', 'local_awareness'),
            helper::get_category_options(),
            [
                'multiple' => true,
                'noselectionstring' => get_string('all', 'local_awareness'),
            ]
        );

        // Course — AJAX autocomplete (avoids loading all courses at render time).
        $filtercoursedefaults = [];
        if ($persistent && $persistent->get('id') > 0 && !empty($persistent->get('filtervalues'))) {
            $existingfilters = json_decode($persistent->get('filtervalues'), true);
            if (!empty($existingfilters['filter_course'])) {
                $courseids = array_map('intval', $existingfilters['filter_course']);
                if (!empty($courseids)) {
                    [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
                    $selectedcourses = $DB->get_records_select('course', "id {$insql}", $inparams, '', 'id, fullname');
                    foreach ($selectedcourses as $sc) {
                        $filtercoursedefaults[$sc->id] = self::course_label($sc);
                    }
                }
            }
        }
        $mform->addElement(
            'autocomplete',
            'filter_course',
            get_string('filter_course', 'local_awareness'),
            $filtercoursedefaults,
            [
                'multiple' => true,
                'noselectionstring' => get_string('all', 'local_awareness'),
                'ajax' => 'local_awareness/course_search',
                'showSuggestions' => false,
                'placeholder' => get_string('course_search_placeholder', 'local_awareness'),
            ]
        );

        // Format.
        $mform->addElement(
            'autocomplete',
            'filter_format',
            get_string('filter_courseformat', 'local_awareness'),
            helper::get_course_format_options(),
            [
                'multiple' => true,
                'noselectionstring' => get_string('all', 'local_awareness'),
            ]
        );

        // Theme.
        $mform->addElement(
            'autocomplete',
            'filter_theme',
            get_string('filter_theme', 'local_awareness'),
            helper::get_theme_options(),
            [
                'multiple' => true,
                'noselectionstring' => get_string('all', 'local_awareness'),
            ]
        );

        // Modal appearance.
        $mform->addElement('header', 'header_appearance', get_string('editor:section:appearance', 'local_awareness'));
        $mform->addElement(
            'static',
            'header_appearance_desc',
            '',
            get_string('editor:section:appearance:desc', 'local_awareness')
        );

        $mform->addElement('text', 'modal_width', get_string('notice:modal_width', 'local_awareness'));
        $mform->setType('modal_width', PARAM_RAW);
        $mform->addHelpButton('modal_width', 'notice:modal_width', 'local_awareness');
        $mform->addRule(
            'modal_width',
            get_string('notice:modal_dimension_invalid', 'local_awareness'),
            'regex',
            '/^(\d+(\.\d+)?(px|%|vw|vh))?$/',
            'client'
        );

        $mform->addElement('text', 'modal_height', get_string('notice:modal_height', 'local_awareness'));
        $mform->setType('modal_height', PARAM_RAW);
        $mform->addHelpButton('modal_height', 'notice:modal_height', 'local_awareness');
        $mform->addRule(
            'modal_height',
            get_string('notice:modal_dimension_invalid', 'local_awareness'),
            'regex',
            '/^(\d+(\.\d+)?(px|%|vw|vh))?$/',
            'client'
        );

        /*
         * The last two sections start collapsed because most notices never touch them — but a
         * section that already holds a value opens regardless, and forces past any stored user
         * preference to do it. Hiding a filter somebody set is worse than showing an empty section:
         * the author cannot act on what the page does not admit is there.
         */
        $this->set_optional_section_state('header_filters', [
            'pathmatch', 'filter_category', 'filter_course', 'filter_format', 'filter_theme',
        ]);
        $this->set_optional_section_state('header_appearance', ['modal_width', 'modal_height']);

        $buttonarray = [];

        /*
         * Preview sits with Save and Cancel because it is the third thing an author does with the
         * whole form, not a property of the page head. type="button" and no name of its own: it
         * must never submit, and the JS finds it by its data-action.
         */
        $buttonarray[] = $mform->createElement(
            'button',
            'previewnotice',
            get_string('editor:action:preview', 'local_awareness'),
            ['data-action' => 'preview', 'type' => 'button']
        );
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('savechanges'));
        $buttonarray[] = $mform->createElement('cancel');

        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);

        /*
         * closeHeaderBefore() is not decoration. The renderer wraps the group's HTML in the sticky
         * footer IN PLACE, so without this the footer is emitted inside the last section's
         * collapsible container — and collapsing "Modal appearance" took Save and Cancel with it.
         * It is the same call course/edit_form.php makes for the same reason.
         */
        $mform->closeHeaderBefore('buttonar');

        /*
         * Core's sticky footer, not a hand-rolled one. set_sticky_footer() exists on all supported
         * branches and is what course/edit_form.php and moodleform_mod.php use; the plugin used to
         * make the button group position:sticky itself, which left the buttons inside the last
         * section's card instead of at the foot of the page.
         */
        $mform->set_sticky_footer('buttonar');
    }


    /**
     * The display label for a course option, escaped for the stash core renders it through.
     *
     * element-autocomplete.mustache emits every option as a triple stash and lib/form/select.php
     * passes the text through untouched, so a fullname carrying markup reaches the page as markup
     * and a multilang fullname reaches it as literal {mlang} text. The default escape is what that
     * sink wants, and it matches what external::search_courses() hands the same widget over AJAX —
     * the two halves of this picker have to agree, because the author sees both in one field.
     *
     * @param \stdClass $course A course record carrying at least id and fullname.
     * @return string The formatted, escaped course name.
     */
    private static function course_label(\stdClass $course): string {
        $context = \context_course::instance($course->id, IGNORE_MISSING) ?: \context_system::instance();

        return format_string($course->fullname, true, ['context' => $context]);
    }

    /**
     * Collapse an optional section, unless the notice being edited already uses it.
     *
     * A collapsed section that holds a value is worse than an expanded empty one: the author
     * cannot act on a filter the page does not admit is there, and nothing on screen suggests
     * looking. $ignoreuserpref is deliberately true in that case — a stored "I keep this closed"
     * preference must not win over data the notice actually carries.
     *
     * Reads pathmatch straight off the notice and the rest out of the filtervalues JSON, which is
     * where the display restrictions and modal dimensions live.
     *
     * @param string $header Name of the header element.
     * @param array $fields Field names the section owns.
     * @return void
     */
    protected function set_optional_section_state(string $header, array $fields): void {
        $mform =& $this->_form;
        $persistent = $this->get_persistent();

        $used = false;
        if ($persistent && $persistent->get('id') > 0) {
            $filters = [];
            if (!empty($persistent->get('filtervalues'))) {
                $filters = json_decode($persistent->get('filtervalues'), true) ?: [];
            }

            foreach ($fields as $field) {
                $value = $filters[$field] ?? null;
                if ($value === null && \local_awareness\persistent\awareness::has_property($field)) {
                    $value = $persistent->get($field);
                }
                if (is_array($value) ? !empty($value) : trim((string) $value) !== '') {
                    $used = true;
                    break;
                }
            }
        }

        $mform->setExpanded($header, $used, $used);
    }

    /**
     * Returns a default data.
     * @return \stdClass
     */
    protected function get_default_data() {
        $data = parent::get_default_data();
        $data->perpetual = $data->timestart == 0 && $data->timeend == 0;

        /*
         * Hand the notice's own file area to the content editor.
         *
         * core\form\persistent::get_default_data() turns content + contentformat into a
         * text/format pair but never supplies an itemid. MoodleQuickForm_editor::toHtml() then
         * mints an empty draft area of its own, and the save path syncs that empty area over
         * local_awareness/content/<noticeid>, deleting every file embedded in the notice.
         *
         * file_prepare_draft_area() only copies when the draft id is empty, so on submit the
         * user's in-progress draft is left alone and deletions made in the editor still stick.
         */
        $noticeid = (int) $this->get_persistent()->get('id');
        $draftitemid = file_get_submitted_draft_itemid('content');
        $content = is_array($data->content)
            ? $data->content
            : ['text' => (string) $data->content, 'format' => FORMAT_HTML];
        $content['text'] = file_prepare_draft_area(
            $draftitemid,
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $noticeid > 0 ? $noticeid : null,
            helper::get_file_editor_options(),
            $content['text']
        );
        $content['itemid'] = $draftitemid;
        $data->content = $content;

        // Ensure reqcourse is always an integer (0 = no course).
        if (empty($data->reqcourse)) {
            $data->reqcourse = 0;
        }

        /*
         * The insistence select is not a persistent property — it is the two stored columns read
         * as one ordered decision, so there is no third copy of the truth. Derived here on the way
         * in; helper::sanitise_data() writes it back out to those columns on the way out.
         */
        $data->insistence = $this->get_persistent()->get_insistence();

        // Unpack filter values.
        if (!empty($data->filtervalues)) {
            $filters = json_decode($data->filtervalues, true);
            foreach ($filters as $key => $value) {
                $data->$key = $value;
            }
        }

        if (isset($data->filter_competency_rules) && is_array($data->filter_competency_rules)) {
            $data->filter_competency_rules = json_encode($data->filter_competency_rules);
        }

        return $data;
    }
}
