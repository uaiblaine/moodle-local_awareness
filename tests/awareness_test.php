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

namespace local_awareness;

use local_awareness\persistent\awareness;
use local_awareness\persistent\linkhistory;
use local_awareness\persistent\noticelink;

/**
 * Test cases
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class awareness_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test notice creation.
     *
     * @covers \local_awareness\helper::create_new_notice
     *
     * @dataProvider create_notices_provider
     * @param array $formdata Array of form data to create notices
     * @param bool $allowdeletion Whether or not to allow deletion of notices
     * @param bool $cleanup Whether or not to clean up extra link data after notice deletion
     * @param array $expected Array of expected testcase results
     */
    public function test_create_notices(array $formdata, bool $allowdeletion, bool $cleanup, array $expected): void {
        $this->setAdminUser();
        set_config('allow_delete', $allowdeletion, 'local_awareness');
        set_config('cleanup_deleted_notice', $cleanup, 'local_awareness');

        /*
         * No cohort branch here. There was one, assigning a BARE id where the four sibling loops in
         * this file all assign [id] — and create_notices_provider has never supplied a 'cohorts'
         * key, so it never ran. Dead code that disagreed with its own neighbours about the shape of
         * the value is worse than no code: the day the provider gained a cohort case it would have
         * written a scalar into a field the persistent stores as a list, and the four loops that do
         * cover the array shape would still have been green.
         */
        foreach ($formdata as $data) {
            helper::create_new_notice($data);
        }

        $allnotices = array_values(awareness::get_enabled_notices());
        $this->assertEquals($expected['noticecount'], count($allnotices));

        foreach ($allnotices as $noticeindex => $notice) {
            $this->assertEquals($expected['titles'][$noticeindex], $notice->get('title'));

            $allinks = noticelink::get_notice_link_records($notice->get('id'));
            $this->assertEquals($expected['linkcounts'][$noticeindex], count($allinks));
            $this->assertStringContainsString('data-linkid', $notice->get('content'));
            $this->assertEquals($expected['linktexts'][$noticeindex], array_column($allinks, 'text'));
            $this->assertEquals($expected['linkurls'][$noticeindex], array_column($allinks, 'link'));
        }

        $idtodelete = $allnotices[0]->get('id');
        helper::delete_notice($allnotices[0]);
        $allnotices = awareness::get_enabled_notices();
        $this->assertEquals($expected['noticecount'] - (int)$allowdeletion, count($allnotices));

        $allinks = noticelink::get_notice_link_records($idtodelete);
        $this->assertEquals($cleanup ? 0 : $expected['linkcounts'][0], count($allinks));
    }

    /**
     * A site with no cohorts must not end up storing the multi-select marker as an audience.
     *
     * The cohorts autocomplete posts a hidden '_qf__force_multiselect_submission' value so an
     * empty selection still submits. Core strips it in HTML_QuickForm_select::exportValue(), but
     * only inside its `!empty($this->_options)` branch — with no cohorts on the site the option
     * list is empty and the marker survives. Stored as a cohort it matches nobody, so every
     * notice created through the form became invisible to every user.
     *
     * @covers \local_awareness\helper::create_new_notice
     */
    public function test_the_multiselect_marker_is_never_stored_as_a_cohort(): void {
        global $USER;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $data = new \stdClass();
        $data->title = 'Notice with no audience';
        $data->content = '<p>Everyone should see this.</p>';
        $data->cohorts = ['_qf__force_multiselect_submission'];
        helper::create_new_notice($data);

        $notices = array_values(awareness::get_enabled_notices());
        $this->assertCount(1, $notices);
        $this->assertSame([], $notices[0]->get('cohorts'));

        // The notice must actually reach a user with no cohort membership at all.
        $this->setUser($user);
        unset($USER->viewednotices);
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));
    }

    /**
     * Control for the test above: a real cohort selection still restricts the audience.
     *
     * Without this, the assertion that the notice reaches a cohort-less user would also pass if
     * cohort filtering had been removed altogether.
     *
     * @covers \local_awareness\helper::create_new_notice
     */
    public function test_a_real_cohort_selection_still_restricts_the_audience(): void {
        global $USER;

        $this->setAdminUser();
        $cohort = $this->getDataGenerator()->create_cohort();
        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $member->id);

        $data = new \stdClass();
        $data->title = 'Cohort only';
        $data->content = '<p>Members only.</p>';
        $data->cohorts = [$cohort->id];
        helper::create_new_notice($data);

        $this->setUser($member);
        unset($USER->viewednotices);
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));

        $this->setUser($outsider);
        unset($USER->viewednotices);
        $this->assertCount(0, helper::retrieve_user_notices('/my/'));
    }

    /**
     * Test set reset notice.
     *
     * @covers \local_awareness\helper::reset_notice
     *
     * @dataProvider generic_provider
     * @param array $formdata Array of form data to create notices
     */
    public function test_reset_notices(array $formdata): void {
        $this->setAdminUser();

        foreach ($formdata as $data) {
            if (property_exists($data, 'cohorts')) {
                $data->cohorts = [$this->getDataGenerator()->create_cohort()->id];
            }
            helper::create_new_notice($data);
        }

        $allnotices = awareness::get_enabled_notices();
        $this->assertEquals(4, count($allnotices));
        $oldnotice1 = array_shift($allnotices);
        $oldnotice2 = array_shift($allnotices);
        // Only reset Notice 1.
        sleep(1);
        helper::reset_notice($oldnotice1);
        $allnotices = awareness::get_enabled_notices();
        $newnotice1 = array_shift($allnotices);
        $newnotice2 = array_shift($allnotices);
        $this->assertEquals("Notice 1", $newnotice1->get('title'));
        $this->assertGreaterThan($oldnotice1->get('timemodified'), $newnotice1->get('timemodified'));
        $this->assertEquals($oldnotice1->get('timecreated'), $newnotice1->get('timecreated'));
        $this->assertEquals($newnotice2->get('timemodified'), $oldnotice2->get('timemodified'));
        $this->assertEquals($newnotice2->get('timecreated'), $oldnotice2->get('timecreated'));
    }

    /**
     * Test enable/disable notice.
     *
     * @covers \local_awareness\helper::enable_notice
     * @covers \local_awareness\helper::disable_notice
     *
     * @dataProvider generic_provider
     * @param array $formdata Array of form data to create notices
     */
    public function test_enable_notices(array $formdata): void {
        $this->setAdminUser();

        foreach ($formdata as $data) {
            if (property_exists($data, 'cohorts')) {
                $data->cohorts = [$this->getDataGenerator()->create_cohort()->id];
            }
            helper::create_new_notice($data);
        }

        $allnotices = awareness::get_enabled_notices();
        $notice1 = array_shift($allnotices);
        $this->assertEquals("Notice 1", $notice1->get('title'));

        // Only disable Notice 1.
        helper::disable_notice($notice1);
        $allnotices = awareness::get_enabled_notices();
        $this->assertEquals(3, count($allnotices));
        $notice2 = array_shift($allnotices);
        $this->assertEquals("Notice 2", $notice2->get('title'));

        // Enable Notice 1, disable Notice 2.
        helper::enable_notice($notice1);
        helper::disable_notice($notice2);
        $allnotices = awareness::get_enabled_notices();
        $this->assertEquals(3, count($allnotices));
        $notice1 = array_shift($allnotices);
        $this->assertEquals("Notice 1", $notice1->get('title'));
    }

    /**
     * Test user notice interaction.
     *
     * @covers \local_awareness\helper::dismiss_notice
     * @covers \local_awareness\helper::acknowledge_notice
     * @covers \local_awareness\helper::reset_notice
     *
     * @dataProvider generic_provider
     * @param array $formdata Data to test on.
     */
    public function test_user_notice($formdata): void {
        global $USER;

        $this->setAdminUser();
        foreach ($formdata as $data) {
            if (property_exists($data, 'cohorts')) {
                $data->cohorts = [$this->getDataGenerator()->create_cohort()->id];
            }
            helper::create_new_notice($data);
        }

        $user1 = $this->getDataGenerator()->create_user();
        $allnotices = awareness::get_enabled_notices();
        $this->assertEquals(4, count($allnotices));
        $notice1 = array_shift($allnotices);
        $notice2 = array_shift($allnotices);
        $cohortnotice1 = array_shift($allnotices);
        $cohortnotice2 = array_shift($allnotices);

        // Only notice 1 and notice 2 are applied to user 1.
        $this->setUser($user1);
        $usernotices = helper::retrieve_user_notices('/my/');
        $this->assertEquals(2, count($usernotices));

        $this->setAdminUser();
        helper::disable_notice($notice2);

        // Only Notice 1 applied to user 1.
        $this->setUser($user1);
        $usernotices = helper::retrieve_user_notices('/my/');
        $this->assertEquals(1, count($usernotices));
        $notice = reset($usernotices);
        $this->assertEquals('Notice 1', $notice->get('title'));

        $cohorts1 = $cohortnotice1->get('cohorts');
        $cohorts2 = $cohortnotice2->get('cohorts');

        // Add user 1 to cohorts of cohort notice 1 and cohort notice 2, there will be 3 notices for the user.
        cohort_add_member(reset($cohorts1), $user1->id);
        cohort_add_member(reset($cohorts2), $user1->id);
        $usernotices = helper::retrieve_user_notices('/my/');
        $this->assertEquals(3, count($usernotices));

        // User 1 dismissed notice 1, there will be 2 notices for the user.
        helper::dismiss_notice($notice1);
        $usernotices = helper::retrieve_user_notices('/my/');
        $this->assertEquals(2, count($usernotices));
        $this->assertEquals(1, count($USER->viewednotices));

        // User 1 acknowledged notice 1, there will be 1 notice for the user.
        helper::acknowledge_notice($cohortnotice1);
        $usernotices = helper::retrieve_user_notices('/my/');
        $this->assertEquals(1, count($usernotices));
        $this->assertEquals(2, count($USER->viewednotices));

        // Admin user reset notice 1.
        sleep(1);
        $this->setAdminUser();
        helper::reset_notice($notice1);

        // There will be 2 notices for user 1.
        $this->setUser($user1);
        $usernotices = helper::retrieve_user_notices('/my/');
        $this->assertEquals(2, count($usernotices));
        $this->assertEquals(1, count($USER->viewednotices));
    }

    /**
     * Test user link interaction
     *
     * @covers \local_awareness\helper::track_link
     *
     * @dataProvider generic_provider
     * @param array $formdata Data to test on.
     */
    public function test_user_hlink_interact($formdata): void {
        $this->setAdminUser();
        foreach ($formdata as $data) {
            if (property_exists($data, 'cohorts')) {
                $data->cohorts = [$this->getDataGenerator()->create_cohort()->id];
            }
            helper::create_new_notice($data);
        }

        $user1 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $allnotices = awareness::get_enabled_notices();
        $this->assertEquals(4, count($allnotices));
        $notice1 = array_shift($allnotices);

        $links = noticelink::get_notice_link_records($notice1->get('id'));
        $this->assertEquals(2, count($links));
        $link1 = array_shift($links);
        $link2 = array_shift($links);

        /*
         * Delivery through the real read path, because track_link() now requires it. The site
         * switch is off by default, so turning it on is a precondition rather than the subject.
         *
         * select_for_display() hands over the HEAD of the queue, and with four applicable notices
         * that head is not guaranteed to be the one this test picked with array_shift(). It is
         * today, and the assertion below is what makes that a checked fact instead of a lucky one:
         * change the queue order and this fails loudly rather than quietly clicking links on a
         * notice nobody delivered.
         */
        set_config('enabled', 1, 'local_awareness');
        \local_awareness\external\get_notices::execute('/my/');
        $this->assertTrue(
            helper::was_notice_delivered($notice1),
            'the queue served a different notice, so this test would be clicking links on an undelivered one'
        );

        // Clink on links.
        helper::track_link($link1->id);
        helper::track_link($link2->id);
        $userlinks = linkhistory::count_clicked_links($user1->id, $notice1->get('id'));
        $this->assertEquals(2, count($userlinks));
    }


    /**
     * Test course completion option.
     *
     * @covers \local_awareness\helper::retrieve_user_notices
     */
    public function test_user_required_completion(): void {
        global $DB;
        $this->setAdminUser();

        $formdata = new \stdClass();
        $formdata->title = "Course Notice 1";
        $formdata->content = "Course Notice 1 <a href=\"www.examplecourse1.com\">Link Course 1</a> " .
            "<a href=\"www.examplecourse2.com\">Link Course 2</a>";

        // Create a course with completion enabled.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Finish creating the notice.
        $formdata->reqcourse = $course->id;
        helper::create_new_notice($formdata);

        // Enrol a user in the course.
        $user = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentrole->id);

        // Add two activities that use completion.
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id],
            ['completion' => 1]
        );
        $data = $this->getDataGenerator()->create_module(
            'data',
            ['course' => $course->id],
            ['completion' => 1]
        );

        // Now retrieve all the user notices.
        $this->setUser($user);
        $usernotices = helper::retrieve_user_notices('/my/');
        // There is only one notice.
        $this->assertEquals(1, count($usernotices));
        $this->assertEquals("Course Notice 1", reset($usernotices)->get('title'));

        // Mark one of them as completed for a user.
        $cmassign = get_coursemodule_from_id('assign', $assign->cmid);
        $completion = new \completion_info($course);
        $completion->update_state($cmassign, COMPLETION_COMPLETE, $user->id);

        // Now retrieve all the user notices.
        $usernotices = helper::retrieve_user_notices('/my/');
        // There should still be one notice.
        $this->assertEquals(1, count($usernotices));

        // Now, mark the course as completed.
        $ccompletion = new \completion_completion(['course' => $course->id, 'userid' => $user->id]);
        $ccompletion->mark_complete();

        // Now retrieve all the user notices.
        $usernotices = helper::retrieve_user_notices('/my/');
        // There should not be any user notices.
        $this->assertEquals(0, count($usernotices));
    }

    /**
     * Test user see required notice after dismissing it.
     *
     * @covers \local_awareness\helper::retrieve_user_notices
     */
    public function test_retrieve_user_notices_when_dismissed_one_that_requires_acknowledgement(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $formdata = (object)[
            'title' => 'Notice 1',
            'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
            'perpetual' => 1,
            'reqack' => 1,
        ];

        helper::create_new_notice($formdata);
        $allnotices = awareness::get_all_notices();
        $notice = array_shift($allnotices);

        // Must see 1 notice.
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));

        // After notice is dismissed, should still see 1 as it's required.
        $this->setAdminUser();
        helper::dismiss_notice($notice);
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));
    }

    /**
     * Test user see required notice after dismissing, and then acknowledged it.
     *
     * @covers \local_awareness\helper::retrieve_user_notices
     */
    public function test_retrieve_user_notices_when_dismiss_and_then_acknowledged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $formdata = (object)[
            'title' => 'Notice 1',
            'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
            'perpetual' => 1,
            'reqack' => 1,
        ];

        helper::create_new_notice($formdata);
        $allnotices = awareness::get_all_notices();
        $notice = array_shift($allnotices);

        // Must see 1 notice.
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));

        // After notice is dismissed, should still see 1 as it's required.
        helper::dismiss_notice($notice);
        // User should be logged out after dismissing.
        $this->setAdminUser();
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));

        // After notice is acknowledged, should still see 0.
        helper::acknowledge_notice($notice);
        $this->assertCount(0, helper::retrieve_user_notices('/my/'));

        // Logout user and log in again. Still shouldn't require to see the notice.
        $this->setUser();
        $this->setAdminUser();
        $this->assertCount(0, helper::retrieve_user_notices('/my/'));
    }

    /**
     * Test user see required notice when forcelogout logout.
     *
     * @covers \local_awareness\helper::retrieve_user_notices
     */
    public function test_retrieve_user_notices_when_force_logout(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $formdata = (object)[
            'title' => 'Notice 1',
            'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
            'perpetual' => 1,
            'reqack' => 0,
            'forcelogout' => 1,
        ];

        helper::create_new_notice($formdata);
        $allnotices = awareness::get_all_notices();
        $notice = array_shift($allnotices);

        // Admin must see 1 notice.
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));
        helper::dismiss_notice($notice);
        // Admin shouldn't be logged out.
        $this->assertNotEmpty($USER->username);
        // After notice is dismissed, admin shouldb't see it anymore.
        $this->assertCount(0, helper::retrieve_user_notices('/my/'));

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // After notice is dismissed, should still see 1 as it's required.
        helper::dismiss_notice($notice);
        // User should be logged out.
        $this->assertTrue(!isset($USER->username));

        // Login again and check we still see the notice.
        $this->setUser($user);
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));
    }

    /**
     * Generic data provider to set up multiple tests.
     *
     * @return array
     */
    public static function generic_provider(): array {
        return [
            'formdata' => [
                [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 2',
                        'content' => 'Notice 2 <a href="www.example3.com">Link 3</a> <a href="www.example4.com">Link 4</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Cohort Notice 1',
                        'content' => 'Cohort Notice 1 <a href="www.example5.com">Link 5</a> <a href="www.example6.com">Link 6</a>',
                        'cohorts' => '',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Cohort Notice 2',
                        'content' => 'Cohort Notice 2 <a href="www.example7.com">Link 7</a> <a href="www.example8.com">Link 8</a>',
                        'cohorts' => '',
                        'perpetual' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * Data provider for test_create_notices
     *
     * @return array
     */
    public static function create_notices_provider(): array {
        return [
            'one basic notice with deletion not allowed' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                ],
                'allowdeletion' => false,
                'cleanup' => false,
                'expected' => [
                    'noticecount' => 1,
                    'titles' => ['Notice 1'],
                    'linkcounts' => [2],
                    'linktexts' => [['Link 1', 'Link 2']],
                    'linkurls' => [['www.example1.com', 'www.example2.com']],
                ],
            ],
            'two basic notices with deletion not allowed' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 2',
                        'content' => 'Notice 2 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 4</a>',
                        'perpetual' => 1,
                    ],
                ],
                'allowdeletion' => false,
                'cleanup' => false,
                'expected' => [
                    'noticecount' => 2,
                    'titles' => ['Notice 1', 'Notice 2'],
                    'linkcounts' => [2, 2],
                    'linktexts' => [['Link 1', 'Link 2'], ['Link 1', 'Link 4']],
                    'linkurls' => [['www.example1.com', 'www.example2.com'], ['www.example1.com', 'www.example2.com']],
                ],
            ],
            'two basic notices and one notice with expiry in the future' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 2',
                        'content' => 'Notice 2 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 4</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 3',
                        'content' => 'Notice 3 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 4</a>',
                        'perpetual' => 0,
                        'timestart' => time() + HOURSECS,
                        'timeend' => time() + DAYSECS,
                    ],
                ],
                'allowdeletion' => false,
                'cleanup' => false,
                'expected' => [
                    'noticecount' => 3,
                    'titles' => ['Notice 1', 'Notice 2', 'Notice 3'],
                    'linkcounts' => [2, 2, 2],
                    'linktexts' => [['Link 1', 'Link 2'], ['Link 1', 'Link 4'], ['Link 1', 'Link 4']],
                    'linkurls' => [
                        ['www.example1.com', 'www.example2.com'],
                        ['www.example1.com', 'www.example2.com'],
                        ['www.example1.com', 'www.example2.com'],
                    ],
                ],
            ],
            'two basic notices and one notice with expiry in the past' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 2',
                        'content' => 'Notice 2 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 4</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 3',
                        'content' => 'Notice 3 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 4</a>',
                        'perpetual' => 0,
                        'timestart' => time() - DAYSECS,
                        'timeend' => time() - HOURSECS,
                    ],
                ],
                'allowdeletion' => false,
                'cleanup' => false,
                'expected' => [
                    'noticecount' => 2,
                    'titles' => ['Notice 1', 'Notice 2'],
                    'linkcounts' => [2, 2],
                    'linktexts' => [['Link 1', 'Link 2'], ['Link 1', 'Link 4']],
                    'linkurls' => [['www.example1.com', 'www.example2.com'], ['www.example1.com', 'www.example2.com']],
                ],
            ],
            'one basic notice with deletion allowed and cleanup disabled' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                ],
                'allowdeletion' => true,
                'cleanup' => false,
                'expected' => [
                    'noticecount' => 1,
                    'titles' => ['Notice 1'],
                    'linkcounts' => [2],
                    'linktexts' => [['Link 1', 'Link 2']],
                    'linkurls' => [['www.example1.com', 'www.example2.com']],
                ],
            ],
            'two basic notices with deletion allowed and cleanup disabled' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 2',
                        'content' => 'Notice 2 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 4</a>',
                        'perpetual' => 1,
                    ],
                ],
                'allowdeletion' => true,
                'cleanup' => false,
                'expected' => [
                    'noticecount' => 2,
                    'titles' => ['Notice 1', 'Notice 2'],
                    'linkcounts' => [2, 2],
                    'linktexts' => [['Link 1', 'Link 2'], ['Link 1', 'Link 4']],
                    'linkurls' => [['www.example1.com', 'www.example2.com'], ['www.example1.com', 'www.example2.com']],
                ],
            ],
            'one basic notice with deletion allowed and cleanup enabled' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                ],
                'allowdeletion' => true,
                'cleanup' => true,
                'expected' => [
                    'noticecount' => 1,
                    'titles' => ['Notice 1'],
                    'linkcounts' => [2],
                    'linktexts' => [['Link 1', 'Link 2']],
                    'linkurls' => [['www.example1.com', 'www.example2.com']],
                ],
            ],
            'two basic notices with deletion allowed and cleanup enabled' => [
                'formdata' => [
                    (object)[
                        'title' => 'Notice 1',
                        'content' => 'Notice 1 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 2</a>',
                        'perpetual' => 1,
                    ],
                    (object)[
                        'title' => 'Notice 2',
                        'content' => 'Notice 2 <a href="www.example1.com">Link 1</a> <a href="www.example2.com">Link 4</a>',
                        'perpetual' => 1,
                    ],
                ],
                'allowdeletion' => true,
                'cleanup' => true,
                'expected' => [
                    'noticecount' => 2,
                    'titles' => ['Notice 1', 'Notice 2'],
                    'linkcounts' => [2, 2],
                    'linktexts' => [['Link 1', 'Link 2'], ['Link 1', 'Link 4']],
                    'linkurls' => [['www.example1.com', 'www.example2.com'], ['www.example1.com', 'www.example2.com']],
                ],
            ],
        ];
    }
}
