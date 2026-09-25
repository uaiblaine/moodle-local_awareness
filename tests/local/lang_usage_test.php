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
 * A dead string is still translated, reviewed and carried in both packs, and no other gate
 * reports it.
 *
 * The convention exemptions are named rather than skipped wholesale, because each is required for
 * a different reason: a _help string is fetched by addHelpButton() from the base key, a cachedef_
 * string by core from the cache's name in db/caches.php, and a messageprovider: string from
 * db/messages.php. A task_ string is not exempt: every task class, the adhoc one included, fetches
 * its own in get_name().
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
     * The code of every source file the plugin ships, concatenated, comments removed.
     *
     * Swept from the root with an exclusion list rather than from a list of named directories, so
     * a directory added later is covered by default. Tests are skipped and comments removed, so a
     * key named only in a test or in a comment counts as unused.
     *
     * @return string The concatenated code.
     */
    private function sources(): string {
        $root = $this->plugin_root();
        $skip = ['lang', 'docs', 'build', '.git', 'node_modules', 'tests'];

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
            $extension = $file->getExtension();
            if ($file->isFile() && in_array($extension, ['php', 'mustache', 'js'], true)) {
                $sources .= $this->code_of(file_get_contents($file->getPathname()), $extension) . "\n";
            }
        }

        return $sources;
    }

    /**
     * A source with its comments removed.
     *
     * PHP goes through the tokenizer, so a `//` inside a string survives. JS loses its block
     * comments and every `//` comment not preceded by a colon or a quote, which keeps a URL or a
     * string holding one. Mustache loses its `{{! }}` comments.
     *
     * @param string $source The file's contents.
     * @param string $extension php, js or mustache.
     * @return string The code.
     */
    private function code_of(string $source, string $extension): string {
        if ($extension === 'php') {
            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= ' ';
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            return $code;
        }
        if ($extension === 'mustache') {
            return preg_replace('/\{\{!.*?\}\}/s', ' ', $source);
        }

        $source = preg_replace('~/\*.*?\*/~s', ' ', $source);
        return preg_replace('~(^|[^:\'"\\\\])//.*$~m', '$1', $source);
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
            if (preg_match('/^(cachedef_|messageprovider:)|_help$/', $key)) {
                continue;
            }
            /*
             * Bounded on both sides, so notice:timemodified is not reported as used merely because
             * report_notice:timemodified exists; a str_contains() would miss that.
             */
            if (!preg_match('/(?<![A-Za-z0-9_:])' . preg_quote($key, '/') . '(?![A-Za-z0-9_:])/', $sources)) {
                $dead[] = $key;
            }
        }

        $this->assertSame([], $dead, 'these strings are defined in both packs and used nowhere: ' . implode(', ', $dead));
    }

    /**
     * A key named only in a comment or in a test does not count as used.
     *
     * Each fixture holds keys in code beside keys in comments, so a stripper that removes too much
     * fails as surely as one that removes too little.
     *
     * @return void
     */
    public function test_comments_and_tests_do_not_count_as_usage(): void {
        $fixtures = [
            'php' => "<?php\n// Named dead:one.\n/** Named dead:two. */\n"
                . "\$s = get_string('live:one', 'local_awareness'); // Named dead:three.\n"
                . "\$u = 'http://example.com/live:two';\n",
            'js' => "// Named dead:one.\n/* Named dead:two. */\n"
                . "getString('live:one', 'local_awareness'); // Named dead:three.\n"
                . "var url = 'https://example.com/live:two';\n",
            'mustache' => "{{! Named dead:one, dead:two and dead:three. }}\n"
                . "{{#str}} live:one, local_awareness {{/str}}\n<a href=\"http://example.com/live:two\">\n",
        ];
        foreach ($fixtures as $extension => $source) {
            $code = $this->code_of($source, $extension);
            foreach (['live:one', 'live:two'] as $key) {
                $this->assertStringContainsString($key, $code, "{$extension}: the code naming {$key} was stripped");
            }
            foreach (['dead:one', 'dead:two', 'dead:three'] as $key) {
                $this->assertStringNotContainsString($key, $code, "{$extension}: the comment naming {$key} was kept");
            }
        }

        // This method's name is code in a test file and nowhere else, so the sweep must not hold it.
        $sources = $this->sources();
        $this->assertStringContainsString('namespace local_awareness', $sources, 'the sweep read no plugin class');
        $this->assertStringNotContainsString(__FUNCTION__, $sources, 'the sweep reads the tests');
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
