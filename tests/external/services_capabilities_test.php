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

namespace local_awareness\external;

/**
 * The capabilities each web service declares are the ones its gate accepts.
 *
 * The declaration is documentation only (the web service admin screens read it; nothing enforces
 * it), so a stale one misleads silently. The editor services accept the site capability or its
 * course counterpart, and the list's preview either verb in either scope, through
 * helper::require_author().
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class services_capabilities_test extends \basic_testcase {
    /**
     * Each service's declared capabilities, as core splits them.
     *
     * @return array Function name => capability names; an empty list when none is declared.
     */
    private function declared(): array {
        $functions = [];
        require(dirname(__DIR__, 2) . '/db/services.php');

        $declared = [];
        foreach ($functions as $name => $definition) {
            $list = array_map('trim', explode(',', $definition['capabilities'] ?? ''));
            $declared[$name] = array_values(array_filter($list, 'strlen'));
        }

        return $declared;
    }

    /**
     * Every declared capability exists, and each service declares what its gate accepts.
     */
    public function test_each_service_declares_what_its_gate_accepts(): void {
        $capabilities = [];
        require(dirname(__DIR__, 2) . '/db/access.php');
        $declared = $this->declared();
        $this->assertGreaterThan(5, count($declared), 'implausibly few services read');

        foreach ($declared as $name => $list) {
            foreach ($list as $capability) {
                $this->assertArrayHasKey($capability, $capabilities, "{$name} declares an unknown capability");
            }
        }

        $editor = ['local/awareness:manage', 'local/awareness:managecourse'];
        $services = ['check_collision', 'search_roles', 'search_courses', 'estimate_audience', 'get_estimate', 'preview_notice'];
        foreach ($services as $service) {
            $this->assertSame($editor, $declared['local_awareness_' . $service], $service);
        }
        $this->assertSame(
            [
                'local/awareness:manage',
                'local/awareness:managecourse',
                'local/awareness:viewreports',
                'local/awareness:viewreportscourse',
            ],
            $declared['local_awareness_render_notice']
        );

        // The reader's services declare none: the control that a missing declaration parses as an empty list.
        foreach (['dismiss', 'acknowledge', 'tracklink', 'getnotices'] as $service) {
            $this->assertSame([], $declared['local_awareness_' . $service], $service);
        }
    }
}
