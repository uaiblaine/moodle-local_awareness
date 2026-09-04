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

namespace local_awareness\output;

use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\local\collision;
use local_awareness\persistent\awareness;
use local_awareness\table\all_notices_filterset;
use renderable;
use renderer_base;
use templatable;

/**
 * Renderable for the manage page.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_page implements renderable, templatable {
    /** @var string */
    protected $tablehtml;

    /** @var string */
    protected $createurl;

    /** @var array Current filter values, keyed by filter name. */
    protected $filtervalues;

    /**
     * Constructor.
     *
     * @param string $tablehtml Rendered notices table HTML.
     * @param string $createurl URL of the create-notice page.
     * @param array $filtervalues Current filter values, keyed by filter name.
     */
    /** @var author_scope The scope the list is for. */
    protected $scope;

    /**
     * Constructor.
     *
     * @param string $tablehtml The rendered table.
     * @param string $createurl Where the create button goes.
     * @param array $filtervalues The current filter values.
     * @param author_scope|null $scope The scope the list is for; the site when not given.
     */
    public function __construct(string $tablehtml, string $createurl, array $filtervalues = [], ?author_scope $scope = null) {
        $this->tablehtml = $tablehtml;
        $this->createurl = $createurl;
        $this->filtervalues = $filtervalues;
        $this->scope = $scope ?? author_scope::site();
    }

    /**
     * Site-wide counts for the strip above the table.
     *
     * One statement with conditional aggregates rather than a query per tile. These describe the
     * SITE and not the filtered list: the dynamic-table web service returns table HTML and nothing
     * else, so filtered totals would need a web service of the plugin's own, and the result count
     * under the filter bar already answers "how many matched".
     *
     * @return array List of {label, value, accent} rows.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    protected function stats(): array {
        global $DB;

        // The population the numbers describe is the list's: a course page counts that course only.
        $where = $this->scope->is_site() ? '' : ' WHERE courseid = :courseid';
        $params = $this->scope->is_site() ? [] : ['courseid' => $this->scope->get_courseid()];
        $sql = 'SELECT COUNT(1) AS total,
                       SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS live,
                       SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) AS draft,
                       SUM(COALESCE(audiencecount, 0)) AS reach
                  FROM {' . awareness::TABLE . '}' . $where;
        $row = $DB->get_record_sql($sql, $params);

        /* Counts come back as strings under both drivers, so they are cast before formatting. */
        return [
            [
                'label' => get_string('manage:stat:live', 'local_awareness'),
                'value' => number_format((int) ($row->live ?? 0)),
                'accent' => false,
            ],
            [
                'label' => get_string('manage:stat:draft', 'local_awareness'),
                'value' => number_format((int) ($row->draft ?? 0)),
                'accent' => false,
            ],
            [
                'label' => get_string('manage:stat:reach', 'local_awareness'),
                'value' => number_format((int) ($row->reach ?? 0)),
                'accent' => true,
            ],
            [
                'label' => get_string('manage:stat:clash', 'local_awareness'),
                'value' => number_format(count(collision::clashing_ids($this->scope))),
                'accent' => false,
            ],
        ];
    }

    /**
     * Options for one of the select filters, with the current value pre-selected.
     *
     * @param string $name Filter name, used to read the current value.
     * @param string $alllabel Label of the "no restriction" option.
     * @param array $values Filter value => language string key.
     * @return array List of {value, label, selected} rows.
     * @throws \coding_exception
     */
    protected function options(string $name, string $alllabel, array $values): array {
        $current = (string) ($this->filtervalues[$name] ?? '');

        $options = [[
            'value' => '',
            'label' => $alllabel,
            'selected' => $current === '',
        ]];
        foreach ($values as $value => $key) {
            $options[] = [
                'value' => $value,
                'label' => get_string($key, 'local_awareness'),
                'selected' => $current === $value,
            ];
        }

        return $options;
    }

    /**
     * Export the manage page data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function export_for_template(renderer_base $output) {
        return [
            'lede' => get_string($this->scope->is_site() ? 'manage:lede' : 'manage:lede:course', 'local_awareness'),
            // Only an author is offered a create button; a reports-only viewer reads the list.
            'createurl' => helper::require_author($this->scope, 'manage', false) ? $this->createurl : '',
            'createlabel' => get_string('notice:create', 'local_awareness'),
            'stats' => $this->stats(),
            'filters' => [
                'namelabel' => get_string('manage:filter:name', 'local_awareness'),
                'nameplaceholder' => get_string('manage:filter:nameplaceholder', 'local_awareness'),
                'namevalue' => (string) ($this->filtervalues['name'] ?? ''),
                'statuslabel' => get_string('notice:status', 'local_awareness'),
                'statusoptions' => $this->options(
                    'status',
                    get_string('manage:filter:status:all', 'local_awareness'),
                    [
                        all_notices_filterset::STATUS_LIVE => 'notice:status:live',
                        all_notices_filterset::STATUS_DRAFT => 'notice:status:draft',
                        all_notices_filterset::STATUS_CLASH => 'manage:filter:status:clash',
                    ]
                ),
                'validitylabel' => get_string('notice:validity', 'local_awareness'),
                'validityoptions' => $this->options(
                    'validity',
                    get_string('manage:filter:validity:all', 'local_awareness'),
                    [
                        all_notices_filterset::VALIDITY_PERMANENT => 'notice:validity:permanent',
                        all_notices_filterset::VALIDITY_CURRENT => 'notice:validity:current',
                        all_notices_filterset::VALIDITY_SCHEDULED => 'notice:validity:scheduled',
                        all_notices_filterset::VALIDITY_EXPIRED => 'notice:validity:expired',
                    ]
                ),
                'clearlabel' => get_string('manage:filter:clear', 'local_awareness'),
            ],
            'tablehtml' => $this->tablehtml,
        ];
    }
}
