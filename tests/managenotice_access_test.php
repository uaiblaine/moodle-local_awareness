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

use admin_externalpage;
use local_awareness\local\author_scope;

/**
 * Who Site administration lets into the notice list.
 *
 * managenotice.php opens the list to either verb, but in site mode admin_externalpage_setup() runs
 * first and throws for anyone the page registered in settings.php does not admit. settings.php
 * runs inside admin_get_root(), so the tree is rebuilt as each user.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class managenotice_access_test extends \advanced_testcase {
    /**
     * A user holding one capability at the site, through a role of their own.
     *
     * @param string|null $capability The capability, or null for a user holding none.
     * @return \stdClass The user.
     */
    private function user_with(?string $capability): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        if ($capability !== null) {
            $roleid = $this->getDataGenerator()->create_role();
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            role_assign($roleid, (int) $user->id, \context_system::instance()->id);
        }

        return $user;
    }

    /**
     * The list's admin page, as the tree is built for the current user.
     *
     * @return admin_externalpage
     */
    private function page(): admin_externalpage {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $page = admin_get_root(true, true)->locate('local_awareness_managenotice', true);
        $this->assertInstanceOf(admin_externalpage::class, $page);

        return $page;
    }

    /**
     * Either capability opens the list; a user with neither is the control that the page gates at all.
     */
    public function test_either_capability_opens_the_site_list(): void {
        $this->resetAfterTest();

        $this->setUser($this->user_with('local/awareness:viewreports'));
        $this->assertTrue($this->page()->check_access(), 'a reports reader opens the list');
        // What the list offers is still decided by the manage verb, which this user does not hold.
        $this->assertFalse(helper::require_author(author_scope::site(), 'manage', false));

        $this->setUser($this->user_with('local/awareness:manage'));
        $this->assertTrue($this->page()->check_access(), 'an author opens the list');

        $this->setUser($this->user_with(null));
        $this->assertFalse($this->page()->check_access(), 'a user with neither is refused');
    }
}
