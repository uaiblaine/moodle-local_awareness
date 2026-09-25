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

/**
 * Guards the plugin's Bootstrap 4 / Bootstrap 5 contract.
 *
 * Moodle 4.5 ships Bootstrap 4 and 5.0+ ship Bootstrap 5, and the bridging is asymmetric:
 * 4.5's forward bridge (theme/boost/scss/moodle/bs5-bridge.scss) covers only g-0, btn-close,
 * the ms/me/ps/pe spacers and float/text/border/rounded-start/end, while 5.x's backward bridge
 * (bs4-compat.scss) back-ports most Bootstrap 4 names. A BS5 utility outside that short list
 * resolves to nothing on 4.5.
 *
 * Nothing else in the pipeline sees a class name that resolves to nothing: phpcs, the mustache
 * lint and stylelint never read a class name out of a Mustache, JS or PHP file.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\bootstrap
 */
final class bootstrap_compat_test extends \basic_testcase {
    /**
     * Bootstrap 5 utilities that do not exist on Moodle 4.5.
     *
     * Each entry maps a regular expression matching the class in a class attribute or a JS class
     * string to the human-readable family name reported when it is found unpolyfilled. Source of
     * truth for what 4.5 does bridge: theme/boost/scss/moodle/bs5-bridge.scss.
     *
     * @return array Regex => family label.
     */
    private function bs5_only_utilities(): array {
        /*
         * The list is wider than current usage on purpose: it is a detector, not a polyfill, and
         * it costs nothing until somebody reaches for one of these.
         */
        return [
            '/\\bform-select(-sm|-lg)?\\b/' => 'form-select',
            '/\\bform-label\\b/' => 'form-label',
            '/\\bform-switch\\b/' => 'form-switch',
            '/\\bform-check-reverse\\b/' => 'form-check-reverse',
            '/\\bfw-(bold|semibold|light|lighter|bolder|medium|normal)\\b/' => 'fw-*',
            '/\\bfs-[1-6]\\b/' => 'fs-*',
            '/\\bfst-italic\\b/' => 'fst-italic',
            '/\\bfont-monospace\\b/' => 'font-monospace',
            '/\\bgap-([a-z]{2}-)?[0-9]\\b/' => 'gap-*',
            '/\\b(row|column)-gap-[0-9]\\b/' => 'row-gap-* / column-gap-*',
            '/\\bg-[1-6]\\b/' => 'g-*',
            '/\\blh-(1|base|lg|sm)\\b/' => 'lh-*',
            '/\\bd-grid\\b/' => 'd-grid',
            '/\\bvisually-hidden(-focusable)?\\b/' => 'visually-hidden',
            '/\\btext-bg-[a-z]+\\b/' => 'text-bg-*',
            '/\\bbg-body(-secondary|-tertiary)?\\b/' => 'bg-body*',
            '/\\bborder-[1-5]\\b/' => 'border-*',
            '/\\bobject-fit-[a-z]+\\b/' => 'object-fit-*',
            '/\\bz-[0-3]\\b/' => 'z-*',
            '/\\btranslate-middle(-[xy])?\\b/' => 'translate-middle',
            // The lookbehind keeps border-top-0 / border-bottom-0 out: those are border utilities,
            // present on both branches, and share the suffix with the BS5-only positional ones.
            '/(?<!border-)\\b(top|bottom|start|end)-(0|50|100)\\b/' => 'top-* / bottom-* / start-* / end-*',
            '/\\bratio(-[0-9]+x[0-9]+)?\\b/' => 'ratio*',
            '/\\bvr\\b/' => 'vr',
            '/\\bfst-normal\\b/' => 'fst-normal',
            /*
             * Absent from Bootstrap 4: rounded-1..5 (Bootstrap 4 has only rounded-sm and
             * rounded-lg), sticky-bottom, and modal-fullscreen with its -down variants.
             */
            '/\\brounded-[1-5]\\b/' => 'rounded-*',
            '/\\bsticky-bottom\\b/' => 'sticky-bottom',
            '/\\bmodal-fullscreen(-[a-z]{2,3}-down)?\\b/' => 'modal-fullscreen*',
        ];
    }

