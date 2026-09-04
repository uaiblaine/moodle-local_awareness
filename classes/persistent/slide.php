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
 * One slide of a carousel notice.
 *
 * A slide shows one media - an image uploaded into the slidemedia file area, keyed by the slide's
 * own id, or an external video link the multimedia filter turns into a player - and a plain-text
 * caption under it. The rows are the author's structured answer to "what are the slides": they are
 * validated when the notice is saved, so a carousel cannot reach a reader half-formed.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slide extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_awareness_slides';

    /** The file area holding a slide's image; the item id is the slide id, never the notice id. */
    public const FILEAREA = 'slidemedia';

    /** A slide that shows an uploaded image. */
    public const MEDIA_IMAGE = 'image';

    /** A slide that shows a video built from its link. */
    public const MEDIA_VIDEO = 'video';

    /** A slide with a caption and nothing else. */
    public const MEDIA_TEXT = 'text';

    /**
     * Returns a list of properties.
     *
     * @return array[]
     */
    protected static function define_properties() {
        return [
            'noticeid' => [
                'type' => PARAM_INT,
            ],
            'sortorder' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'videourl' => [
                'type' => PARAM_URL,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'caption' => [
                'type' => PARAM_TEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
        ];
    }

    /**
     * The slides of a notice, in display order.
     *
     * @param int $noticeid The notice id.
     * @return slide[] Indexed from 0, in sortorder then id.
     */
    public static function for_notice(int $noticeid): array {
        return array_values(static::get_records(['noticeid' => $noticeid], 'sortorder, id'));
    }

    /**
     * The image uploaded for this slide, if any.
     *
     * @return \stored_file|null
     */
    public function get_image(): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_awareness',
            self::FILEAREA,
            (int) $this->get('id'),
            'id',
            false
        );

        return $files ? reset($files) : null;
    }

    /**
     * What this slide shows.
     *
     * An image wins over a link, which the form does not allow to coexist; the order here only
     * decides what a row written by another path renders as.
     *
     * @return string One of the MEDIA_* constants.
     */
    public function get_mediatype(): string {
        if ($this->get_image() !== null) {
            return self::MEDIA_IMAGE;
        }
        if (trim((string) $this->get('videourl')) !== '') {
            return self::MEDIA_VIDEO;
        }

        return self::MEDIA_TEXT;
    }

    /**
     * Delete every slide of a notice, files first.
     *
     * Each slide's image lives under the slide's own id, so the files have to go while the rows
     * still say which ids exist; deleting the rows first would leave the files unreachable and
     * undeletable at the same time.
     *
     * @param int $noticeid The notice id.
     * @return void
     */
    public static function delete_for_notice(int $noticeid): void {
        $fs = get_file_storage();
        foreach (static::for_notice($noticeid) as $slide) {
            $fs->delete_area_files(
                \context_system::instance()->id,
                'local_awareness',
                self::FILEAREA,
                (int) $slide->get('id')
            );
            $slide->delete();
        }
    }
}
