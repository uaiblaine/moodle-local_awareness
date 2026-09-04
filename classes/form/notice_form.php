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
use local_awareness\local\author_scope;
use local_awareness\local\group_scope;
use local_awareness\persistent\awareness;
use local_awareness\persistent\slide;

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

    /*
     * insistence sits here beside perpetual for the same reason: it is a form field, not a stored
     * property. The author chooses one level and helper::sanitise_data() writes it back to the two
     * columns that hold it. Listing it is a declaration rather than a fix — core's
     * filter_data_for_persistent() removes only these names, and from_record() then drops anything
     * that is not a property anyway — but the next reader should not have to derive that.
     */
    /** @var int A carousel starts with this many empty slides, and adds this many per click. */
    public const SLIDES_MIN = 2;

    /**
     * The positions in the reading order of the 3x3 grid the stylesheet draws: the top row, the
     * centre, the bottom row. awareness::POSITIONS puts the default first, which is right for a
     * vocabulary and wrong for a grid, where a screen reader would announce the centre before the
     * corner it sits under. Same set as awareness::POSITIONS; picker_render_test pins that.
     */
    public const POSITION_GRID = ['top-start', 'top', 'top-end', 'center', 'bottom-start', 'bottom', 'bottom-end'];

    /** @var array Fields to remove from the persistent validation. */
    protected static $foreignfields = [
        // The layout picker's group names, and the repeated slide rows: none is a column.
        'templategroup', 'positiongroup', 'slide_no', 'slide_image', 'slide_videourl', 'slide_caption',
        'slide_id', 'slide_repeats', 'slide_add', 'slide_delete', 'slide_delete-hidden',
        'insistence', 'perpetual', 'cohorts', 'filter_role_context', 'filter_role', 'filter_category',
        'filter_course', 'filter_groups', 'filter_format', 'filter_theme', 'filter_competency_rules',
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
        /*
         * The scope the form writes under decides what it offers. Under a course scope the fields
         * the scope FORCES (the course, the role context) and FORBIDS (category, theme, format) are
         * not rendered at all — a field the author cannot change is not a field — and the pickers
         * the scope RESTRICTS offer only what the scope admits, so nothing the form shows is
         * something the save would refuse or quietly drop. extra_validation() applies the same
         * scope, so what slips past the form is still caught with a message on the field.
         */
        $scope = $this->scope();
        $coursemode = !$scope->is_site();

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
        // The video and carousel layouts fill their media band themselves; a background behind it
        // would be two competing surfaces, so the field is not offered for them.
        foreach (awareness::TEMPLATES as $template) {
            if (!awareness::uses_bgimage($template)) {
                $mform->hideIf('bgimage', 'template', 'eq', $template);
            }
        }

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

        if ($coursemode) {
            // Where the notice reaches is the course, said once here rather than as five absent fields.
            $mform->addElement(
                'static',
                'scope_line',
                get_string('notice:scope:thiscourse:label', 'local_awareness'),
                get_string('notice:scope:thiscourse', 'local_awareness')
            );
        } else {
            // Context / Filter fields. Forced to the course under a course scope, so not offered there.
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
        }

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
            $scope->cohort_options(),
            ['noselectionstring' => get_string('notice:cohort:all', 'local_awareness'), 'multiple' => true, 'id' => 'id_cohorts']
        );

        $mform->setDefault('cohorts', 0);

        /*
         * Groups, for a course notice. The picker offers exactly what the author may target,
         * decided the way core decides it for an activity: the course's group mode and
         * moodle/site:accessallgroups, through group_scope. It is offered only while groups are in
         * use, as core's own group menus are, except that a notice already naming groups keeps its
         * field whatever the mode, so the author can see and clear what it names. The save narrows
         * what the scope refuses and extra_validation() reports it on this field.
         */
        if ($coursemode) {
            $groups = group_scope::for_author($scope);
            $targeted = ($persistent && $persistent->get('id') > 0) ? group_scope::targeted($persistent) : [];
            if ($groups->offered() || $targeted !== []) {
                $mform->addElement(
                    'autocomplete',
                    'filter_groups',
                    get_string('notice:groups', 'local_awareness'),
                    $groups->options(),
                    ['noselectionstring' => get_string('notice:groups:all', 'local_awareness'), 'multiple' => true]
                );
                $mform->addHelpButton('filter_groups', 'notice:groups', 'local_awareness');
            }
        }

        // AJAX autocomplete for course requirement.
        // Only pre-load the currently selected course (if editing), not all courses.
        $reqcourseoptions = [0 => get_string('booleanformat:false', 'local_awareness')];
        if ($persistent && $persistent->get('id') > 0 && $persistent->get('reqcourse') > 0) {
            $selcourse = $DB->get_record('course', ['id' => $persistent->get('reqcourse')], 'id, fullname');
            if ($selcourse) {
                $reqcourseoptions[$selcourse->id] = self::course_label($selcourse);
            }
        }

        if ($coursemode) {
            // The scope restricts the required course to this one or none: a plain choice, no search.
            $mform->addElement(
                'select',
                'reqcourse',
                get_string('notice:reqcourse', 'local_awareness'),
                [
                    0 => get_string('booleanformat:false', 'local_awareness'),
                    $scope->get_courseid() => get_string('notice:reqcourse:thiscourse', 'local_awareness'),
                ]
            );
        } else {
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
        }
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
                $existingrules = self::existing_competency_rules($existingfilters['filter_competency_rules'] ?? []);
            }

            $mform->addElement('static', 'filter_competency_label', get_string('filter_competency', 'local_awareness'), '');
            $mform->addHelpButton('filter_competency_label', 'filter_competency', 'local_awareness');
            $mform->addElement(
                'html',
                '<div id="awareness-competency-filter" class="mb-3"
                    data-contextid="' . (int) $scope->context()->id . '"
                    data-courseid="' . (int) $scope->get_courseid() . '"
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

        /*
         * The four page filters below are the site scope's. Under a course scope the course is
         * forced and the other three are forbidden, so none of them is rendered: the static line
         * in the audience section says what the notice reaches instead.
         */
        if ($coursemode) {
            $this->define_appearance($mform);

            return;
        }

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

        $this->define_appearance($mform);
    }

    /**
     * The scope this form writes under: from the page, or the site.
     *
     * @return author_scope
     */
    private function scope(): author_scope {
        $scope = $this->_customdata['scope'] ?? null;

        return $scope instanceof author_scope ? $scope : author_scope::site();
    }

    /**
     * The appearance section, the same under both scopes.
     *
     * @param \MoodleQuickForm $mform The form.
     */
    private function define_appearance(\MoodleQuickForm $mform): void {
        // Modal appearance.
        $mform->addElement('header', 'header_appearance', get_string('editor:section:appearance', 'local_awareness'));
        $mform->addElement(
            'static',
            'header_appearance_desc',
            '',
            get_string('editor:section:appearance:desc', 'local_awareness')
        );

        /*
         * Layout: one radio per layout, each labelled with a schematic thumbnail and its name, so
         * the choice is seen rather than deciphered from a select. The radios share the name the
         * column has; the group name is what the help button and the dependencies hang off.
         */
        $layouts = [];
        foreach (awareness::TEMPLATES as $template) {
            $layouts[] = $mform->createElement('radio', 'template', '', self::layout_label($template), $template);
        }
        $mform->addGroup($layouts, 'templategroup', get_string('notice:template', 'local_awareness'), '', false);
        $mform->addHelpButton('templategroup', 'notice:template', 'local_awareness');
        $mform->setDefault('template', awareness::TEMPLATES[0]);

        /*
         * Position: the seven anchors as radios, emitted in the reading order of the 3x3 grid the
         * stylesheet lays them out as. A fullscreen dialogue has no position, so the field is
         * hidden for it; the corners belong to the card alone, and notice_form.js greys them for
         * every other layout while extra_validation() refuses them for good.
         */
        $positions = [];
        foreach (self::POSITION_GRID as $position) {
            $positions[] = $mform->createElement('radio', 'position', '', self::position_label($position), $position);
        }
        $mform->addGroup($positions, 'positiongroup', get_string('notice:position', 'local_awareness'), '', false);
        $mform->addHelpButton('positiongroup', 'notice:position', 'local_awareness');
        $mform->setDefault('position', awareness::POSITIONS[0]);
        $mform->hideIf('positiongroup', 'template', 'eq', 'fullscreen');

        $mform->addElement(
            'select',
            'animation',
            get_string('notice:animation', 'local_awareness'),
            self::animation_options()
        );
        $mform->addHelpButton('animation', 'notice:animation', 'local_awareness');
        $mform->setDefault('animation', awareness::ANIMATIONS[0]);

        /*
         * The video layout's link. Deliberately no file picker: the site's media players decide what
         * a link plays, and an upload would make the plugin a video host with a file area, a byte-range
         * path and a size cap of its own to carry.
         */
        $mform->addElement(
            'url',
            'videourl',
            get_string('notice:videourl', 'local_awareness'),
            ['size' => 60],
            ['usefilepicker' => false]
        );
        $mform->setType('videourl', PARAM_URL);
        $mform->addHelpButton('videourl', 'notice:videourl', 'local_awareness');
        $mform->hideIf('videourl', 'template', 'neq', 'video');

        $this->define_slides($mform);

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
        /*
         * The appearance fields are never empty - every notice stores a layout - so "holds a value"
         * has to mean "holds something other than the default", or the section would open on every
         * edit of every notice and the collapse would be decoration.
         */
        $this->set_optional_section_state(
            'header_appearance',
            ['modal_width', 'modal_height', 'template', 'position', 'animation', 'videourl'],
            [
                'template' => awareness::TEMPLATES[0],
                'position' => awareness::POSITIONS[0],
                'animation' => awareness::ANIMATIONS[0],
            ]
        );

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
     * Refuse a value that names something the site does not have, and say which field.
     *
     * The pickers cannot do this: three of them are ajax autocompletes whose values core declines
     * to validate, and a non-ajax select skips its allowlist when its option list is empty. So the
     * same scope the write path enforces is asked here first, where the answer can reach the
     * author as an error on the field instead of an exception after they pressed Save.
     *
     * \core\form\persistent::validation() is final; this is the hook it leaves.
     *
     * @param \stdClass $data Submitted data.
     * @param array $files Submitted files.
     * @param array $errors Errors found so far.
     * @return array Additional errors, keyed by element name.
     */
    protected function extra_validation($data, $files, array &$errors) {
        $result = $this->scope()->apply((array) $data);

        $extra = [];
        foreach ($result->problem_fields() as $field) {
            // The competency rules travel in a hidden field; its label is what the author sees.
            $element = $field === 'filter_competency_rules' ? 'filter_competency_label' : $field;
            $extra[$element] = self::problem_message($field);
        }

        return $extra + $this->validate_layout($data);
    }

    /**
     * The rules that tie the layout to the other choices, each refused with its reason.
     *
     * hideIf hides a field; it does not stop the value travelling, and a client rule never posts
     * the form. So the combinations the picker cannot express are refused here, on the field the
     * author can act on: a card has no room for the acknowledgement box, a corner is the card's
     * alone, the video layout needs a link, and a carousel needs slides to turn.
     *
     * @param \stdClass $data The submitted data.
     * @return array Element name => message.
     */
    private function validate_layout(\stdClass $data): array {
        $extra = [];
        $template = (string) ($data->template ?? awareness::TEMPLATES[0]);

        if (
            (int) ($data->insistence ?? awareness::INSISTENCE_INFORMATIONAL) >= awareness::INSISTENCE_ACKNOWLEDGE
            && !awareness::accepts_acknowledgement($template)
        ) {
            $extra['insistence'] = get_string('notice:insistence:notforlayout', 'local_awareness', self::layout_name($template));
        }

        $position = (string) ($data->position ?? awareness::POSITIONS[0]);
        if ($template !== 'fullscreen' && !in_array($position, awareness::positions_for($template), true)) {
            $extra['positiongroup'] = get_string('notice:position:notforlayout', 'local_awareness', self::layout_name($template));
        }

        if (awareness::uses_video($template)) {
            $problem = self::video_link_problem((string) ($data->videourl ?? ''), true);
            if ($problem !== null) {
                $extra['videourl'] = $problem;
            }
        }

        if ($template === 'carousel') {
            $extra += $this->validate_slides($data);
        }

        return $extra;
    }

    /**
     * What is wrong with a video link, or null when nothing is.
     *
     * PARAM_URL lets a scheme-less value through, and the browser would then resolve it against
     * the page it is on; only an absolute http(s) link plays.
     *
     * @param string $url The submitted link, trimmed here.
     * @param bool $required Whether an empty link is itself a problem.
     * @return string|null The message to show, or null.
     */
    private static function video_link_problem(string $url, bool $required): ?string {
        $url = trim($url);
        if ($url === '') {
            return $required ? get_string('notice:videourl:required', 'local_awareness') : null;
        }
        if (!preg_match('~^https?://~i', $url) || clean_param($url, PARAM_URL) !== $url) {
            return get_string('notice:videourl:invalid', 'local_awareness');
        }

        return null;
    }

    /**
     * The carousel's slides: at least two with something on them, and no slide asked to be both.
     *
     * The draft ids are read from the submitted arrays, never through
     * file_get_submitted_draft_itemid(), which cannot address a repeated element.
     *
     * @param \stdClass $data The submitted data.
     * @return array Element name => message.
     */
    private function validate_slides(\stdClass $data): array {
        $extra = [];
        $present = 0;
        $captions = (array) ($data->slide_caption ?? []);
        $links = (array) ($data->slide_videourl ?? []);
        $drafts = (array) ($data->slide_image ?? []);

        $indexes = array_keys($captions + $links + $drafts);
        sort($indexes);
        foreach ($indexes as $i) {
            $caption = trim((string) ($captions[$i] ?? ''));
            $link = trim((string) ($links[$i] ?? ''));
            $draftid = (int) ($drafts[$i] ?? 0);
            $hasimage = $draftid > 0 && (int) file_get_draft_area_info($draftid)['filecount'] > 0;

            if ($link !== '') {
                $problem = self::video_link_problem($link, false);
                if ($problem !== null) {
                    $extra["slide_videourl[{$i}]"] = $problem;
                } else if ($hasimage) {
                    $extra["slide_videourl[{$i}]"] = get_string('notice:slide:both', 'local_awareness');
                }
            }
            if ($caption !== '' || $link !== '' || $hasimage) {
                $present++;
            }
        }

        if ($present < self::SLIDES_MIN) {
            $extra['templategroup'] = get_string('notice:slides:required', 'local_awareness', self::SLIDES_MIN);
        }

        return $extra;
    }

    /**
     * The slide rows: a repeated group of image, link and caption, hidden unless the layout is the carousel.
     *
     * A carousel starts with SLIDES_MIN empty rows and grows by that many per click; a row left
     * entirely empty is not a slide and is not saved. The Delete button is core's no-submit
     * repeat-deletion, so removing a slide never posts the form.
     *
     * @param \MoodleQuickForm $mform The form.
     * @return void
     */
    private function define_slides(\MoodleQuickForm $mform): void {
        global $CFG;

        $repeats = max(self::SLIDES_MIN, count($this->existing_slides()));

        $elements = [
            $mform->createElement('static', 'slide_no', get_string('notice:slide', 'local_awareness')),
            $mform->createElement(
                'filepicker',
                'slide_image',
                get_string('notice:slide:image', 'local_awareness'),
                null,
                ['maxbytes' => $CFG->maxbytes, 'accepted_types' => ['image'], 'maxfiles' => 1]
            ),
            $mform->createElement(
                'url',
                'slide_videourl',
                get_string('notice:slide:video', 'local_awareness'),
                ['size' => 60],
                ['usefilepicker' => false]
            ),
            $mform->createElement('text', 'slide_caption', get_string('notice:slide:caption', 'local_awareness'), ['size' => 60]),
            $mform->createElement('hidden', 'slide_id', 0),
            $mform->createElement('submit', 'slide_delete', get_string('notice:slide:delete', 'local_awareness'), [], false),
        ];
        $hide = ['hideif' => ['template', 'neq', 'carousel']];
        $options = [
            'slide_no' => ['default' => '{no}'] + $hide,
            'slide_image' => $hide,
            'slide_videourl' => ['type' => PARAM_URL] + $hide,
            'slide_caption' => ['type' => PARAM_TEXT] + $hide,
            'slide_id' => ['type' => PARAM_INT],
            'slide_delete' => $hide,
        ];
        $this->repeat_elements(
            $elements,
            $repeats,
            $options,
            'slide_repeats',
            'slide_add',
            self::SLIDES_MIN,
            get_string('notice:slide:add', 'local_awareness', '{no}'),
            true,
            'slide_delete'
        );
        $mform->hideIf('slide_add', 'template', 'neq', 'carousel');
    }

    /**
     * The slides the notice being edited already has, in order; none for a new notice.
     *
     * @return slide[]
     */
    private function existing_slides(): array {
        $noticeid = (int) $this->get_persistent()->get('id');

        return $noticeid > 0 ? slide::for_notice($noticeid) : [];
    }

    /**
     * The layout radio's label: a thumbnail and the name, from the plugin's own template.
     *
     * @param string $template One of awareness::TEMPLATES.
     * @return string HTML.
     */
    private static function layout_label(string $template): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_awareness/editor/layout_option', [
            'template' => $template,
            'name' => self::layout_name($template),
        ]);
    }

    /**
     * A layout's name, from a literal string id per value.
     *
     * @param string $template One of awareness::TEMPLATES.
     * @return string
     * @throws \coding_exception For a layout with no name, so a value added to the vocabulary cannot ship unnamed.
     */
    public static function layout_name(string $template): string {
        switch ($template) {
            case 'classic':
                return get_string('notice:template:classic', 'local_awareness');
            case 'hero':
                return get_string('notice:template:hero', 'local_awareness');
            case 'fullscreen':
                return get_string('notice:template:fullscreen', 'local_awareness');
            case 'card':
                return get_string('notice:template:card', 'local_awareness');
            case 'video':
                return get_string('notice:template:video', 'local_awareness');
            case 'carousel':
                return get_string('notice:template:carousel', 'local_awareness');
            default:
                throw new \coding_exception("No name for the layout '{$template}'");
        }
    }

    /**
     * A position's name, from a literal string id per value.
     *
     * @param string $position One of awareness::POSITIONS.
     * @return string
     * @throws \coding_exception For a position with no name.
     */
    public static function position_label(string $position): string {
        switch ($position) {
            case 'center':
                return get_string('notice:position:center', 'local_awareness');
            case 'top':
                return get_string('notice:position:top', 'local_awareness');
            case 'bottom':
                return get_string('notice:position:bottom', 'local_awareness');
            case 'top-start':
                return get_string('notice:position:topstart', 'local_awareness');
            case 'top-end':
                return get_string('notice:position:topend', 'local_awareness');
            case 'bottom-start':
                return get_string('notice:position:bottomstart', 'local_awareness');
            case 'bottom-end':
                return get_string('notice:position:bottomend', 'local_awareness');
            default:
                throw new \coding_exception("No name for the position '{$position}'");
        }
    }

    /**
     * The animation select's options, one literal string id per value.
     *
     * @return array Value => name, in vocabulary order.
     */
    public static function animation_options(): array {
        return [
            'none' => get_string('notice:animation:none', 'local_awareness'),
            'fade' => get_string('notice:animation:fade', 'local_awareness'),
            'slide' => get_string('notice:animation:slide', 'local_awareness'),
            'zoom' => get_string('notice:animation:zoom', 'local_awareness'),
            'spring' => get_string('notice:animation:spring', 'local_awareness'),
        ];
    }

    /**
     * The message shown on a field the author scope corrected.
     *
     * A fixed key per field rather than a dynamic string id, and no value in the message: what was
     * submitted is never echoed, so the error cannot confirm that a guessed id exists.
     *
     * @param string $field The corrected field.
     * @return string
     * @throws \coding_exception For a field with no message, so a field added to the scope cannot be mislabelled.
     */
    private static function problem_message(string $field): string {
        switch ($field) {
            case 'filter_category':
                return get_string('scope:problem:filter_category', 'local_awareness');
            case 'filter_competency_rules':
                return get_string('scope:problem:filter_competency_rules', 'local_awareness');
            case 'filter_course':
                return get_string('scope:problem:filter_course', 'local_awareness');
            case 'filter_format':
                return get_string('scope:problem:filter_format', 'local_awareness');
            case 'filter_groups':
                return get_string('scope:problem:filter_groups', 'local_awareness');
            case 'filter_role':
                return get_string('scope:problem:filter_role', 'local_awareness');
            case 'filter_role_context':
                return get_string('scope:problem:filter_role_context', 'local_awareness');
            case 'filter_theme':
                return get_string('scope:problem:filter_theme', 'local_awareness');
            case 'reqcourse':
                return get_string('scope:problem:reqcourse', 'local_awareness');
            default:
                throw new \coding_exception("No message for a scope problem on '{$field}'");
        }
    }

    /**
     * The stored competency rules, normalised, named, and without any rule whose competency has
     * since been deleted.
     *
     * The course, role and required-course pickers drop a dead referent because they re-query and
     * offer only what they find. This field is a hidden JSON value the author cannot see, so a dead
     * rule left in it would be resubmitted verbatim, refused by the author scope on save, and the
     * author shown an error on a rule the page never displayed. Dropping it here is what makes
     * "corrected the next time it is saved" true for this field too. Both places the form reads the
     * stored rules go through here — the element's default in definition() and the data set over it
     * from get_default_data() — so the two cannot disagree.
     *
     * @param mixed $raw The stored rules, as JSON or as an array.
     * @return array Normalised rules, each with a name.
     */
    private static function existing_competency_rules($raw): array {
        $rules = helper::normalise_competency_rules($raw);
        if (empty($rules)) {
            return [];
        }

        $names = helper::get_competency_names(array_column($rules, 'id'));

        $kept = [];
        foreach ($rules as $rule) {
            if (!isset($names[$rule['id']])) {
                continue;
            }
            if (empty($rule['name'])) {
                $rule['name'] = $names[$rule['id']];
            }
            $kept[] = $rule;
        }

        return $kept;
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
     * @param array $defaults Field name => the value that means "not chosen", for columns that are never empty.
     * @return void
     */
    protected function set_optional_section_state(string $header, array $fields, array $defaults = []): void {
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
                // A column with a default is "used" only away from it: a stored 'classic' is
                // the absence of a choice, not a choice the author needs to see.
                if (array_key_exists($field, $defaults) && $value === $defaults[$field]) {
                    continue;
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

        /*
         * A new notice arrives with a fade; a saved one keeps what it stored, 'none' included.
         * The column default stays 'none' so the upgrade changes nothing a reader sees, and only
         * the empty form suggests motion. Pinned by a test: editing an old notice must not drift it.
         */
        if ($noticeid <= 0) {
            $data->animation = 'fade';
        }

        /*
         * One draft area per existing slide, so its picker shows the image it has. The draft id is
         * read from the submitted array directly: file_get_submitted_draft_itemid() cannot address
         * a repeated element, and returns false with a developer warning when asked to.
         */
        $submitted = optional_param_array('slide_image', [], PARAM_INT);
        $data->slide_image = [];
        $data->slide_videourl = [];
        $data->slide_caption = [];
        $data->slide_id = [];
        foreach ($this->existing_slides() as $i => $slide) {
            $draftid = (int) ($submitted[$i] ?? 0);
            file_prepare_draft_area(
                $draftid,
                \context_system::instance()->id,
                'local_awareness',
                slide::FILEAREA,
                (int) $slide->get('id'),
                ['maxfiles' => 1, 'accepted_types' => ['image']]
            );
            $data->slide_image[$i] = $draftid;
            $data->slide_videourl[$i] = (string) $slide->get('videourl');
            $data->slide_caption[$i] = (string) $slide->get('caption');
            $data->slide_id[$i] = (int) $slide->get('id');
        }

        // Unpack filter values.
        if (!empty($data->filtervalues)) {
            $filters = json_decode($data->filtervalues, true);
            foreach ($filters as $key => $value) {
                $data->$key = $value;
            }
        }

        if (isset($data->filter_competency_rules) && is_array($data->filter_competency_rules)) {
            $data->filter_competency_rules = json_encode(self::existing_competency_rules($data->filter_competency_rules));
        }

        return $data;
    }
}
