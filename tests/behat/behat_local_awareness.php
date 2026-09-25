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
 * Steps definitions related to local_awareness.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use local_awareness\persistent\awareness;
use local_awareness\persistent\slide;

/**
 * Site notice step definitions.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_awareness extends behat_base {
    /**
     * Creates new notices.
     *
     * @Given the following site notices exist
     * @param TableNode $noticedata The notices to be created.
     */
    public function the_following_site_notices_exist(TableNode $noticedata) {
        global $DB;

        // Create each notice from the table row.
        foreach ($noticedata->getHash() as $noticeinfo) {
            $now = time();
            $noticeinfo['cohorts'] = $noticeinfo['cohorts'] ?? 0;
            $noticeinfo['reqack'] = $noticeinfo['reqack'] ?? 0;
            $noticeinfo['enabled'] = $noticeinfo['enabled'] ?? 1;
            $noticeinfo['resetinterval'] = $noticeinfo['resetinterval'] ?? 0;
            $noticeinfo['usermodified'] = $noticeinfo['usermodified'] ?? 2;
            $noticeinfo['timecreated'] = $noticeinfo['timecreated'] ?? $now;
            $noticeinfo['timemodified'] = $noticeinfo['timemodified'] ?? $now;
            $noticeinfo['timestart'] = $noticeinfo['timestart'] ?? 0;
            $noticeinfo['timeend'] = $noticeinfo['timeend'] ?? 0;
            $noticeinfo['forcelogout'] = $noticeinfo['forcelogout'] ?? 0;

            // A scenario names a course by shortname; the row carries its id. An empty cell is the site.
            if (!empty($noticeinfo['course'])) {
                $noticeinfo['courseid'] = $DB->get_field('course', 'id', ['shortname' => $noticeinfo['course']], MUST_EXIST);
            }
            unset($noticeinfo['course']);
            $noticeinfo['courseid'] = $noticeinfo['courseid'] ?? 0;

            /*
             * A scenario may say `insistence` and mean the level an author would choose, rather
             * than spell out the two columns it is stored in. Mapped as helper::sanitise_data()
             * maps it, through the same constants: a step body runs after config.php, so the
             * plugin's classes autoload here.
             */
            if (isset($noticeinfo['insistence'])) {
                $level = (int) $noticeinfo['insistence'];
                $noticeinfo['reqack'] = $level >= awareness::INSISTENCE_ACKNOWLEDGE ? 1 : 0;
                $noticeinfo['outsideclick'] = $level >= awareness::INSISTENCE_BLOCKING ? 0 : 1;
                unset($noticeinfo['insistence']);
            }
            $noticeinfo['outsideclick'] = $noticeinfo['outsideclick'] ?? 1;

            // An image column names a placeholder file for the notice's picture, keyed by the notice id.
            $image = $noticeinfo['bgimage'] ?? '';
            $noticeinfo['bgimage'] = $image !== '' ? 1 : 0;
            $noticeid = $DB->insert_record('local_awareness', $noticeinfo);
            if ($image !== '') {
                get_file_storage()->create_file_from_string([
                    'contextid' => \context_system::instance()->id,
                    'component' => 'local_awareness',
                    'filearea' => 'bgimage',
                    'itemid' => $noticeid,
                    'filepath' => '/',
                    'filename' => $image,
                ], base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
                ));
            }
        }

        /*
         * Inserted straight into the table, so the persistent's after_create() never runs and the
         * enabled-notices cache is never invalidated. The cache keeps an empty result too, so a
         * notice created this way would stay invisible until something purged it.
         */
        \cache::make('local_awareness', 'enabled_notices')->purge();
    }

    /**
     * Creates carousel slides for notices that already exist, named by title.
     *
     * The image column names a placeholder file written into the slide's own area
     * (slide::FILEAREA), keyed by the slide id as the plugin keys it.
     *
     * @Given the following site notice slides exist
     * @param TableNode $slidedata notice (a title), sortorder, videourl, caption, image.
     */
    public function the_following_site_notice_slides_exist(TableNode $slidedata): void {
        global $DB;

        foreach ($slidedata->getHash() as $row) {
            $noticeid = $DB->get_field('local_awareness', 'id', ['title' => $row['notice']], MUST_EXIST);
            $now = time();
            $slideid = $DB->insert_record('local_awareness_slides', (object) [
                'noticeid' => $noticeid,
                'sortorder' => (int) ($row['sortorder'] ?? 0),
                'videourl' => ($row['videourl'] ?? '') !== '' ? $row['videourl'] : null,
                'caption' => ($row['caption'] ?? '') !== '' ? $row['caption'] : null,
                'usermodified' => 2,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            if (!empty($row['image'])) {
                get_file_storage()->create_file_from_string([
                    'contextid' => \context_system::instance()->id,
                    'component' => 'local_awareness',
                    'filearea' => slide::FILEAREA,
                    'itemid' => $slideid,
                    'filepath' => '/',
                    'filename' => $row['image'],
                ], 'not really an image');
            }
        }
    }

    /**
     * Delete a notice behind an open page, the way another administrator would.
     *
     * @Given the site notice :title has been deleted
     * @param string $title The notice's title.
     */
    public function the_site_notice_has_been_deleted(string $title): void {
        global $DB;

        $DB->delete_records('local_awareness', ['title' => $title]);
    }

    /**
     * Checks the notice module was queued into the current page.
     *
     * "I should see" on the modal text proves display; this proves delivery. Its negative twin
     * below pins the page probe in the footer hook: a page where no notice could appear must not
     * load the module, and so never fires its AJAX call.
     *
     * @Then the awareness notice module should be loaded
     * @throws \Behat\Mink\Exception\ExpectationException When the module is absent.
     */
    public function the_awareness_notice_module_should_be_loaded() {
        // The trailing quote keeps 'local_awareness/notice_editor' and friends from matching.
        if (strpos($this->getSession()->getPage()->getContent(), "local_awareness/notice'") === false) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The local_awareness/notice module was expected on this page but was not loaded.',
                $this->getSession()
            );
        }
    }

    /**
     * Checks the notice module was not even queued into the current page.
     *
     * @Then the awareness notice module should not be loaded
     * @throws \Behat\Mink\Exception\ExpectationException When the module is present.
     */
    public function the_awareness_notice_module_should_not_be_loaded() {
        // The trailing quote keeps 'local_awareness/notice_editor' and friends from matching.
        if (strpos($this->getSession()->getPage()->getContent(), "local_awareness/notice'") !== false) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The local_awareness/notice module was loaded on a page where nothing could appear.',
                $this->getSession()
            );
        }
    }
}
