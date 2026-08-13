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

namespace local_awareness\persistent;

use core\persistent;

/**
 * Site notice class.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class awareness extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_awareness';

    /**
     * Returns a list of properties.
     *
     * @return array[]
     */
    protected static function define_properties() {
        return [
            'title' => [
                'type' => PARAM_RAW_TRIMMED,
                'null' => NULL_NOT_ALLOWED,
            ],
            'content' => [
                'type' => PARAM_RAW,
                'null' => NULL_NOT_ALLOWED,
            ],
            'contentformat' => [
                'type' => PARAM_INT,
                'default' => FORMAT_HTML,
            ],
            'cohorts' => [
                'type' => PARAM_RAW,
                'null' => NULL_NOT_ALLOWED,
                'default' => '',
            ],
            'reqack' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'reqcourse' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'forcelogout' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'timestart' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'timeend' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'enabled' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 1,
            ],
            'resetinterval' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'pathmatch' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'filtervalues' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'bgimage' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'modal_width' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'modal_height' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'outsideclick' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 1,
            ],
        ];
    }

    /**
     * Custom setter.
     *
     * @param array $value
     */
    protected function set_cohorts(array $value) {
        $this->raw_set('cohorts', implode(',', $value));
    }

    /**
     * Custom getter for building cohorts array.
     *
     * @return array
     */
    protected function get_cohorts(): array {
        if (!empty($this->raw_get('cohorts'))) {
            return explode(',', $this->raw_get('cohorts'));
        }

        return [];
    }

    /**
     * Get cache instance.
     *
     * @return \cache
     */
    protected static function get_enabled_notices_cache(): \cache {
        return \cache::make('local_awareness', 'enabled_notices');
    }

    /**
     * Purge related caches.
     */
    protected function purge_caches() {
        self::get_enabled_notices_cache()->purge();
        // Also purge the session-scoped user notices cache.
        \cache::make('local_awareness', 'user_notices')->purge();
    }

    /**
     * Run after update.
     *
     * @param bool $result Result of update.
     */
    protected function after_update($result) {
        if ($result) {
            self::purge_caches();
        }
    }

    /**
     * Run after created.
     */
    protected function after_create() {
        self::purge_caches();
    }

    /**
     * Run after deleted.
     *
     * @param bool $result Result of delete.
     */
    protected function after_delete($result) {
        if ($result) {
            self::purge_caches();
        }
    }

    /**
     * Get enabled notices.
     *
     * @return self[]
     */
    public static function get_enabled_notices(): array {
        /*
         * Compared against false, which is the only value the cache uses to say "I do not have
         * this". A falsy test treats a cached empty array as a miss, and a site with no notice
         * currently live then re-runs this query on every page load, for ever — which is the state
         * nearly every site is in nearly all of the time.
         */
        if (($result = self::get_enabled_notices_cache()->get('records')) === false) {
            $select = "enabled = ? AND ((timeend = 0 AND timestart = 0) OR (timeend <> 0 AND timestart <> 0 AND ? < timeend))";
            $result = self::get_records_select($select, [1, time()], 'id');
            self::get_enabled_notices_cache()->set('records', $result);
        }

        return $result;
    }

    /**
     * Get all notices
     *
     * @return \stdClass[]
     */
    public static function get_all_notices(): array {
        return self::get_records([], 'timemodified', 'DESC');
    }

    /**
     * Create new notice
     * @param \stdClass $data
     * @return persistent
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     */
    public static function create_new_notice(\stdClass $data) {
        self::normalise_cohorts($data);

        $persistent = new self(0, $data);
        return $persistent->create();
    }

    /**
     * Reduce the submitted cohort selection to a comma-separated list of cohort ids.
     *
     * The autocomplete element posts a hidden '_qf__force_multiselect_submission' marker so that
     * an empty multi-select still submits a value. Core drops it in
     * HTML_QuickForm_select::exportValue(), but only inside the `!empty($this->_options)` branch —
     * on a site with no cohorts the option list is empty, the value is returned unfiltered and the
     * marker reaches us as a literal. Stored as a cohort it matches no user, which hides the notice
     * from everyone. Casting to int discards it and any other non-id value.
     *
     * @param \stdClass $data Form data, modified in place.
     */
    private static function normalise_cohorts(\stdClass $data): void {
        $ids = $data->cohorts ?? [];
        if (is_string($ids)) {
            // Already stored form: a comma-separated list, as get_cohorts() returns it.
            $ids = explode(',', $ids);
        }
        $data->cohorts = implode(',', array_filter(array_map('intval', (array) $ids)));
    }

    /**
     * Update content of the notice
     * @param awareness $persistent site notice persistent object
     * @param string $content new content
     * @return bool
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     */
    public static function update_notice_content(awareness $persistent, string $content) {
        $persistent->set('content', $content);
        return $persistent->update();
    }

    /**
     * Update data of the notice
     * @param awareness $persistent site notice persistent object
     * @param \stdClass $data new data
     * @return bool
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     */
    public static function update_notice_data(awareness $persistent, \stdClass $data) {
        self::normalise_cohorts($data);

        $persistent->from_record($data);
        return $persistent->update();
    }
}
