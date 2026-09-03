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

namespace local_awareness\local;

/**
 * What an author scope made of a set of submitted criteria.
 *
 * Two things, kept apart on purpose: the criteria as the scope left them, which are always safe
 * to store or count, and the fields the scope had to correct to get there. A caller with a human
 * in front of it turns the second into form errors; a caller that has none refuses the request.
 * Neither has to re-derive what happened from a diff of the two arrays.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scope_result {
    /** @var array The criteria as the scope left them, keyed by field name. */
    private array $criteria;

    /** @var array Field name => one of the author_scope::PROBLEM_* reasons, for every corrected field. */
    private array $problems;

    /**
     * Constructor.
     *
     * @param array $criteria The criteria as the scope left them.
     * @param array $problems Field name => problem reason, for every field the scope corrected.
     */
    public function __construct(array $criteria, array $problems) {
        $this->criteria = $criteria;
        $this->problems = $problems;
    }

    /**
     * The criteria as the scope left them.
     *
     * @return array
     */
    public function criteria(): array {
        return $this->criteria;
    }

    /**
     * The fields the scope had to correct, and why.
     *
     * @return array Field name => one of the author_scope::PROBLEM_* reasons.
     */
    public function problems(): array {
        return $this->problems;
    }

    /**
     * The names of the corrected fields, for a message that must not echo the values.
     *
     * @return string[]
     */
    public function problem_fields(): array {
        return array_keys($this->problems);
    }

    /**
     * Whether every submitted value was acceptable as it came.
     *
     * @return bool
     */
    public function is_clean(): bool {
        return empty($this->problems);
    }
}
