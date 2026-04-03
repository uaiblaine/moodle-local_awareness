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

/**
 * Temporary debug script for Awareness filters.
 * Access: /local/awareness/debug_filters.php
 * DELETE after debugging.
 *
 * @package local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/awareness/debug_filters.php'));
$PAGE->set_title('Awareness Debug');

echo $OUTPUT->header();
echo '<h2>Awareness Filter Debug</h2>';

// 1. Show raw DB data for all notices.
echo '<h3>1. Raw DB records (local_awareness table)</h3>';
$records = $DB->get_records('local_awareness', null, 'id DESC');
echo '<table border="1" cellpadding="5" style="border-collapse:collapse; margin-bottom:20px;">';
echo '<tr><th>ID</th><th>Title</th><th>Enabled</th><th>pathmatch</th><th>filtervalues</th></tr>';
foreach ($records as $r) {
    $pm = isset($r->pathmatch) ? htmlspecialchars($r->pathmatch) : '<em style="color:red;">COLUMN MISSING</em>';
    $fv = isset($r->filtervalues) ? htmlspecialchars($r->filtervalues) : '<em style="color:red;">COLUMN MISSING</em>';
    echo "<tr><td>{$r->id}</td><td>" . htmlspecialchars($r->title) .
        "</td><td>{$r->enabled}</td><td>{$pm}</td><td><pre>{$fv}</pre></td></tr>";
}
echo '</table>';

// 2. Check if columns exist.
echo '<h3>2. Column existence in DB</h3>';
$columns = $DB->get_columns('local_awareness');
$haspathmatch = isset($columns['pathmatch']);
$hasfiltervalues = isset($columns['filtervalues']);
echo '<p>pathmatch column exists: <strong>' . ($haspathmatch ? '✅ YES' : '❌ NO') . '</strong></p>';
echo '<p>filtervalues column exists: <strong>' . ($hasfiltervalues ? '✅ YES' : '❌ NO') . '</strong></p>';

// 3. Check current PAGE context.
echo '<h3>3. Current $PAGE info</h3>';
try {
    echo '<p>$PAGE->url: ' . $PAGE->url->out() . '</p>';
} catch (Exception $e) {
    echo '<p style="color:red;">$PAGE->url not set: ' . $e->getMessage() . '</p>';
}
echo '<p>$PAGE->context: ' . $PAGE->context->get_context_name() . ' (level: ' . $PAGE->context->contextlevel . ')</p>';

// 4. Show current user roles.
echo '<h3>4. Current user roles</h3>';
$systemroles = get_user_roles(context_system::instance(), $USER->id, true);
echo '<p>System context roles (with parents): ';
$roleids = [];
foreach ($systemroles as $r) {
    $roleids[] = $r->roleid;
    echo $r->shortname . ' (id=' . $r->roleid . '), ';
}
echo '</p>';

// 5. Test filter evaluation for each notice.
echo '<h3>5. Filter evaluation per notice</h3>';
foreach ($records as $r) {
    echo '<div style="border:1px solid #ccc; padding:10px; margin:10px 0;">';
    echo '<h4>Notice: ' . htmlspecialchars($r->title) . ' (ID: ' . $r->id . ')</h4>';

    // Path match test.
    $pathmatch = isset($r->pathmatch) ? ($r->pathmatch ?? '') : '';
    echo '<p><strong>pathmatch:</strong> "' . htmlspecialchars($pathmatch) . '"</p>';
    if (empty($pathmatch)) {
        echo '<p>→ Path check: <strong style="color:green;">PASS (no pattern = show everywhere)</strong></p>';
    } else {
        $result = \local_awareness\helper::check_path_match($pathmatch);
        $color = $result ? 'green' : 'red';
        $status = $result ? 'PASS' : 'FAIL';
        echo '<p>→ Path check for current URL: <strong style="color:' . $color . ';">' . $status . '</strong></p>';
    }

    // Filter test.
    $filtervalues = isset($r->filtervalues) ? $r->filtervalues : null;
    echo '<p><strong>filtervalues:</strong> <code>' . htmlspecialchars($filtervalues ?? 'NULL') . '</code></p>';
    if (empty($filtervalues)) {
        echo '<p>→ Filter check: <strong style="color:green;">PASS (no filters = show to all)</strong></p>';
    } else {
        $decoded = json_decode($filtervalues, true);
        $debugoutput = var_export($decoded, true);
        echo '<p>→ Decoded filters: <pre>' . htmlspecialchars($debugoutput) . '</pre></p>';
        $result = \local_awareness\helper::check_filters($filtervalues);
        $color = $result ? 'green' : 'red';
        $status = $result ? 'PASS' : 'FAIL';
        echo '<p>→ Filter check: <strong style="color:' . $color . ';">' . $status . '</strong></p>';
    }

    echo '</div>';
}

// 6. Check cache.
echo '<h3>6. Cache status</h3>';
$cache = cache::make('local_awareness', 'enabled_notices');
$cached = $cache->get('records');
if ($cached === false) {
    echo '<p>Cache is empty (will be populated on next page load)</p>';
} else {
    echo '<p>Cache has ' . count($cached) . ' notice(s). Checking first cached notice for new fields...</p>';
    if (!empty($cached)) {
        $first = reset($cached);
        $hasfield = true;
        try {
            $val = $first->get('filtervalues');
            echo '<p>filtervalues in cached object: <code>' . htmlspecialchars($val ?? 'NULL') . '</code></p>';
        } catch (Exception $e) {
            echo '<p style="color:red;">❌ filtervalues NOT accessible in cached object: ' . $e->getMessage() . '</p>';
            echo '<p><strong>FIX: Purge all caches from Site Administration → Development → Purge all caches</strong></p>';
        }
    }
}

echo '<hr>';
echo '<p><em>After debugging, delete this file: local/awareness/debug_filters.php</em></p>';
echo $OUTPUT->footer();
