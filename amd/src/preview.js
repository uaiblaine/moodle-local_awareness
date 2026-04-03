/**
 * User interaction with notice
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/modal_cancel', 'core/str'],
    function($, ModalCancel, str) {
        var preview = {};

        preview.init = function() {
            $('a.notice-preview').on('click', function(e) {
                var clickedLink = $(e.currentTarget);
                var content = clickedLink.attr('data-noticecontent');
                return ModalCancel.create({
                    title: str.get_string('notice:content', 'local_awareness'),
                    body: content,
                    large: true
                })
                .then(function(modal) {
                    return modal.show();
                });
            });
        };

        return preview;
    }
);
