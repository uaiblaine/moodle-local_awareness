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
 * The stylesheet's own hygiene: every rule can match markup the plugin emits, stays on its pages,
 * and is not silently replaced by another rule.
 *
 * stylelint checks syntax only, and a plugin's styles.css is compiled into every page of the site,
 * so a stray selector costs every page and a dead one costs every reader of the file.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\output\editor_page
 */
final class stylesheet_contract_test extends \basic_testcase {
    /** @var array The class-name prefixes the plugin owns; a class under one is the plugin's to emit. */
    private const NAMESPACES = ['la-', 'local-awareness-', 'competency-picker-'];

    /**
     * Absolute path to the plugin root.
     *
     * @return string Plugin directory without a trailing separator.
     */
    private function plugin_root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * A plugin file.
     *
     * @param string $relative Path from the plugin root.
     * @return string The contents.
     */
    private function read(string $relative): string {
        $contents = file_get_contents($this->plugin_root() . '/' . $relative);
        $this->assertNotFalse($contents, "Could not read {$relative}");

        return $contents;
    }

    /**
     * The rules of a stylesheet chunk, in source order, with the at-rule each sits in.
     *
     * Rules inside @media are returned with that condition as their context; @keyframes blocks are
     * skipped, because their steps are not selectors.
     *
     * @param string $css The chunk, comments already removed.
     * @param string $context The enclosing at-rule, or an empty string at the top level.
     * @return array List of [context, selector list, declarations].
     */
    private function rules(string $css, string $context = ''): array {
        $rules = [];
        $offset = 0;
        $length = strlen($css);
        while (($open = strpos($css, '{', $offset)) !== false) {
            $selector = trim(substr($css, $offset, $open - $offset));
            $depth = 1;
            $close = $open;
            while ($depth > 0 && ++$close < $length) {
                if ($css[$close] === '{') {
                    $depth++;
                } else if ($css[$close] === '}') {
                    $depth--;
                }
            }
            $body = substr($css, $open + 1, $close - $open - 1);
            $offset = $close + 1;

            if (str_starts_with($selector, '@keyframes')) {
                continue;
            }
            if (str_starts_with($selector, '@')) {
                $rules = array_merge($rules, $this->rules($body, $selector));
                continue;
            }
            $rules[] = [$context, $selector, trim($body)];
        }

        return $rules;
    }

    /**
     * The rules of the plugin's stylesheet.
     *
     * @return array As rules() returns them.
     */
    private function stylesheet_rules(): array {
        $css = preg_replace('~/\*.*?\*/~s', '', $this->read('styles.css'));
        $rules = $this->rules($css);
        $this->assertGreaterThan(100, count($rules), 'implausibly few rules read from styles.css; the parser is broken');

        return $rules;
    }

    /**
     * Every selector of the stylesheet, one per entry.
     *
     * @return array List of selectors.
     */
    private function selectors(): array {
        $selectors = [];
        foreach ($this->stylesheet_rules() as [, $group]) {
            foreach (explode(',', $group) as $selector) {
                $selectors[] = trim($selector);
            }
        }

        return $selectors;
    }

    /**
     * The code of every file the plugin ships that can emit a class name, comments removed.
     *
     * @return string The concatenated code.
     */
    private function shipped_code(): string {
        $root = $this->plugin_root();
        $skip = ['tests', 'docs', 'lang', 'amd/build', '.git', 'node_modules', 'vendor'];

        $code = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $extension = $file->getExtension();
            if (!$file->isFile() || !in_array($extension, ['php', 'mustache', 'js'], true)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach ($skip as $prefix) {
                if (str_starts_with($relative, $prefix . '/')) {
                    continue 2;
                }
            }
            $code .= $this->code_of(file_get_contents($file->getPathname()), $extension) . "\n";
        }

        return $code;
    }

