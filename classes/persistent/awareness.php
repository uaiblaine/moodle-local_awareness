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
use local_awareness\local\window;

/**
 * Site notice class.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class awareness extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_awareness';

    /** Insistence: the reader may dismiss the notice freely, including by clicking away from it. */
    const INSISTENCE_INFORMATIONAL = 0;

    /** Insistence: the reader must use a button, and a refusal brings the notice back. */
    const INSISTENCE_BLOCKING = 1;

    /** Insistence: blocking, and Accept is gated behind an explicit acknowledgement. */
    const INSISTENCE_ACKNOWLEDGE = 2;

    /**
     * Dialogue layouts an author may choose. The first is the column default, and it is what every
     * notice written before layouts existed renders as: the header, body and footer it always had.
     *
     * One source for the form, the web service, the JavaScript mirror and the tests; the columns
     * only hold the choice, and the persistent's `choices` gate is what keeps them inside it.
     */
    public const TEMPLATES = ['classic', 'hero', 'split', 'minimal', 'fullscreen', 'card', 'banner', 'image', 'video', 'carousel'];

    /** Positions on the screen. start/end are logical, so a corner flips under RTL. */
    public const POSITIONS = ['center', 'top', 'bottom', 'top-start', 'top-end', 'bottom-start', 'bottom-end'];

    /** The positions every layout accepts; the corners are for the card alone. */
    public const POSITIONS_EDGE = ['center', 'top', 'bottom'];

    /** The four corners, where only a card is small enough to sit. */
    public const POSITIONS_CORNER = ['top-start', 'top-end', 'bottom-start', 'bottom-end'];

    /** The two edges a strip can lie against; a banner is a strip, so it has no centre. */
    public const POSITIONS_STRIP = ['top', 'bottom'];

    /** Entrance animations. slide takes its direction from the position; all collapse under reduced motion. */
    public const ANIMATIONS = ['none', 'fade', 'slide', 'zoom', 'spring'];

    /**
     * Layouts narrower than, or shaped unlike, core's large dialogue.
     *
     * modal_notice.js takes modal-lg off for these, sizes them itself, and ignores the width and
     * height an author set; motion_contract_test pins its copy of this list against this one.
     */
    public const COMPACT = ['card', 'minimal', 'banner', 'image'];

    /**
     * Layouts that paint the uploaded image in the media band rather than as a cover behind the
     * whole dialogue: a band across the top, a panel beside the text, or the image alone.
     */
    public const BAND = ['hero', 'split', 'image'];

    /**
     * The positions a layout may take.
     *
     * A fullscreen dialogue covers the screen, so it has no position; a card is compact enough for
     * a corner; a banner is a strip and lies against the top or the bottom edge, never in the
     * middle of the page; everything else sits centred or against the top or bottom edge, where a
     * wide box still reads as one. The form offers these and extra_validation() refuses the rest,
     * because a value the CSS has no rule for renders as centred and the stored choice would then lie.
     *
     * @param string $template One of TEMPLATES.
     * @return string[] A subset of POSITIONS.
     */
    public static function positions_for(string $template): array {
        if ($template === 'fullscreen') {
            return ['center'];
        }
        if ($template === 'card') {
            return self::POSITIONS;
        }
        if ($template === 'banner') {
            return self::POSITIONS_STRIP;
        }

        return self::POSITIONS_EDGE;
    }

    /**
     * The insistence levels a layout can honour, in order.
     *
     * The footer is one shared block: the acknowledgement box beside the buttons. A card and a
     * minimal box are a single line of buttons with no room for the box, so neither can be Must
     * acknowledge. A banner is a strip and an image is the image alone: each shows a close and
     * nothing else, so each can only be Informational — a reader could neither accept nor be
     * blocked by a dialogue with no button to do it. The form refuses the rest with the reason.
     *
     * @param string $template One of TEMPLATES.
     * @return int[] The INSISTENCE_* values allowed, lowest first.
     */
    public static function insistence_levels_for(string $template): array {
        if (in_array($template, ['banner', 'image'], true)) {
            return [self::INSISTENCE_INFORMATIONAL];
        }
        if (in_array($template, ['card', 'minimal'], true)) {
            return [self::INSISTENCE_INFORMATIONAL, self::INSISTENCE_BLOCKING];
        }

        return [self::INSISTENCE_INFORMATIONAL, self::INSISTENCE_BLOCKING, self::INSISTENCE_ACKNOWLEDGE];
    }

    /**
     * Whether a layout can carry the acknowledgement checkbox.
     *
     * The highest level insistence_levels_for() allows: offering it to a layout whose footer has
     * no room for the box would render an insistence the reader cannot satisfy.
     *
     * @param string $template One of TEMPLATES.
     * @return bool
     */
    public static function accepts_acknowledgement(string $template): bool {
        return in_array(self::INSISTENCE_ACKNOWLEDGE, self::insistence_levels_for($template), true);
    }

    /**
     * Whether a layout is drawn from the dedicated video field rather than the content editor.
     *
     * @param string $template One of TEMPLATES.
     * @return bool
     */
    public static function uses_video(string $template): bool {
        return $template === 'video';
    }

    /**
     * Whether a layout shows the uploaded image.
     *
     * The video and carousel layouts fill their media band from the video field and the slides,
     * and a banner is one line of text with no room for a picture; a background behind any of
     * them would be two competing surfaces, so the field is hidden for them and a stored file is
     * ignored.
     *
     * @param string $template One of TEMPLATES.
     * @return bool
     */
    public static function uses_bgimage(string $template): bool {
        return !in_array($template, ['video', 'carousel', 'banner'], true);
    }

    /**
     * Whether a layout cannot render without the uploaded image.
     *
     * The image layout IS the image: with nothing uploaded there would be nothing to show but a
     * close button, so the form refuses to save it without one.
     *
     * @param string $template One of TEMPLATES.
     * @return bool
     */
    public static function requires_bgimage(string $template): bool {
        return $template === 'image';
    }

    /**
     * Whether a layout needs the notice's text.
     *
     * Every layout shows the content but the image layout, which shows the image alone; there the
     * text is optional, and what an author writes is read to a screen-reader user as the image's
     * description while staying invisible to everyone else.
     *
     * @param string $template One of TEMPLATES.
     * @return bool
     */
    public static function requires_content(string $template): bool {
        return $template !== 'image';
    }

    /**
     * How insistent this notice is, as one ordered value.
     *
     * Derived rather than stored, so there is no third copy of the truth to drift from the two
     * columns the display path has always read. The author sets one thing; these are how it lands:
     *
     *  - Informational  reqack = 0, outsideclick = 1
     *  - Blocking       reqack = 0, outsideclick = 0
     *  - Acknowledge    reqack = 1
     *
     * The fourth combination — reqack = 1 with outsideclick = 1 — is unreachable from the form but
     * may exist in data written before the settings were consolidated, or by a web service. It
     * reads as Acknowledge, because requiring an acknowledgement is the more insistent statement
     * and because that is already how the dialogue behaves: its block test has always been
     * `reqack || !outsideclick`.
     *
     * Ordered on purpose. Callers ask "is this at least Blocking", not "is this exactly Blocking",
     * so a level added above Acknowledge later does not silently fall out of those tests.
     *
     * @return int One of the INSISTENCE_* constants.
     */
    public function get_insistence(): int {
        if ((int) $this->get('reqack') === 1) {
            return self::INSISTENCE_ACKNOWLEDGE;
        }

        return (int) $this->get('outsideclick') === 0
            ? self::INSISTENCE_BLOCKING
            : self::INSISTENCE_INFORMATIONAL;
    }

    /**
     * Returns a list of properties.
     *
     * @return array[]
     */
    protected static function define_properties() {
        return [
            'courseid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'title' => [
                'type' => PARAM_RAW_TRIMMED,
                'null' => NULL_NOT_ALLOWED,
            ],
            'content' => [
                'type' => PARAM_RAW,
                'null' => NULL_NOT_ALLOWED,
            ],
            'contentformat' => [
                'type' => PARAM_INT,
                'default' => FORMAT_HTML,
            ],
            'cohorts' => [
                'type' => PARAM_RAW,
                'null' => NULL_NOT_ALLOWED,
                'default' => '',
            ],
            'reqack' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'reqcourse' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'forcelogout' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'timestart' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'timeend' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'enabled' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 1,
            ],
            'resetinterval' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'pathmatch' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'filtervalues' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'bgimage' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            'modal_width' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'modal_height' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            /*
             * The three choices are gated by `choices`, not by the PARAM type: PARAM_ALPHA and
             * PARAM_ALPHAEXT only say which characters may appear, and a misspelt layout is made
             * of perfectly legal letters. `choices` is the one server-side gate on every write path.
             */
            'template' => [
                'type' => PARAM_ALPHA,
                'null' => NULL_NOT_ALLOWED,
                'default' => 'classic',
                'choices' => self::TEMPLATES,
            ],
            'position' => [
                // PARAM_ALPHAEXT, because the corners carry a hyphen.
                'type' => PARAM_ALPHAEXT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 'center',
                'choices' => self::POSITIONS,
            ],
            'animation' => [
                'type' => PARAM_ALPHA,
                'null' => NULL_NOT_ALLOWED,
                'default' => 'none',
                'choices' => self::ANIMATIONS,
            ],
            /*
             * PARAM_URL accepts a scheme-less value, which the browser would then resolve relative
             * to the page; the form's extra_validation() is where http(s) is demanded.
             */
            'videourl' => [
                'type' => PARAM_URL,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'audiencecount' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'audiencecomputed' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'audiencehash' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'outsideclick' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 1,
            ],
        ];
    }

    /**
     * Custom setter.
     *
     * @param array $value
     */
    protected function set_cohorts(array $value) {
        $this->raw_set('cohorts', implode(',', $value));
    }

    /**
     * Custom getter for building cohorts array.
     *
     * @return array
     */
    protected function get_cohorts(): array {
        if (!empty($this->raw_get('cohorts'))) {
            return explode(',', $this->raw_get('cohorts'));
        }

        return [];
    }

    /**
     * Get cache instance.
     *
     * @return \cache
     */
    protected static function get_enabled_notices_cache(): \cache {
        return \cache::make('local_awareness', 'enabled_notices');
    }

    /**
     * Purge related caches.
     */
    protected function purge_caches() {
        self::get_enabled_notices_cache()->purge();
    }

    /**
     * Run after update.
     *
     * @param bool $result Result of update.
     */
    protected function after_update($result) {
        if ($result) {
            self::purge_caches();
        }
    }

    /**
     * Run after created.
     */
    protected function after_create() {
        self::purge_caches();
    }

    /**
     * Run after deleted.
     *
     * @param bool $result Result of delete.
     */
    protected function after_delete($result) {
        if ($result) {
            self::purge_caches();
        }
    }

    /**
     * Get enabled notices.
     *
     * @return self[]
     */
    public static function get_enabled_notices(): array {
        /*
         * Compared against false, which is the only value the cache uses to say "I do not have
         * this". A falsy test treats a cached empty array as a miss, and a site with no notice
         * currently live then re-runs this query on every page load, for ever — which is the state
         * nearly every site is in nearly all of the time.
         */
        if (($result = self::get_enabled_notices_cache()->get('records')) === false) {
            /*
             * The window's LOWER bound is deliberately absent. This result is cached with no TTL
             * and purged only by a write, so a condition that turns TRUE as the clock moves would
             * leave a scheduled notice permanently outside the cached set — it would never appear
             * at all. local\window explains it in full; helper::is_within_active_window() applies
             * the lower bound against a live clock on what comes back.
             */
            [$windowsql, $windowparams] = window::open_prefilter_sql('win', time());
            $select = "enabled = :enabled AND {$windowsql}";
            $result = self::get_records_select($select, ['enabled' => 1] + $windowparams, 'id');
            self::get_enabled_notices_cache()->set('records', $result);
        }

        return $result;
    }

    /**
     * Get all notices
     *
     * @return \stdClass[]
     */
    public static function get_all_notices(): array {
        return self::get_records([], 'timemodified', 'DESC');
    }

    /**
     * Create new notice
     * @param \stdClass $data
     * @return persistent
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     */
    public static function create_new_notice(\stdClass $data) {
        self::normalise_cohorts($data);

        $persistent = new self(0, $data);
        return $persistent->create();
    }

    /**
     * Reduce the submitted cohort selection to a comma-separated list of cohort ids.
     *
     * The autocomplete element posts a hidden '_qf__force_multiselect_submission' marker so that
     * an empty multi-select still submits a value. Core drops it in
     * HTML_QuickForm_select::exportValue(), but only inside the `!empty($this->_options)` branch —
     * on a site with no cohorts the option list is empty, the value is returned unfiltered and the
     * marker reaches us as a literal. Stored as a cohort it matches no user, which hides the notice
     * from everyone. Casting to int discards it and any other non-id value.
     *
     * @param \stdClass $data Form data, modified in place.
     */
    private static function normalise_cohorts(\stdClass $data): void {
        $ids = $data->cohorts ?? [];
        if (is_string($ids)) {
            // Already stored form: a comma-separated list, as get_cohorts() returns it.
            $ids = explode(',', $ids);
        }
        $data->cohorts = implode(',', array_filter(array_map('intval', (array) $ids)));
    }

    /**
     * Update content of the notice
     * @param awareness $persistent site notice persistent object
     * @param string $content new content
     * @return bool
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     */
    public static function update_notice_content(awareness $persistent, string $content) {
        $persistent->set('content', $content);
        return $persistent->update();
    }

    /**
     * Update data of the notice
     * @param awareness $persistent site notice persistent object
     * @param \stdClass $data new data
     * @return bool
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     */
    public static function update_notice_data(awareness $persistent, \stdClass $data) {
        self::normalise_cohorts($data);

        $persistent->from_record($data);
        return $persistent->update();
    }
}
