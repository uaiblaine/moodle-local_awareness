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

use renderable;
use templatable;
use renderer_base;

/**
 * Renderable for the manage page wrapper.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_page implements renderable, templatable {

    /** @var string */
    protected $tablehtml;

    /** @var string */
    protected $createurl;

    public function __construct(string $tablehtml, string $createurl) {
        $this->tablehtml = $tablehtml;
        $this->createurl = $createurl;
    }

    public function export_for_template(renderer_base $output) {
        return [
            'pagetitle' => get_string('setting:managenotice', 'local_awareness'),
            'subtitle' => get_string('editor:subtitle', 'local_awareness'),
            'createurl' => $this->createurl,
            'createlabel' => get_string('notice:create', 'local_awareness'),
            'topbar' => [
                'brand' => 'Local Awareness',
                'breadcrumbs' => [
                    ['label' => get_string('administrationsite')],
                    ['label' => get_string('pluginname', 'local_awareness'), 'current' => true],
                ],
            ],
            'tablehtml' => $this->tablehtml,
        ];
    }
}