    /**
     * Bootstrap 4 class names that Moodle 5.x back-ports but marks deprecated.
     *
     * Most of these resolve on 5.x only through bs4-compat.scss, which wraps each in
     * deprecated-styles() (a red outline under behat-site and themedesignermode) and which
     * Moodle 6.0 removes (MDL-84465). Their Bootstrap 5 spellings resolve on 4.5 too: through
     * core's forward bridge, except visually-hidden, which the plugin's Bootstrap 4 polyfill
     * defines. So the BS5 name alone is correct on both branches, and writing both spellings
     * side by side only adds a deprecation.
     *
     * @return array Regex matching the deprecated name => the Bootstrap 5 spelling to use instead.
     */
    private function deprecated_bs4_names(): array {
        return [
            '/\\bml-([0-9]|auto)\\b/' => 'ms-*',
            '/\\bmr-([0-9]|auto)\\b/' => 'me-*',
            '/\\bpl-[0-9]\\b/' => 'ps-*',
            '/\\bpr-[0-9]\\b/' => 'pe-*',
            '/\\btext-left\\b/' => 'text-start',
            '/\\btext-right\\b/' => 'text-end',
            '/\\bfloat-left\\b/' => 'float-start',
            '/\\bfloat-right\\b/' => 'float-end',
            '/\\bborder-left\\b/' => 'border-start',
            '/\\bborder-right\\b/' => 'border-end',
            '/\\brounded-left\\b/' => 'rounded-start',
            '/\\brounded-right\\b/' => 'rounded-end',
            '/\\bsr-only(-focusable)?\\b/' => 'visually-hidden',
            '/\\bno-gutters\\b/' => 'g-0',
        ];
    }

    /**
     * Saturated background utilities that need an explicit light text colour.
     *
     * Bootstrap 4's .badge sets no colour at all, so a saturated badge renders near-black text
     * on a dark fill; Bootstrap 5's .badge defaults to white, so a light background renders white
     * on near-white: bg-success gives 3.07:1 on 4.5 and bg-secondary 1.49:1 on 5.2, against the
     * 4.5:1 AA floor. Only markup that states its text colour is correct on both branches.
     *
     * @return array Background utility => the text utility it requires.
     */
    private function badge_text_colours(): array {
        return [
            'bg-success' => 'text-white',
            'bg-primary' => 'text-white',
            'bg-danger' => 'text-white',
            'bg-info' => 'text-white',
            'bg-dark' => 'text-white',
            'bg-secondary' => 'text-dark',
            'bg-warning' => 'text-dark',
        ];
    }

