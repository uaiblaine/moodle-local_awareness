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
 * Guards two asynchronous contracts in the plugin's JavaScript.
 *
 * Neither can be reached by the tests this fleet runs. PHPUnit never loads a JS file; Behat drives
 * a real browser, where a response arriving out of order is exactly the thing that will not
 * reproduce on demand — and the notice case additionally cannot be provoked at all, because the
 * failure it guards is a web-service call that never answers. So the observer is a source
 * contract, the same mechanism and the same justification as criteria_contract_test and
 * bootstrap_compat_test beside it.
 *
 * A source scan pins a shape rather than a behaviour, which is a real limitation and the reason
 * each assertion below names the defect it exists for. What it does buy is the thing that was
 * missing: deleting either guard stops being invisible.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external
 */
final class async_contract_test extends \basic_testcase {
    /**
     * Absolute path to the plugin root.
     *
     * @return string Plugin directory without a trailing separator.
     */
    private function plugin_root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Read one AMD source file.
     *
     * @param string $module Module file name, e.g. "notice.js".
     * @return string File contents.
     */
    private function amd_source(string $module): string {
        $path = $this->plugin_root() . '/amd/src/' . $module;
        $this->assertFileExists($path, "amd/src/{$module} has been renamed — this test is now blind, not passing.");

        return file_get_contents($path);
    }

    /**
     * Extract one function body from an AMD source file.
     *
     * The terminator is explicit because the two modules nest differently: audience_estimator.js
     * declares its functions at four spaces, notice.js at eight inside its define() wrapper. A
     * single hard-coded terminator silently over-reads in one of them — it did, and the body of
     * dismissNotice() came back with acknowledgeNotice() attached, so a guard deleted from the
     * first was still "found" in the second and the mutation survived.
     *
     * @param string $source The module source.
     * @param string $needle The line the function starts with.
     * @param string $terminator The line ending the body, at that module's indent.
     * @return string The body.
     */
    private function body_after(string $source, string $needle, string $terminator = "\n    }"): string {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, "'{$needle}' is gone from the module — the scan below would pass blind.");

        $rest = substr($source, $start + strlen($needle));
        $end = strpos($rest, $terminator);
        $this->assertNotFalse($end, "'{$needle}' has no '{$terminator}' after it — the scan would read past its body.");

        return substr($rest, 0, $end);
    }

    /**
     * The estimator discards an answer belonging to a superseded request.
     *
     * A poll answers for the job it was sent for. Start a new estimate while one is in flight —
     * which the debounce makes ordinary, since every pause in typing can start one — and the older
     * answer can land last and overwrite a fresher count. The author is shown a number for a
     * question they have already changed, with nothing on screen to say so.
     *
     * The guard is a monotonic counter captured at send time, spelled exactly as
     * collision_warning.js spells it: one pattern, one name.
     */
    public function test_the_estimator_guards_its_async_answers_with_a_sequence(): void {
        $source = $this->amd_source('audience_estimator.js');

        $this->assertStringContainsString(
            'sequence: 0',
            $source,
            'The estimator must carry a request counter in its state, as collision_warning.js does.'
        );

        /*
         * Two comparisons per function, not one, and counted rather than merely found. Both the
         * success path and the failure path have to be guarded: an answer that arrives late is
         * just as stale when it is an error, and an error panel raised for a question the author
         * has already changed is the same defect wearing different clothes.
         *
         * Counting is what makes this bite. A first draft asserted only that "state.sequence"
         * appeared somewhere in the body — and it passed with the .then guard deleted, because the
         * capture line and the .catch guard still mentioned it. Mutation testing is the only
         * reason that is not still true.
         */
        foreach (['function pollOnce()', 'function trigger()'] as $needle) {
            $body = $this->body_after($source, $needle);
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($body, 'mine !== state.sequence'),
                "{$needle} must discard a superseded answer on BOTH its success and failure paths."
            );
        }

        /*
         * The pending timer has to be cancelled as well as superseded: the counter discards an
         * answer already on the wire, and stopPolling() stops one that has not been sent yet.
         * Neither covers the other.
         */
        $this->assertStringContainsString(
            'stopPolling();',
            $this->body_after($source, 'function trigger()'),
            'trigger() must cancel the in-flight poll as well as supersede it.'
        );
    }

    /**
     * The notice modal is hidden in exactly one place, and it is not the click handler.
     *
     * Hiding on click meant the modal closed before the server had answered. A dismissal or an
     * acknowledgement that never arrived — an expired session, a network drop, a 500 — looked
     * identical to one that did: the notice vanished, the failure went to the browser console, and
     * the acknowledgement report simply had no row. For a plugin whose entire purpose is evidence
     * that a notice was seen, that is the worst available failure mode.
     *
     * The hide now happens only where the queue is empty, so a call that did not succeed leaves
     * the notice on screen.
     */
    public function test_the_notice_modal_is_hidden_only_when_the_queue_is_empty(): void {
        $source = $this->amd_source('notice.js');

        $this->assertSame(
            1,
            substr_count($source, 'modal.hide()'),
            'modal.hide() must appear exactly once — hiding on click closes the notice before the '
                . 'server has answered, so a failed dismissal is indistinguishable from a successful one.'
        );

        $this->assertStringContainsString(
            'modal.hide()',
            $this->body_after($source, 'var nextNotice = function()', "\n        };"),
            'The single hide belongs in nextNotice(), on the empty-queue branch.'
        );
    }

    /**
     * A second click cannot start a second write while the first is in flight.
     *
     * Required by the change above, not incidental to it. modal.hide() on click was what made a
     * double dismissal impossible; with the modal staying up until the server answers, the window
     * is open again — and it is not only a fast double-tap, because modal_notice.js routes
     * outside-click and escape into a synthetic close-button click.
     */
    public function test_the_notice_write_paths_are_guarded_against_re_entry(): void {
        $source = $this->amd_source('notice.js');

        foreach (['var dismissNotice = function()', 'var acknowledgeNotice = function()'] as $needle) {
            $body = $this->body_after($source, $needle, "\n        };");
            $this->assertStringContainsString(
                'if (inflight)',
                $body,
                "{$needle} must refuse to start while a write is already in flight."
            );
            $this->assertStringContainsString(
                'inflight = false;',
                $body,
                "{$needle} must release the guard, or one failure locks the notice for the rest of the page."
            );
        }
    }

    /**
     * The shipped bundles were rebuilt from the sources above.
     *
     * amd/build is tracked and is what Moodle serves. A source fix committed without its rebuilt
     * bundle changes nothing on any site, and the failure is silent — which is precisely the shape
     * of defect this file exists to stop.
     */
    public function test_the_built_bundles_carry_the_guards(): void {
        $root = $this->plugin_root();

        $estimator = file_get_contents($root . '/amd/build/audience_estimator.min.js');
        $this->assertNotEmpty($estimator, 'the estimator bundle is missing');
        $this->assertStringContainsString(
            'sequence',
            $estimator,
            'amd/build/audience_estimator.min.js predates the sequence guard — run mdl grunt and commit the bundle.'
        );

        $notice = file_get_contents($root . '/amd/build/notice.min.js');
        $this->assertNotEmpty($notice, 'the notice bundle is missing');
        $this->assertStringContainsString(
            'inflight',
            $notice,
            'amd/build/notice.min.js predates the in-flight guard — run mdl grunt and commit the bundle.'
        );
    }
}
