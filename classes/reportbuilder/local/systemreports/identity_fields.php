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

declare(strict_types=1);

namespace local_awareness\reportbuilder\local\systemreports;

use context;
use core_reportbuilder\local\entities\user;

/**
 * The identity fields of the users a system report's rows name, as core's own system reports add them.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait identity_fields {
    /**
     * Join each row's live account and return the identity columns and filters the viewer may see.
     *
     * The fields are core's own choice in the given context, custom profile fields included
     * ({@see \core_reportbuilder\local\entities\user::get_identity_columns()}, which reads
     * {@see \core_user\fields::get_identity_fields()}): showuseridentity lists them,
     * moodle/site:viewuseridentity admits them, hiddenuserfields withholds one unless the viewer
     * holds moodle/course:viewhiddenuserfields (in a course) or moodle/user:viewhiddendetails, and
     * profile_field_base::is_visible() decides each custom field. A filter is offered only beside
     * its column, since a filter on a withheld field would let its value be probed.
     *
     * They come from a second instance of core's user entity, named 'identity', whose join skips a
     * deleted account. Core keeps a deleted or privacy-pseudonymised user's row with deleted = 1
     * and, after delete_user(), its old email inside the username and its department, institution,
     * phone and address in place; through this join such a row's identity columns are empty and its
     * filters match on nothing it held, while the 'user' entity still names the user.
     *
     * @param string $useridfield SQL of the row's user id.
     * @param context $context The context the fields are decided in: the notice's own scope.
     * @return array Two lists, the columns and then the filters, in the order showuseridentity lists them.
     */
    private function join_identity(string $useridfield, context $context): array {
        $entity = (new user())->set_entity_name('identity');
        $alias = $entity->get_table_alias('user');
        $this->add_entity($entity->add_join(
            "LEFT JOIN {user} {$alias} ON {$alias}.id = {$useridfield} AND {$alias}.deleted = 0"
        ));

        return [$entity->get_identity_columns($context), $entity->get_identity_filters($context)];
    }
}
