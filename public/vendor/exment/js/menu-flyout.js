/**
 * The left menu, "to the side" (ISS02958): the items under a first-level item open in a panel to
 * the right of the menu, on hover or click, also with the menu folded, and with the keyboard.
 *
 * Only when <body> has "exm-menu-flyout" (MenuOpen: the user's choice or the site default) and the
 * screen has a fine pointer and is wide (as menu-flyout.css). On a touch screen or a narrow one
 * (the menu is a drawer) AdminLTE's accordion stays as it is.
 *
 * The menu is not drawn again by pjax, so the handlers are set once, on document.
 */
(function () {
    'use strict';
    if (window.exmMenuFlyout) {
        return;
    }

    var OPEN_DELAY = 120;
    var SWITCH_DELAY = 60;
    var CLOSE_DELAY = 300;
    var GAP = 8;
    var TOP = '.sidebar-menu > li.treeview';
    var media = window.matchMedia('(min-width: 768px) and (hover: hover) and (pointer: fine)');

    var openLi = null;
    var openTimer = null;
    var closeTimer = null;

    function active() {
        return document.body.classList.contains('exm-menu-flyout') && media.matches;
    }

    function topLi(el) {
        if (!el || !el.closest) {
            return null;
        }
        var li = el.closest(TOP);
        return li && li.querySelector(':scope > .treeview-menu') ? li : null;
    }

    function linkOf(li) {
        return li.querySelector(':scope > a');
    }

    function panelOf(li) {
        return li.querySelector(':scope > .treeview-menu');
    }

    function prepare() {
        var items = document.querySelectorAll(TOP);
        for (var i = 0; i < items.length; i++) {
            var a = linkOf(items[i]);
            var panel = panelOf(items[i]);
            if (!a || !panel) {
                continue;
            }
            if (!panel.id) {
                panel.id = 'exm-menu-flyout-' + i;
            }
            a.setAttribute('aria-haspopup', 'true');
            a.setAttribute('aria-controls', panel.id);
            a.setAttribute('aria-expanded', 'false');
        }
    }

    // The panel is position: fixed. An ancestor with a transform makes it fixed to that ancestor,
    // not to the window, so the place is measured and corrected.
    function moveTo(panel, left, top) {
        panel.style.setProperty('left', '0px', 'important');
        panel.style.setProperty('top', '0px', 'important');
        var base = panel.getBoundingClientRect();
        panel.style.setProperty('left', (left - base.left) + 'px', 'important');
        panel.style.setProperty('top', (top - base.top) + 'px', 'important');
    }

    function place(li) {
        var a = linkOf(li);
        var panel = panelOf(li);
        var sidebar = li.closest('.main-sidebar') || li.closest('.sidebar') || li.parentElement;
        var item = a.getBoundingClientRect();
        var side = sidebar.getBoundingClientRect();
        var height = window.innerHeight;

        panel.style.setProperty('max-height', (height - GAP * 2) + 'px', 'important');
        // folded: AdminLTE shows the name of the item at its right on hover; the panel goes under it
        var folded = document.body.classList.contains('sidebar-collapse');
        var top = folded ? item.bottom : item.top;
        moveTo(panel, side.right, top);
        var box = panel.getBoundingClientRect();
        if (box.bottom > height - GAP) {
            moveTo(panel, side.right, Math.max(GAP, height - GAP - box.height));
        }
    }

    function clearTimers() {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        openTimer = closeTimer = null;
    }

    function open(li, focusFirst) {
        clearTimers();
        if (openLi && openLi !== li) {
            close(openLi, false);
        }
        openLi = li;
        li.classList.add('exm-flyout-open');
        linkOf(li).setAttribute('aria-expanded', 'true');
        place(li);
        if (focusFirst) {
            var first = links(li)[0];
            if (first) {
                first.focus();
            }
        }
    }

    function close(li, focusParent) {
        if (!li) {
            return;
        }
        li.classList.remove('exm-flyout-open');
        var a = linkOf(li);
        a.setAttribute('aria-expanded', 'false');
        var panel = panelOf(li);
        ['left', 'top', 'max-height'].forEach(function (p) {
            panel.style.removeProperty(p);
        });
        if (openLi === li) {
            openLi = null;
        }
        if (focusParent) {
            a.focus();
        }
    }

    function closeAll() {
        clearTimers();
        if (openLi) {
            close(openLi, false);
        }
    }

    // the links of the panel that can be seen (a folded group inside it hides its own)
    function links(li) {
        var all = panelOf(li).querySelectorAll('a');
        var seen = [];
        for (var i = 0; i < all.length; i++) {
            if (all[i].offsetParent !== null) {
                seen.push(all[i]);
            }
        }
        return seen;
    }

    // a click on a first-level item: open or close its panel. In the capture phase, so that
    // AdminLTE's slide (delegated on .sidebar) does not run for it.
    document.addEventListener('click', function (e) {
        if (!active()) {
            return;
        }
        var a = e.target.closest && e.target.closest(TOP + ' > a');
        if (a && topLi(a)) {
            e.preventDefault();
            e.stopPropagation();
            var li = a.parentElement;
            if (li.classList.contains('exm-flyout-open')) {
                close(li, false);
            } else {
                open(li, false);
            }
            return;
        }
        if (openLi && !openLi.contains(e.target)) {
            closeAll();
        }
    }, true);

    // hover
    document.addEventListener('mouseover', function (e) {
        if (!active()) {
            return;
        }
        var li = topLi(e.target);
        if (!li) {
            return;
        }
        if (li === openLi) {
            clearTimeout(closeTimer);
            closeTimer = null;
            return;
        }
        clearTimeout(openTimer);
        openTimer = setTimeout(function () {
            open(li, false);
        }, openLi ? SWITCH_DELAY : OPEN_DELAY);
    });

    document.addEventListener('mouseout', function (e) {
        if (!active()) {
            return;
        }
        var li = topLi(e.target);
        if (!li) {
            return;
        }
        if (e.relatedTarget && li.contains(e.relatedTarget)) {
            return;
        }
        clearTimeout(openTimer);
        openTimer = null;
        if (li === openLi) {
            clearTimeout(closeTimer);
            closeTimer = setTimeout(function () {
                close(li, false);
            }, CLOSE_DELAY);
        }
    });

    // keyboard
    document.addEventListener('keydown', function (e) {
        if (!active()) {
            return;
        }
        var key = e.key;
        var a = e.target.closest && e.target.closest(TOP + ' > a');
        if (a && topLi(a)) {
            var li = a.parentElement;
            if (key === 'Enter' || key === ' ' || key === 'ArrowRight') {
                e.preventDefault();
                open(li, true);
            } else if ((key === 'Escape' || key === 'ArrowLeft') && li === openLi) {
                e.preventDefault();
                close(li, true);
            }
            return;
        }
        if (!openLi || !panelOf(openLi).contains(e.target)) {
            if (key === 'Escape' && openLi) {
                closeAll();
            }
            return;
        }
        var list = links(openLi);
        var at = list.indexOf(e.target);
        if (key === 'ArrowDown' || key === 'ArrowUp') {
            e.preventDefault();
            var next = key === 'ArrowDown' ? at + 1 : at - 1;
            if (next < 0) {
                next = list.length - 1;
            }
            if (next >= list.length) {
                next = 0;
            }
            if (list[next]) {
                list[next].focus();
            }
        } else if (key === 'Home' || key === 'End') {
            e.preventDefault();
            var to = key === 'Home' ? list[0] : list[list.length - 1];
            if (to) {
                to.focus();
            }
        } else if (key === 'Escape' || key === 'ArrowLeft') {
            e.preventDefault();
            close(openLi, true);
        }
    });

    // the focus goes out of the open item (Tab): close
    document.addEventListener('focusout', function (e) {
        if (!openLi || !openLi.contains(e.target)) {
            return;
        }
        var li = openLi;
        setTimeout(function () {
            if (openLi === li && !li.contains(document.activeElement) && !li.matches(':hover')) {
                close(li, false);
            }
        }, 0);
    });

    // the page moves: close (scrolling inside the panel is fine)
    window.addEventListener('scroll', function (e) {
        if (openLi && !(e.target instanceof Element && panelOf(openLi).contains(e.target))) {
            closeAll();
        }
    }, true);
    window.addEventListener('resize', closeAll);
    if (media.addEventListener) {
        media.addEventListener('change', closeAll);
    }
    // a new page by pjax, the menu folded or opened
    if (window.jQuery) {
        window.jQuery(document).on('pjax:send', closeAll);
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.sidebar-toggle')) {
            closeAll();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', prepare);
    } else {
        prepare();
    }

    window.exmMenuFlyout = { open: open, close: close, closeAll: closeAll, active: active };
})();
