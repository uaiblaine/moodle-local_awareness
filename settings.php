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
 * Settings.
 * @package local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('root', new admin_category('awareness', new lang_string('pluginname', 'local_awareness')));

if ($hassiteconfig) {
    $temp = new admin_settingpage(
        'awarenesssettings',
        new lang_string('setting:settings', 'local_awareness')
    );

    $temp->add(
        new admin_setting_configcheckbox(
            'local_awareness/enabled',
            new lang_string('setting:enabled', 'local_awareness'),
            new lang_string('setting:enableddesc', 'local_awareness'),
            0
        )
    );

    $temp->add(
        new admin_setting_configcheckbox(
            'local_awareness/allow_update',
            new lang_string('setting:allow_update', 'local_awareness'),
            new lang_string('setting:allow_updatedesc', 'local_awareness'),
            0
        )
    );

    $temp->add(
        new admin_setting_configcheckbox(
            'local_awareness/allow_delete',
            new lang_string('setting:allow_delete', 'local_awareness'),
            new lang_string('setting:allow_deletedesc', 'local_awareness'),
            0
        )
    );

    $temp->add(
        new admin_setting_configcheckbox(
            'local_awareness/cleanup_deleted_notice',
            new lang_string('setting:cleanup_deleted_notice', 'local_awareness'),
            new lang_string('setting:cleanup_deleted_noticedesc', 'local_awareness'),
            0
        )
    );

    $ADMIN->add('awareness', $temp);
    $settings = null;
}

$managenotice = new admin_externalpage(
    'local_awareness_managenotice',
    get_string('setting:managenotice', 'local_awareness', null, true),
    new moodle_url('/local/awareness/managenotice.php'),
    'local/awareness:manage'
);

$ADMIN->add('awareness', $managenotice);
