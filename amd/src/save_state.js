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
 * The editor's save-state line.
 *
 * The page head shows when the notice was last saved. This flips that to "unsaved changes" the
 * moment the author touches the form, and then stops listening — the line has only two states and
 * the second one is terminal until the page is submitted.
 *
 * It reports; it does not save. This plugin stores no draft, deliberately: the notice body is
 * already autosaved by core on both supported branches — MoodleQuickForm_editor defaults
 * 'autosave' => true and helper::get_file_editor_options() does not override it, with tiny_autosave
 * shipping in lib/editor/tiny/plugins/autosave — and formslib already wires core_form/changechecker
 * to warn on tab close. A second store would duplicate the largest and most sensitive field, and
 * would have to write into a moodleform to restore it, which is the pattern that produced this
 * page's last two shipped defects.
 *
 * The replacement text comes off a data attribute rather than core/str: it is one string, already
 * resolved server side, and a round trip to fetch it would leave the line lying for as long as the
 * request took.
 *
 * @module     local_awareness/save_state
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    editor: '[data-region="la-editor"]',
    line: '[data-region="la-savestate"]',
    form: 'form.mform',
};

export const init = () => {
    const editor = document.querySelector(SELECTORS.editor);

    if (!editor) {
        return;
    }

    const line = editor.querySelector(SELECTORS.line);
    const form = editor.querySelector(SELECTORS.form);

    if (!line || !form) {
        return;
    }

    const unsaved = line.dataset.labelUnsaved;

    if (!unsaved) {
        return;
    }

    const markDirty = () => {
        line.textContent = unsaved;
        line.classList.add('la-pagehead-autosaved--dirty');
    };

    /*
     * Registered once on both, so the listeners remove themselves. 'input' catches typing, 'change'
     * catches the selects and date pickers that never fire input — and the rich editor, which
     * writes into its textarea rather than being typed into, fires neither, which is precisely the
     * field core is already autosaving for us.
     */
    form.addEventListener('input', markDirty, {once: true});
    form.addEventListener('change', markDirty, {once: true});
};
