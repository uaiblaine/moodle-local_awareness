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
 * Persistent for asynchronous audience-estimate jobs.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience_job extends persistent {
    /** Table name. */
    const TABLE = 'local_awareness_audience_jobs';

    /** Status: enqueued, awaiting worker. */
    const STATUS_PENDING = 'pending';
    /** Status: completed successfully. */
    const STATUS_READY = 'ready';
    /** Status: completed with an error. */
    const STATUS_ERROR = 'error';

    /** Window (seconds) in which a completed job with the same criteria hash may be reused. */
    const DEDUP_WINDOW = 300;

    /**
     * Returns a list of properties.
     *
     * @return array[]
     */
    protected static function define_properties() {
        return [
            'jobid' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'userid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'criteriahash' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'criteria' => [
                'type' => PARAM_RAW,
                'null' => NULL_NOT_ALLOWED,
            ],
            'status' => [
                'type' => PARAM_ALPHA,
                'null' => NULL_NOT_ALLOWED,
                'default' => self::STATUS_PENDING,
                'choices' => [self::STATUS_PENDING, self::STATUS_READY, self::STATUS_ERROR],
            ],
            'resultcount' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'breakdown' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'errormsg' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'timecompleted' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
        ];
    }

    /**
     * Find a recently-completed job for the same criteria hash that can be reused.
     *
     * @param string $criteriahash
     * @return self|false
     */
    public static function find_reusable(string $criteriahash) {
        $mints = time() - self::DEDUP_WINDOW;
        $records = self::get_records_select(
            'criteriahash = :hash AND status = :status AND timecompleted IS NOT NULL AND timecompleted >= :mints',
            ['hash' => $criteriahash, 'status' => self::STATUS_READY, 'mints' => $mints],
            'timecompleted DESC',
            '*',
            0,
            1
        );
        return reset($records) ?: false;
    }

    /**
     * Generate a UUID v4 string suitable for jobid.
     *
     * @return string
     */
    public static function new_jobid(): string {
        $bytes = random_bytes(16);
        // Per RFC 4122 v4.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
