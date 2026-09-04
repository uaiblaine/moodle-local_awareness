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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\local\notice_payload;
use local_awareness\persistent\awareness;

/**
 * The editor's preview: the notice as the form currently holds it, rendered as the reader would get it.
 *
 * The preview used to hand the editor's raw HTML to a dialogue on the client. That was merely
 * imprecise while a notice was text and images; with a video, a carousel and a layout it would be
 * misleading, because the multimedia filter runs on the server and the slides are rows the form
 * has not saved yet. So the form's fields travel here, are rendered exactly as notice_payload
 * renders a saved notice, and come back in the same shape - draft file URLs standing in for the
 * pluginfile URLs a save will mint. Nothing is written.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_notice extends external_api {
    /**
     * Incoming params: the form's fields, as it holds them.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'course the editor is scoped to, 0 for the site', VALUE_DEFAULT, 0),
            'title' => new external_value(PARAM_RAW, 'the title as typed', VALUE_DEFAULT, ''),
            'content' => new external_value(PARAM_RAW, 'the editor\'s HTML, draft file URLs included', VALUE_DEFAULT, ''),
            'template' => new external_value(PARAM_ALPHA, 'layout; see awareness::TEMPLATES', VALUE_DEFAULT, 'classic'),
            'position' => new external_value(PARAM_ALPHAEXT, 'position; see awareness::POSITIONS', VALUE_DEFAULT, 'center'),
            'animation' => new external_value(PARAM_ALPHA, 'entrance; see awareness::ANIMATIONS', VALUE_DEFAULT, 'none'),
            'insistence' => new external_value(PARAM_INT, 'insistence level', VALUE_DEFAULT, 0),
            'videourl' => new external_value(PARAM_RAW, 'the video link as typed', VALUE_DEFAULT, ''),
            'bgimagedraftid' => new external_value(PARAM_INT, 'the draft area holding the background image', VALUE_DEFAULT, 0),
            'modalwidth' => new external_value(PARAM_RAW, 'author-set width, or empty', VALUE_DEFAULT, ''),
            'modalheight' => new external_value(PARAM_RAW, 'author-set height, or empty', VALUE_DEFAULT, ''),
            'slides' => new external_multiple_structure(
                new external_single_structure([
                    'imagedraftid' => new external_value(PARAM_INT, 'the draft area holding the slide image', VALUE_DEFAULT, 0),
                    'videourl' => new external_value(PARAM_RAW, 'the slide\'s video link as typed', VALUE_DEFAULT, ''),
                    'caption' => new external_value(PARAM_RAW, 'the caption as typed', VALUE_DEFAULT, ''),
                ]),
                'the slide rows, in order',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Render the form's current state as a notice payload.
     *
     * A value outside a vocabulary is taken as the default rather than refused: the author is
     * mid-edit, and a preview that errors on a half-typed form is a preview that never opens. The
     * form's own validation refuses the same value on save.
     *
     * @param int $courseid The editor's scope.
     * @param string $title The title.
     * @param string $content The editor's HTML.
     * @param string $template The layout.
     * @param string $position The position.
     * @param string $animation The entrance.
     * @param int $insistence The insistence level.
     * @param string $videourl The video link.
     * @param int $bgimagedraftid The background image's draft area.
     * @param string $modalwidth The author-set width.
     * @param string $modalheight The author-set height.
     * @param array $slides The slide rows.
     * @return array As notice_payload::structure() declares.
     */
    public static function execute(
        int $courseid = 0,
        string $title = '',
        string $content = '',
        string $template = 'classic',
        string $position = 'center',
        string $animation = 'none',
        int $insistence = 0,
        string $videourl = '',
        int $bgimagedraftid = 0,
        string $modalwidth = '',
        string $modalheight = '',
        array $slides = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'title' => $title,
            'content' => $content,
            'template' => $template,
            'position' => $position,
            'animation' => $animation,
            'insistence' => $insistence,
            'videourl' => $videourl,
            'bgimagedraftid' => $bgimagedraftid,
            'modalwidth' => $modalwidth,
            'modalheight' => $modalheight,
            'slides' => $slides,
        ]);

        $scope = author_scope::for_request(null, (int) $params['courseid']);
        self::validate_context($scope->context());
        helper::require_author($scope, 'manage');

        $context = \context_system::instance();
        $template = in_array($params['template'], awareness::TEMPLATES, true) ? $params['template'] : awareness::TEMPLATES[0];
        $position = in_array($params['position'], awareness::positions_for($template), true)
            ? $params['position']
            : awareness::POSITIONS[0];
        $animation = in_array($params['animation'], awareness::ANIMATIONS, true) ? $params['animation'] : awareness::ANIMATIONS[0];
        $level = max(awareness::INSISTENCE_INFORMATIONAL, min(awareness::INSISTENCE_ACKNOWLEDGE, (int) $params['insistence']));

        return [
            'id' => 0,
            'title' => format_string($params['title'], true, ['context' => $context]),
            'content' => format_text($params['content'], FORMAT_HTML, ['noclean' => true, 'context' => $context]),
            'insistence' => awareness::accepts_acknowledgement($template) ? $level : min($level, awareness::INSISTENCE_BLOCKING),
            'modal_width' => $params['modalwidth'],
            'modal_height' => $params['modalheight'],
            'bgimageurl' => awareness::uses_bgimage($template) ? self::draft_file_url((int) $params['bgimagedraftid']) : '',
            'template' => $template,
            'position' => $position,
            'animation' => $animation,
            'videohtml' => awareness::uses_video($template) ? helper::render_media_link($params['videourl']) : '',
            'slides' => $template === 'carousel' ? self::preview_slides($params['slides']) : [],
        ];
    }

    /**
     * The URL of the first file in one of the current user's draft areas, or empty.
     *
     * A draft area belongs to the user who owns it - it lives in their user context - so the id
     * names nothing another user could have uploaded.
     *
     * @param int $draftid The draft item id.
     * @return string
     */
    private static function draft_file_url(int $draftid): string {
        global $USER;

        if ($draftid <= 0) {
            return '';
        }
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftid,
            'id',
            false
        );
        if (!$files) {
            return '';
        }
        $file = reset($files);

        return \moodle_url::make_draftfile_url($draftid, $file->get_filepath(), $file->get_filename())->out(false);
    }

    /**
     * The slide rows as the carousel would show them, from drafts rather than saved files.
     *
     * The same rules as the save path: an image wins over a link, a row with nothing on it is not
     * a slide.
     *
     * @param array $rows The submitted rows.
     * @return array[] As notice_payload::structure() declares slides.
     */
    private static function preview_slides(array $rows): array {
        $context = \context_system::instance();
        $slides = [];
        foreach ($rows as $row) {
            $caption = format_string((string) $row['caption'], true, ['context' => $context, 'escape' => false]);
            $url = self::draft_file_url((int) $row['imagedraftid']);
            $link = trim((string) $row['videourl']);
            if ($url !== '') {
                $slides[] = ['type' => 'image', 'html' => helper::render_image_slide($url, $caption), 'caption' => $caption];
            } else if ($link !== '') {
                $slides[] = ['type' => 'video', 'html' => helper::render_media_link($link), 'caption' => $caption];
            } else if ($caption !== '') {
                $slides[] = ['type' => 'text', 'html' => '', 'caption' => $caption];
            }
        }

        return $slides;
    }

    /**
     * Return parameters: the same shape the reader's queue receives.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return notice_payload::structure();
    }
}
