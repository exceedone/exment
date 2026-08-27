/*!
 * grid_tools.js - toolbar helpers for the Exment data grid.
 *
 * Adds controls that work on every view kind rendering the standard
 * table (default view, all-data view, filter view):
 *   - column visibility  -> rewrites the `_columns_` query parameter
 *   - column pin         -> `position: sticky` on the chosen columns
 *   - group rows         -> reorders the rows of the current page
 *   - page filter        -> hides the rows of the current page that do
 *                           not match the cell the menu was opened on
 *   - density switch     -> a class on the table, no request at all
 *   - auto refresh       -> periodic pjax reload of the current URL
 *   - bulk action bar    -> second entrance to the batch actions
 *
 * Every listener is delegated on `document`, so the file is loaded once
 * (from Middleware\Bootstrap) and keeps working after any number of
 * pjax navigations - the replaced DOM inherits the same behaviour.
 */
(function () {
  'use strict';

  var DENSITY_KEY = 'exment_grid_density';
  var DENSITIES = ['compact', 'comfortable', 'spacious'];
  var DEFAULT_DENSITY = 'comfortable';
  var REFRESH_PREFIX = 'exment_grid_refresh_';
  var PIN_PREFIX = 'exment_grid_pin_';
  var PIN_RIGHT_PREFIX = 'exment_grid_pinright_';
  var PIN_HEAD_PREFIX = 'exment_grid_pinhead_';
  var GROUP_PREFIX = 'exment_grid_group_';
  var NOWRAP_KEY = 'exment_grid_nowrap';
  // sessionStorage, both of them, and on purpose: which groups are folded
  // and what the page is filtered on are "this sitting" state like the
  // grouping column itself - none of it should ambush the user tomorrow.
  var GROUP_FOLD_PREFIX = 'exment_grid_groupfold_';
  var FILTER_PREFIX = 'exment_grid_pagefilter_';

  // laravel-admin's names for the two columns it adds itself. They are
  // rendered as `column-__row_selector__` / `column-__actions__` like any
  // other column, so the pin code can address them the same way.
  var SELECTOR_COLUMN = '__row_selector__';
  var ACTION_COLUMN = '__actions__';

  function each(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      if (el.matches && el.matches(sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  // Storage access is wrapped: private-browsing modes and locked-down
  // policies make localStorage throw on access rather than return null.
  function readStore(store, key) {
    try {
      return window[store].getItem(key);
    } catch (e) {
      return null;
    }
  }

  function writeStore(store, key, value) {
    try {
      if (value === null) {
        window[store].removeItem(key);
      } else {
        window[store].setItem(key, String(value));
      }
    } catch (e) {
      /* preference simply does not survive the reload */
    }
  }

  /**
   * An on/off preference whose default is not "off".
   *
   * `=== '1'` cannot express that: it reads an absent key and an explicit
   * "no" as the same thing, so a shipped-on switch could never be turned
   * off for good. Storing '0' keeps the two apart.
   */
  function readFlag(key, fallback) {
    var raw = readStore('localStorage', key);
    if (raw === null || raw === undefined || raw === '') return fallback;
    return raw === '1';
  }

  /**
   * Tick the entry matching `value` in a dropdown and untick the rest.
   */
  function markActive(box, itemSelector, attribute, value) {
    each(box.querySelectorAll(itemSelector), function (item) {
      if (item.getAttribute(attribute) === value) {
        item.classList.add('active');
      } else {
        item.classList.remove('active');
      }
    });
  }

  /**
   * The grid table a toolbar button belongs to.
   */
  function gridOf(box) {
    return document.getElementById(box.getAttribute('data-grid'));
  }

  /**
   * The element that actually scrolls sideways. `position: sticky` is
   * resolved against it, and it is also what the shadow classes go on.
   */
  function scrollBoxOf(table) {
    return closest(table, '.table-responsive');
  }

  /**
   * Grid column name of a cell, read back from its `column-<name>` class.
   *
   * The table blade writes that class on every th and td, which makes it
   * the one link between a column in the toolbar menus and its cells -
   * cheaper and far more robust than counting cell positions, which any
   * hidden column or colspan would throw off.
   */
  function columnNameOf(cell) {
    var classes = cell.classList;
    for (var i = 0; i < classes.length; i++) {
      if (classes[i].indexOf('column-') === 0) {
        return classes[i].substring(7);
      }
    }
    return '';
  }

  /**
   * The text a cell shows, whitespace collapsed.
   *
   * This is the only value the browser has for a cell - the stored value
   * never reaches the page - and it is also what the user is reading, so
   * grouping and filtering both key on it: two rows match exactly when
   * they look alike.
   */
  function cellText(cell) {
    return cell ? (cell.textContent || '').replace(/\s+/g, ' ').trim() : '';
  }

  /**
   * Column names in render order, without the two columns laravel-admin
   * adds itself.
   */
  function dataColumnNames(table) {
    var names = [];
    each(table.querySelectorAll('thead > tr > th'), function (th) {
      var name = columnNameOf(th);
      if (name && name !== SELECTOR_COLUMN && name !== ACTION_COLUMN) {
        names.push(name);
      }
    });
    return names;
  }

  /* ------------------------------------------------------- density --- */

  function currentDensity() {
    var d = readStore('localStorage', DENSITY_KEY);
    return DENSITIES.indexOf(d) === -1 ? DEFAULT_DENSITY : d;
  }

  function applyDensity(table, density) {
    if (!table) return;
    // `exm-grid` is what scopes grid_tools.css to the data grid, so it is
    // set here rather than server-side: the stock laravel-admin table
    // blade has no hook for extra table classes.
    table.classList.add('exm-grid');
    DENSITIES.forEach(function (d) {
      table.classList.remove('table-density-' + d);
    });
    table.classList.add('table-density-' + density);
  }

  /* ---------------------------------------------------- one line --- */

  /**
   * One row = one line.
   *
   * Without it a long text column drags its row to two or three lines and
   * the row heights go ragged (56-114px measured on the incident grid),
   * which is what makes a list impossible to scan down a column. The text
   * is not lost - syncCellTitles moves it into `title`.
   */
  function nowrapOn() {
    return readFlag(NOWRAP_KEY, true);
  }

  function applyNowrap(table, on) {
    if (!table) return;
    table.classList.toggle('exm-nowrap', on);
    syncCellTitles(table, on);
  }

  /**
   * Hover text for the cells that ended up cut.
   *
   * Only the cut ones: a `title` on every cell turns an ordinary mouse
   * rest into a tooltip storm. Cells that came with their own title are
   * left alone, which is why the ones we set are marked.
   */
  function syncCellTitles(table, on) {
    each(table.querySelectorAll('tbody > tr > td'), function (td) {
      var ours = td.getAttribute('data-exm-cut') === '1';
      if (!on) {
        if (ours) {
          td.removeAttribute('title');
          td.removeAttribute('data-exm-cut');
        }
        return;
      }
      if (td.title && !ours) return;
      if (td.scrollWidth > td.clientWidth + 1) {
        td.title = cellText(td);
        td.setAttribute('data-exm-cut', '1');
      } else if (ours) {
        td.removeAttribute('title');
        td.removeAttribute('data-exm-cut');
      }
    });
  }

  function initDensity() {
    var density = currentDensity();
    var wrap = nowrapOn();
    each(document.querySelectorAll('.exm-grid-density[data-grid]'), function (box) {
      var table = document.getElementById(box.getAttribute('data-grid'));
      applyDensity(table, density);
      applyNowrap(table, wrap);
      markActive(box, '.exm-density-item', 'data-density', density);
      var item = box.querySelector('.exm-wrap-item');
      if (item) item.classList.toggle('active', wrap);
    });
  }

  /* -------------------------------------------------- auto refresh --- */

  /**
   * The timer id lives at module scope, NOT on the button element. A pjax
   * navigation throws the old toolbar away while the interval keeps
   * running in the background, so keeping the id here is what lets us
   * clear the previous timer - even after N reloads, and even when the
   * user has navigated to a page that has no grid at all.
   */
  var refreshTimer = null;
  var pjaxInFlight = false;
  // Debounce flag: jQuery-pjax fires `pjax:complete` and `pjax:end` in
  // rapid succession. Running our `initAll` twice discards work the
  // first call did - most visibly the bulk bar it moved into <body>.
  // A microtask defer collapses the pair down to one boot.
  var initAllQueued = false;

  function initRefresh() {
    if (refreshTimer) {
      clearInterval(refreshTimer);
      refreshTimer = null;
    }

    var box = document.querySelector('.exm-grid-refresh[data-key]');
    if (!box) return;

    var sec = parseInt(readStore('sessionStorage', REFRESH_PREFIX + box.getAttribute('data-key')) || '0', 10);
    if (isNaN(sec) || sec < 0) sec = 0;

    var btn = box.querySelector('.exm-refresh-btn');
    var label = box.querySelector('.exm-refresh-timer');
    markActive(box, '.exm-refresh-item', 'data-sec', String(sec));

    if (sec <= 0) {
      if (btn) btn.classList.remove('active');
      if (label) label.textContent = '';
      return;
    }

    if (btn) btn.classList.add('active');
    if (label) label.textContent = sec + 's';

    refreshTimer = setInterval(function () {
      // Skip while another reload is in flight or the tab is hidden,
      // otherwise identical requests pile up on the same URL. Also never
      // yank the page from under an open modal.
      if (pjaxInFlight || document.hidden) return;
      // The bulk edit overlay is its own markup, not a Bootstrap
      // `.modal`, so it needs naming here or the refresh fires straight
      // through it - the selection being edited would untick under the
      // user mid-modal.
      if (document.querySelector('.modal.show, .modal.d-block, .exm-bulk-modal-overlay')) return;
      // Same for an open right-click menu: swapping the grid under it
      // tears the menu away just as the user is aiming at an entry.
      if (activeCtx) return;
      // Never pull the page out from under an edit in progress. The
      // picked value would be dropped on the floor (the swap cancels the
      // editor, it does not commit it) and a save still in flight would
      // come back to a cell that no longer exists.
      if (activeInlineTd || document.querySelector('td.exm-editing, td.exm-saving')) return;

      if (window.$ && typeof window.$.pjax === 'function') {
        pjaxInFlight = true;
        try {
          window.$.pjax.reload({ container: '#pjax-container', url: window.location.href });
        } catch (e) {
          pjaxInFlight = false;
        }
      } else {
        window.location.reload();
      }
    }, sec * 1000);
  }

  /* ----------------------------------------------- column visibility --- */

  function pjaxGo(url) {
    if (window.$ && typeof window.$.pjax === 'function') {
      pjaxInFlight = true;
      try {
        window.$.pjax({ container: '#pjax-container', url: url });
        return;
      } catch (e) {
        pjaxInFlight = false;
      }
    }
    window.location.href = url;
  }

  function hideModal(modal) {
    if (!modal) return;
    try {
      if (window.bootstrap && window.bootstrap.Modal) {
        var inst = window.bootstrap.Modal.getInstance(modal);
        if (inst) {
          inst.hide();
          return;
        }
      }
    } catch (e) {
      /* fall through to the manual close below */
    }
    modal.classList.remove('show', 'd-block');
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('aria-modal');
    modal.style.display = 'none';
    cleanupBackdrops();
  }

  /**
   * Bootstrap 5 appends `.modal-backdrop` to <body>, outside
   * `#pjax-container`. When pjax swaps the container while a modal is
   * open the modal itself disappears but the backdrop stays behind and
   * blocks every click on the new page, so it has to be cleared by hand.
   */
  function cleanupBackdrops() {
    if (document.querySelector('.modal.show, .modal.d-block')) return;
    each(document.querySelectorAll('.modal-backdrop'), function (b) {
      b.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  }

  function applyColumns(applyBtn) {
    var modal = closest(applyBtn, '.modal');
    if (!modal) return;

    var selected = [];
    var defaults = [];
    each(modal.querySelectorAll('.exm-col-check'), function (c) {
      if (c.checked) selected.push(c.value);
      if (c.getAttribute('data-default') === '1') defaults.push(c.value);
    });

    var warn = modal.querySelector('.exm-col-warn');
    // Submitting an empty set would not "show everything": the grid falls
    // back to the checkbox and action columns only, which looks like the
    // table lost its data. Refuse it instead.
    if (!selected.length) {
      if (warn) warn.classList.add('show');
      return;
    }
    if (warn) warn.classList.remove('show');

    var url = new URL(window.location.href);
    if (selected.slice().sort().join(',') === defaults.slice().sort().join(',')) {
      // Back to the view's own columns - drop the parameter so the URL
      // stays clean and shareable.
      url.searchParams.delete('_columns_');
    } else {
      url.searchParams.set('_columns_', selected.join(','));
    }

    hideModal(modal);
    pjaxGo(url.toString());
  }

  /* --------------------------------------------------- column pin --- */

  /**
   * The pinned set, or null when the user has never chosen one.
   *
   * The null matters: "never touched this grid" takes the shipped default
   * (defaultPins), while "unpinned everything" has to stay unpinned.
   */
  function readPins(key) {
    var raw = readStore('localStorage', PIN_PREFIX + key);
    if (raw === null || raw === undefined || raw === '') return null;
    try {
      var list = JSON.parse(raw);
      return Array.isArray(list) ? list : [];
    } catch (e) {
      // A hand-edited or half-written entry must not take the grid down.
      return [];
    }
  }

  function writePins(key, list) {
    // An empty list is written, not removed - see readPins.
    writeStore('localStorage', PIN_PREFIX + key, JSON.stringify(list));
  }

  /**
   * What freezes on a grid nobody has configured.
   *
   * Only the first data column, and only when the table really is wider
   * than its box: on a table that fits there is nothing to scroll under a
   * frozen column and the seam would be drawn for no gain.
   */
  function defaultPins(table) {
    var sc = scrollBoxOf(table);
    if (!sc || sc.scrollWidth <= sc.clientWidth + 4) return [];
    var names = dataColumnNames(table);
    return names.length > 2 ? [names[0]] : [];
  }

  /**
   * "Scroll inside the table" mode: cap the scroll box at the viewport
   * and let the header row stick to its top edge.
   *
   * Sticky-top only works against a vertically scrolling ancestor, and
   * the stock layout scrolls the whole document - which is exactly why
   * the header disappears two screens into a long page today. Turning
   * the existing `.table-responsive` into that ancestor keeps both
   * scrollbars on one element, so the column pins (sticky-left against
   * the same box) keep working unchanged.
   *
   * Runs BEFORE the pin measuring pass on purpose: capping the height is
   * what makes the vertical scrollbar appear, and the ~15px it eats have
   * to be gone from the box before any column width is read.
   */
  function applyHeadLock(box, table, key) {
    var sc = scrollBoxOf(table);
    if (!sc) return;

    // Ships on: a header row that scrolls away is the single most common
    // complaint about a long list, and the switch is one click away.
    var on = readFlag(PIN_HEAD_PREFIX + key, true);
    var item = box.querySelector('.exm-pin-head');
    if (item) item.classList.toggle('active', on);

    sc.classList.toggle('exm-headlock', on);
    if (!on) {
      sc.classList.remove('exm-headlock-scrolled');
      sc.style.removeProperty('max-height');
      return;
    }

    // Measured, not a fixed calc(): the space above the table moves with
    // the toolbar wrapping, the breadcrumb and the filter chip. 60px
    // keeps the paginator visible below the box; the 200px floor stops a
    // small window from squeezing the table into a letterbox (then the
    // page scrolls as before - worse than ideal, never broken).
    var top = sc.getBoundingClientRect().top
      + (window.pageYOffset || document.documentElement.scrollTop || 0);
    var h = Math.max(200, window.innerHeight - top - 60);
    sc.style.maxHeight = h + 'px';
  }

  /**
   * Freeze the chosen columns.
   *
   * No markup is duplicated, unlike laravel-admin's own FixColumns: the
   * table stays one table and every cell of a pinned column just gets
   * `.exm-pin` plus the `left` it has to stick at. Everything hanging off
   * a row - the batch checkbox, the action links, the cell appearance
   * markup - therefore keeps working untouched.
   */
  function applyPins(box, notify) {
    var table = gridOf(box);
    if (!table) return;

    var key = box.getAttribute('data-key');
    // Ships on for the same reason as the header lock: on a wide grid the
    // view/edit links of a row sit outside the window, so the one thing a
    // list exists for - opening a record - needs a sideways scroll first.
    var pinRight = readFlag(PIN_RIGHT_PREFIX + key, true);

    // Height first, widths second - see the note on applyHeadLock.
    applyHeadLock(box, table, key);

    // Measured after the height pass on purpose: capping the box is what
    // brings the vertical scrollbar in, and defaultPins compares widths.
    var pinned = readPins(key);
    if (pinned === null) pinned = defaultPins(table);

    // Full reset first: the offsets are cumulative, so they can only be
    // measured on a table where nothing is sticky yet.
    each(table.querySelectorAll('.exm-pin, .exm-pin-last, .exm-pin-right-first'), function (cell) {
      cell.classList.remove('exm-pin', 'exm-pin-last', 'exm-pin-right-first');
      cell.style.removeProperty('left');
      cell.style.removeProperty('right');
    });
    each(table.querySelectorAll('.exm-pin-flag'), function (flag) {
      flag.remove();
    });

    // Order comes from the table, never from the order the user ticked
    // the menu: pinned columns have to stack the way they are rendered or
    // the frozen block would not match the row it came from.
    var group = [];
    each(table.querySelectorAll('thead > tr > th'), function (th) {
      var name = columnNameOf(th);
      if (!name || name === SELECTOR_COLUMN) return;
      if (pinned.indexOf(name) > -1) group.push(name);
    });

    // The row selector travels with the frozen block - a checkbox
    // drifting away from its own row reads as a broken table - but only
    // when there IS a block. A pin whose column the column picker has
    // since hidden leaves nothing on screen to freeze, and freezing the
    // checkbox on its own looks like a bug rather than a preference.
    if (group.length && table.querySelector('thead > tr > th.column-' + SELECTOR_COLUMN)) {
      group.unshift(SELECTOR_COLUMN);
    }

    // The markers go in before anything is measured. A narrow column such
    // as the id grows by the width of the icon, and an offset measured
    // before that happens leaves the next frozen column sitting on top of
    // it - which is exactly how the marker ended up half cut off.
    var flagTitle = box.getAttribute('data-flag') || '';
    group.forEach(function (name) {
      if (name === SELECTOR_COLUMN) return;
      var th = table.querySelector('thead > tr > th.column-' + name);
      if (!th) return;
      th.insertAdjacentHTML(
        'beforeend',
        '<i class="fa fa-thumbtack exm-pin-flag" title="' + flagTitle + '"></i>'
      );
    });

    // A frozen block wider than the box leaves nothing to scroll: on a
    // phone, "pin 3 columns" adds up to more than the whole grid and
    // horizontal scrolling turns into a no-op (measured 444px pinned in
    // a 330px box). Pins therefore apply only while they fit inside a
    // share of the box. The STORED choice is kept whole on purpose - the
    // same grid pins fully again the moment it gets a wider screen.
    var scBox = scrollBoxOf(table);
    var budget = scBox ? scBox.clientWidth * 0.6 : Infinity;

    var left = 0;
    var keptData = 0;
    var dropped = 0;
    var lastKept = null;
    group.forEach(function (name) {
      var cells = table.querySelectorAll('.column-' + name);
      if (!cells.length) return;

      // Fractional widths are kept as they are. Rounding every column and
      // adding up the rounded numbers is what produces the 1px seams
      // between frozen columns.
      var width = 0;
      each(cells, function (cell) {
        var w = cell.getBoundingClientRect().width;
        if (w > width) width = w;
      });

      // The selector column rides along for free; a data column has to
      // fit the budget. The FIRST data column is pinned even when it
      // alone is over - the user asked for that column by name, and one
      // frozen column always leaves the rest of the box scrollable.
      var isData = name !== SELECTOR_COLUMN;
      if (isData && keptData > 0 && left + width > budget) {
        dropped++;
        var straggler = table.querySelector('thead > tr > th.column-' + name + ' .exm-pin-flag');
        if (straggler) straggler.remove();
        return;
      }

      each(cells, function (cell) {
        cell.classList.add('exm-pin');
        cell.style.left = left + 'px';
      });
      if (isData) keptData++;
      lastKept = name;
      left += width;
    });
    // The seam shadow belongs to the rightmost column that actually froze.
    if (lastKept) {
      each(table.querySelectorAll('.column-' + lastKept), function (cell) {
        cell.classList.add('exm-pin-last');
      });
    }

    // Said once, on the action itself - not again on every resize or
    // pjax reload re-applying the same stored pins.
    if (notify && dropped > 0) {
      var narrowMsg = box.getAttribute('data-narrow') || '';
      if (narrowMsg && window.toastr && typeof toastr.info === 'function') {
        toastr.info(narrowMsg);
      }
    }

    // On by default (see above). On a table that fits, sticky-right
    // resolves to the cell's own place, so it costs nothing there.
    if (pinRight) {
      each(table.querySelectorAll('.column-' + ACTION_COLUMN), function (cell) {
        cell.classList.add('exm-pin', 'exm-pin-right-first');
        cell.style.right = '0px';
      });
    }

    each(box.querySelectorAll('.exm-pin-item'), function (item) {
      item.classList.toggle('active', pinned.indexOf(item.getAttribute('data-col')) > -1);
    });
    var rightItem = box.querySelector('.exm-pin-right');
    if (rightItem) rightItem.classList.toggle('active', pinRight);
    var btn = box.querySelector('.exm-pin-btn');
    if (btn) btn.classList.toggle('active', group.length > 0 || pinRight);

    updatePinShadow(table);
  }

  /**
   * The seam shadows only make sense once something is scrolled under
   * them, so they are switched by the scroll position rather than being
   * always on.
   */
  function updatePinShadow(table) {
    var sc = scrollBoxOf(table);
    if (!sc) return;
    sc.classList.toggle('exm-pin-scrolled', sc.scrollLeft > 2);
    sc.classList.toggle('exm-pin-scrolled-end', sc.scrollLeft + sc.clientWidth < sc.scrollWidth - 2);
    // Vertical twin of the seam shadows above: the line under the locked
    // header only appears once rows are actually sliding beneath it.
    sc.classList.toggle('exm-headlock-scrolled',
      sc.classList.contains('exm-headlock') && sc.scrollTop > 2);
  }

  /**
   * Move the filter chips from the toolbar to just above the table.
   *
   * A grid tool can only render inside the toolbar, but that is not where
   * the chips belong: they say what the list below IS, so they read as a
   * caption for the table - and that is also where the page-filter chip
   * appears, so the two kinds of "this list is narrowed" sit together
   * instead of in two unrelated places.
   */
  function placeFilterChips() {
    var chips = document.querySelector('.exm-filter-chips');
    if (!chips || chips.getAttribute('data-exm-placed') === '1') return;

    var table = document.querySelector('table.exm-grid');
    var sc = table ? scrollBoxOf(table) : null;
    if (!sc || !sc.parentNode) return;

    chips.setAttribute('data-exm-placed', '1');
    sc.parentNode.insertBefore(chips, sc);
  }

  function initPin() {
    each(document.querySelectorAll('.exm-grid-pin[data-grid]'), function (box) {
      var table = gridOf(box);
      if (!table) return;

      var sc = scrollBoxOf(table);
      // The container is thrown away and rebuilt by every pjax load, so
      // the listener dies with it; the marker only guards against binding
      // twice within one page.
      if (sc && !sc.hasAttribute('data-exm-pin-bound')) {
        sc.setAttribute('data-exm-pin-bound', '1');
        sc.addEventListener('scroll', function () {
          updatePinShadow(table);
        });
      }

      applyPins(box);
    });
  }

  /* --------------------------------------------------- group rows --- */

  /**
   * Reorder the rows of the current page into groups.
   *
   * Client side on purpose - it costs no query and cannot disturb the
   * filter, the sort or the paginator. The price is that a group only
   * ever covers the page in front of the user, which is why every header
   * carries the "this page only" label the toolbar button hands over.
   *
   * Rows are grouped on the text the cell shows, not on the stored value:
   * that is the only thing the browser has, and it is also what the user
   * is reading, so two rows group together exactly when they look alike.
   */
  function applyGroup(box) {
    var table = gridOf(box);
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var key = box.getAttribute('data-key');
    var col = readStore('sessionStorage', GROUP_PREFIX + key) || '';

    each(tbody.querySelectorAll('tr.exm-group-row'), function (tr) {
      tr.remove();
    });
    each(tbody.querySelectorAll('tr.exm-row-hidden'), function (tr) {
      tr.classList.remove('exm-row-hidden');
    });

    markActive(box, '.exm-group-item', 'data-col', col);
    var btn = box.querySelector('.exm-group-btn');
    if (btn) btn.classList.toggle('active', !!col);
    if (!col) return;

    var order = [];
    var buckets = {};
    each(tbody.querySelectorAll('tr'), function (tr) {
      // A row the page filter took out is not part of any group, so it is
      // neither counted nor moved. Leaving it out is what keeps the count
      // on a group header equal to the number of rows actually under it.
      if (tr.classList.contains('exm-row-filtered')) return;
      var cell = tr.querySelector('td.column-' + col);
      if (!cell) return;
      var value = cellText(cell);
      // Prefixed so a cell reading "constructor" or "__proto__" cannot
      // collide with something already on Object.prototype.
      var k = 'v' + value;
      if (!buckets[k]) {
        buckets[k] = { label: value, rows: [] };
        order.push(k);
      }
      buckets[k].rows.push(tr);
    });

    // Nothing matched - the column is hidden by the column picker, so
    // there is no value on screen to group by.
    if (!order.length) return;

    var span = table.querySelectorAll('thead > tr > th').length || 1;
    var pageLabel = box.getAttribute('data-page-label') || '';
    // Folds recorded for this table + this column. Everything below is a
    // rebuild - a regroup, a filter, an inline edit of the grouping
    // column - and without this the rebuild would silently unfold every
    // group the user had just closed.
    var fold = readGroupFold(key, col);
    var refolded = false;

    order.forEach(function (k) {
      var bucket = buckets[k];

      var head = document.createElement('tr');
      head.className = 'exm-group-row';
      // The raw cell value, NOT the '-' placeholder the empty label is
      // displayed as: the fold store and the click handler must speak
      // the same value or the empty group can never stay folded.
      head.setAttribute('data-exm-group-val', bucket.label);
      var td = document.createElement('td');
      td.setAttribute('colspan', String(span));

      var label = document.createElement('span');
      label.className = 'exm-group-label';
      label.innerHTML = '<i class="fa fa-caret-down"></i>';

      // textContent, never innerHTML: the label is a cell value, i.e.
      // data somebody typed into the table.
      var text = document.createElement('span');
      text.textContent = bucket.label === '' ? '-' : bucket.label;
      label.appendChild(text);

      var count = document.createElement('span');
      count.className = 'exm-group-count';
      count.textContent = String(bucket.rows.length);
      label.appendChild(count);

      if (pageLabel) {
        var page = document.createElement('span');
        page.className = 'exm-group-page';
        page.textContent = pageLabel;
        label.appendChild(page);
      }

      td.appendChild(label);
      head.appendChild(td);

      // appendChild moves the rows that are already in the tbody, so
      // walking the groups in order is enough to reorder the whole table.
      tbody.appendChild(head);
      bucket.rows.forEach(function (tr) {
        tbody.appendChild(tr);
      });

      if (fold.indexOf(bucket.label) > -1) {
        setGroupCollapsed(head, true);
        refolded = true;
      }
    });

    // Rows just went invisible again, so the selection rule has to run
    // over them (see syncBulk) - deferred like every other caller.
    if (refolded) setTimeout(syncBulk, 0);
  }

  function initGroup() {
    each(document.querySelectorAll('.exm-grid-group[data-grid]'), applyGroup);
  }

  /**
   * Labels of the groups folded shut, remembered per table AND per
   * grouping column: "in progress" folded under `state` says nothing
   * about a heading that happens to read the same under `priority`.
   */
  function readGroupFold(key, col) {
    var raw = readStore('sessionStorage', GROUP_FOLD_PREFIX + key);
    if (!raw) return [];
    try {
      var saved = JSON.parse(raw);
      if (!saved || saved.c !== col || !Array.isArray(saved.l)) return [];
      return saved.l;
    } catch (e) {
      return [];
    }
  }

  function writeGroupFold(key, col, labels) {
    writeStore('sessionStorage', GROUP_FOLD_PREFIX + key,
      labels.length ? JSON.stringify({ c: col, l: labels }) : null);
  }

  /**
   * Fold a group shut / open it back up. One walker for the click
   * handler and for the rebuild in applyGroup, so the two can never
   * disagree on what "folded" looks like. Selection cleanup is NOT here:
   * syncBulk owns the "hidden rows leave the selection" rule, callers
   * only have to schedule it.
   */
  function setGroupCollapsed(groupRow, collapsed) {
    groupRow.classList.toggle('collapsed', collapsed);
    var caret = groupRow.querySelector('.fa');
    if (caret) caret.className = collapsed ? 'fa fa-caret-right' : 'fa fa-caret-down';
    var next = groupRow.nextElementSibling;
    while (next && !next.classList.contains('exm-group-row')) {
      next.classList.toggle('exm-row-hidden', collapsed);
      next = next.nextElementSibling;
    }
  }

  /**
   * Column a table is currently grouped by, '' when grouping is off.
   *
   * Read back from the same store `applyGroup` writes, so there is one
   * source of truth for "is this column the one the headings are built
   * on" - which is what tells an edited cell whether the row it sits in
   * has to be re-placed.
   */
  function groupColumnOf(table) {
    if (!table || !table.id) return '';
    var box = document.querySelector('.exm-grid-group[data-grid="' + table.id + '"]');
    if (!box) return '';
    return readStore('sessionStorage', GROUP_PREFIX + box.getAttribute('data-key')) || '';
  }

  /**
   * Storage key for per-table client state, read off whichever toolbar
   * box is present. The group and pin tools are both constructed with
   * the same server-side key (the table name - DefaultGrid passes it to
   * both), so it does not matter which one answers; what matters is that
   * the key comes from the server and not from the DOM id, which
   * laravel-admin regenerates per render.
   */
  function storeKeyOf(table) {
    if (!table || !table.id) return '';
    var box = document.querySelector(
      '.exm-grid-group[data-grid="' + table.id + '"], .exm-grid-pin[data-grid="' + table.id + '"]'
    );
    return box ? (box.getAttribute('data-key') || '') : '';
  }

  /* --------------------------------------------------- page filter --- */

  /**
   * "Show me only the rows whose <column> reads like this one."
   *
   * Client side, over the rows of the current page - the same contract
   * grouping works under, and for the same reason: the grid only accepts
   * a query parameter for a column the view lists in its grid filters,
   * and a view that lists none - which is the default - registers no
   * custom column at all. There is no URL that could carry "state =
   * in progress", so a server round trip would land on an unfiltered
   * page and quietly look like the feature did nothing.
   *
   * Hiding uses `.exm-row-filtered`, deliberately NOT the
   * `.exm-row-hidden` a collapsed group sets. The two are independent
   * reasons for a row to be invisible and both may hold at once;
   * sharing one class would mean expanding a group un-hides rows the
   * filter had taken out.
   */
  var FILTER_COL_ATTR = 'data-exm-filter-col';
  var FILTER_VAL_ATTR = 'data-exm-filter-val';

  function applyRowFilter(table, menu, col, value) {
    if (!table || !col || col.indexOf('__') === 0) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    // Start from a clean slate: a second filter replaces the first rather
    // than narrowing it further, so what the menu offers is always what
    // the user gets.
    clearRowFilter(table, true);

    var rows = [];
    each(tbody.querySelectorAll('tr'), function (tr) {
      if (tr.classList.contains('exm-group-row')) return;
      var cell = tr.querySelector('td.column-' + col);
      // No cell means the column is hidden by the column picker. Then
      // there is no value on screen to compare against and hiding every
      // row would be the only possible outcome - refuse instead.
      if (!cell) return;
      rows.push({ tr: tr, text: cellText(cell) });
    });
    if (!rows.length) return;

    var hidden = 0;
    rows.forEach(function (row) {
      if (row.text === value) return;
      row.tr.classList.add('exm-row-filtered');
      // A hidden row must not stay in the selection: the bulk bar counts
      // what is ticked, and a batch delete would reach rows the user can
      // no longer see.
      uncheckBox(row.tr.querySelector('.grid-row-checkbox'));
      hidden++;
    });

    table.setAttribute(FILTER_COL_ATTR, col);
    table.setAttribute(FILTER_VAL_ATTR, value);

    // Grouping survives a reload (sessionStorage) - a filter that did not
    // would silently evaporate on the next auto-refresh tick while the
    // button that caused it sits right next to the one that set it up.
    // Same store, same lifetime.
    var storeKey = storeKeyOf(table);
    if (storeKey) {
      writeStore('sessionStorage', FILTER_PREFIX + storeKey,
        JSON.stringify({ c: col, v: value }));
    }

    // Groups re-count over the rows that are left, and the frozen columns
    // are re-measured because dropping rows can change a column's width.
    initGroup();
    initPin();
    setTimeout(syncBulk, 0);
    renderFilterChip(table, menu, value, hidden);
  }

  /**
   * Drop the filter. `quiet` skips the follow-up work for the caller that
   * is about to apply a new filter and would only redo it.
   */
  function clearRowFilter(table, quiet) {
    if (!table) return;
    each(table.querySelectorAll('tr.exm-row-filtered'), function (tr) {
      tr.classList.remove('exm-row-filtered');
    });
    table.removeAttribute(FILTER_COL_ATTR);
    table.removeAttribute(FILTER_VAL_ATTR);
    removeFilterChip(table);
    var storeKey = storeKeyOf(table);
    if (storeKey) writeStore('sessionStorage', FILTER_PREFIX + storeKey, null);
    if (quiet) return;
    initGroup();
    initPin();
    setTimeout(syncBulk, 0);
  }

  /**
   * Re-apply the stored page filter after a reload or a pjax swap.
   *
   * The fresh DOM knows nothing - the filter lives in classes and
   * attributes the swap threw away - so this walks the grids, finds a
   * stored entry and runs the normal applyRowFilter over the new rows.
   * On another page of the same table that is exactly the wanted
   * behaviour: the chip re-appears and the rows that do not match hide,
   * same as if the user had filtered here by hand.
   */
  function initFilter() {
    each(document.querySelectorAll('table.exm-grid'), function (table) {
      var storeKey = storeKeyOf(table);
      if (!storeKey) return;
      var raw = readStore('sessionStorage', FILTER_PREFIX + storeKey);
      if (!raw) return;

      var saved = null;
      try {
        saved = JSON.parse(raw);
      } catch (e) {
        /* half-written entry - fall through to the cleanup below */
      }
      if (!saved || !saved.c) {
        writeStore('sessionStorage', FILTER_PREFIX + storeKey, null);
        return;
      }

      applyRowFilter(table, findCtxMenuFor(table), saved.c, saved.v || '');

      // applyRowFilter refused - the column picker has hidden the column,
      // so there is no value on screen to compare against. Dropping the
      // entry here keeps it from retrying (and failing) on every load;
      // the user re-filters in one right-click when the column returns.
      if (table.getAttribute(FILTER_COL_ATTR) !== saved.c) {
        writeStore('sessionStorage', FILTER_PREFIX + storeKey, null);
      }
    });
  }

  function chipOf(table) {
    var sc = scrollBoxOf(table);
    if (!sc || !sc.parentNode) return null;
    return sc.parentNode.querySelector(':scope > .exm-gridfilter-chip');
  }

  function removeFilterChip(table) {
    var chip = chipOf(table);
    if (chip) chip.remove();
  }

  /**
   * The strip above the table saying what is being filtered on, with the
   * way out. Without it the rows are simply gone and the only way back is
   * a page reload - the filter leaves no trace in the URL.
   */
  function renderFilterChip(table, menu, value, hidden) {
    removeFilterChip(table);
    var sc = scrollBoxOf(table);
    if (!sc || !sc.parentNode) return;

    var activeTpl = (menu && menu.getAttribute('data-filter-active')) || '{v}';
    var hiddenTpl = (menu && menu.getAttribute('data-filter-hidden')) || '';
    var clearLbl = (menu && menu.getAttribute('data-filter-clear')) || '';

    var chip = document.createElement('div');
    chip.className = 'exm-gridfilter-chip';
    // Same `data-grid` handle every other control in this file carries,
    // so the clear button finds its table through `gridOf`.
    chip.setAttribute('data-grid', table.id);

    var icon = document.createElement('i');
    icon.className = 'fa fa-filter';
    chip.appendChild(icon);

    // textContent throughout: the value is a cell, i.e. data somebody
    // typed into the table.
    var text = document.createElement('span');
    text.className = 'exm-gridfilter-text';
    text.textContent = activeTpl.replace('{v}', value === '' ? '-' : value);
    chip.appendChild(text);

    if (hiddenTpl) {
      var count = document.createElement('span');
      count.className = 'exm-gridfilter-count';
      count.textContent = hiddenTpl.replace('{n}', hidden);
      chip.appendChild(count);
    }

    var clear = document.createElement('a');
    clear.className = 'exm-gridfilter-clear';
    clear.href = '#';
    clear.innerHTML = '<i class="fa fa-times"></i>';
    clear.appendChild(document.createTextNode(' ' + clearLbl));
    chip.appendChild(clear);

    sc.parentNode.insertBefore(chip, sc);
  }

  /**
   * Put a row back where its NEW value belongs.
   *
   * Grouping and the page filter are both built on the text a cell
   * shows, so the moment an inline edit changes that text the row can be
   * sitting under a heading that no longer describes it - with a count
   * that no longer adds up - or still visible under a filter it stopped
   * matching. Re-running the one that keys on the edited column fixes
   * both; re-running it for any other column would only shuffle the page
   * for nothing.
   */
  function refreshRowPlacement(td) {
    var table = closest(td, 'table.exm-grid');
    if (!table) return;

    // Both keys are matched against the cell's own `column-*` classes,
    // never by comparing two names. A `<td>` carries the column name AND
    // the view-column key (`column-state column-ckey_...`) while a `<th>`
    // only ever carries the key - so the grouping menu keys on the one
    // the editor never sees, and a string comparison silently never
    // matches.
    var fcol = table.getAttribute(FILTER_COL_ATTR);
    if (fcol && td.classList.contains('column-' + fcol)) {
      // Re-filtering also re-groups and re-measures the pins, so the
      // grouping branch below would be redundant work.
      applyRowFilter(
        table,
        findCtxMenuFor(td),
        fcol,
        table.getAttribute(FILTER_VAL_ATTR) || ''
      );
      return;
    }

    var gcol = groupColumnOf(table);
    if (gcol && td.classList.contains('column-' + gcol)) {
      initGroup();
      initPin();
    }
  }

  /* ---------------------------------------------- bulk action bar --- */

  /**
   * The real batch action links, i.e. the ones laravel-admin bound its
   * confirm dialogs and requests to. The bar never reimplements an
   * action, it only clicks one of these.
   */
  function batchLinks(bar) {
    var allName = bar.getAttribute('data-all');
    if (!allName) return [];
    var menu = document.querySelector('.' + allName + '-btn .dropdown-menu');
    if (!menu) return [];
    return Array.prototype.slice.call(menu.querySelectorAll('a'));
  }

  function syncBulk() {
    // "A row nobody can see is never selected" - and this is where the
    // rule is actually enforced, because one entry point walks around
    // every other guard: the theme's select-all script ticks EVERY
    // `.grid-row-checkbox` on the page (BatchActions::script has no
    // notion of the client-side hiding), so with a page filter or a
    // folded group active it happily selects rows the user cannot see,
    // and the batch delete behind it would reach them. Every selection
    // change already funnels through here; unticking fires ifChanged,
    // which schedules one more syncBulk that then finds nothing left to
    // untick.
    each(document.querySelectorAll(
      'tr.exm-row-hidden .grid-row-checkbox, tr.exm-row-filtered .grid-row-checkbox'
    ), uncheckBox);

    var bar = document.querySelector('.exm-bulkbar');
    if (!bar) return;

    // The theme paints a ticked row through `admin.grid.check_status()`,
    // which is only reached from handlers the grid does not render, so
    // the highlight is set here as well. Same class, so the two agree
    // wherever both do run.
    each(document.querySelectorAll('.grid-row-checkbox'), function (box) {
      var row = closest(box, 'tr');
      if (row) row.classList.toggle('selected', box.checked);
    });

    var selected = document.querySelectorAll('.grid-row-checkbox:checked').length;
    var count = bar.querySelector('.exm-bulk-count');
    if (count) {
      count.textContent = (bar.getAttribute('data-label') || '{n}').replace('{n}', selected);
    }
    // With no batch action available (neither a copy from the stock
    // dropdown, nor a static bulk-edit / bulk-export button that the
    // renderer chose to include) the bar would be a dead label.
    var hasActions = bar.querySelectorAll('.exm-bulk-act, .exm-bulk-edit, .exm-bulk-export').length > 0;
    bar.classList.toggle('show', selected > 0 && hasActions);

    syncStockSelection(bar);
  }

  /**
   * Keep the theme's own batch button in step.
   *
   * That button is driven by iCheck's `ifClicked`, which fires for a
   * pointer on the checkbox and for nothing else - so a row unticked from
   * code (the bar's clear, the page filter, a collapsed group) leaves it
   * showing the count from before, sitting above rows that are no longer
   * selected or no longer on screen. The list behind it is fine: that one
   * is maintained on `ifChanged`, which does fire. So the count is simply
   * rewritten from the list, in the theme's own wording.
   */
  function syncStockSelection(bar) {
    var grid = window.$ && window.$.admin && window.$.admin.grid;
    if (!grid || typeof grid.selected !== 'function') return;
    var allName = bar.getAttribute('data-all');
    if (!allName) return;
    var box = document.querySelector('.' + allName + '-btn');
    if (!box) return;

    var count = grid.selected().length;
    // The blade ships it hidden, so '' is exactly the state it started in.
    box.style.display = count > 0 ? '' : 'none';
    var label = box.querySelector('.selected');
    var tpl = bar.getAttribute('data-stock-label');
    if (label && tpl) label.textContent = tpl.replace('{n}', count);
  }

  /**
   * Clear the selection.
   *
   * Where iCheck took over the checkbox it has to do the unticking:
   * assigning `.checked` would leave its skin showing a tick and, worse,
   * skip the handler that maintains `$.admin.grid.selected()` - the very
   * list batch delete sends to the server.
   *
   * Without iCheck the flag is set directly and a `change` is dispatched.
   * Not `.click()`: a click on a row is also the theme's shortcut for
   * opening that row (common.js `tableHoverLink`), so clearing ten rows
   * would navigate away from the grid.
   */
  function uncheckBox(box) {
    if (!box || !box.checked) return;
    if (window.$ && window.$.fn && typeof window.$.fn.iCheck === 'function') {
      window.$(box).iCheck('uncheck');
      return;
    }
    box.checked = false;
    box.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function clearSelection() {
    each(document.querySelectorAll('.grid-row-checkbox'), uncheckBox);
    uncheckBox(document.querySelector('.grid-select-all'));

    setTimeout(syncBulk, 0);
  }

  function initBulk() {
    // A bar that was re-parented to <body> is outside #pjax-container, so
    // the swap that replaced its grid left it behind with a stale count.
    each(document.querySelectorAll('body > .exm-bulkbar'), function (bar) {
      bar.remove();
    });

    var bar = document.querySelector('.exm-bulkbar[data-grid]');
    if (!bar) return;

    var host = bar.querySelector('.exm-bulk-actions');
    if (host) {
      // Static buttons (bulk-edit / bulk-export) were rendered by
      // GridBulkBar::render() and must survive re-init - a pjax reload
      // that rebuilt the bar keeps them, and the copies of the stock
      // batch links are placed AFTER them so the CustomOperation-driven
      // rows stay side by side.
      each(host.querySelectorAll('.exm-bulk-act'), function (n) { n.remove(); });
      batchLinks(bar).forEach(function (link, idx) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-default exm-bulk-act';
        btn.setAttribute('data-idx', String(idx));
        // The link's inner HTML carries the icon that Exment's batch
        // action `render()` prefixed - copy the markup, not just the
        // text, so the bar row shows the same trash / undo / magic
        // icon the dropdown link does. The source is server-rendered
        // markup we control, so an innerHTML copy is safe here.
        btn.innerHTML = (link.innerHTML || '').trim();
        // Colour the button by intent detected from its icon. Doing
        // this in JS (rather than teaching each Batch* render() to
        // emit a variant class) keeps colouring one-way: the icon is
        // the single source of truth, and CustomOperation-driven
        // rows without a matching icon fall back to the neutral base
        // colour without special casing.
        var icon = btn.querySelector('i');
        var cls = icon ? (icon.className || '') : '';
        if (/\bfa-trash\b/.test(cls))              btn.classList.add('exm-bulk-danger');
        else if (/\bfa-times-circle\b/.test(cls))  btn.classList.add('exm-bulk-danger-strong');
        else if (/\bfa-undo\b/.test(cls))          btn.classList.add('exm-bulk-success');
        else if (/\bfa-magic\b/.test(cls))         btn.classList.add('exm-bulk-info');
        host.appendChild(btn);
      });
    }

    // `position: fixed` is resolved against the nearest transformed
    // ancestor rather than the viewport, and the theme animates the
    // sidebar with a transform. Re-parenting to <body> is what makes the
    // bar reliably stick to the window.
    document.body.appendChild(bar);
    syncBulk();
  }

  /* ---------------------------------------------- bulk edit modal --- */
  /**
   * Return the ids of the currently ticked rows across all `.grid-row-checkbox`
   * inputs on the page. The bulk bar only surfaces when at least one is
   * ticked, so an empty return means the user cleared their selection
   * between the click and the action - the caller should bail out.
   *
   * @return {string[]}
   */
  function selectedRowIds() {
    var ids = [];
    each(document.querySelectorAll('.grid-row-checkbox:checked'), function (cb) {
      var id = cb.getAttribute('data-id');
      if (id) ids.push(id);
    });
    return ids;
  }

  /**
   * Locate the inline editor config that pairs with a bulk bar. The bar
   * carries `data-grid` = the tableID, and grid_tools.js already indexes
   * inline configs by that key on boot. Returning `null` disables bulk
   * edit for tables that have no editable columns exposed.
   *
   * @param {HTMLElement} bar
   * @return {object|null}
   */
  function inlineConfigForBar(bar) {
    var gridId = bar ? bar.getAttribute('data-grid') : null;
    if (!gridId) return null;
    return INLINE_CFGS[gridId] || null;
  }

  /**
   * Read a `{n}`-templated translation off the bulk bar - the server
   * folded the labels into the inline-editor config's `labels` map so
   * both features share the same source of truth.
   *
   * @param {HTMLElement} bar
   * @param {string} key
   * @return {string}
   */
  function bulkLabel(bar, key) {
    var cfg = inlineConfigForBar(bar);
    return (cfg && cfg.labels && cfg.labels[key]) || '';
  }

  /**
   * Open the bulk edit modal.
   *
   * Redmine-style form: every editable column is shown at once, and
   * each starts on a sentinel "(no change)" option. On apply, only
   * columns the user moved off the sentinel are sent to the server -
   * the rest are treated as "leave as is" and never appear in the
   * PUT body.
   *
   * The set of editable columns is exactly the set the inline editor
   * uses (server-side `GridInlineEditor::isEditable`), so a column
   * that would be inline-editable in a single cell is also bulk-editable,
   * and one that is not is missing here too.
   *
   * @param {HTMLElement} bar
   */
  function openBulkEditModal(bar) {
    if (!bar) return;
    var ids = selectedRowIds();
    if (!ids.length) return;

    var cfg = inlineConfigForBar(bar);
    if (!cfg || !cfg.columns) return;

    // Only columns whose editor can express "leave this one alone".
    //
    // The whole modal rests on a sentinel option meaning "no change", and
    // an option only exists inside a <select>. Give the same treatment to
    // a free text input and its empty starting value reads as a real
    // value, so pressing apply would blank that column on EVERY selected
    // row - the loudest possible way to lose data. A type without a
    // <select> is therefore left out of the modal entirely rather than
    // shown with a sentinel it cannot honour.
    var columnNames = Object.keys(cfg.columns).filter(function (name) {
      var meta = cfg.columns[name];
      return meta && meta.type === 'select';
    });
    if (!columnNames.length) return;

    var overlay = document.createElement('div');
    overlay.className = 'exm-bulk-modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');

    var title = bulkLabel(bar, 'bulk_edit_title') || 'Bulk edit';
    var pickFields = bulkLabel(bar, 'bulk_edit_pick_fields')
      || bulkLabel(bar, 'bulk_edit_pick_column')
      || 'Fill only fields to change';
    var noChangeLbl = bulkLabel(bar, 'bulk_edit_no_change') || '(no change)';
    var nothingLbl = bulkLabel(bar, 'bulk_edit_nothing') || 'No field selected';
    var applyTpl = bulkLabel(bar, 'bulk_edit_apply') || 'Apply';
    var cancelLbl = bulkLabel(bar, 'bulk_edit_cancel') || 'Cancel';
    var applyLabel = applyTpl.replace('{n}', ids.length);

    // Build the field grid: one row per editable column. Each editor
    // is a fresh instance from `buildEditor` so the value control
    // matches the column type (Select => <select>, Yesno => <select>,
    // ...), with a sentinel "(no change)" first option we own.
    var fieldsHTML = columnNames.map(function (name) {
      var meta = cfg.columns[name];
      var label = (meta && meta.label) || name;
      return (
        '<div class="exm-bulk-field-label" data-for="' + escAttr(name) + '">' + escHtml(label) + '</div>' +
        '<div class="exm-bulk-field-value" data-col="' + escAttr(name) + '"></div>'
      );
    }).join('');

    overlay.innerHTML =
      '<div class="exm-bulk-modal">' +
        '<div class="exm-bulk-modal-header">' + escHtml(title) + '</div>' +
        '<div class="exm-bulk-modal-body">' +
          '<div class="exm-bulk-modal-hint">' + escHtml(pickFields) + '</div>' +
          '<div class="exm-bulk-fields">' + fieldsHTML + '</div>' +
          '<div class="exm-bulk-modal-progress" role="status" aria-live="polite"></div>' +
        '</div>' +
        '<div class="exm-bulk-modal-footer">' +
          '<button type="button" class="btn btn-default exm-bulk-modal-cancel">' + escHtml(cancelLbl) + '</button>' +
          '<button type="button" class="btn btn-primary exm-bulk-modal-apply">' + escHtml(applyLabel) + '</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);

    var applyBtn = overlay.querySelector('.exm-bulk-modal-apply');
    var cancelBtn = overlay.querySelector('.exm-bulk-modal-cancel');
    var progress = overlay.querySelector('.exm-bulk-modal-progress');

    // Sentinel value that means "leave this column alone". Any real
    // value the user picks (including the empty string, meaning
    // "clear the field" for nullable columns) is a change. Using a
    // token no ordinary select option would ever emit avoids the
    // ambiguity between "empty" and "no change".
    var NO_CHANGE = '\u0000EXM_NO_CHANGE\u0000';
    var editors = {};

    columnNames.forEach(function (name) {
      var meta = cfg.columns[name];
      var slot = overlay.querySelector('.exm-bulk-field-value[data-col="' + cssEscape(name) + '"]');
      var labelEl = overlay.querySelector('.exm-bulk-field-label[data-for="' + cssEscape(name) + '"]');
      if (!slot || !meta) return;

      var editor = buildEditor(meta, '');
      editor.setAttribute('data-role', 'exm-bulk-editor');
      editor.setAttribute('data-col', name);
      editor.style.maxWidth = 'none';
      editor.style.width = '100%';

      // Second line of defence behind the filter above: if buildEditor
      // ever hands back something that is not a <select>, there is
      // nowhere to put the sentinel, so drop the whole field rather than
      // ship a control whose "untouched" state is indistinguishable from
      // "clear this column".
      if (editor.tagName !== 'SELECT') {
        if (labelEl) labelEl.remove();
        slot.remove();
        return;
      }

      // Prepended, so "no change" is the option the field opens on. Any
      // blank option buildEditor added for a nullable column stays where
      // it is and keeps its own meaning - "clear this column".
      editor.insertBefore(new Option(noChangeLbl, NO_CHANGE, true, true), editor.firstChild);
      editor.value = NO_CHANGE;

      slot.appendChild(editor);
      editors[name] = { editor: editor, meta: meta, labelEl: labelEl, slot: slot };

      // Live "dirty" highlight so the user can eyeball which fields
      // will move before hitting apply.
      editor.addEventListener('change', function () {
        var isDirty = editor.value !== NO_CHANGE;
        if (labelEl) labelEl.classList.toggle('exm-bulk-dirty', isDirty);
        slot.classList.toggle('exm-bulk-dirty', isDirty);
      });
    });

    function close() {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (ev) {
      if (ev.target === overlay) close();
    });

    applyBtn.addEventListener('click', function () {
      // Collect only the columns the user moved away from the sentinel.
      // Everything else stays out of the PUT body, so the server keeps
      // the current value untouched.
      var payload = {};
      var picked = 0;
      var missingRequired = null;
      Object.keys(editors).forEach(function (name) {
        var ent = editors[name];
        var val = ent.editor.value;
        if (val === NO_CHANGE) return;
        if (ent.meta.required && (val === '' || val === null || typeof val === 'undefined')) {
          if (!missingRequired) missingRequired = ent;
          return;
        }
        payload[name] = val;
        picked++;
      });

      if (missingRequired) {
        progress.classList.add('is-error');
        progress.textContent = (missingRequired.meta.label || '') + ': ' + (bulkLabel(bar, 'bulk_edit_value') || 'value');
        missingRequired.editor.focus();
        return;
      }
      if (!picked) {
        progress.classList.add('is-error');
        progress.textContent = nothingLbl;
        return;
      }
      progress.classList.remove('is-error');

      var confirmTpl = bulkLabel(bar, 'bulk_edit_confirm') || 'Update {n} records?';
      if (!window.confirm(confirmTpl.replace('{n}', ids.length))) return;

      applyBtn.disabled = true;
      cancelBtn.disabled = true;
      Object.keys(editors).forEach(function (n) { editors[n].editor.disabled = true; });

      var updateBase = cfg.updateUrl;
      var csrf = cfg.csrf;
      var okCount = 0;
      var failCount = 0;
      var i = 0;
      var progressTpl = bulkLabel(bar, 'bulk_edit_progress') || '{done}/{total}';

      function step() {
        if (i >= ids.length) return finish();
        var id = ids[i++];
        progress.textContent = progressTpl.replace('{done}', i).replace('{total}', ids.length);
        fetch(updateBase + '/' + encodeURIComponent(id), {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({ value: payload })
        }).then(function (r) {
          if (r.ok) okCount++; else failCount++;
        }).catch(function () {
          failCount++;
        }).then(step);
      }

      function finish() {
        var summaryTpl = failCount === 0
          ? (bulkLabel(bar, 'bulk_edit_summary_ok') || '{n} updated')
          : (bulkLabel(bar, 'bulk_edit_summary_partial') || '{ok} succeeded, {fail} failed');
        progress.textContent = summaryTpl
          .replace('{n}', okCount)
          .replace('{ok}', okCount)
          .replace('{fail}', failCount);
        setTimeout(function () {
          close();
          reloadGridSoft();
        }, failCount === 0 ? 700 : 1600);
      }

      step();
    });

    // Focus the first editable field on open so keyboard users can
    // start picking a value without a hunt-and-click first.
    setTimeout(function () {
      var first = overlay.querySelector('[data-role="exm-bulk-editor"]');
      if (first) first.focus();
    }, 0);
  }

  /**
   * CSS attribute selector escape - column names are safe from the
   * server (`custom_column.column_name` is validated at insert), but
   * belt-and-braces here so a future rename can't blow up the modal.
   */
  function cssEscape(s) {
    if (window.CSS && typeof CSS.escape === 'function') return CSS.escape(s);
    return String(s).replace(/(["\\])/g, '\\$1');
  }

  /**
   * Send the browser to the standard Exment CSV export URL for the
   * currently selected row ids. This piggybacks on laravel-admin's
   * `_export_=selected:{ids}` scope, which the base `AbstractExporter`
   * turns into a `whereIn('id', ...)` before writing the file - no new
   * controller and no new permission check.
   *
   * @param {HTMLElement} bar
   * @param {string} format 'csv' or 'xlsx' - whatever else arrives is
   *   folded to csv, so a doctored data-format attribute can only ever
   *   pick between the two real exporters.
   */
  function runBulkExport(bar, format) {
    if (!bar) return;
    var ids = selectedRowIds();
    if (!ids.length) return;
    var listUrl = bar.getAttribute('data-list-url') || '';
    if (!listUrl) return;
    // Preserve existing query so any active filter still influences
    // e.g. relation resolution during export.
    var params = new URLSearchParams(window.location.search);
    params.set('_export_', 'selected:' + ids.join(','));
    params.set('action', 'export');
    params.set('format', format === 'xlsx' ? 'xlsx' : 'csv');
    // A GET navigation triggers a download response; opening in a new
    // tab keeps the current grid selection intact for the user to keep
    // working with.
    window.open(listUrl + '?' + params.toString(), '_blank');
  }

  /**
   * Ask laravel-admin to swap the current grid content. Safe to call
   * whether pjax is loaded or not - a full location reload is a decent
   * fallback if it is not.
   */
  function reloadGridSoft() {
    try {
      if (window.$ && window.$.pjax && typeof window.$.pjax.reload === 'function') {
        // `url` is required next to `container`: without it pjax reloads
        // the container from its last-known URL, which for a fresh mount
        // is the empty string and clears the box out.
        window.$.pjax.reload({ container: '#pjax-container', url: window.location.href });
        return;
      }
    } catch (e) { /* fall through */ }
    window.location.reload();
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }
  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  /* ------------------------------------------------------ inline edit --- */
  /**
   * Turn a `<td>` into a small picker, PUT the change, then swap the
   * cell markup back for the server's rendered version. There is only
   * ever one editor open at a time and it never repositions the row -
   * that is what keeps the picker readable while a table below it is
   * grouping, sorting or being cleared by a sibling checkbox.
   *
   * The editor NEVER writes back to the cell from JS state. The only
   * markup it ever puts into `.exm-cell-wrap` is what
   * `GET .../cell/<id>/<column>` returns. That is the same pipeline the
   * grid runs, so a Select column's badge / bar / colour stays byte for
   * byte identical after a save as it was after a full pjax reload.
   */

  var INLINE_CFGS = {};

  function initInline() {
    INLINE_CFGS = {};
    each(document.querySelectorAll('script.exm-inline-config'), function (script) {
      var gridId = script.getAttribute('data-grid');
      if (!gridId) return;
      try {
        var cfg = JSON.parse(script.textContent || script.innerText || '{}');
        if (cfg && cfg.columns) INLINE_CFGS[gridId] = cfg;
      } catch (e) {
        /* malformed config just disables inline edit on this grid */
      }
    });

    // A pen icon on every editable cell, so hovering a row makes the
    // "you can edit this" affordance obvious - the dblclick alone is
    // easy to miss. Only added once per cell; a pjax reload rebuilds
    // the DOM anyway so nothing survives.
    each(document.querySelectorAll('.table.exm-grid td.exm-editable'), function (td) {
      if (td.querySelector(':scope > .exm-edit-pen')) return;
      var pen = document.createElement('span');
      pen.className = 'exm-edit-pen';
      pen.setAttribute('title', getInlineLabel(td, 'edit'));
      pen.innerHTML = '<i class="fa fa-pencil"></i>';
      td.appendChild(pen);
    });
  }

  function inlineConfigFor(td) {
    var table = closest(td, 'table.exm-grid');
    if (!table) return null;
    return INLINE_CFGS[table.id] || null;
  }

  function getInlineLabel(td, key) {
    var cfg = inlineConfigFor(td);
    return (cfg && cfg.labels && cfg.labels[key]) || '';
  }

  function inlineColumnMeta(td) {
    var col = columnNameOf(td);
    var cfg = inlineConfigFor(td);
    if (!cfg || !cfg.columns) return null;
    return cfg.columns[col] || null;
  }

  function inlineRowId(td) {
    var tr = closest(td, 'tr');
    return rowIdOf(tr);
  }

  // Read the record id from a `<tr>`. laravel-admin used to hang the id
  // as `data-key`/`data-id` on the row, but the version shipped here
  // does not - the batch checkbox is the only element that carries it
  // (`.grid-row-checkbox[data-id]`). Reading through the checkbox keeps
  // both the ctxmenu and the inline editor working with the stock
  // theme; if a future upgrade puts the attribute back on the `tr`, the
  // faster path is still tried first.
  function rowIdOf(tr) {
    if (!tr) return null;
    var v = tr.getAttribute('data-key') || tr.getAttribute('data-id');
    if (v) return v;
    var cb = tr.querySelector('input.grid-row-checkbox[data-id]');
    return cb ? cb.getAttribute('data-id') : null;
  }

  // Reverse lookup. The ctxmenu keeps only the id, so when it re-opens
  // the row to filter / copy / delete it has to find the row again.
  // The stock theme has no attribute we can pin on the `tr`, so we walk
  // the table's checkboxes; the search is capped to `.exm-grid tables`
  // to skip any unrelated table on the page.
  function findRowById(id) {
    if (!id) return null;
    var safe = String(id).replace(/"/g, '\\"');
    var direct = document.querySelector(
      'table.exm-grid tr[data-key="' + safe + '"], table.exm-grid tr[data-id="' + safe + '"]'
    );
    if (direct) return direct;
    var cbs = document.querySelectorAll('table.exm-grid input.grid-row-checkbox[data-id="' + safe + '"]');
    for (var i = 0; i < cbs.length; i++) {
      var tr = closest(cbs[i], 'tr');
      if (tr) return tr;
    }
    return null;
  }

  // One-at-a-time guard. `activeInlineTd` is the cell currently being
  // edited; a second open call on a different cell finishes the first
  // one before opening the new editor.
  var activeInlineTd = null;

  function openInlineEditor(td) {
    if (!td || !td.classList.contains('exm-editable')) return;
    if (td.classList.contains('exm-editing')) return;
    if (td.classList.contains('exm-saving')) return;

    var meta = inlineColumnMeta(td);
    if (!meta) return;
    var id = inlineRowId(td);
    if (!id) return;
    var cfg = inlineConfigFor(td);
    if (!cfg) return;

    if (activeInlineTd && activeInlineTd !== td) {
      finishInlineEditor(activeInlineTd, false);
    }
    activeInlineTd = td;

    // Snapshot the entire cell HTML (badge + pen) so cancel puts it
    // back untouched, and so a swap after save doesn't have to try to
    // preserve the pen icon on its own.
    //
    // The whole `<td>` is the sink here, NOT its first child - the
    // badge (`.exm-cell-pill`, `.exm-cell-dot`, ...) IS the first
    // child, and writing new markup INTO it would nest the fresh
    // badge inside the old one.
    var snapshot = td.innerHTML;
    td.setAttribute('data-exm-snapshot', snapshot);
    td.classList.add('exm-editing');

    var current = readCurrentValue(td, meta);
    var editor = buildEditor(meta, current);
    // What the editor actually holds after prefill, kept for the "did
    // the user change anything" check on close. NOT the same thing as
    // `current`: an <input> sanitizes what it is given (a stray newline
    // inside a stored text is dropped on assignment), and comparing a
    // later value against the cell would read that sanitization as a
    // user edit - committing it on every blur.
    editor._exmInitial = editor.value;
    // The old markup stays in the cell as an invisible ghost so the
    // column keeps the width and the row the height the table was laid
    // out with, and the editor floats above them. Replacing the content
    // outright would resize this column - and shove every column right of
    // it sideways - at the moment the user is aiming at the cell.
    //
    // The pen icon is inside the snapshot, so cancel/commit restore it
    // verbatim either way.
    var ghost = document.createElement('span');
    ghost.className = 'exm-edit-ghost';
    ghost.setAttribute('aria-hidden', 'true');
    ghost.innerHTML = snapshot;
    td.innerHTML = '';
    td.appendChild(ghost);
    td.appendChild(editor);
    sizeInlineEditor(td, editor, meta);

    // Focus & open picker as soon as the browser lets us - the second
    // click of a dblclick would otherwise land on the fresh element
    // and mark it selected without opening the dropdown.
    setTimeout(function () {
      editor.focus();
      var wantsPicker = editor.tagName === 'SELECT'
        || editor.type === 'date' || editor.type === 'datetime-local';
      if (wantsPicker && typeof editor.showPicker === 'function') {
        try { editor.showPicker(); } catch (e) { /* not supported everywhere */ }
      } else if (editor.tagName === 'INPUT') {
        editor.select();
        // Selecting puts the caret at the end, and a value too long for
        // the box then opens scrolled to its tail - the one part of it
        // the cell was already showing. A value is read from its start.
        editor.scrollLeft = 0;
      }
    }, 0);

    // Commit-on-change is for the <select> only: there, picking an
    // option IS the decision. On a typed input `change` fires while the
    // user is still mid-thought (a date input emits one per completed
    // segment), so those commit through Enter or click-away and cancel
    // through Escape, the way a text editor is expected to behave.
    if (editor.tagName === 'SELECT') {
      editor.addEventListener('change', function () { finishInlineEditor(td, true); });
    }
    editor.addEventListener('blur', function () {
      // A tiny delay so a click on the dropdown's own option is not
      // read as a blur before the change fires.
      setTimeout(function () {
        if (td.classList.contains('exm-editing')) finishInlineEditor(td, true);
      }, 80);
    });
    editor.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); finishInlineEditor(td, true); }
      else if (ev.key === 'Escape') { ev.preventDefault(); finishInlineEditor(td, false); }
    });
  }

  /**
   * Width floors per editor type, in px. Not guesses at a pretty size -
   * each one is what the widget needs before its own content starts being
   * cut: a date input renders 10 digits plus a picker button, a
   * datetime-local those plus a time down to seconds, a number the value
   * plus its spinner.
   */
  var EDITOR_MIN_WIDTH = {
    text: 200,
    number: 110,
    date: 140,
    datetime: 230,
    select: 140
  };

  /**
   * Smallest the "stop following the value" cap ever gets, and the whole
   * cap on a narrow screen. Wide enough for the values the grid itself
   * shortens (it cuts at 50 characters), which is exactly where the
   * editor used to stop showing the whole value.
   */
  var EDITOR_MAX_WIDTH = 640;

  /**
   * On a wider grid the cap follows the grid instead: the editor is an
   * overlay, and past roughly this much of what the user can see it stops
   * being a field in a row and becomes a panel over one.
   */
  var EDITOR_MAX_RATIO = .6;

  /**
   * Give the (absolutely positioned) editor a width that fits the value
   * rather than the column, and keep it inside the part of the grid the
   * user can actually see.
   *
   * Both measurements are taken here, in the tick the editor is built:
   * the ghost holds the cell's box exactly as the table laid it out, so
   * nothing about the cell moves between the swap and this call.
   */
  function sizeInlineEditor(td, editor, meta) {
    var rect = td.getBoundingClientRect();
    var want = Math.max(rect.width, EDITOR_MIN_WIDTH[meta.type] || 120);

    // An <input> whose value overruns its box reports the value's full
    // length as scrollWidth - the cheapest true measurement of "how wide
    // does this text need to be", no font maths involved. Read it before
    // the width below is written, while the box is still the cell's.
    if (editor.tagName === 'INPUT' && editor.scrollWidth > editor.clientWidth) {
      want = Math.max(want, editor.scrollWidth + 14);
    }
    // A <select> never overruns - it clips its option instead, which is
    // the same unreadable half-value in a different shape. Left to size
    // itself it takes the width of its widest option (plus the arrow),
    // and that is the measurement wanted: every option legible without
    // opening the list.
    if (editor.tagName === 'SELECT') {
      var keep = editor.style.width;
      editor.style.width = 'auto';
      want = Math.max(want, editor.offsetWidth + 2);
      editor.style.width = keep;
    }

    var left = 0;
    var sc = scrollBoxOf(td);
    var box = sc ? sc.getBoundingClientRect() : null;
    want = Math.min(want, box
      ? Math.max(EDITOR_MAX_WIDTH, Math.round(box.width * EDITOR_MAX_RATIO))
      : EDITOR_MAX_WIDTH);

    if (box) {
      // An editor opening past the edge of the scroll box would have to
      // be scrolled to before it could be typed in. Anything that does
      // not fit slides sideways rather than being cut: the columns it
      // covers are readable again the moment the editor closes, half a
      // value is not.
      want = Math.min(want, box.width - 12);
      if (rect.left + want > box.right - 6) {
        left = box.right - 6 - want - rect.left;
      }
      if (rect.left + left < box.left + 6) {
        left = box.left + 6 - rect.left;
      }
    }

    editor.style.width = Math.round(want) + 'px';
    if (left) editor.style.left = Math.round(left) + 'px';
  }

  function readCurrentValue(td, meta) {
    if (!meta) return '';
    // A cell the grid had to shorten shows '...' where the rest of the
    // value was, and carries the real one in an empty `.exm-cell-raw`
    // marker - opening the editor on the visible text would put those
    // three dots in the input and save them over the stored value.
    //
    // Every other cell shows its value in full, so the text IS the value.
    // Reading it back is best effort: the client only uses this to
    // prefill, so a miss means the editor opens empty, which beats
    // opening on a wrong guess.
    var marker = td.querySelector('.exm-cell-raw');
    var raw = marker ? marker.getAttribute('data-v') : null;
    var text = raw !== null ? raw : (td.textContent || '').replace(/\s+/g, ' ').trim();
    if (meta.type === 'select') {
      // The marker holds the stored option key, the cell text holds the
      // label - so match against whichever one we have. An unknown key is
      // no match on purpose: the editor opening on its first option
      // instead is one stray blur away from saving a value nobody picked.
      for (var i = 0; i < meta.choices.length; i++) {
        var hit = raw !== null
          ? String(meta.choices[i].v) === raw
          : String(meta.choices[i].l) === text;
        if (hit) return meta.choices[i].v;
      }
      return '';
    }
    if (meta.type === 'number') {
      // Down from the display formatting - thousands separators, a
      // currency symbol, a unit suffix - to the bare number the input
      // accepts.
      return text.replace(/[^0-9.\-]/g, '');
    }
    if (meta.type === 'date') {
      return parseDateText(text);
    }
    if (meta.type === 'datetime') {
      var day = parseDateText(text);
      if (!day) return '';
      var hm = text.match(/(\d{1,2}):(\d{2})(?::(\d{2}))?/);
      if (!hm) return day + 'T00:00';
      // <input type="datetime-local"> wants the ISO 'T' form. Seconds
      // ride along when the cell shows them, so they survive an edit
      // that only touches the date.
      return day + 'T' + pad2(hm[1]) + ':' + hm[2] + (hm[3] ? ':' + hm[3] : '');
    }
    // The text-ish types (text, email, url) show the stored value as is.
    return text;
  }

  /**
   * 'YYYY-MM-DD' out of the formats Exment renders dates in - Y-m-d,
   * Y/m/d and the Japanese Y年m月d日. Anything else returns '' and the
   * date editor opens empty; a wrong prefill would be worse than none.
   */
  function parseDateText(text) {
    var m = (text || '').match(/(\d{4})[-\/年](\d{1,2})[-\/月](\d{1,2})/);
    return m ? m[1] + '-' + pad2(m[2]) + '-' + pad2(m[3]) : '';
  }

  function pad2(v) {
    v = String(v);
    return v.length === 1 ? '0' + v : v;
  }

  function buildEditor(meta, current) {
    if (meta.type === 'select') {
      var select = document.createElement('select');
      select.className = 'exm-edit-input';
      if (!meta.required) {
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '';
        select.appendChild(blank);
      }
      var matched = false;
      for (var i = 0; i < meta.choices.length; i++) {
        var opt = document.createElement('option');
        opt.value = meta.choices[i].v;
        opt.textContent = meta.choices[i].l;
        if (String(meta.choices[i].v) === String(current)) {
          opt.selected = true;
          matched = true;
        }
        select.appendChild(opt);
      }
      // A required column can still hold a value that is not among the
      // options - the option was deleted after the fact, or the value
      // arrived through import or the API. With nothing marked selected
      // the browser falls back to selecting the FIRST option, and the
      // blur that closes an untouched editor would then commit a value
      // nobody picked. A hidden placeholder pins the editor to '' until
      // the user actually chooses.
      if (meta.required && !matched) {
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = '';
        ph.disabled = true;
        ph.hidden = true;
        ph.selected = true;
        select.insertBefore(ph, select.firstChild);
      }
      return select;
    }
    // Typed inputs. The real validation rules stay on the server (the
    // PUT runs the same ApiDataController pipeline the edit form does),
    // so the input type here only drives the widget: the spinner, the
    // calendar, the mobile keyboard. A value the server refuses comes
    // back as a failed save and the cell flashes red with the old
    // markup restored - never a silent wrong write.
    var input = document.createElement('input');
    input.className = 'exm-edit-input';
    if (meta.type === 'number') {
      input.type = 'number';
      input.step = meta.decimal ? 'any' : '1';
      if (meta.min != null) input.min = meta.min;
      if (meta.max != null) input.max = meta.max;
    } else if (meta.type === 'date') {
      input.type = 'date';
    } else if (meta.type === 'datetime') {
      input.type = 'datetime-local';
      // Seconds included: the grid displays them ('09:15:30'), so the
      // editor must carry them - at step 60 the widget holds minutes
      // only, and editing just the date would silently write the
      // seconds back as :00.
      input.step = '1';
    } else {
      input.type = 'text';
      if (meta.maxLength) input.maxLength = meta.maxLength;
    }
    input.value = current;
    return input;
  }

  /**
   * Close the editor - commit if `commit`, revert otherwise.
   */
  function finishInlineEditor(td, commit) {
    if (!td || !td.classList.contains('exm-editing')) return;
    if (activeInlineTd === td) activeInlineTd = null;

    // The cell is no longer in the page: an auto-refresh or a pjax
    // navigation replaced the grid while the editor was open, and the
    // blur timer is only now catching up. There is nothing left to
    // restore and nothing the value could be saved against.
    if (td.isConnected === false) return;

    var editor = td.querySelector('.exm-edit-input');
    var snapshot = td.getAttribute('data-exm-snapshot') || '';
    td.removeAttribute('data-exm-snapshot');
    td.classList.remove('exm-editing');

    if (!commit || !editor) {
      td.innerHTML = snapshot;
      return;
    }
    var newValue = editor.value;
    var meta = inlineColumnMeta(td);
    // Restore the snapshot first - every return below must leave the
    // cell showing its own markup again.
    td.innerHTML = snapshot;
    // No meta means the config this editor was opened from is gone - the
    // grid was re-rendered underneath it. Without the column definition
    // there is nothing to validate the picked value against, so the edit
    // is dropped rather than sent on a guess.
    if (!meta) return;
    // A value the widget itself flags as unparseable - '2026-99-99' in a
    // date field, letters pasted into a number - surfaces as value ''
    // plus validity.badInput. Committing that would not save what the
    // user typed, it would silently CLEAR the column; closing as a
    // revert keeps the stored value and the half-typed text is lost,
    // which is the smaller of the two losses.
    if (editor.validity && editor.validity.badInput) return;
    // Compared against what the editor OPENED holding, not against the
    // cell text: the prefill already went through the input's own
    // sanitizer, and re-reading the cell would count that difference (a
    // dropped newline, a collapsed space) as an edit worth saving.
    var initial = typeof editor._exmInitial === 'string'
      ? editor._exmInitial
      : readCurrentValue(td, meta);
    if (String(newValue) === String(initial)) {
      // Same value the editor opened on: no request, no flash.
      return;
    }

    var cfg = inlineConfigFor(td);
    var id = inlineRowId(td);
    var column = columnNameOf(td);
    if (!cfg || !id || !column) {
      return;
    }

    // Optimistic UI: keep the snapshot on screen until the server
    // confirms. Not writing the guessed markup in first - a Select
    // column's badge colour is not something the client can compose
    // on its own.
    td.classList.remove('exm-error');
    td.classList.add('exm-saving');

    saveInlineCell(cfg, id, column, serializeEditorValue(newValue, meta), td, snapshot);
  }

  /**
   * What the PUT body carries for a picked value. Only datetime needs a
   * translation: <input type="datetime-local"> speaks ISO's 'T' and
   * drops the seconds, while the server-side date validator reads
   * 'Y-m-d H:i:s'. The compare in finishInlineEditor stays in the
   * input's own format - both sides of it came from there.
   */
  function serializeEditorValue(value, meta) {
    if (meta && meta.type === 'datetime' && value) {
      var v = value.replace('T', ' ');
      return v.length === 16 ? v + ':00' : v;
    }
    return value;
  }

  function saveInlineCell(cfg, id, column, value, td, snapshot) {
    var body = { value: {} };
    body.value[column] = value;

    var updateUrl = cfg.updateUrl + '/' + encodeURIComponent(id);
    var cellUrl = cfg.cellUrl + '/' + encodeURIComponent(id) + '/' + encodeURIComponent(column);

    var headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };
    if (cfg.csrf) headers['X-CSRF-TOKEN'] = cfg.csrf;

    fetch(updateUrl, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(body)
    }).then(function (r) {
      if (!r.ok) throw new Error('put:' + r.status);
      // Skip parsing the JSON body - we do not use it, and dataUpdate
      // may return an object OR an array of objects depending on the
      // call shape.
      return fetch(cellUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
    }).then(function (r) {
      if (!r.ok) throw new Error('cell:' + r.status);
      return r.json();
    }).then(function (payload) {
      td.classList.remove('exm-saving');
      var html = (payload && payload.html != null) ? String(payload.html) : '';
      // Server returns the value markup only (badge, bar, plain text).
      // Replace the whole cell content with it and re-attach the pen -
      // writing `wrap.innerHTML = html` where `wrap` was the previous
      // badge would just nest the new one inside it.
      td.innerHTML = html;
      addPenIcon(td);
      td.classList.add('exm-saved');
      setTimeout(function () { td.classList.remove('exm-saved'); }, 1200);
      // The row may belong somewhere else now - see the note on
      // refreshRowPlacement. Done before the pins are measured because
      // it can move the row and change a column's width.
      refreshRowPlacement(td);
      // Widths may have changed; re-measure pinned columns so a wider
      // badge does not lift the next pin off its left edge.
      each(document.querySelectorAll('.exm-grid-pin[data-key]'), applyPins);
    }).catch(function () {
      td.classList.remove('exm-saving');
      td.innerHTML = snapshot;
      td.classList.add('exm-error');
      setTimeout(function () { td.classList.remove('exm-error'); }, 2500);
    });
  }

  // Add a fresh pen icon to a cell if it's editable and does not have
  // one yet. Called after `saveInlineCell` replaces `<td>`'s children.
  function addPenIcon(td) {
    if (!td || !td.classList.contains('exm-editable')) return;
    if (td.querySelector(':scope > .exm-edit-pen')) return;
    var pen = document.createElement('span');
    pen.className = 'exm-edit-pen';
    pen.setAttribute('title', getInlineLabel(td, 'edit'));
    pen.innerHTML = '<i class="fa fa-pencil"></i>';
    td.appendChild(pen);
  }

  /* ------------------------------------------------------ context menu --- */
  /**
   * Right-click menu. There is one `<div class="exm-ctxmenu">` per
   * grid rendered by GridContextMenu - the JS only positions it, fills
   * its head with the current row's key values and routes clicks
   * through the base URLs the div already carries. It never builds
   * paths from string glue and it never issues a DELETE without a
   * confirm.
   */

  var activeCtx = null;

  function initCtxMenu() {
    // Nothing to init: contextmenu listener is attached on document
    // once (see below), and the menu itself is server-rendered.
  }

  function findCtxMenuFor(el) {
    var table = closest(el, 'table.exm-grid');
    if (!table) return null;
    return document.querySelector('.exm-ctxmenu[data-grid="' + table.id + '"]');
  }

  var ctxScrollBaseline = null;

  function hideCtxMenu() {
    if (!activeCtx) return;
    activeCtx.classList.remove('show');
    activeCtx = null;
    ctxScrollBaseline = null;
    window.removeEventListener('scroll', onCtxScroll, true);
  }

  // A page scroll would leave the menu floating over the wrong row.
  // Bound only while the menu is open (see showCtxMenu) so it does not
  // fire on every unrelated scroll for the life of the page. We also
  // ignore scrolls inside nested containers (pinned scroll wrapper,
  // dropdowns, etc.) and require a small delta before dismissing:
  // browsers/tools like Puppeteer emit a phantom scroll frame right
  // after a right-click that would otherwise close the menu instantly.
  function onCtxScroll(e) {
    if (!activeCtx || !ctxScrollBaseline) return;
    var t = e.target;
    if (t !== document && t !== window && t !== document.documentElement && t !== document.body) return;
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;
    var x = window.pageXOffset || document.documentElement.scrollLeft || 0;
    if (Math.abs(y - ctxScrollBaseline.y) > 10 || Math.abs(x - ctxScrollBaseline.x) > 10) {
      hideCtxMenu();
    }
  }

  function showCtxMenu(menu, td, ev) {
    var tr = closest(td, 'tr');
    if (!tr) return;
    var id = rowIdOf(tr);
    if (!id) return;

    menu.setAttribute('data-row-id', id);
    menu.setAttribute('data-row-col', columnNameOf(td) || '');

    // Head: first two visible data columns (id + main label) as a
    // reminder of which row was right-clicked. `textContent` reads
    // through any badge span, so the label stays legible.
    var head = menu.querySelector('.exm-ctx-head');
    if (head) {
      var summary = [];
      var siblings = tr.children;
      for (var i = 0; i < siblings.length && summary.length < 2; i++) {
        var name = columnNameOf(siblings[i]);
        if (!name || name.indexOf('__') === 0) continue;
        var txt = (siblings[i].textContent || '').replace(/\s+/g, ' ').trim();
        if (txt) summary.push(txt.length > 30 ? txt.slice(0, 30) + '…' : txt);
      }
      head.textContent = summary.join(' - ');
    }

    // The filter entry acts on the column that was right-clicked, so it
    // has nothing to work with over the checkbox and action columns. Show
    // it greyed there rather than let it look live and do nothing.
    var fcol = menu.getAttribute('data-row-col') || '';
    var filterable = !!fcol && fcol.indexOf('__') !== 0;
    var flink = menu.querySelector('.exm-ctx-filter');
    if (flink) flink.classList.toggle('disabled', !filterable);

    var flabel = menu.querySelector('.exm-ctx-filter-label');
    if (flabel) {
      var baseLabel = menu.getAttribute('data-filter-label') || '';
      var raw = cellText(td);
      flabel.textContent = (filterable && raw && raw.length <= 24)
        ? baseLabel + '：「' + raw + '」'
        : baseLabel;
    }

    menu.style.left = '0px';
    menu.style.top = '0px';
    menu.classList.add('show');

    // Read size after making it visible; position may need flipping
    // near the right / bottom edges of the viewport. `.exm-ctxmenu`
    // is CSS `position: fixed`, so left/top are viewport coordinates
    // and clientX/clientY are what we point at.
    var rect = menu.getBoundingClientRect();
    var w = rect.width, h = rect.height;
    var x = ev.clientX, y = ev.clientY;
    var vw = document.documentElement.clientWidth;
    var vh = document.documentElement.clientHeight;
    if (x + w > vw) x = Math.max(0, vw - w - 4);
    if (y + h > vh) y = Math.max(0, vh - h - 4);
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';

    activeCtx = menu;
    // Bind scroll dismissal deferred so the browser's / driver's own
    // scroll-into-view for the right-click event doesn't immediately
    // close the menu we just opened.
    setTimeout(function () {
      if (activeCtx !== menu) return;
      ctxScrollBaseline = {
        x: window.pageXOffset || document.documentElement.scrollLeft || 0,
        y: window.pageYOffset || document.documentElement.scrollTop || 0
      };
      window.addEventListener('scroll', onCtxScroll, true);
    }, 60);
  }

  /* ------------------------------------------------- quick preview --- */

  function notify(kind, message) {
    if (!message) return;
    if (window.toastr && typeof toastr[kind] === 'function') {
      toastr[kind](message);
    }
  }

  /**
   * Read a record without leaving the list.
   *
   * The page is fetched exactly the way a pjax navigation fetches it, so
   * what the modal shows IS the show page - same blocks, same formatting,
   * same permission checks - rather than a second rendering that would
   * drift away from it over time. Only the two parts that make no sense
   * inside a modal are dropped: the page header and the row of action
   * buttons; "open full page" in the footer is how the user gets to them.
   *
   * Read-only on purpose. Scripts arriving through innerHTML never run,
   * so an interactive block would be half-alive in here - better to send
   * the user to the real page than to fake it.
   */
  function openPeekModal(menu, id) {
    var base = menu.getAttribute('data-view-url') || '';
    if (!base) return;

    var url = base + '/' + encodeURIComponent(id);
    var head = menu.querySelector('.exm-ctx-head');
    var title = head ? cellText(head) : '';

    var overlay = document.createElement('div');
    overlay.className = 'exm-bulk-modal-overlay exm-peek-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML =
      '<div class="exm-bulk-modal exm-peek-modal">' +
        '<div class="exm-bulk-modal-header">' + escHtml(title) + '</div>' +
        '<div class="exm-bulk-modal-body exm-peek-body">' +
          '<div class="exm-peek-note">' +
            escHtml(menu.getAttribute('data-peek-loading') || '') +
          '</div>' +
        '</div>' +
        '<div class="exm-bulk-modal-footer">' +
          '<button type="button" class="btn btn-default exm-peek-close">' +
            escHtml(menu.getAttribute('data-peek-close') || '') +
          '</button>' +
          '<a class="btn btn-primary" href="' + escAttr(url) + '">' +
            escHtml(menu.getAttribute('data-peek-open') || '') +
          '</a>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);

    var onKey = function (ev) {
      if (ev.key === 'Escape') close();
    };
    function close() {
      document.removeEventListener('keydown', onKey);
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (ev) {
      // Only the backdrop itself - a click that started inside the panel
      // must not close the record the user is reading.
      if (ev.target === overlay || closest(ev.target, '.exm-peek-close')) close();
    });

    var body = overlay.querySelector('.exm-peek-body');
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-PJAX': 'true', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      if (!r.ok) throw new Error('peek:' + r.status);
      return r.text();
    }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');

      // Two blocks, in reading order. The values live in the `.box-info`
      // box's `.form-horizontal`; `.block_custom_value_show` is the tail
      // of the page - attachments, revisions, comments - which is half of
      // what "read this incident" means, so it comes along.
      var parts = [
        doc.querySelector('section.content .box.box-info > .form-horizontal'),
        doc.querySelector('.block_custom_value_show')
      ].filter(Boolean);
      if (!parts.length) throw new Error('peek:empty');

      body.innerHTML = '';
      parts.forEach(function (part) {
        // Anything that could act is taken out rather than left dead: the
        // modal is a place to read, and a save button that navigates the
        // page out from under the list is worse than no button at all.
        // The settings modals go too - they belong to the page's own
        // toolbar, which we already dropped.
        each(part.querySelectorAll('script, .modal, .box-tools, input, button, textarea, select, .btn'),
          function (el) { el.remove(); });
        body.appendChild(part);
      });
    }).catch(function () {
      body.innerHTML = '<div class="exm-peek-note exm-peek-error">'
        + escHtml(menu.getAttribute('data-peek-error') || '') + '</div>';
    });
  }

  /* -------------------------------------------------- assign to me --- */

  /**
   * Put the current user in the row's assignee column.
   *
   * Goes through `PUT /admin/webapi/data/{table}/{id}` - the same
   * endpoint the inline editor and the bulk edit use - so the workflow,
   * the revision history and the validation all run exactly as they do
   * when the field is changed on the edit form. Nothing here writes to
   * the database on its own.
   *
   * The changed cell is re-read from the server rather than composed on
   * the client: a user column renders as an avatar plus a name, and only
   * the server knows what that looks like. When the column is not on
   * screen (hidden by the column picker, or a view that does not list it)
   * the whole grid is reloaded instead - the row may not even belong on
   * this page any more once its assignee changed.
   */
  function assignToMe(menu, id) {
    var col = menu.getAttribute('data-assign-col');
    var user = menu.getAttribute('data-assign-user');
    var webapi = menu.getAttribute('data-webapi-url') || '';
    var cellBase = menu.getAttribute('data-cell-url') || '';
    var csrf = menu.getAttribute('data-csrf') || '';
    if (!col || !user || !webapi) return;

    var headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };
    if (csrf) headers['X-CSRF-TOKEN'] = csrf;

    var body = { value: {} };
    body.value[col] = user;

    var tr = findRowById(id);
    var td = tr ? tr.querySelector('td.column-' + cssEscape(col)) : null;
    if (td) td.classList.add('exm-saving');

    fetch(webapi + '/' + encodeURIComponent(id), {
      method: 'PUT',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(body)
    }).then(function (r) {
      if (!r.ok) throw new Error('assign:' + r.status);
      if (!td || !cellBase) return null;
      return fetch(cellBase + '/' + encodeURIComponent(id) + '/' + encodeURIComponent(col), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r2) {
        return r2.ok ? r2.json() : null;
      });
    }).then(function (payload) {
      if (td) td.classList.remove('exm-saving');
      notify('success', menu.getAttribute('data-assign-done') || '');

      if (!td || !payload || payload.html == null) {
        reloadGridSoft();
        return;
      }
      td.innerHTML = String(payload.html);
      addPenIcon(td);
      td.classList.add('exm-saved');
      setTimeout(function () { td.classList.remove('exm-saved'); }, 1200);
      // The row may belong somewhere else now (grouped, page-filtered)
      // and a wider name can move every pinned offset.
      refreshRowPlacement(td);
      each(document.querySelectorAll('.exm-grid-pin[data-key]'), applyPins);
    }).catch(function () {
      if (td) {
        td.classList.remove('exm-saving');
        td.classList.add('exm-error');
        setTimeout(function () { td.classList.remove('exm-error'); }, 1600);
      }
      notify('error', menu.getAttribute('data-assign-error') || '');
    });
  }

  function runCtxAction(menu, act) {
    var id = menu.getAttribute('data-row-id');
    var col = menu.getAttribute('data-row-col');
    if (!id) return;
    var view = menu.getAttribute('data-view-url') || '';
    var webapi = menu.getAttribute('data-webapi-url') || '';
    var csrf = menu.getAttribute('data-csrf') || '';

    if (act === 'preview') {
      openPeekModal(menu, id);
      return;
    }
    if (act === 'assign-me') {
      assignToMe(menu, id);
      return;
    }
    if (act === 'view') {
      window.location.href = view + '/' + encodeURIComponent(id);
      return;
    }
    if (act === 'edit') {
      window.location.href = view + '/' + encodeURIComponent(id) + '/edit';
      return;
    }
    if (act === 'copy-row') {
      window.location.href = view + '/create?copy_id=' + encodeURIComponent(id);
      return;
    }
    if (act === 'filter') {
      // Not a data column - the entry is rendered inert in that case
      // (see showCtxMenu), so this is only the belt-and-braces path. It
      // must never navigate: dropping the user on a bare list URL would
      // lose the filter, the sort and the page they were on.
      if (!col || col.indexOf('__') === 0) return;
      var tr = findRowById(id);
      if (!tr) return;
      var ftable = closest(tr, 'table.exm-grid');
      var fcell = tr.querySelector('td.column-' + col);
      if (!ftable || !fcell) return;
      applyRowFilter(ftable, menu, col, cellText(fcell));
      return;
    }
    if (act === 'copy-cell') {
      var tr2 = findRowById(id);
      var target = null;
      if (tr2 && col) target = tr2.querySelector('td.column-' + col);
      var text = target ? (target.textContent || '').trim() : '';
      if (!text) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(function () { /* silent */ });
      } else {
        // Fallback: hidden textarea + document.execCommand('copy')
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (er) { /* silent */ }
        document.body.removeChild(ta);
      }
      return;
    }
    if (act === 'delete') {
      var msg = menu.getAttribute('data-confirm') || 'Delete this record?';
      if (!window.confirm(msg)) return;
      var deleteUrl = webapi + '/' + encodeURIComponent(id);
      fetch(deleteUrl, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).then(function (r) {
        if (!r.ok && r.status !== 204) throw new Error('delete:' + r.status);
        var tr3 = findRowById(id);
        if (tr3 && tr3.parentNode) tr3.parentNode.removeChild(tr3);
        // A deleted row was probably still selected. Keep the bulk bar
        // in sync so its count does not sit one high.
        setTimeout(syncBulk, 0);
      }).catch(function () {
        // Rely on the user-visible bar - a full alert would be too much
        // for a one-row delete. The row simply stays.
        console.warn('exment: inline delete failed');
      });
      return;
    }
  }

  /* ------------------------------------------------------- listeners --- */

  document.addEventListener('click', function (e) {
    var t = e.target;

    var densityItem = closest(t, '.exm-density-item');
    if (densityItem) {
      e.preventDefault();
      var densityBox = closest(densityItem, '.exm-grid-density[data-grid]');
      if (!densityBox) return;
      var density = densityItem.getAttribute('data-density') || DEFAULT_DENSITY;
      writeStore('localStorage', DENSITY_KEY, density);
      applyDensity(document.getElementById(densityBox.getAttribute('data-grid')), density);
      markActive(densityBox, '.exm-density-item', 'data-density', density);
      return;
    }

    var refreshItem = closest(t, '.exm-refresh-item');
    if (refreshItem) {
      e.preventDefault();
      var refreshBox = closest(refreshItem, '.exm-grid-refresh[data-key]');
      if (!refreshBox) return;
      var sec = parseInt(refreshItem.getAttribute('data-sec') || '0', 10);
      var key = REFRESH_PREFIX + refreshBox.getAttribute('data-key');
      writeStore('sessionStorage', key, sec > 0 ? sec : null);
      initRefresh();
      return;
    }

    // Pinning is usually done a few columns at a time, so these two keep
    // the dropdown open - Bootstrap closes it on any click that reaches
    // the document.
    var pinItem = closest(t, '.exm-pin-item');
    if (pinItem) {
      e.preventDefault();
      e.stopPropagation();
      var pinBox = closest(pinItem, '.exm-grid-pin[data-key]');
      if (!pinBox) return;
      var pinKey = pinBox.getAttribute('data-key');
      var pinTable = gridOf(pinBox);
      var pins = readPins(pinKey);
      // The first tick on an untouched grid starts from what is on screen,
      // not from an empty list - otherwise the default pin would silently
      // vanish the moment the user pins a second column.
      if (pins === null) pins = pinTable ? defaultPins(pinTable) : [];
      var col = pinItem.getAttribute('data-col');
      var at = pins.indexOf(col);
      if (at > -1) {
        pins.splice(at, 1);
      } else {
        pins.push(col);
      }
      writePins(pinKey, pins);
      applyPins(pinBox, true);
      return;
    }

    var wrapItem = closest(t, '.exm-wrap-item');
    if (wrapItem) {
      e.preventDefault();
      if (!closest(wrapItem, '.exm-grid-density[data-grid]')) return;
      writeStore('localStorage', NOWRAP_KEY, nowrapOn() ? '0' : '1');
      initDensity();
      // Column widths move with the wrapping, so every frozen offset is
      // stale now.
      initPin();
      return;
    }

    var pinRightItem = closest(t, '.exm-pin-right');
    if (pinRightItem) {
      e.preventDefault();
      e.stopPropagation();
      var rightBox = closest(pinRightItem, '.exm-grid-pin[data-key]');
      if (!rightBox) return;
      var rightKey = PIN_RIGHT_PREFIX + rightBox.getAttribute('data-key');
      var wasRight = readFlag(rightKey, true);
      writeStore('localStorage', rightKey, wasRight ? '0' : '1');
      applyPins(rightBox);
      return;
    }

    var pinHeadItem = closest(t, '.exm-pin-head');
    if (pinHeadItem) {
      e.preventDefault();
      e.stopPropagation();
      var headBox = closest(pinHeadItem, '.exm-grid-pin[data-key]');
      if (!headBox) return;
      var headKey = PIN_HEAD_PREFIX + headBox.getAttribute('data-key');
      var wasOn = readFlag(headKey, true);
      writeStore('localStorage', headKey, wasOn ? '0' : '1');
      // The whole pin pass, not just the height: toggling the vertical
      // scrollbar changes the box's inner width, so every pinned offset
      // has to be measured again.
      applyPins(headBox);
      return;
    }

    var pinPreset = closest(t, '.exm-pin-preset');
    if (pinPreset) {
      e.preventDefault();
      var presetBox = closest(pinPreset, '.exm-grid-pin[data-key]');
      if (!presetBox) return;
      var table = gridOf(presetBox);
      if (!table) return;
      var take = parseInt(pinPreset.getAttribute('data-preset') || '0', 10);
      writePins(presetBox.getAttribute('data-key'), dataColumnNames(table).slice(0, take));
      applyPins(presetBox, true);
      return;
    }

    var groupItem = closest(t, '.exm-group-item');
    if (groupItem) {
      e.preventDefault();
      var groupBox = closest(groupItem, '.exm-grid-group[data-key]');
      if (!groupBox) return;
      var groupCol = groupItem.getAttribute('data-col') || '';
      writeStore('sessionStorage', GROUP_PREFIX + groupBox.getAttribute('data-key'), groupCol || null);
      applyGroup(groupBox);
      return;
    }

    var groupRow = closest(t, 'tr.exm-group-row');
    if (groupRow) {
      var collapsed = !groupRow.classList.contains('collapsed');
      setGroupCollapsed(groupRow, collapsed);

      // Remember the fold so the next rebuild - a regroup, a filter, a
      // pjax reload - puts the heading back the way the user left it.
      var gtable = closest(groupRow, 'table.exm-grid');
      var gkey = storeKeyOf(gtable);
      var gcol = groupColumnOf(gtable);
      if (gkey && gcol) {
        var folds = readGroupFold(gkey, gcol);
        var gval = groupRow.getAttribute('data-exm-group-val') || '';
        var at2 = folds.indexOf(gval);
        if (collapsed && at2 === -1) folds.push(gval);
        if (!collapsed && at2 > -1) folds.splice(at2, 1);
        writeGroupFold(gkey, gcol, folds);
      }

      // Rows that just went invisible leave the selection - enforced in
      // syncBulk, which also refreshes both selection counters. Runs on
      // expand too so the counters never sit stale.
      setTimeout(syncBulk, 0);
      return;
    }

    var filterClear = closest(t, '.exm-gridfilter-clear');
    if (filterClear) {
      e.preventDefault();
      var chipBox = closest(filterClear, '.exm-gridfilter-chip[data-grid]');
      if (!chipBox) return;
      clearRowFilter(gridOf(chipBox));
      return;
    }

    var bulkAct = closest(t, '.exm-bulk-act');
    if (bulkAct) {
      e.preventDefault();
      var actBar = closest(bulkAct, '.exm-bulkbar');
      if (!actBar) return;
      var link = batchLinks(actBar)[parseInt(bulkAct.getAttribute('data-idx') || '-1', 10)];
      // A native click, so the handler laravel-admin bound to the
      // original link runs with its own confirm dialog and permissions.
      if (link) link.click();
      return;
    }

    var bulkEditBtn = closest(t, '.exm-bulk-edit');
    if (bulkEditBtn) {
      e.preventDefault();
      openBulkEditModal(closest(bulkEditBtn, '.exm-bulkbar'));
      return;
    }

    var bulkExportBtn = closest(t, '.exm-bulk-export');
    if (bulkExportBtn) {
      e.preventDefault();
      runBulkExport(
        closest(bulkExportBtn, '.exm-bulkbar'),
        bulkExportBtn.getAttribute('data-format') || 'csv'
      );
      return;
    }

    if (closest(t, '.exm-bulk-clear')) {
      e.preventDefault();
      clearSelection();
      return;
    }

    var applyBtn = closest(t, '.exm-col-apply');
    if (applyBtn) {
      e.preventDefault();
      applyColumns(applyBtn);
      return;
    }

    var bulk = closest(t, '.exm-col-all, .exm-col-none, .exm-col-default');
    if (bulk) {
      e.preventDefault();
      var colModal = closest(bulk, '.modal');
      if (!colModal) return;
      var mode = bulk.classList.contains('exm-col-all')
        ? 'all'
        : (bulk.classList.contains('exm-col-none') ? 'none' : 'default');
      each(colModal.querySelectorAll('.exm-col-check'), function (c) {
        if (mode === 'all') {
          c.checked = true;
        } else if (mode === 'none') {
          c.checked = false;
        } else {
          c.checked = c.getAttribute('data-default') === '1';
        }
      });
      var warnEl = colModal.querySelector('.exm-col-warn');
      if (warnEl) warnEl.classList.remove('show');
      return;
    }

    // ------ inline editor: pen icon opens the same picker double-click does.
    var pen = closest(t, '.exm-edit-pen');
    if (pen) {
      e.preventDefault();
      e.stopPropagation();
      openInlineEditor(closest(pen, 'td.exm-editable'));
      return;
    }

    // ------ context menu entries. The menu is a floating <div>, so
    // clicking anywhere OUTSIDE it must close it. `closest(t, .exm-ctxmenu)`
    // tells us apart.
    var ctxEntry = closest(t, '.exm-ctxmenu a[data-act]');
    if (ctxEntry) {
      e.preventDefault();
      // Greyed entry: swallow the click but leave the menu open so the
      // user can pick something that does apply to this cell.
      if (ctxEntry.classList.contains('disabled')) return;
      var ctxMenu = closest(ctxEntry, '.exm-ctxmenu');
      if (!ctxMenu) return;
      var act = ctxEntry.getAttribute('data-act');
      hideCtxMenu();
      // Deferred a tick so the menu is torn down before a navigation
      // begins; without it a very fast browser can end up trying to
      // hide a menu on a page that is already unloading.
      setTimeout(function () { runCtxAction(ctxMenu, act); }, 0);
      return;
    }

    // Any click outside an open menu closes it. Do this last so the
    // click on an entry above still runs its action first.
    if (activeCtx && !closest(t, '.exm-ctxmenu')) {
      hideCtxMenu();
    }
  });

  // Double-click on an editable cell opens the picker. Delegated on
  // document so a pjax swap does not need re-binding.
  document.addEventListener('dblclick', function (e) {
    // Ignore double-clicks on links, buttons and inputs the row already
    // owns - dblclick on the row's own action link should not eat it.
    if (closest(e.target, 'a, button, input, textarea, select, label')) return;
    var td = closest(e.target, 'td.exm-editable');
    if (!td) return;
    e.preventDefault();
    openInlineEditor(td);
  });

  // Right-click on any grid cell opens the context menu. Shift+right-click
  // is deliberately let through: it is the browser's own escape hatch to
  // "copy link address", "inspect element", etc.
  document.addEventListener('contextmenu', function (e) {
    if (e.shiftKey) return;
    var td = closest(e.target, 'table.exm-grid td');
    if (!td) return;
    var tr = closest(td, 'tr');
    if (!tr || tr.classList.contains('exm-group-row')) return;
    var menu = findCtxMenuFor(td);
    if (!menu) return;
    e.preventDefault();
    showCtxMenu(menu, td, e);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && activeCtx) hideCtxMenu();
  });

  document.addEventListener('input', function (e) {
    if (!e.target.classList || !e.target.classList.contains('exm-col-search')) return;
    var q = (e.target.value || '').toLowerCase();
    var modal = closest(e.target, '.modal');
    if (!modal) return;
    each(modal.querySelectorAll('.exm-col-item'), function (li) {
      var nameEl = li.querySelector('.exm-col-name');
      var name = nameEl ? (nameEl.textContent || '') : '';
      li.style.display = name.toLowerCase().indexOf(q) === -1 ? 'none' : '';
    });
  });

  // Selection changes. The batch checkboxes are driven by iCheck, whose
  // own event is jQuery-only and is hooked up in bindPjaxHooks below;
  // this native listener is what covers a build where iCheck never took
  // over. Deferred by a tick either way, so the theme's own handlers have
  // finished updating `checked` before the count is read.
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.classList) return;
    if (el.classList.contains('grid-row-checkbox') || el.classList.contains('grid-select-all')) {
      setTimeout(syncBulk, 0);
    }
  });

  /* ------------------------------------------------------------ boot --- */

  /**
   * Mark the toolbar row that carries the grid tools. The header itself
   * is stock laravel-admin markup with no class of its own to hook, and
   * grid_tools.css needs one for the phone layout (flex flow instead of
   * two floats) and for keeping its dropdown menus on screen.
   */
  function initHeaderFlag() {
    each(document.querySelectorAll('.exm-grid-tool'), function (tool) {
      var head = closest(tool, '.box-header');
      if (head) head.classList.add('exm-grid-header');
    });
  }

  function initAll() {
    placeFilterChips();
    initHeaderFlag();
    initDensity();
    initRefresh();
    // Grouping moves rows around, so it runs before the pins are measured.
    initGroup();
    initPin();
    // After group and pin: re-applying a stored filter re-runs both over
    // the rows it hides, so this order does the double work only when a
    // filter actually exists.
    initFilter();
    initBulk();
    initInline();
    initCtxMenu();
  }

  // Column widths - and therefore every pinned offset - change with the
  // window, the sidebar toggle and a zoom step.
  var pinResizeTimer = null;
  window.addEventListener('resize', function () {
    if (pinResizeTimer) clearTimeout(pinResizeTimer);
    pinResizeTimer = setTimeout(initPin, 150);
  });

  // Bootstrap positions a header dropdown against its own button, not
  // against the screen. On a phone that puts the right-anchored pin menu
  // 70px past the left edge (its button sits near it) and walks the view
  // menu off the right. Once a menu is open, nudge it back inside the
  // viewport. The nudge rides on margin-left - safe ground, because
  // Popper owns transform/inset and rewrites only those on its updates.
  //
  // Swept from a click, not from shown.bs.dropdown: the shown event fires
  // before Popper's (async) first positioning, so a rect read there is
  // the menu's pre-Popper spot - and the view selector's menu never
  // fires the event at all. Every one of these menus opens and closes by
  // click, so one deferred sweep after each click catches them all.
  function clampHeaderMenus() {
    each(document.querySelectorAll('.exm-grid-header .dropdown-menu'), function (menu) {
      if (!menu.classList.contains('show')) {
        // Closed: drop the nudge so the next open starts from
        // Bootstrap's own position and is measured fresh - the toolbar
        // may have wrapped differently by then.
        menu.style.removeProperty('margin-left');
        return;
      }
      var vw = document.documentElement.clientWidth;
      var rect = menu.getBoundingClientRect();
      var dx = 0;
      if (rect.right > vw - 8) dx = vw - 8 - rect.right;
      if (rect.left + dx < 8) dx = 8 - rect.left;
      if (dx) {
        // Additive: the rect already includes any nudge applied on an
        // earlier sweep of this same open menu.
        var base = parseFloat(menu.style.marginLeft) || 0;
        menu.style.marginLeft = Math.round(base + dx) + 'px';
      }
    });
  }
  document.addEventListener('click', function () {
    setTimeout(clampHeaderMenus, 50);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // pjax events are jQuery custom events, so they are only visible on the
  // jQuery bus - a native addEventListener never sees them. jQuery may not
  // be parsed yet when this file runs, hence the retry.
  function bindPjaxHooks() {
    if (!window.$ || !window.$.fn) {
      return setTimeout(bindPjaxHooks, 200);
    }
    window.$(document)
      .on('pjax:send pjax:start', function () {
        pjaxInFlight = true;
        // An editor still open when the grid is replaced would be left
        // holding a detached cell, and its blur timer would fire against
        // a config that no longer exists. Cancelled, never committed:
        // the auto-refresh is not the user pressing Enter.
        if (activeInlineTd) finishInlineEditor(activeInlineTd, false);
        each(document.querySelectorAll('.exm-col-modal.show, .exm-col-modal.d-block'), hideModal);
        // The bar lives on <body>, outside the container being swapped,
        // so it would otherwise keep showing the count of a page that is
        // already gone.
        each(document.querySelectorAll('body > .exm-bulkbar'), function (bar) {
          bar.classList.remove('show');
        });
      })
      .on('pjax:complete pjax:end pjax:error', function () {
        pjaxInFlight = false;
        cleanupBackdrops();
        // jQuery-pjax fires pjax:complete then pjax:end back to back
        // for a normal load. Running initAll twice would remove the
        // bulk bar we just re-parented into <body>: the first pass
        // moves the fresh bar out of the pjax container, the second
        // pass strips the body copy but has nothing to replace it
        // with. A microtask debounce collapses the pair without any
        // additional bookkeeping - the handler is idempotent enough
        // that skipping the duplicate call is safe.
        if (initAllQueued) return;
        initAllQueued = true;
        Promise.resolve().then(function () {
          initAllQueued = false;
          initAll();
        });
      })
      // iCheck replaces the checkbox with its own widget and reports
      // through these two events only; nothing native fires.
      .on('ifChanged ifClicked', '.grid-row-checkbox, .grid-select-all', function () {
        setTimeout(syncBulk, 0);
      });
  }
  bindPjaxHooks();
})();
