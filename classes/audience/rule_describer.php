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

namespace local_awareness\audience;

/**
 * Turns a rule's stored values into the text the editor's chips show.
 *
 * The chips used to render the raw criteria — "Course category: 4" — which is the one thing the
 * author cannot check against the form they just filled in. Resolving happens here rather than in
 * {@see estimator}, which stays free of presentation, and at READ time rather than when the job is
 * computed: a job is reused across users by criteria hash, so a label baked into the stored result
 * would hand the next reader the first reader's language.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_describer {
    /** Names listed in full before collapsing to a count. */
    private const MAX_NAMES = 3;

    /**
     * Describe one rule's values.
     *
     * @param string $key The criteria key.
     * @param mixed $values The stored value for that key — a list of ids, a list of strings, or a scalar.
     * @return string Display text, empty when the rule carries nothing worth naming.
     */
    public static function describe(string $key, $values): string {
        if ($values === null || $values === '' || $values === []) {
            return '';
        }

        switch ($key) {
            case 'pathmatch':
                return (string) $values;
            case 'filter_category':
                return self::join(self::category_names(self::ids($values)));
            case 'filter_course':
                return self::join(self::course_names(self::ids($values)));
            case 'filter_format':
                return self::join(self::plugin_names('format', (array) $values));
            case 'filter_theme':
                return self::join(self::plugin_names('theme', (array) $values));
            default:
                return '';
        }
    }

    /**
     * Coerce a stored list into positive integer ids.
     *
     * @param mixed $values
     * @return int[]
     */
    private static function ids($values): array {
        $ids = [];
        foreach ((array) $values as $value) {
            $value = (int) $value;
            if ($value > 0) {
                $ids[] = $value;
            }
        }
        return $ids;
    }

    /**
     * Join names for display, collapsing a long list to the first few plus a remainder count.
     *
     * @param array $names
     * @return string
     */
    private static function join(array $names): string {
        if (empty($names)) {
            return '';
        }
        if (count($names) <= self::MAX_NAMES) {
            return implode(', ', $names);
        }

        $shown = array_slice($names, 0, self::MAX_NAMES);
        return get_string(
            'audience:rule:andmore',
            'local_awareness',
            (object) ['names' => implode(', ', $shown), 'count' => count($names) - self::MAX_NAMES]
        );
    }

    /**
     * Resolve category ids to their formatted names.
     *
     * @param array $ids
     * @return string[]
     */
    private static function category_names(array $ids): array {
        global $DB;

        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cat');
        $records = $DB->get_records_select('course_categories', "id {$insql}", $params, 'name ASC', 'id, name');

        $names = [];
        foreach ($records as $record) {
            $names[] = format_string($record->name, true, ['context' => \context_system::instance()]);
        }
        return $names;
    }

    /**
     * Resolve course ids to their formatted full names.
     *
     * @param array $ids
     * @return string[]
     */
    private static function course_names(array $ids): array {
        global $DB;

        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'crs');
        $records = $DB->get_records_select('course', "id {$insql}", $params, 'fullname ASC', 'id, fullname');

        $names = [];
        foreach ($records as $record) {
            $names[] = format_string(
                $record->fullname,
                true,
                ['context' => \context_course::instance($record->id, IGNORE_MISSING) ?: \context_system::instance()]
            );
        }
        return $names;
    }

    /**
     * Resolve plugin directory names to their human-readable plugin names.
     *
     * A format or theme can be uninstalled while a notice still names it, so a missing string falls
     * back to the stored value rather than dropping the rule from the chip entirely — the author
     * needs to see that the notice is pinned to something no longer present.
     *
     * @param string $type Plugin type, e.g. "format" or "theme".
     * @param array $values Stored plugin directory names.
     * @return string[]
     */
    private static function plugin_names(string $type, array $values): array {
        $names = [];
        foreach ($values as $value) {
            $value = clean_param((string) $value, PARAM_PLUGIN);
            if ($value === '') {
                continue;
            }
            $component = $type . '_' . $value;
            $names[] = get_string_manager()->string_exists('pluginname', $component)
                ? get_string('pluginname', $component)
                : $value;
        }
        sort($names, SORT_STRING);
        return $names;
    }
}