    /**
     * Absolute path to the plugin root.
     *
     * @return string Plugin directory without a trailing separator.
     */
    private function plugin_root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Every file whose contents can put a class name in front of a user.
     *
     * Skips amd/build (generated from amd/src) and docs (not shipped, and .gitattributes keeps it
     * out of the release zip).
     *
     * @return array List of absolute file paths.
     */
    private function markup_files(): array {
        $root = $this->plugin_root();

        /*
         * An exclusion list walked from the plugin root rather than an inclusion list of
         * directories, so report/, the root entry points and any directory added later are
         * scanned by default.
         */
        $skip = ['amd/build', 'tests', 'docs', 'lang', '.git', 'node_modules', 'vendor'];

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!in_array($file->getExtension(), ['mustache', 'js', 'php'], true)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach ($skip as $prefix) {
                if (str_starts_with($relative, $prefix . '/')) {
                    continue 2;
                }
            }
            $files[] = $file->getPathname();
        }
        sort($files);
        return $files;
    }

    /**
     * The lines of a source that are markup or code rather than prose, keyed by 0-based line index.
     *
     * The rules below are about what reaches the browser. A comment that names a class or an
     * attribute in order to explain a rule is not a violation of it.
     *
     * A comment is recognised when its opener starts a line: `//`, `/*` or `{{!`. A block or
     * Mustache comment then runs to its closer, however many lines later, so the continuation lines
     * of a template docblock are prose too even though nothing marks them; whatever follows the
     * closer on its line is kept. A line starting with `*` outside a tracked comment is taken as the
     * body of a docblock whose opener shared a line with code.
     *
     * @param string $source A whole PHP, JS or Mustache source.
     * @return array Line index => the code on that line, comments removed.
     */
    private function code_lines(string $source): array {
        $openers = ['{{!' => '}}', '/*' => '*/'];
        $code = [];
        $closer = null;
        foreach (preg_split('/\R/', $source) as $index => $line) {
            $rest = $line;
            while (true) {
                if ($closer !== null) {
                    $end = strpos($rest, $closer);
                    if ($end === false) {
                        continue 2;
                    }
                    $rest = substr($rest, $end + strlen($closer));
                    $closer = null;
                }
                $rest = ltrim($rest);
                if ($rest === '' || str_starts_with($rest, '//') || str_starts_with($rest, '*')) {
                    continue 2;
                }
                $opened = false;
                foreach ($openers as $opener => $end) {
                    if (str_starts_with($rest, $opener)) {
                        $rest = substr($rest, strlen($opener));
                        $closer = $end;
                        $opened = true;
                        break;
                    }
                }
                if (!$opened) {
                    break;
                }
            }
            $code[$index] = $rest;
        }

        return $code;
    }

    /**
     * The markup lines of a plugin file.
     *
     * @param string $path Absolute path.
     * @return array Line index => the code on that line.
     */
    private function file_code_lines(string $path): array {
        $source = file_get_contents($path);
        $this->assertNotFalse($source, "Could not read {$path}");

        return $this->code_lines($source);
    }

    /**
     * The rules behind the Bootstrap 4 gate that backport a Boost 5.x behaviour, not a utility.
     *
     * Their classes are core's and the plugin's own, never a Bootstrap 5 utility the markup uses,
     * so the utility checks below leave them out; test_the_row_menu_escapes_its_scroll_wrapper()
     * checks them instead.
     *
     * @return array Exact selector => the declaration it must carry.
     */
    private function backports(): array {
        return [
            'body.' . bootstrap::BODY_CLASS_BS4 . ' .local-awareness-manage .no-overflow .dropdown' => 'position: static;',
        ];
    }

    /**
     * The exact class tokens the polyfill block defines behind the Bootstrap 4 gate.
     *
     * Token-level, not family-level: a family-level check ("is gap-* covered?") passes while
     * gap-2 alone is missing.
     *
     * @return array List of class tokens, e.g. gap-2, without the leading dot.
     */
    private function polyfilled_tokens(): array {
        $css = file_get_contents($this->plugin_root() . '/styles.css');
        /* Strip comments first: the block's own prose names files and classes it does not define. */
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $gate = preg_quote(bootstrap::BODY_CLASS_BS4, '/');
        $tokens = [];
        foreach (explode('}', $css) as $block) {
            $selector = explode('{', $block)[0];
            if (!preg_match('/body\.' . $gate . '\b/', $selector)) {
                continue;
            }
            if (array_key_exists(trim($selector), $this->backports())) {
                continue;
            }
            preg_match_all('/\.([a-z][a-z0-9-]*)/', $selector, $matches);
            foreach ($matches[1] as $token) {
                $tokens[] = $token;
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * The exact Bootstrap 5 class tokens the plugin emits that 4.5 does not define.
     *
     * @return array Token => list of file basenames using it.
     */
    private function used_bs5_tokens(): array {
        $used = [];
        foreach ($this->markup_files() as $path) {
            foreach ($this->file_code_lines($path) as $line) {
                foreach ($this->bs5_only_utilities() as $pattern => $unusedlabel) {
                    if (!preg_match_all($pattern, $line, $matches)) {
                        continue;
                    }
                    foreach ($matches[0] as $token) {
                        $used[$token][basename($path)] = true;
                    }
                }
            }
        }
        return array_map('array_keys', $used);
    }

    /**
     * Every Bootstrap 5 class the plugin emits must be defined by the polyfill for 4.5.
     *
     * @return void
     */
    public function test_every_bs5_utility_used_is_polyfilled(): void {
        $polyfilled = $this->polyfilled_tokens();
        $used = $this->used_bs5_tokens();

        /*
         * Non-vacuity on both sides. "Every used token is polyfilled" is satisfied by a plugin that
         * uses none, and by a scan that finds none — and those two look identical from here.
         */
        $this->assertNotEmpty($used, 'Found no Bootstrap 5 utility in the markup — the scan is broken, not the code.');
        $this->assertNotEmpty($polyfilled, 'Found no polyfill rules in styles.css — the scan is broken, not the code.');

        $missing = [];
        foreach ($used as $token => $files) {
            if (!in_array($token, $polyfilled, true)) {
                $missing[] = $token . ' (used in ' . implode(', ', array_slice($files, 0, 3)) . ')';
            }
        }
        sort($missing);
        $this->assertSame(
            [],
            $missing,
            'These Bootstrap 5 classes are used but resolve to nothing on Moodle 4.5. Either add them '
                . 'to the Bootstrap 4 utility polyfill at the tail of styles.css, or stop using them: '
                . implode('; ', $missing)
        );
    }

    /**
     * The polyfill must not grow rules for classes the plugin no longer uses.
     *
     * A compatibility layer that outlives its callers is how a temporary shim becomes permanent.
     *
     * @return void
     */
    public function test_polyfill_carries_nothing_unused(): void {
        $used = array_keys($this->used_bs5_tokens());
        $polyfilled = $this->polyfilled_tokens();

        /*
         * The body gate is the selector every polyfill rule hangs off, so it can never appear as
         * a class the markup uses.
         */
        $structural = [bootstrap::BODY_CLASS_BS4];

        $this->assertNotEmpty($polyfilled, 'Found no polyfill rules in styles.css — the scan is broken, not the code.');

        $unused = array_values(array_diff($polyfilled, $used, $structural));
        sort($unused);
        $this->assertSame(
            [],
            $unused,
            'The Bootstrap 4 polyfill defines classes nothing uses any more; delete them: '
                . implode(', ', $unused)
        );
    }

    /**
     * The manage list's row menu escapes the table's scroll wrapper on 4.5, as Boost lets it on 5.x.
     *
     * flexible_table wraps a responsive table in an overflow container, and the action menu is
     * positioned against its .dropdown inside it, so the menu of a row near the end of a short list
     * is clipped. Boost 5.x makes that .dropdown static inside .table-responsive; 4.5 names the
     * wrapper .no-overflow and has no such rule, so the plugin supplies it behind the Bootstrap 4
     * gate. The wrapper name comes from core, so it is read from core on the branch the test runs on.
     *
     * @return void
     */
    public function test_the_row_menu_escapes_its_scroll_wrapper(): void {
        global $CFG;

        $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents($this->plugin_root() . '/styles.css'));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);
        $rules = [];
        foreach ($matches as $match) {
            $rules[trim($match[1])] = trim($match[2]);
        }
        $this->assertNotEmpty($this->backports(), 'no backport is listed, so nothing is checked');
        foreach ($this->backports() as $selector => $declaration) {
            $this->assertArrayHasKey($selector, $rules, "styles.css no longer has {$selector}");
            $this->assertStringContainsString($declaration, $rules[$selector], "{$selector} no longer sets {$declaration}");
        }

        $table = file_get_contents($CFG->libdir . '/table/classes/flexible_table.php');
        $this->assertNotFalse($table, 'core\'s flexible_table could not be read');
        $wrapper = bootstrap::is_bs4() ? 'no-overflow' : 'table-responsive';
        $this->assertStringContainsString(
            "'class' => '{$wrapper}'",
            $table,
            "core no longer wraps a responsive table in .{$wrapper}, which the row menu's rule depends on"
        );

        $this->assertMatchesRegularExpression(
            '/class="local-awareness-manage".*\{\{\{tablehtml\}\}\}/s',
            file_get_contents($this->plugin_root() . '/templates/manage/page.mustache'),
            'the list is no longer rendered inside .local-awareness-manage, which the rule is scoped to'
        );
    }

    /**
     * The Bootstrap data-API spellings missing from one line.
     *
     * Extracted from the sweep so the rule can be asserted against fixtures. With no live offender
     * anywhere in the plugin, a test that only walks the tree cannot tell a working detector from a
     * deleted one.
     *
     * @param string $line One line of markup.
     * @return array The attribute names present without their counterpart, in declaration order.
     */
    private function data_api_offences(string $line): array {
        $pairs = [
            'data-toggle' => 'data-bs-toggle',
            'data-target' => 'data-bs-target',
            'data-dismiss' => 'data-bs-dismiss',
            'data-parent' => 'data-bs-parent',
        ];

        /*
         * data-target and data-parent are only Bootstrap's when a toggle sits beside them; on their
         * own they are ordinary custom attributes.
         */
        $wired = preg_match('/(?<![-\w])data-(bs-)?toggle(?![-\w])/', $line);

        $offences = [];
        foreach ($pairs as $bs4 => $bs5) {
            if (!$wired && in_array($bs4, ['data-target', 'data-parent'], true)) {
                continue;
            }
            /* Match the attribute itself, never a longer name that merely starts with it. */
            $hasbs4 = preg_match('/(?<![-\w])' . preg_quote($bs4, '/') . '(?![-\w])/', $line);
            $hasbs5 = preg_match('/(?<![-\w])' . preg_quote($bs5, '/') . '(?![-\w])/', $line);
            if ($hasbs4 !== $hasbs5) {
                $offences[] = $hasbs4 ? $bs4 : $bs5;
            }
        }

        return $offences;
    }

    /**
     * Every badge background must state its text colour, so it reads on both branches.
     *
     * @return void
     */
    public function test_badges_state_their_text_colour(): void {
        /*
         * Every line carrying a background utility is checked, not only lines that also say
         * "badge": code returning a badge's classes often names the badge elsewhere, such as in
         * its method name. The required colour is matched exactly, because text-muted or text-body
         * is not a contrast answer for a saturated background.
         */
        $offenders = [];
        $checked = 0;
        foreach ($this->markup_files() as $path) {
            foreach ($this->file_code_lines($path) as $number => $line) {
                foreach ($this->badge_text_colours() as $background => $required) {
                    if (!preg_match('/\b' . preg_quote($background, '/') . '\b/', $line)) {
                        continue;
                    }
                    $checked++;
                    if (!preg_match('/\b' . preg_quote($required, '/') . '\b/', $line)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' needs ' . $required;
                    }
                }
            }
        }

        /*
         * Non-vacuity: without it the assertion below is satisfied by a scan that reads nothing,
         * such as a broken markup_files().
         */
        $this->assertGreaterThan(0, $checked, 'Found no background utility to check — the scan is broken, not the code.');

        $this->assertSame(
            [],
            $offenders,
            'Bootstrap 4 gives .badge no text colour and Bootstrap 5 defaults it to white, so a badge '
                . 'that does not state its own colour fails contrast on one branch or the other: '
                . implode('; ', $offenders)
        );
    }

    /**
     * A component wired through Bootstrap's markup data-API must carry both attribute spellings.
     *
     * Behat on Moodle 4.5 catches an unpaired toggle only where a scenario opens that component;
     * this checks every line.
     *
     * @return void
     */
    public function test_data_api_attributes_are_paired(): void {
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach ($this->file_code_lines($path) as $number => $line) {
                foreach ($this->data_api_offences($line) as $offence) {
                    $offenders[] = basename($path) . ':' . ($number + 1) . ' has only ' . $offence;
                }
            }
        }

        /*
         * No non-vacuity guard: the plugin currently wires nothing through Bootstrap's markup
         * data-API, so a count guard would fail on a correct tree. test_the_data_api_detector_works
         * proves the detector can still fail.
         */
        $this->assertSame(
            [],
            $offenders,
            'Bootstrap 4 listens on data-toggle and Bootstrap 5 on data-bs-toggle, so markup-wired '
                . 'components need both spellings side by side: ' . implode('; ', $offenders)
        );
    }

    /**
     * The pairing detector reports what it should and stays quiet about what it should not.
     *
     * The sweep above finds no data-API markup today, so it would keep passing with the detector
     * deleted. Fixtures prove the rule without a live offender.
     *
     * @return void
     */
    public function test_the_data_api_detector_works(): void {
        $paired = 'data-toggle="dropdown" data-bs-toggle="dropdown"';
        $this->assertSame([], $this->data_api_offences($paired), 'a correctly paired toggle was reported');

        $this->assertSame(
            ['data-toggle'],
            $this->data_api_offences('<button data-toggle="dropdown">'),
            'a Bootstrap 4 toggle with no Bootstrap 5 spelling was not reported'
        );
        $this->assertSame(
            ['data-bs-toggle'],
            $this->data_api_offences('<button data-bs-toggle="dropdown">'),
            'a Bootstrap 5 toggle with no Bootstrap 4 spelling was not reported'
        );

        /*
         * A bare data-target is an ordinary custom attribute, not Bootstrap's, unless a toggle sits
         * beside it. Requiring a data-bs-target there would be a false positive with no correct fix.
         */
        $this->assertSame(
            [],
            $this->data_api_offences('<div data-target="competency-list">'),
            'a bare data-target was treated as a Bootstrap hook'
        );
        $this->assertSame(
            ['data-target'],
            $this->data_api_offences('<div data-toggle="collapse" data-bs-toggle="collapse" data-target="#x">'),
            'data-target beside a toggle IS Bootstrap\'s and must be paired'
        );

        // A longer attribute that merely starts with the same characters is not a match.
        $this->assertSame([], $this->data_api_offences('<div data-toggle-mode="x">'), 'a longer name matched');
    }

    /**
     * The scans skip every line of a comment, the unmarked ones inside it included, and nothing else.
     *
     * A multi-line Mustache docblock has continuation lines that carry no marker of their own. Each
     * prose line of the fixture names a class one of the scans reports, so a reader that lets one
     * through fails here, and the markup lines around them prove it still reads code.
     *
     * @return void
     */
    public function test_comments_are_told_from_markup(): void {
        $source = implode("\n", [
            '{{!',
            '    A docblock line naming badge bg-success and sr-only.',
            '}}',
            '<span class="badge bg-success text-white">',
            '/*',
            '   A block comment line naming fw-bold.',
            '*/ <b class="after-block">',
            '{{! One line. }}<i class="after-mustache">',
            '// A line comment naming data-toggle.',
            ' * A docblock body naming ml-1.',
            '',
            '<div class="last">',
        ]);

        $this->assertSame(
            [
                3 => '<span class="badge bg-success text-white">',
                6 => '<b class="after-block">',
                7 => '<i class="after-mustache">',
                11 => '<div class="last">',
            ],
            $this->code_lines($source)
        );
    }

    /**
     * The markup must never carry a Bootstrap 4 name that Moodle 5.x has deprecated.
     *
     * The paired form ("ml-1 ms-1") counts too: ms-1 alone already resolves on 4.5, so the pair
     * only adds a deprecation. Comment lines are skipped so prose naming a class cannot trip it.
     *
     * @return void
     */
    public function test_markup_carries_no_deprecated_bootstrap4_names(): void {
        $offenders = [];
        $scanned = 0;
        foreach ($this->markup_files() as $path) {
            $scanned++;
            foreach ($this->file_code_lines($path) as $number => $line) {
                foreach ($this->deprecated_bs4_names() as $pattern => $replacement) {
                    if (preg_match($pattern, $line, $matches)) {
                        $offenders[] = basename($path) . ':' . ($number + 1)
                            . ' has ' . $matches[0] . ', use ' . $replacement;
                    }
                }
            }
        }

        /* A scan that found no files would assert nothing and stay green for ever. */
        $this->assertGreaterThan(0, $scanned, 'Found no markup files to scan — the scan is broken, not the code.');
        $this->assertSame(
            [],
            $offenders,
            'Moodle 5.x back-ports these Bootstrap 4 names only through bs4-compat.scss, which marks '
                . 'them deprecated and which Moodle 6.0 removes; their Bootstrap 5 spellings resolve on '
                . '4.5 too, through core\'s forward bridge or, for visually-hidden, the plugin\'s own '
                . 'polyfill, so use the BS5 name alone: ' . implode('; ', $offenders)
        );
    }

    /**
     * The plugin must not declare custom properties inside core's design-system namespace.
     *
     * Moodle 5.2 ships theme/boost/scss/design-system/ with $mds-* tokens, so an --mds-*
     * declaration in the plugin's stylesheet squats a namespace core is expanding.
     *
     * @return void
     */
    public function test_stylesheet_declares_no_core_design_system_tokens(): void {
        $root = $this->plugin_root();
        $sheets = array_merge([$root . '/styles.css'], glob($root . '/styles_*.css') ?: []);
        $offenders = [];
        $lines = 0;
        foreach ($sheets as $sheet) {
            foreach (file($sheet) as $number => $line) {
                $lines++;
                /* A declaration, not a mention: the property name followed by its colon. */
                if (preg_match('/--mds-[a-z0-9-]+\s*:/i', $line)) {
                    $offenders[] = basename($sheet) . ':' . ($number + 1);
                }
            }
        }
        // Non-vacuity: a renamed stylesheet would otherwise satisfy this by being unreadable.
        $this->assertGreaterThan(0, $lines, 'Found no stylesheet to read — the scan is broken, not the code.');

        $this->assertSame(
            [],
            $offenders,
            'These lines declare custom properties in core\'s --mds- namespace; use the plugin\'s own '
                . 'prefix instead: ' . implode(', ', $offenders)
        );
    }

    /**
     * Every plugin page, i.e. every file calling $PAGE->set_url(), must call bootstrap::mark_page().
     *
     * The polyfill is gated on the marker that method adds, so a page that forgets it renders
     * unstyled on 4.5 while every static gate stays green.
     *
     * @return void
     */
    public function test_entry_points_mark_the_bootstrap_version(): void {
        $root = $this->plugin_root();
        $offenders = [];
        $pages = 0;
        /*
         * Every PHP file markup_files() walks, not only the root, so report/*_systemreport.php is
         * checked too.
         */
        foreach ($this->markup_files() as $path) {
            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $contents = file_get_contents($path);
            /* A real page, not lib.php or version.php: it gives $PAGE a URL of its own. */
            if (!str_contains($contents, '$PAGE->set_url(')) {
                continue;
            }
            $pages++;
            if (!str_contains($contents, 'bootstrap::mark_page()')) {
                $offenders[] = str_replace($root . '/', '', $path);
            }
        }
        /*
         * Non-vacuity, keyed on a page count rather than on a body class: the plugin sets no body
         * class of its own, so a body-class key would find nothing to check.
         */
        $this->assertGreaterThan(0, $pages, 'Found no plugin pages to check — the scan is broken, not the code.');
        $this->assertSame(
            [],
            $offenders,
            'These pages call $PAGE->set_url() but never bootstrap::mark_page(), so the Bootstrap 4 '
                . 'polyfill will not reach them: ' . implode(', ', $offenders)
        );
    }

    /**
     * The Bootstrap 4 verdict follows the core branch and flips at Moodle 5.0.
     *
     * The threshold is the whole behaviour: inverted, the polyfill would ship to 5.x and freeze
     * 4.5's metrics onto it.
     *
     * @return void
     */
    public function test_is_bs4_flips_at_the_first_bootstrap5_branch(): void {
        global $CFG;

        $original = $CFG->branch;
        try {
            $CFG->branch = '405';
            $this->assertTrue(bootstrap::is_bs4(), 'Moodle 4.5 ships Bootstrap 4.');
            $CFG->branch = '499';
            $this->assertTrue(bootstrap::is_bs4(), 'Anything below 5.0 still ships Bootstrap 4.');
            $CFG->branch = '500';
            $this->assertFalse(bootstrap::is_bs4(), 'Moodle 5.0 is the first branch shipping Bootstrap 5.');
            $CFG->branch = '502';
            $this->assertFalse(bootstrap::is_bs4(), 'Moodle 5.2 ships Bootstrap 5.');
        } finally {
            $CFG->branch = $original;
        }
    }

    /**
     * The marker reaches the page body on Bootstrap 4 sites, and only there.
     *
     * The negative half runs first and the positive half is its control. A mark_page() that added
     * nothing at all - an empty body, or a marker spelt differently from the gate styles.css keys
     * off - passes the first assertion and fails the second, so the first cannot stay green by the
     * method never having run.
     *
     * @return void
     */
    public function test_mark_page_marks_only_bootstrap4_pages(): void {
        global $CFG, $PAGE;

        /*
         * A fresh page, restored afterwards: mark_page() writes to the global $PAGE and a
         * basic_testcase does not call reset_all_data(), so the body class added below would
         * otherwise outlive the test.
         */
        $originalpage = $PAGE;
        $PAGE = new \moodle_page();

        $original = $CFG->branch;
        try {
            $CFG->branch = '502';
            bootstrap::mark_page();
            $this->assertStringNotContainsString(
                bootstrap::BODY_CLASS_BS4,
                $PAGE->bodyclasses,
                'The Bootstrap 4 polyfill gate must not reach a Bootstrap 5 site.'
            );

            $CFG->branch = '405';
            bootstrap::mark_page();
            $this->assertStringContainsString(
                bootstrap::BODY_CLASS_BS4,
                $PAGE->bodyclasses,
                'Without this marker on the body, the Bootstrap 4 polyfill in styles.css never applies.'
            );
        } finally {
            $CFG->branch = $original;
            $PAGE = $originalpage;
        }
    }
}
