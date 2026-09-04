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
 * The carousel layout's slides: one shown at a time, moved by the arrows, the dots and the keyboard.
 *
 * Plugin-owned rather than Bootstrap's carousel, whose transition classes are applied by its own
 * JavaScript and change name between Moodle 4.5 and 5.2 - there is no markup that works on both.
 * It never rotates by itself. Inactive slides carry the hidden attribute, so what a test sees is
 * what the reader sees, and the media of a slide being left is paused before the next one shows.
 *
 * @module     local_awareness/carousel
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

const SELECTORS = {
    slide: '[data-region="carousel-slide"]',
    dot: '[data-action="carousel-goto"]',
    prev: '[data-action="carousel-prev"]',
    next: '[data-action="carousel-next"]',
    status: '[data-region="carousel-status"]',
    media: 'video, audio',
    player: '.video-js',
    frame: 'iframe',
};

const MOUNTED = 'laCarouselMounted';

/**
 * Stop whatever is playing inside a slide.
 *
 * A video.js player is paused through its API when the loader initialised one; a plain element is
 * paused directly; an iframe cannot be paused from outside, so its source is written back, which
 * reloads it stopped.
 *
 * @param {HTMLElement} slide The slide being left.
 */
const stopMedia = (slide) => {
    slide.querySelectorAll(SELECTORS.player).forEach((element) => {
        const player = window.videojs && window.videojs.getPlayer ? window.videojs.getPlayer(element) : null;
        if (player && !player.paused()) {
            player.pause();
        }
    });
    slide.querySelectorAll(SELECTORS.media).forEach((element) => {
        if (!element.paused) {
            element.pause();
        }
    });
    slide.querySelectorAll(SELECTORS.frame).forEach((frame) => {
        const source = frame.getAttribute('src');
        if (source) {
            frame.setAttribute('src', source);
        }
    });
};

/**
 * Bind a rendered carousel.
 *
 * @param {HTMLElement|null} root The element carrying data-region="carousel-root".
 */
export const mount = (root) => {
    if (!root || root.dataset[MOUNTED] === '1') {
        return;
    }
    root.dataset[MOUNTED] = '1';

    const slides = Array.from(root.querySelectorAll(SELECTORS.slide));
    const dots = Array.from(root.querySelectorAll(SELECTORS.dot));
    const status = root.querySelector(SELECTORS.status);
    let current = Math.max(0, slides.findIndex((slide) => !slide.hidden));

    /**
     * Show one slide and say so.
     *
     * @param {Number} index Any integer; wraps around.
     */
    const goTo = (index) => {
        const total = slides.length;
        if (!total) {
            return;
        }
        const next = ((index % total) + total) % total;
        if (next === current) {
            return;
        }
        stopMedia(slides[current]);
        slides.forEach((slide, i) => {
            slide.hidden = i !== next;
        });
        dots.forEach((dot, i) => {
            if (i === next) {
                dot.setAttribute('aria-current', 'true');
            } else {
                dot.removeAttribute('aria-current');
            }
        });
        current = next;
        if (status) {
            getString('carousel:slideof', 'local_awareness', {index: next + 1, total: total})
                .then((text) => {
                    status.textContent = text;
                    return null;
                })
                .catch(() => null);
        }
    };

    root.addEventListener('click', (event) => {
        if (event.target.closest(SELECTORS.prev)) {
            goTo(current - 1);
        } else if (event.target.closest(SELECTORS.next)) {
            goTo(current + 1);
        } else {
            const dot = event.target.closest(SELECTORS.dot);
            if (dot) {
                goTo(parseInt(dot.dataset.index, 10));
            }
        }
    });

    /*
     * The arrow keys follow the reading direction: in a right-to-left page the "previous" arrow
     * sits on the right, and the left key should move the way the left arrow points.
     */
    root.addEventListener('keydown', (event) => {
        const rtl = document.documentElement.dir === 'rtl';
        const step = {ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1}[event.key];
        if (step === undefined) {
            return;
        }
        event.preventDefault();
        goTo(current + step);
    });
};
