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
 * Every language string this plugin defines is either used or required by convention.
 *
 * A dead string is not free. It is translated, reviewed and carried in two packs for ever, and the
 * only way anyone finds out it is dead is by trying to grep for it. This repository accumulated ten
 * of them, was told so by an audit, and still had five a year later — prose was the only guard.
 *
 * The convention exemptions are named rather than skipped wholesale, because the reason each one
 * looks dead is different: a _help string is fetched by addHelpButton() from the BASE key, a
 * cachedef_ string is fetched by core from db/caches.php using the cache's name, a
 * messageprovider: string comes from db/messages.php and a task_ string from db/tasks.php using the
 * class name. None of the four ever appears as a literal, and none of them may be deleted.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper
 */
final class lang_usage_test extends \basic_testcase {
    /**
     * Plugin root.
     *
     * @return string Plugin directory without a trailing separator.
     */
    private function plugin_root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Every non-lang source file the plugin ships, concatenated.
     *
     * Swept from the root with an exclusion list rather than from a list of named directories: a
     * directory nobody thought of is then covered by default, which is the failure mode an
     * inclusion list produces silently.
     *
     * @return string The concatenated sources.
     */
    private function sources(): string {
        $root = $this->plugin_root();
        $skip = ['lang', 'docs', 'build', '.git', 'node_modules'];

        $sources = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function ($current) use ($skip): bool {
                    return !($current->isDir() && in_array($current->getFilename(), $skip, true));
                }
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'mustache', 'js'], true)) {
                $sources .= file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }

    /**
     * No string is defined and then never used.
     *
     * @return void
     */
    public function test_no_language_string_is_dead(): void {
        $en = file_get_contents($this->plugin_root() . '/lang/en/local_awareness.php');
        preg_match_all("/^\\\$string\\['([^']+)'\\]/m", $en, $found);
        $keys = $found[1];

        $this->assertGreaterThan(100, count($keys), 'implausibly few strings read — the scan is broken');

        $sources = $this->sources();
        $this->assertGreaterThan(10000, strlen($sources), 'implausibly little source read — the sweep is broken');

        $dead = [];
        foreach ($keys as $key) {
            if (preg_match('/^(cachedef_|messageprovider:|task_)|_help$/', $key)) {
                continue;
            }
            /*
             * Bounded on both sides, so notice:timemodified is not reported as used merely because
             * report_notice:timemodified exists. That exact pair is why this is a regex rather than
             * a str_contains, and it hid a dead string through two audits.
             */
            if (!preg_match('/(?<![A-Za-z0-9_:])' . preg_quote($key, '/') . '(?![A-Za-z0-9_:])/', $sources)) {
                $dead[] = $key;
            }
        }

        $this->assertSame([], $dead, 'these strings are defined in both packs and used nowhere: ' . implode(', ', $dead));
    }

    /**
     * The two packs define exactly the same keys.
     *
     * @return void
     */
    public function test_the_packs_are_in_lockstep(): void {
        $keys = [];
        foreach (['en', 'pt_br'] as $pack) {
            $source = file_get_contents($this->plugin_root() . '/lang/' . $pack . '/local_awareness.php');
            preg_match_all("/^\\\$string\\['([^']+)'\\]/m", $source, $found);
            $keys[$pack] = $found[1];
            sort($keys[$pack]);
        }

        $this->assertNotEmpty($keys['en'], 'no keys read from the English pack — the scan is broken');
        $this->assertSame(
            [],
            array_values(array_diff($keys['en'], $keys['pt_br'])),
            'defined in English and missing from pt_br'
        );
        $this->assertSame(
            [],
            array_values(array_diff($keys['pt_br'], $keys['en'])),
            'defined in pt_br and missing from English'
        );
    }
}
