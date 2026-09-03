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

            /*
             * A scenario may say `insistence` and mean the level an author would choose, rather
             * than spell out the two columns it is stored in. The mapping is the same one
             * helper::sanitise_data() applies to the form, and it is written out rather than
             * shared because this file is loaded by Behat BEFORE config.php, so it cannot reach
             * the plugin's classes. Keep the two in step.
             */
            if (isset($noticeinfo['insistence'])) {
                $level = (int) $noticeinfo['insistence'];
                $noticeinfo['reqack'] = $level >= 2 ? 1 : 0;
                $noticeinfo['outsideclick'] = $level >= 1 ? 0 : 1;
                unset($noticeinfo['insistence']);
            }
            $noticeinfo['outsideclick'] = $noticeinfo['outsideclick'] ?? 1;

            $DB->insert_record('local_awareness', $noticeinfo);
        }

        /*
         * Inserted straight into the table, so the persistent's after_create() never runs and the
         * enabled-notices cache is never invalidated. That was harmless while an empty cached result
         * was re-read on every call — the cache healed itself by being broken. Now that an empty
         * result is honoured, a notice created this way stays invisible until something purges.
         */
        \cache::make('local_awareness', 'enabled_notices')->purge();
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
     * below is what pins the footer-hook redesign: a page where no notice could appear must not
     * merely show nothing, it must not have loaded the module — or fired its AJAX call — at all.
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