    /**
     * A source with its comments removed, so a class named only in prose does not count as emitted.
     *
     * PHP goes through the tokenizer. JS loses its block comments and every `//` comment not
     * preceded by a colon or a quote, which keeps a URL. Mustache loses its `{{! }}` comments.
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
     * Whether the shipped code can put a class on an element.
     *
     * Either the class is written out, or it is built at runtime from a prefix of it: a JS constant
     * such as 'la-tpl-' or a Mustache attribute such as la-layout-option--{{template}}. The prefix
     * has to name more than the plugin's namespace, or every la-* class would pass on 'la-'. Which
     * values a built class can take is motion_contract_test's business.
     *
     * @param string $class A class name without its dot.
     * @param string $code The shipped code.
     * @return bool
     */
    private function emitted(string $class, string $code): bool {
        if (preg_match('/(?<![\w-])' . preg_quote($class, '/') . '(?![\w-])/', $code)) {
            return true;
        }

        $namespace = 0;
        foreach (self::NAMESPACES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                $namespace = strlen($prefix);
            }
        }
        for ($end = strlen($class) - 1; $end > $namespace; $end--) {
            if ($class[$end - 1] !== '-') {
                continue;
            }
            $prefix = substr($class, 0, $end);
            if (preg_match('/(?<![\w-])' . preg_quote($prefix, '/') . '(\{\{|[\'"])/', $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every class of the plugin's namespaces that the stylesheet styles is emitted by some markup.
     *
     * A rule for a class nothing emits is loaded on every page and matches nothing.
     *
     * @return void
     */
    public function test_every_plugin_class_the_stylesheet_styles_is_emitted(): void {
        $classes = [];
        foreach ($this->selectors() as $selector) {
            preg_match_all('/\.([a-z][\w-]*)/i', preg_replace('/\[[^\]]*\]/', '', $selector), $matches);
            foreach ($matches[1] as $class) {
                foreach (self::NAMESPACES as $prefix) {
                    if (str_starts_with($class, $prefix)) {
                        $classes[$class] = true;
                    }
                }
            }
        }
        $this->assertGreaterThan(50, count($classes), 'implausibly few plugin classes in styles.css; the scan is broken');

        $code = $this->shipped_code();
        $dead = array_values(array_filter(array_keys($classes), function (string $class) use ($code): bool {
            return !$this->emitted($class, $code);
        }));
        sort($dead);
        $this->assertSame([], $dead, 'styles.css styles these classes and nothing emits them; delete their rules');
    }

    /**
     * The emission check accepts a written or a built class, and nothing on the namespace alone.
     *
     * @return void
     */
    public function test_the_emission_check_reads_built_classes_and_no_further(): void {
        $this->assertTrue($this->emitted('la-chip--brand', 'class="la-chip la-chip--brand"'), 'a written class');
        $this->assertTrue($this->emitted('la-tpl-banner', "TEMPLATE: 'la-tpl-',"), 'a class built in JS');
        $this->assertTrue($this->emitted('la-layout-option--card', 'la-layout-option--{{template}}'), 'a class built in Mustache');

        $this->assertFalse($this->emitted('la-chip--muted', 'class="la-chip la-chip--brand"'), 'a sibling modifier');
        $this->assertFalse($this->emitted('la-spinner', "var prefix = 'la-';"), 'the namespace alone');
        $this->assertFalse($this->emitted('la-btn--soft', 'class="la-btn--softer"'), 'a longer class');
        $this->assertFalse(
            $this->emitted('la-btn--soft', $this->code_of("<?php\n// Uses la-btn--soft.\n", 'php')),
            'a class named in a comment'
        );
    }

    /**
     * No selector sets a property that another rule already sets for the same selector.
     *
     * Two rules with one selector, in the same media context, tie on specificity, so the later one
     * wins outright and the earlier declaration is dead however it reads.
     *
     * @return void
     */
    public function test_no_rule_restates_what_another_rule_sets(): void {
        $first = [];
        $restated = [];
        foreach ($this->stylesheet_rules() as $index => [$context, $group, $declarations]) {
            preg_match_all('/(?:^|;)\s*([a-z-]+)\s*:/', $declarations, $properties);
            foreach (explode(',', $group) as $selector) {
                $selector = preg_replace('/\s+/', ' ', trim($selector));
                foreach (array_unique($properties[1]) as $property) {
                    $key = "{$context} {$selector} {$property}";
                    if (isset($first[$key]) && $first[$key] !== $index) {
                        $restated[] = trim("{$context} {$selector}") . " sets {$property} twice";
                    }
                    $first[$key] = $first[$key] ?? $index;
                }
            }
        }

        $this->assertNotEmpty($first, 'no declaration read; the scan is blind');
        $this->assertSame([], $restated, 'the earlier of each pair is dead');
    }

    /**
     * A selector naming an element id the plugin does not own is scoped to the editor.
     *
     * moodleform ids (#fitem_id_*, #id_*) and core's #sticky-footer exist on other pages too, and
     * this stylesheet is loaded on all of them. The plugin's own ids start with awareness-.
     *
     * @return void
     */
    public function test_foreign_ids_are_scoped_to_the_editor(): void {
        $checked = 0;
        $unscoped = [];
        foreach ($this->selectors() as $selector) {
            if (!preg_match('/#(?!awareness-)[\w-]+|\[id\b/', $selector)) {
                continue;
            }
            $checked++;
            if (!str_starts_with($selector, '.local-awareness-editor ')) {
                $unscoped[] = $selector;
            }
        }

        $this->assertGreaterThan(10, $checked, 'no selector names a form id; the scan is blind');
        $this->assertSame([], $unscoped, 'these selectors reach form elements on every page of the site');
    }

    /**
     * The page head's title rule names the heading the editor shell renders.
     *
     * The shell's title is an h2 under the page's own h1, and a rule written for another level
     * matches nothing.
     *
     * @return void
     */
    public function test_the_page_head_rule_styles_the_heading_the_shell_renders(): void {
        $this->assertSame(
            1,
            preg_match('/<div class="la-pagehead-title">\s*<(h[1-6])\b/', $this->read('templates/editor/shell.mustache'), $heading),
            'the shell no longer opens its page head with a heading'
        );

        $styled = [];
        foreach ($this->selectors() as $selector) {
            if (preg_match('/^\.la-pagehead (h[1-6])$/', $selector, $match)) {
                $styled[] = $match[1];
            }
        }
        $this->assertSame([$heading[1]], $styled, 'the page head styles a heading level the shell does not render');
    }
}
