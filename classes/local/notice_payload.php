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

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_awareness\helper;
use local_awareness\persistent\awareness;

/**
 * What the dialogue is told about one notice: the one builder, and the one declaration of it.
 *
 * Three web services hand a notice to the dialogue - the reader's queue, the manage list's preview
 * and the editor's preview - and clean_returnvalue() strips whatever a returns declaration does
 * not name. Keeping the builder and the declaration side by side is what stops a field added to
 * one from being silently dropped by another; it is not a class a web service extends, because
 * core registers one external_api class per function.
 *
 * Only what the modal reads crosses the boundary. The record used to be serialised whole, which
 * shipped the notice's segmentation metadata and its author's id to every reader; the builder is
 * the allowlist, and the declaration is what core enforces it with.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notice_payload {
    /**
     * The payload of one saved notice, rendered for the current user.
     *
     * Storage holds the content as authored; filters and pluginfile URLs are resolved here, so a
     * multilang notice reads in each user's own language. The media the layout draws from is
     * rendered here too, because the multimedia filter runs on the server; a layout that does not
     * read a field gets it empty, so the client never paints a background the author could not see.
     *
     * @param awareness $notice The notice.
     * @return array As structure() declares.
     */
    public static function build(awareness $notice): array {
        $record = $notice->to_record();
        $context = \context_system::instance();

        $payload = [];
        foreach (['id', 'modal_width', 'modal_height', 'template', 'position', 'animation'] as $field) {
            $payload[$field] = $record->$field;
        }
        // One level rather than the columns it is derived from; see awareness::get_insistence().
        $payload['insistence'] = $notice->get_insistence();
        $payload['content'] = helper::render_content($notice);
        // The title gets the same treatment as the content, or a multilang title shows its markup.
        $payload['title'] = format_string($record->title, true, ['context' => $context]);
        $payload['bgimageurl'] = !empty($record->bgimage) && awareness::uses_bgimage($record->template)
            ? helper::get_bgimage_url((int) $record->id)
            : '';
        $payload['videohtml'] = awareness::uses_video($record->template) ? helper::render_video($notice) : '';
        $payload['slides'] = $record->template === 'carousel' ? helper::render_slides($notice) : [];

        return $payload;
    }

    /**
     * The declaration every service handing a notice to the dialogue returns.
     *
     * The prose fields are PARAM_RAW on purpose: title and content carry rendered HTML that has to
     * reach the client byte for byte, the width and height are PARAM_RAW in the persistent, and a
     * PARAM_TEXT field whose cleaned value differs from the original THROWS, killing the whole
     * response for every reader rather than dropping one field. The allowlist is the key set; the
     * types are only what can safely be said about each value.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Notice id; 0 for an unsaved preview'),
            'title' => new external_value(PARAM_RAW, 'Title, already through format_string()'),
            'content' => new external_value(PARAM_RAW, 'Body, filters and pluginfile URLs resolved'),
            'insistence' => new external_value(PARAM_INT, 'Insistence level; see awareness::INSISTENCE_*'),
            'modal_width' => new external_value(PARAM_RAW, 'Author-set modal width, or empty'),
            'modal_height' => new external_value(PARAM_RAW, 'Author-set modal height, or empty'),
            'bgimageurl' => new external_value(PARAM_URL, 'Background image URL, or empty'),
            'template' => new external_value(PARAM_ALPHA, 'Dialogue layout; see awareness::TEMPLATES'),
            'position' => new external_value(PARAM_ALPHAEXT, 'Position on the screen; see awareness::POSITIONS'),
            'animation' => new external_value(PARAM_ALPHA, 'Entrance animation; see awareness::ANIMATIONS'),
            // PARAM_RAW: the multimedia filter's output, byte for byte.
            'videohtml' => new external_value(PARAM_RAW, 'The video layout\'s player, rendered; empty otherwise'),
            'slides' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_ALPHA, 'image, video or text'),
                    'html' => new external_value(PARAM_RAW, 'The slide\'s media, rendered; empty for a text slide'),
                    // PARAM_RAW rather than PARAM_TEXT: a caption holding a bare "<" would otherwise
                    // throw and take every reader's whole response with it.
                    'caption' => new external_value(PARAM_RAW, 'Plain-text caption, unescaped'),
                ]),
                'The carousel\'s slides, in order; empty for every other layout'
            ),
        ]);
    }
}
