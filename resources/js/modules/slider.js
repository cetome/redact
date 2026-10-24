// Vertical slider of /about (public/_slider.blade.php): numbered tabs, one screen per step.
//
// <div data-slider> <button role="tab" aria-controls="risks" data-slider-tab> … <div role="tabpanel" id="risks" data-slider-panel>
//
// - Click, ↑/↓ (←/→ too), Home/End move between steps (roving tabindex, the step shows on focus).
// - Hidden panels keep their lazy pictures unloaded: a picture loads when its step is shown.
// - /about#<step> (the home page "What happens next" without script) opens that step.
// - Gentle autoplay while the slider is on screen: 6 s per step (a CSS progress line, its end moves on),
//   stopped for good by any click, key, focus or hover inside, never with prefers-reduced-motion.
// - Without script every panel shows, one under the other.
// - Full screen: the corner button or a click on the picture (see below).

function setup(root) {
    const tabs = [...root.querySelectorAll('[data-slider-tab]')];
    const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));
    if (tabs.length === 0 || panels.some((panel) => !panel)) {
        return;
    }
    let at = 0;
    let playing = false;
    let stopped = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const show = (index, focus = false) => {
        at = (index + tabs.length) % tabs.length;
        tabs.forEach((tab, i) => {
            const on = i === at;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            panels[i].hidden = !on;
        });
        if (focus) {
            tabs[at].focus();
        }
    };

    const stop = () => {
        stopped = true;
        playing = false;
        root.classList.remove('is-playing');
    };

    const play = () => {
        if (stopped || playing) {
            return;
        }
        playing = true;
        root.classList.add('is-playing');
    };

    // The progress line of the active step ends: next step (the animation restarts on the new tab).
    root.addEventListener('animationend', (event) => {
        if (playing && event.animationName === 'r-slider-progress') {
            show(at + 1);
        }
    });

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => {
            stop();
            show(i);
        });
    });

    root.addEventListener('keydown', (event) => {
        const keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
        if (!event.target.closest('[data-slider-tab]')) {
            return;
        }
        let next = null;
        if (event.key in keys) {
            next = at + keys[event.key];
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = tabs.length - 1;
        }
        if (next !== null) {
            event.preventDefault();
            stop();
            show(next, true);
        }
    });

    root.addEventListener('pointerenter', stop);
    root.addEventListener('focusin', stop);

    // Full screen: the 2x picture of the current theme in a native modal <dialog> (focus kept inside,
    // Esc closes, focus back to the opener), in the Fullscreen API when the browser has it.
    const zoom = root.querySelector('[data-slider-zoom]');
    if (zoom) {
        const img = zoom.querySelector('[data-zoom-img]');
        const no = zoom.querySelector('[data-zoom-no]');
        const name = zoom.querySelector('[data-zoom-name]');
        const stage = zoom.querySelector('[data-zoom-stage]');
        let opener = null;

        // The picture shown in the panel (light or dark, whichever the theme displays), its 2x source.
        const source = (panel) => {
            const shown = [...panel.querySelectorAll('.r-tour-figure img')].find((el) => el.offsetParent !== null)
                || panel.querySelector('.r-tour-figure img');
            if (!shown) {
                return null;
            }
            const two = (shown.getAttribute('srcset') || '').split(',').map((s) => s.trim().split(/\s+/))
                .find((parts) => parts[1] === '2x');

            return { src: two ? two[0] : shown.currentSrc || shown.src, alt: shown.alt };
        };

        const fill = () => {
            const picture = source(panels[at]);
            img.src = picture ? picture.src : '';
            img.alt = picture ? picture.alt : '';
            no.textContent = String(at + 1).padStart(2, '0');
            name.textContent = tabs[at].querySelector('.r-slider-name')?.textContent || '';
        };

        const go = (step) => {
            show(at + step);
            fill();
        };

        const open = (trigger) => {
            stop();
            opener = trigger;
            fill();
            zoom.showModal();
            if (zoom.requestFullscreen && !document.fullscreenElement) {
                zoom.requestFullscreen().catch(() => {});
            }
        };

        const close = () => {
            if (document.fullscreenElement === zoom) {
                document.exitFullscreen().catch(() => {});
            }
            if (zoom.open) {
                zoom.close();
            }
        };

        root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-zoom-open]');
            const picture = !button && event.target.closest('.r-slider-panel .r-tour-figure img');
            if (button || picture) {
                open(button || picture);
            }
        });

        zoom.addEventListener('click', (event) => {
            const step = event.target.closest('[data-zoom-go]');
            if (step) {
                go(Number(step.dataset.zoomGo));
            } else if (event.target.closest('[data-zoom-close]') || event.target === zoom || event.target === stage) {
                close();
            }
        });

        zoom.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                event.preventDefault();
                go(event.key === 'ArrowRight' ? 1 : -1);
            }
        });

        // Leaving the browser's full screen (Esc) closes the picture too.
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement && zoom.open) {
                zoom.close();
            }
        });

        zoom.addEventListener('close', () => {
            if (document.fullscreenElement === zoom) {
                document.exitFullscreen().catch(() => {});
            }
            // Back to the opener, or to the button of the step now shown when the step changed.
            const target = opener && opener.closest('[data-slider-panel]') === panels[at]
                ? opener
                : panels[at].querySelector('[data-zoom-open]') || tabs[at];
            target.focus();
            opener = null;
        });

        // A horizontal swipe on touch screens moves between steps.
        let startX = null;
        stage.addEventListener('pointerdown', (event) => {
            startX = event.pointerType === 'touch' ? event.clientX : null;
        });
        stage.addEventListener('pointerup', (event) => {
            if (startX !== null && Math.abs(event.clientX - startX) > 50) {
                go(event.clientX < startX ? 1 : -1);
            }
            startX = null;
        });
    }

    const fromHash = tabs.findIndex((tab) => `#${tab.getAttribute('aria-controls')}` === window.location.hash);
    if (fromHash >= 0) {
        stopped = true;
    }
    show(Math.max(0, fromHash));
    root.classList.add('is-ready');
    if (fromHash >= 0) {
        // After the browser's own jump to the panel: show the section title too.
        const land = () => (root.closest('section') || root).scrollIntoView({ block: 'start' });
        if (document.readyState === 'complete') {
            land();
        } else {
            window.addEventListener('load', () => setTimeout(land, 0), { once: true });
        }
    }

    if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    play();
                } else if (playing) {
                    playing = false;
                    root.classList.remove('is-playing');
                }
            });
        }, { threshold: 0.4 }).observe(root);
    }
}

document.querySelectorAll('[data-slider]').forEach(setup);
