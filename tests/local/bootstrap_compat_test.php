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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Guards the Bootstrap names the plugin's markup uses.
 *
 * Moodle 5.x back-ports most Bootstrap 4 class names through bs4-compat.scss, marked deprecated,
 * and Moodle 6.0 removes them. Nothing else in the pipeline reads a class name out of a Mustache,
 * JS or PHP file: phpcs, the mustache lint and stylelint never do. The badge and data-API arms
 * hold rules that are dead weight on 5.3 alone but keep a change landing here correct on the
 * older branches it is backported to.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class bootstrap_compat_test extends \basic_testcase {
    /**
     * Bootstrap 4 class names that Moodle 5.x back-ports but marks deprecated.
     *
     * Most of these resolve on 5.x only through bs4-compat.scss, which wraps each in
     * deprecated-styles() (a red outline under behat-site and themedesignermode) and which
     * Moodle 6.0 removes (MDL-84465). Writing both spellings side by side only adds a deprecation.
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
     * Bootstrap 5's .badge defaults its text to white, so a light background renders white on
     * near-white (bg-secondary 1.49:1 on 5.2, against the 4.5:1 AA floor). Bootstrap 4's .badge
     * sets no colour at all, so on the 4.5 branch a saturated badge renders near-black text on a
     * dark fill (bg-success 3.07:1); stating the colour on both kinds keeps a backport correct.
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
     * Every badge background must state its text colour, so it reads on every branch.
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
                . 'that does not state its own colour fails contrast here or on the 4.5 branch: '
                . implode('; ', $offenders)
        );
    }

    /**
     * A component wired through Bootstrap's markup data-API must carry both attribute spellings.
     *
     * 5.3 needs only data-bs-*, but new work is backported to the 4.5 branch, whose Bootstrap 4
     * listens on data-toggle; Behat there catches an unpaired toggle only where a scenario opens
     * that component, and this checks every line.
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
     * The paired form ("ml-1 ms-1") counts too: ms-1 alone resolves on every branch, 4.5's forward
     * bridge included, so the pair only adds a deprecation. Comment lines are skipped so prose naming
     * a class cannot trip it.
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
                . 'them deprecated and which Moodle 6.0 removes, so use the BS5 name alone: '
                . implode('; ', $offenders)
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
}
