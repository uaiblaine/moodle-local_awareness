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

use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;

/**
 * Tests for the notice editing form.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\form\notice_form
 */
final class notice_form_test extends \advanced_testcase {
    /**
     * Create a notice carrying one file in its content file area.
     *
     * @return array{0: awareness, 1: string} The notice and the stored file name.
     */
    private function create_notice_with_embedded_file(): array {
        $notice = new awareness(0, (object) [
            'title' => 'Policy update',
            'content' => '<p>See the diagram below.</p>',
        ]);
        $notice->create();

        get_file_storage()->create_file_from_string(
            [
                'contextid' => \context_system::instance()->id,
                'component' => 'local_awareness',
                'filearea' => 'content',
                'itemid' => $notice->get('id'),
                'filepath' => '/',
                'filename' => 'diagram.png',
            ],
            'not really a png'
        );

        return [$notice, 'diagram.png'];
    }

    /**
     * The option texts of one named select in the rendered form.
     *
     * Scoped to the element rather than matched across the whole page on purpose: a bare
     * str_contains() over the rendered form matches the wanted text anywhere downstream — including
     * a legitimate occurrence in a different element — and so passes while the select under test is
     * wrong.
     *
     * @param string $html The rendered form.
     * @param string $name The select's name attribute.
     * @return array The option texts, in document order.
     */
    private function option_texts(string $html, string $name): array {
        $matched = preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '\[?\]?"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertSame(1, $matched, "the form rendered no select named {$name}");

        preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $m[1], $options);
        return array_map('trim', $options[1]);
    }

    /**
     * Read the form's default data, which is protected.
     *
     * @param notice_form $form Form instance.
     * @return \stdClass
     */
    private function default_data(notice_form $form): \stdClass {
        $method = new \ReflectionMethod($form, 'get_default_data');
        $method->setAccessible(true);
        return $method->invoke($form);
    }

    /**
     * Editing a notice must hand the editor a draft area holding the notice's own files.
     *
     * Without an itemid, MoodleQuickForm_editor mints an empty draft area and the save path
     * syncs that empty area over local_awareness/content/<noticeid>, deleting every embedded
     * file. Asserting the draft is populated is what catches a regression: an itemid alone
     * would still be present if the area were prepared from item 0 again.
     */
    public function test_editing_a_notice_loads_its_files_into_the_content_draft_area(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$notice, $filename] = $this->create_notice_with_embedded_file();

        $form = new notice_form(null, ['persistent' => $notice, 'id' => $notice->get('id')]);
        $data = $this->default_data($form);

        $this->assertIsArray($data->content);
        $this->assertArrayHasKey('itemid', $data->content);
        $draftitemid = (int) $data->content['itemid'];
        $this->assertGreaterThan(0, $draftitemid);

        $draftfiles = get_file_storage()->get_area_files(
            \context_user::instance((int) $USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        );

        $names = array_map(static function (\stored_file $file): string {
            return $file->get_filename();
        }, $draftfiles);
        $this->assertContains($filename, array_values($names));
    }

    /**
     * Creating a notice must start from an empty draft area.
     *
     * The control for the test above: it proves the population comes from the notice's own
     * item id rather than from every file the plugin holds.
     */
    public function test_creating_a_notice_starts_from_an_empty_content_draft_area(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_notice_with_embedded_file();

        $form = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $data = $this->default_data($form);

        $draftitemid = (int) $data->content['itemid'];
        $this->assertGreaterThan(0, $draftitemid);

        $draftfiles = get_file_storage()->get_area_files(
            \context_user::instance((int) $USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        );

        $this->assertEmpty($draftfiles);
    }

    /**
     * Editing a notice shows the insistence level it actually has.
     *
     * The level is derived rather than stored, so it has to be put into the form on the way in and
     * mapped back to the two columns on the way out. Only the second direction had a test, and the
     * first is the one that loses data: a form that silently offers "Informational" for a Blocking
     * notice demotes it the moment the author saves anything else about it — a title fix would
     * quietly make an unskippable notice skippable, with nothing on screen to say so.
     *
     * The three rows are each other's controls. A mapping stuck on any single value satisfies one
     * of them and fails the other two.
     *
     * @return void
     */
    public function test_editing_a_notice_offers_the_level_it_actually_has(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cases = [
            [0, 1, awareness::INSISTENCE_INFORMATIONAL],
            [0, 0, awareness::INSISTENCE_BLOCKING],
            [1, 0, awareness::INSISTENCE_ACKNOWLEDGE],
        ];

        foreach ($cases as [$reqack, $outsideclick, $expected]) {
            $notice = new awareness(0, (object) [
                'title' => 'Level ' . $expected,
                'content' => '<p>Body</p>',
                'enabled' => 1,
                'reqack' => $reqack,
                'outsideclick' => $outsideclick,
            ]);
            $notice->create();

            $form = new notice_form(null, ['persistent' => $notice, 'id' => $notice->get('id')]);
            $data = $this->default_data($form);

            $this->assertSame(
                $expected,
                $data->insistence,
                "a notice stored as reqack={$reqack}, outsideclick={$outsideclick} must open at level {$expected}"
            );
        }
    }

    /**
     * Every field with a help string actually offers the help button.
     *
     * "Is perpetual" had `notice:perpetual_help` defined in both language packs and no
     * addHelpButton() call, so the sentence explaining what the field does existed, was
     * translated, was maintained — and never reached a single author. Nothing in the pipeline can
     * see that: a help string with no button is not an unused string (the sniff cannot tell) and
     * not a broken one.
     *
     * Driven from the language pack rather than a hand-kept list, so a field whose help string is
     * added later without its button turns this red on its own.
     */
    public function test_every_field_with_a_help_string_has_its_help_button(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        /*
         * Rendering the form reaches MoodleQuickForm_editor, which asks TinyMCE for its plugin
         * configuration, and tiny_autosave reads $PAGE->url. Without a URL that emits a
         * debugging() call — harmless on 5.x, but an unasserted debugging() FAILS PHPUnit on 4.5,
         * so this line is what makes the test run on the lower half of the supported range.
         */
        $PAGE->set_url(new \moodle_url('/local/awareness/editnotice.php'));

        $form = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $html = $form->render();

        $strings = get_string_manager()->load_component_strings('local_awareness', 'en');

        $missing = [];
        $checked = [];
        foreach (array_keys($strings) as $key) {
            if (!str_starts_with($key, 'notice:') || !str_ends_with($key, '_help')) {
                continue;
            }
            $element = substr($key, strlen('notice:'), -strlen('_help'));

            /*
             * Both halves read from the rendered page, which is the only place that can answer
             * "did the author see this". A help string whose field the form does not render is
             * not this test's business; a field that IS rendered and shows no help is.
             *
             * The help TEXT is the anchor, because core's help_icon template carries no field
             * identifier — it puts the string itself into data-bs-content. Matching the text is
             * therefore also the stronger check: it proves the right help reached the right field.
             */
            if (!str_contains($html, 'name="' . $element . '"')) {
                continue;
            }
            $checked[] = $element;

            $needle = s(shorten_text(strip_tags($strings[$key]), 40, true, ''));
            if ($needle !== '' && !str_contains($html, $needle)) {
                $missing[] = $element;
            }
        }

        $this->assertSame([], $missing, 'these fields define a help string but never show it: '
            . implode(', ', $missing));

        /*
         * Non-vacuity, both ways. The loop has to have examined some fields, and the specific one
         * this test was written for has to be among them — otherwise a rename would leave the
         * assertion above passing over an empty set.
         */
        $this->assertNotEmpty($checked, 'no rendered field carried a help string — the scan is broken');
        $this->assertContains(
            'perpetual',
            $checked,
            'the field this test was written for must be among those examined'
        );
    }

    /**
     * Admin-set names reach the pickers in the ESCAPED spelling.
     *
     * Both option lists are rendered by core's element-autocomplete.mustache, which emits every
     * option as {{{text}}} — a triple stash — and lib/form/select.php passes the text through
     * untouched. So a course or category name carrying markup arrives as markup, and a multilang
     * name arrives as literal {mlang} text.
     *
     * This is the half of the same defect that lives in PHP rather than in the web service. The
     * finding named only search_courses(); the picker has two sides and the author sees both in
     * one field, so fixing one and not the other would have left the AJAX suggestions and the
     * pre-loaded selection disagreeing about the same course.
     *
     * A bare ampersand is the fixture because tag-shaped input is stripped identically in both
     * spellings and would prove nothing.
     */
    public function test_admin_set_names_reach_the_pickers_escaped(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/awareness/editnotice.php'));

        $category = $this->getDataGenerator()->create_category(['name' => 'Science & Tech']);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Physics & Chemistry']);

        $notice = new awareness(0, (object) [
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
            'reqcourse' => $course->id,
        ]);
        $notice->create();

        $form = new notice_form(null, ['persistent' => $notice, 'id' => $notice->get('id')]);
        $html = $form->render();

        $categories = $this->option_texts($html, 'filter_category');
        $courses = $this->option_texts($html, 'reqcourse');

        // Non-vacuity: the scan found the options at all, so the assertions below can fail.
        $this->assertNotEmpty($categories);
        $this->assertNotEmpty($courses);

        $this->assertContains('Science &amp; Tech', $categories);
        $this->assertContains('Physics &amp; Chemistry', $courses);

        // Control: the unescaped spelling is not what was rendered.
        $this->assertNotContains('Science & Tech', $categories);
        $this->assertNotContains('Physics & Chemistry', $courses);

        // Precondition: the fixtures really do carry the character under test.
        $this->assertStringContainsString('&', $category->name);
        $this->assertStringContainsString('&', $course->fullname);
    }

    /**
     * The form refuses a course that no longer exists, on the field the author can see.
     *
     * \core\form\persistent::validation() is final, so the check lives in extra_validation(),
     * exercised here with the submitted-data shape the form hands it. The real course is the
     * control: no error for it, or the hook would refuse every course filter.
     */
    public function test_the_form_refuses_a_course_that_no_longer_exists(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {course}') + 1000;
        $this->assertFalse($DB->record_exists('course', ['id' => $missing]));

        $form = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $method = new \ReflectionMethod($form, 'extra_validation');
        $method->setAccessible(true);
        $errors = [];

        $clean = $method->invokeArgs($form, [(object) ['filter_course' => [(int) $course->id]], [], &$errors]);
        $this->assertSame([], $clean);

        $refused = $method->invokeArgs($form, [(object) ['filter_course' => [(int) $course->id, $missing]], [], &$errors]);
        $this->assertSame(
            ['filter_course' => get_string('scope:problem:filter_course', 'local_awareness')],
            $refused
        );
    }

    /**
     * Every field the site scope can report has a message, and a field without one fails loudly.
     *
     * Pinned against author_scope::RULES rather than a list of names, so a field added to the
     * scope without a message here reddens instead of reaching an author as the wrong message.
     */
    public function test_every_field_the_scope_can_report_has_a_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $method = new \ReflectionMethod($form, 'problem_message');
        $method->setAccessible(true);

        foreach (array_keys(author_scope::RULES) as $field) {
            if (author_scope::site()->rule_for($field) !== author_scope::RULE_EXISTS) {
                continue;
            }
            $this->assertStringStartsNotWith('[[', $method->invoke(null, $field), "{$field} has no message");
        }

        $this->expectException(\coding_exception::class);
        $method->invoke(null, 'cohorts');
    }

    /**
     * A rule for a competency that no longer exists is dropped when the form loads.
     *
     * The other pickers drop a dead referent because they re-query; this field is a hidden JSON
     * value the author cannot see, so a dead rule left in it would be resubmitted verbatim, refused
     * on save, and reported on a field the page never showed as a problem. The surviving rule is
     * the control, and the element's value is read after core has set the default data over it,
     * which is the value the page actually carries.
     */
    public function test_a_rule_for_a_deleted_competency_is_dropped_when_the_form_loads(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'core_competency');

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $kept = (int) $generator->create_competency(['competencyframeworkid' => $framework->get('id')])->get('id');
        $gone = (int) $generator->create_competency(['competencyframeworkid' => $framework->get('id')])->get('id');

        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
            'filtervalues' => json_encode(['filter_competency_rules' => [
                ['id' => $kept, 'proficient' => 1, 'name' => 'Kept'],
                ['id' => $gone, 'proficient' => 1, 'name' => 'Gone'],
            ]]),
        ]);
        $DB->delete_records('competency', ['id' => $gone]);

        $form = new notice_form(null, ['persistent' => $notice, 'id' => $notice->get('id')]);
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        $value = json_decode($property->getValue($form)->getElement('filter_competency_rules')->getValue(), true);

        $this->assertSame([$kept], array_column($value, 'id'));
    }

    /**
     * A fresh course with one cohort wired to it and one not.
     *
     * @return array [course, wired cohort, unwired cohort]
     */
    private function course_with_cohorts(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $wired = $this->getDataGenerator()->create_cohort(['name' => 'Wired cohort']);
        $unwired = $this->getDataGenerator()->create_cohort(['name' => 'Unwired cohort']);
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        enrol_get_plugin('cohort')->add_instance($course, ['customint1' => $wired->id, 'roleid' => $studentroleid]);

        return [$course, $wired, $unwired];
    }

    /**
     * Under a course scope the form offers only what the scope admits; under the site, everything.
     *
     * Read from the rendered page, the only place that answers "did the author see this", and with
     * the bracketed names the multi-selects emit. The site form in the same test is the control
     * for every absence: a field missing from both is a broken selector, not a scoped form.
     */
    public function test_the_form_under_a_course_scope_offers_only_what_the_scope_admits(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/awareness/editnotice.php'));
        [$course, $wired, $unwired] = $this->course_with_cohorts();

        $site = (new notice_form(null, ['persistent' => null, 'id' => 0]))->render();
        $mine = (new notice_form(null, [
            'persistent' => null,
            'id' => 0,
            'scope' => author_scope::course((int) $course->id),
        ]))->render();

        // Forbidden and forced fields: rendered for the site, absent for the course.
        foreach (['filter_category[]', 'filter_theme[]', 'filter_format[]', 'filter_course[]', 'filter_role_context'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $site, "the site form renders {$name}");
            $this->assertStringNotContainsString('name="' . $name . '"', $mine, "the course form must not render {$name}");
        }
        $this->assertStringContainsString(get_string('notice:scope:thiscourse', 'local_awareness'), $mine);
        $this->assertStringNotContainsString(get_string('notice:scope:thiscourse', 'local_awareness'), $site);

        // The required course: a search on the site, a two-way choice in the course.
        $this->assertSame(
            1,
            preg_match('/<select[^>]*name="reqcourse"[^>]*>(.*?)<\/select>/s', $mine, $select),
            'reqcourse is a select'
        );
        $this->assertSame(2, substr_count($select[1], '<option'), 'no course, or this course');
        $this->assertStringContainsString('value="' . $course->id . '"', $select[1]);

        // The cohorts: only the wired one is offered in the course; both on the site.
        $this->assertSame(1, preg_match('/<select[^>]*name="cohorts\[\]"[^>]*>(.*?)<\/select>/s', $mine, $cohorts));
        $this->assertStringContainsString('Wired cohort', $cohorts[1]);
        $this->assertStringNotContainsString('Unwired cohort', $cohorts[1]);
        $this->assertSame(1, preg_match('/<select[^>]*name="cohorts\[\]"[^>]*>(.*?)<\/select>/s', $site, $sitecohorts));
        $this->assertStringContainsString('Unwired cohort', $sitecohorts[1]);

        // The competency picker is told its course.
        $this->assertStringContainsString('data-courseid="' . $course->id . '"', $mine);
        $this->assertStringContainsString('data-courseid="0"', $site);
    }

    /**
     * extra_validation() applies the form's own scope: a category is refused in a course and accepted at the site.
     *
     * The submission names a real category, so the refusal is the FORBID rule and not existence;
     * the site form accepting it in the same test is what proves the scope was read at all.
     */
    public function test_extra_validation_applies_the_form_s_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $data = (object) ['filter_category' => [(int) $course->category]];

        $site = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $method = new \ReflectionMethod($site, 'extra_validation');
        $method->setAccessible(true);
        $errors = [];
        $this->assertSame([], $method->invokeArgs($site, [$data, [], &$errors]), 'the site accepts a real category');

        $mine = new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => author_scope::course((int) $course->id)]);
        $this->assertSame(
            ['filter_category' => get_string('scope:problem:filter_category', 'local_awareness')],
            $method->invokeArgs($mine, [$data, [], &$errors]),
            'the course refuses it, with the field\'s own message'
        );
    }

    /**
     * Every field the course scope can report — forbidden or restricted — has a message too.
     */
    public function test_every_field_the_course_scope_can_report_has_a_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $method = new \ReflectionMethod($form, 'problem_message');
        $method->setAccessible(true);

        $course = $this->getDataGenerator()->create_course();
        $scope = author_scope::course((int) $course->id);
        $checked = 0;
        foreach (array_keys(author_scope::RULES) as $field) {
            $rule = $scope->rule_for($field);
            if ($rule !== author_scope::RULE_FORBID && $rule !== author_scope::RULE_RESTRICT) {
                continue;
            }
            if ($field === 'cohorts') {
                continue; // Narrowed silently, by design: no message, and the site test pins the exception.
            }
            $this->assertStringStartsNotWith('[[', $method->invoke(null, $field), "{$field} has no message");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(5, $checked, 'the course scope forbids or restricts several fields');
    }

    /**
     * The values a form's group picker offers, or null when the form has no picker.
     *
     * @param notice_form $form The form.
     * @return int[]|null
     */
    private function offered_groups(notice_form $form): ?array {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        $mform = $property->getValue($form);
        if (!$mform->elementExists('filter_groups')) {
            return null;
        }
        $values = [];
        foreach ($mform->getElement('filter_groups')->_options as $option) {
            $values[] = (int) $option['attr']['value'];
        }
        sort($values);

        return $values;
    }

    /**
     * The group picker offers exactly what the author may reach, and only while groups are in use.
     *
     * Separate groups: a teacher without accessallgroups is offered their own group, an editing
     * teacher every group. The course's group mode does not gate the field — only having groups to
     * offer does. The site form never has the picker.
     */
    public function test_the_group_picker_offers_the_author_s_reach(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $red = $generator->create_group(['courseid' => $course->id]);
        $blue = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $editor = $generator->create_and_enrol($course, 'editingteacher');
        $generator->create_group_member(['groupid' => $red->id, 'userid' => $teacher->id]);
        $scope = author_scope::course((int) $course->id);
        $every = [(int) $red->id, (int) $blue->id];
        sort($every);

        $this->setUser($teacher);
        $form = new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => $scope]);
        $this->assertSame([(int) $red->id], $this->offered_groups($form));

        $this->setUser($editor);
        $form = new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => $scope]);
        $this->assertSame($every, $this->offered_groups($form));
        $site = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $this->assertNull($this->offered_groups($site), 'the site has no groups');

        /*
         * The mode is not what decides the picker: it governs how activities separate participants,
         * and a course can hold hundreds of groups with the mode left at "No groups", which is how
         * core ships it. Gating on the mode hid the picker on every real course on the dev site.
         */
        $DB->set_field('course', 'groupmode', NOGROUPS, ['id' => $course->id]);
        $form = new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => $scope]);
        $this->assertSame($every, $this->offered_groups($form), 'the group mode does not hide the picker');

        // What does decide it is having something to pick.
        $bare = $generator->create_course();
        $form = new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => author_scope::course((int) $bare->id)]);
        $this->assertNull($this->offered_groups($form), 'a course with no groups offers no picker');

        $notice = new awareness(0, (object) [
            'title' => 'Briefing',
            'content' => '<p>Body</p>',
            'courseid' => (int) $course->id,
            'filtervalues' => json_encode(['filter_groups' => [(int) $red->id]]),
        ]);
        $notice->create();
        $form = new notice_form(null, ['persistent' => $notice, 'id' => (int) $notice->get('id'), 'scope' => $scope]);
        $this->assertSame($every, $this->offered_groups($form), 'a notice naming groups keeps its picker');
    }

    /**
     * The competency picker is handed the course context, from which only 'parents' reaches a framework.
     *
     * Frameworks live at the system or a category context, never at a course, so a listing that
     * walked 'children' from the course context would be empty on every site, always — which is what
     * a first cut of this did, silently. The module asks for 'parents' from a course; this pins the
     * pairing on the server side: the same seeded framework is found from the course context one way
     * and not the other.
     */
    public function test_the_picker_s_course_context_reaches_a_framework_only_through_its_parents(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/awareness/editnotice.php'));
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->get_plugin_generator('core_competency')->create_framework();

        $scope = author_scope::course((int) $course->id);
        $html = (new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => $scope]))->render();
        $context = \context_course::instance($course->id);
        $this->assertStringContainsString(
            'data-contextid="' . $context->id . '"',
            $html,
            'the picker is handed the course context'
        );

        $parents = \core_competency\api::list_frameworks('shortname', 'ASC', 0, 0, $context, 'parents', true);
        $children = \core_competency\api::list_frameworks('shortname', 'ASC', 0, 0, $context, 'children', true);
        $this->assertNotEmpty($parents, 'walking up from the course reaches the framework');
        $this->assertEmpty($children, 'walking down from the course reaches nothing, on any site');
    }
}
