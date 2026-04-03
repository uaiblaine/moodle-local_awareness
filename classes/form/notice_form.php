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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form to create new notice
 * @package local_awareness
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notice_form extends \core\form\persistent {
    /** @var string Persistent class name. */
    protected static $persistentclass = 'local_awareness\persistent\awareness';

    /** @var array Fields to remove from the persistent validation. */
    protected static $foreignfields = [
        'perpetual', 'cohorts', 'filter_role', 'filter_category',
        'filter_course', 'filter_format', 'filter_theme', 'filter_competency_rules',
        'filter_competency_requireall', 'bgimage',
    ];

    /**
     * Form definition.
     */
    public function definition() {
        global $CFG, $DB;
        $mform =& $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

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

        $mform->addElement('duration', 'resetinterval', get_string('notice:resetinterval', 'local_awareness'));
        $mform->addHelpButton('resetinterval', 'notice:resetinterval', 'local_awareness');
        $mform->setDefault('resetinterval', 0);

        $mform->addElement('selectyesno', 'reqack', get_string('notice:reqack', 'local_awareness'));
        $mform->addHelpButton('reqack', 'notice:reqack', 'local_awareness');

        $mform->setDefault('reqack', 0);

        $mform->addElement('selectyesno', 'forcelogout', get_string('notice:forcelogout', 'local_awareness'));
        $mform->addHelpButton('forcelogout', 'notice:forcelogout', 'local_awareness');

        $mform->setDefault('forcelogout', 0);

        $mform->addElement('selectyesno', 'outsideclick', get_string('notice:outsideclick', 'local_awareness'));
        $mform->addHelpButton('outsideclick', 'notice:outsideclick', 'local_awareness');
        $mform->setDefault('outsideclick', 1);

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
        $persistent = $this->get_persistent();
        if ($persistent && $persistent->get('id') > 0 && $persistent->get('reqcourse') > 0) {
            $selcourse = $DB->get_record('course', ['id' => $persistent->get('reqcourse')], 'id, fullname');
            if ($selcourse) {
                $reqcourseoptions[$selcourse->id] = $selcourse->fullname;
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

        $mform->addElement('selectyesno', 'perpetual', get_string('notice:perpetual', 'local_awareness'));
        $mform->setDefault('perpetual', 1);

        $activeoptions = ['startyear' => date("Y"), 'stopyear' => 2030];
        $mform->addElement('date_time_selector', 'timestart', get_string('notice:activefrom', 'local_awareness'), $activeoptions);
        $mform->addHelpButton('timestart', 'notice:activefrom', 'local_awareness');
        $mform->hideIf('timestart', 'perpetual', 'eq', 1);

        $expiryoptions = ['startyear' => date("Y"), 'stopyear' => 2030, 'defaulttime' => time() + HOURSECS];
        $mform->addElement('date_time_selector', 'timeend', get_string('notice:expiry', 'local_awareness'), $expiryoptions);
        $mform->addHelpButton('timeend', 'notice:expiry', 'local_awareness');
        $mform->hideIf('timeend', 'perpetual', 'eq', 1);

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

        // Modal Dimensions.
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

        // Filters.
        $mform->addElement('header', 'header_filters', get_string('filters', 'local_awareness'));

        // Path Match.
        $mform->addElement('text', 'pathmatch', get_string('pathmatch', 'local_awareness'));
        $mform->setType('pathmatch', PARAM_RAW);
        $mform->addHelpButton('pathmatch', 'pathmatch', 'local_awareness');

        // Context / Filter fields.
        // Role.
        $mform->addElement(
            'autocomplete',
            'filter_role',
            get_string('filter_role', 'local_awareness'),
            helper::get_role_options(),
            [
                'multiple' => true,
                'noselectionstring' => get_string('all', 'local_awareness'),
            ]
        );

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
                        $filtercoursedefaults[$sc->id] = $sc->fullname;
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
                    data-picker-title="' . s(get_string('filter_competency_picker_title', 'local_awareness')) . '"
                    data-picker-framework="' . s(get_string('filter_competency_picker_framework', 'local_awareness')) . '"
                    data-picker-search="' . s(get_string('search')) . '"
                    data-picker-noframeworks="' . s(get_string('filter_competency_picker_noframeworks', 'local_awareness')) . '"
                    data-picker-nocompetencies="' . s(get_string('filter_competency_picker_nocompetencies', 'local_awareness')) . '"
                    data-picker-loading="' . s(get_string('loading', 'admin')) . '"
                    data-picker-addselected="' . s(get_string('filter_competency_picker_addselected', 'local_awareness')) . '"
                    data-picker-cancel="' . s(get_string('cancel')) . '">
                    <button type="button" id="id_awareness_add_competencies" class="btn btn-secondary">' .
                        s(get_string('filter_competency_add', 'local_awareness')) . '
                    </button>
                    <div id="id_awareness_competency_rules" class="mt-2"></div>
                </div>'
            );

            $mform->addElement('hidden', 'filter_competency_rules', json_encode($existingrules));
            $mform->setType('filter_competency_rules', PARAM_RAW);

            $mform->addElement('selectyesno', 'filter_competency_requireall', get_string('filter_competency_requireall', 'local_awareness'));
            $mform->setDefault('filter_competency_requireall', 0);
            $mform->addHelpButton('filter_competency_requireall', 'filter_competency_requireall', 'local_awareness');

            if ($persistent && $persistent->get('id') > 0 && !empty($persistent->get('filtervalues'))) {
                $existingfilters = json_decode($persistent->get('filtervalues'), true);
                if (!empty($existingfilters['filter_competency_requireall'])) {
                    $mform->setDefault('filter_competency_requireall', 1);
                }
            }
        }

        $mform->addElement('selectyesno', 'enabled', get_string('notice:enable', 'local_awareness'));
        $mform->setDefault('enabled', 1);

        $buttonarray = [];

        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('savechanges'));
        $buttonarray[] = $mform->createElement('cancel');

        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
    }


    /**
     * Returns a default data.
     * @return \stdClass
     */
    protected function get_default_data() {
        $data = parent::get_default_data();
        $data->perpetual = $data->timestart == 0 && $data->timeend == 0;

        // Ensure reqcourse is always an integer (0 = no course).
        if (empty($data->reqcourse)) {
            $data->reqcourse = 0;
        }

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
