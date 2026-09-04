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

use local_awareness\persistent\awareness;

/**
 * Which groups an author may target in a course, decided the way core decides it.
 *
 * Core has no course-level "who may address a group" helper; the nearest is
 * groups_get_activity_allowed_groups(), and this is that rule lifted to the course. In visible
 * groups mode, or with moodle/site:accessallgroups in the course, every participation group; in
 * separate groups mode without the capability, only the groups the author belongs to; with groups
 * off, nothing is offered, but nothing is refused either, because separation is what the mode
 * switches off. Participation is the flag core reads to decide which groups may be picked for an
 * activity, and it is read the same way here.
 *
 * Reaching and receiving are two questions, and this class answers only the first. Who RECEIVES a
 * notice is membership, checked by the delivery path against {groups_members} whatever the group's
 * visibility, because a member of a hidden group is still a member. Who may TARGET a group, and act
 * on a notice that targets it, is decided here, and it is the whole of what "separate groups" means
 * for a notice: a teacher of one group neither sees nor edits a notice aimed only at another, and a
 * manager who can access all groups does both.
 *
 * The site scope has no course and so no groups: applies() is false and every list is empty.
 * author_scope's rule table forbids the field at the site for the same reason.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class group_scope {
    /** The key the notice's filtervalues carry the targeted groups under. */
    public const FIELD = 'filter_groups';

    /** @var int The course, or 0 for the site scope. */
    private int $courseid;

    /** @var int The user whose reach this is. */
    private int $userid;

    /** @var array|null Memo of the groups the user may target, id => group row. */
    private ?array $groups = null;

    /**
     * Constructor. Use for_author().
     *
     * @param int $courseid The course, or 0 for the site.
     * @param int $userid The user.
     */
    private function __construct(int $courseid, int $userid) {
        $this->courseid = $courseid;
        $this->userid = $userid;
    }

    /**
     * The reach of the current user writing under a scope.
     *
     * Always the current user, and it cannot honestly be anyone else: groups_get_all_groups()
     * applies the group visibility rules for whoever is logged in, so asking it about another
     * person's groups answers through the asker's own eyes. The capability half would take a user
     * id; the group list would not, and half an answer is worse here than none.
     *
     * @param author_scope $scope The scope the author writes under.
     * @return self
     */
    public static function for_author(author_scope $scope): self {
        global $USER;

        return new self($scope->get_courseid(), (int) $USER->id);
    }

    /**
     * The groups a stored notice targets, as ids; empty when it targets everyone in its course.
     *
     * Read here rather than by each caller, so the one JSON key is spelt in one place. A malformed
     * payload reads as no targeting, exactly as check_filters() treats the other rules.
     *
     * @param awareness $notice The notice.
     * @return int[]
     */
    public static function targeted(awareness $notice): array {
        return self::decode($notice->get('filtervalues'));
    }

    /**
     * The groups a stored filtervalues payload names, as ids.
     *
     * @param string|null $filtervalues The JSON column as stored.
     * @return int[]
     */
    public static function decode(?string $filtervalues): array {
        $filters = json_decode((string) $filtervalues, true);
        if (!is_array($filters) || empty($filters[self::FIELD]) || !is_array($filters[self::FIELD])) {
            return [];
        }
        $ids = array_filter(array_map('intval', $filters[self::FIELD]), static function (int $id): bool {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    /**
     * Whether groups mean anything under this scope: only a course has them.
     *
     * @return bool
     */
    public function applies(): bool {
        return $this->courseid > SITEID;
    }

    /**
     * The course's group mode: NOGROUPS, SEPARATEGROUPS or VISIBLEGROUPS.
     *
     * @return int NOGROUPS for the site scope.
     */
    public function groupmode(): int {
        if (!$this->applies()) {
            return NOGROUPS;
        }

        return (int) get_course($this->courseid)->groupmode;
    }

    /**
     * Whether the user is confined to their own groups.
     *
     * Separate groups mode, and no moodle/site:accessallgroups in the course: the same pair of
     * facts groups_get_activity_allowed_groups() reads. Visible groups separate nobody for this
     * purpose, and groups switched off separate nobody at all.
     *
     * @return bool
     */
    public function is_restricted(): bool {
        if (!$this->applies() || $this->groupmode() !== SEPARATEGROUPS) {
            return false;
        }

        return !has_capability('moodle/site:accessallgroups', \context_course::instance($this->courseid), $this->userid);
    }

    /**
     * Whether a form should offer the group picker at all.
     *
     * Groups in use, and at least one the user may target. The core group menus disappear from a
     * course page in the same two cases.
     *
     * @return bool
     */
    public function offered(): bool {
        return $this->applies() && $this->groupmode() !== NOGROUPS && $this->allowed_ids() !== [];
    }

    /**
     * The groups the user may target, as ids.
     *
     * @return int[]
     */
    public function allowed_ids(): array {
        return array_keys($this->groups());
    }

    /**
     * The groups the user may target, as id => name, for a picker.
     *
     * @return array
     */
    public function options(): array {
        $context = $this->applies() ? \context_course::instance($this->courseid) : \context_system::instance();
        $options = [];
        foreach ($this->groups() as $id => $group) {
            $options[$id] = format_string($group->name, true, ['context' => $context]);
        }

        return $options;
    }

    /**
     * Whether the separation of groups keeps the user away from a notice aimed at these.
     *
     * Two questions live in this class and they are not the same one. narrow() answers what an
     * author may SAVE: their own participation groups, the set the picker offers. This answers who
     * may REACH what is already saved, and only separate groups keep anyone away — visible groups,
     * the accessallgroups capability, groups switched off and the site scope all confine nobody,
     * so they admit everything.
     *
     * A group that no longer exists is skipped rather than refused. Otherwise deleting a group
     * would hide the notices naming it from every single person, the administrator included, and
     * the one row that needs fixing would be the one nobody could see — the same trap
     * author_scope::exists() exists to avoid for a deleted course. A notice naming a live group of
     * someone else's and a dead one is still refused, on the live one.
     *
     * @param int[] $groupids Group ids.
     * @return bool
     */
    public function admits(array $groupids): bool {
        if (!$this->is_restricted()) {
            return true;
        }
        $named = array_intersect(array_map('intval', $groupids), $this->course_group_ids());

        return array_diff($named, $this->allowed_ids()) === [];
    }

    /**
     * The given groups cut to the ones the user may target, in the order given.
     *
     * @param int[] $groupids Group ids.
     * @return int[]
     */
    public function narrow(array $groupids): array {
        return array_values(array_intersect(array_map('intval', $groupids), $this->allowed_ids()));
    }

    /**
     * Every group of the course, whatever its visibility or participation, as ids.
     *
     * Read straight from the table rather than through groups_get_all_groups(), because this
     * decides whether a stored id still names something and that answer may not depend on who is
     * asking or on what a group is for.
     *
     * @return int[]
     */
    private function course_group_ids(): array {
        global $DB;

        if (!$this->applies()) {
            return [];
        }

        return array_map('intval', $DB->get_fieldset_select('groups', 'id', 'courseid = ?', [$this->courseid]));
    }

    /**
     * The groups the user may target, read once.
     *
     * groups_get_all_groups() with a user id returns that user's groups, without one every group;
     * both honour the group visibility settings for the CURRENT user, which is right for a picker
     * and for a gate and would be wrong for delivery, which is why delivery never reads this class.
     * Participation only, as core's activity pickers.
     *
     * @return array id => group row.
     */
    private function groups(): array {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $this->groups = [];
        if ($this->applies()) {
            $member = $this->is_restricted() ? $this->userid : 0;
            foreach (groups_get_all_groups($this->courseid, $member, 0, 'g.*', false, true) as $group) {
                $this->groups[(int) $group->id] = $group;
            }
        }

        return $this->groups;
    }
}
