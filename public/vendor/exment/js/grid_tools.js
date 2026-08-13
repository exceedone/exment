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
  var GROUP_PREFIX = 'exment_grid_group_';

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

  function initDensity() {
    var density = currentDensity();
    each(document.querySelectorAll('.exm-grid-density[data-grid]'), function (box) {
      applyDensity(document.getElementById(box.getAttribute('data-grid')), density);
      markActive(box, '.exm-density-item', 'data-density', density);
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
      if (document.querySelector('.modal.show, .modal.d-block')) return;
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

  function readPins(key) {
    var raw = readStore('localStorage', PIN_PREFIX + key);
    if (!raw) return [];
    try {
      var list = JSON.parse(raw);
      return Array.isArray(list) ? list : [];
    } catch (e) {
      // A hand-edited or half-written entry must not take the grid down.
      return [];
    }
  }

  function writePins(key, list) {
    writeStore('localStorage', PIN_PREFIX + key, list.length ? JSON.stringify(list) : null);
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
  function applyPins(box) {
    var table = gridOf(box);
    if (!table) return;

    var key = box.getAttribute('data-key');
    var pinned = readPins(key);
    var pinRight = readStore('localStorage', PIN_RIGHT_PREFIX + key) === '1';

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

    var left = 0;
    group.forEach(function (name, idx) {
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

      var last = idx === group.length - 1;
      each(cells, function (cell) {
        cell.classList.add('exm-pin');
        cell.style.left = left + 'px';
        if (last) cell.classList.add('exm-pin-last');
      });

      left += width;
    });

    // Off by default: with a narrow table there is nothing to scroll, and
    // a frozen action column would simply sit on top of the last real
    // column for no gain.
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

    order.forEach(function (k) {
      var bucket = buckets[k];

      var head = document.createElement('tr');
      head.className = 'exm-group-row';
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
    });
  }

  function initGroup() {
    each(document.querySelectorAll('.exm-grid-group[data-grid]'), applyGroup);
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
    if (quiet) return;
    initGroup();
    initPin();
    setTimeout(syncBulk, 0);
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
   */
  function runBulkExport(bar) {
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
    params.set('format', 'csv');
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
    // Clear the cell down to the editor. The pen icon is inside the
    // snapshot, so cancel/commit restore it verbatim.
    td.innerHTML = '';
    td.appendChild(editor);

    // Focus & open picker as soon as the browser lets us - the second
    // click of a dblclick would otherwise land on the fresh element
    // and mark it selected without opening the dropdown.
    setTimeout(function () {
      editor.focus();
      if (editor.tagName === 'SELECT' && typeof editor.showPicker === 'function') {
        try { editor.showPicker(); } catch (e) { /* not supported everywhere */ }
      } else if (editor.tagName === 'INPUT') {
        editor.select();
      }
    }, 0);

    // change on a select fires on picking a value; blur is the cancel /
    // click-away commit path. Enter commits, Escape reverts.
    editor.addEventListener('change', function () { finishInlineEditor(td, true); });
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

  function readCurrentValue(td, meta) {
    if (!meta) return '';
    // Best-effort: read what the cell text says today. The client only
    // ever uses this to pre-select the matching option in the dropdown,
    // so a miss just means the dropdown opens with its first entry.
    var text = (td.textContent || '').replace(/\s+/g, ' ').trim();
    if (meta.type === 'select') {
      for (var i = 0; i < meta.choices.length; i++) {
        if (String(meta.choices[i].l) === text) return meta.choices[i].v;
      }
    }
    return '';
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
      for (var i = 0; i < meta.choices.length; i++) {
        var opt = document.createElement('option');
        opt.value = meta.choices[i].v;
        opt.textContent = meta.choices[i].l;
        if (String(meta.choices[i].v) === String(current)) opt.selected = true;
        select.appendChild(opt);
      }
      return select;
    }
    // No numeric/text editor in this release - would need per-type
    // validation the server owns. Bail with a plain input so anything
    // still opens, but this branch is not reachable while the meta
    // builder only emits `type: 'select'`.
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'exm-edit-input';
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
    // Restore the snapshot first so `readCurrentValue` sees the badge,
    // not the leftover `<select>`. Then compare against the value that
    // was picked.
    td.innerHTML = snapshot;
    // No meta means the config this editor was opened from is gone - the
    // grid was re-rendered underneath it. Without the column definition
    // there is nothing to validate the picked value against, so the edit
    // is dropped rather than sent on a guess.
    if (!meta) return;
    var currentValue = readCurrentValue(td, meta);
    if (String(newValue) === String(currentValue)) {
      // Same value the cell already shows: no request, no flash.
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

    saveInlineCell(cfg, id, column, newValue, td, snapshot);
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

  function runCtxAction(menu, act) {
    var id = menu.getAttribute('data-row-id');
    var col = menu.getAttribute('data-row-col');
    if (!id) return;
    var view = menu.getAttribute('data-view-url') || '';
    var webapi = menu.getAttribute('data-webapi-url') || '';
    var csrf = menu.getAttribute('data-csrf') || '';

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
      var pins = readPins(pinKey);
      var col = pinItem.getAttribute('data-col');
      var at = pins.indexOf(col);
      if (at > -1) {
        pins.splice(at, 1);
      } else {
        pins.push(col);
      }
      writePins(pinKey, pins);
      applyPins(pinBox);
      return;
    }

    var pinRightItem = closest(t, '.exm-pin-right');
    if (pinRightItem) {
      e.preventDefault();
      e.stopPropagation();
      var rightBox = closest(pinRightItem, '.exm-grid-pin[data-key]');
      if (!rightBox) return;
      var rightKey = PIN_RIGHT_PREFIX + rightBox.getAttribute('data-key');
      var wasRight = readStore('localStorage', rightKey) === '1';
      writeStore('localStorage', rightKey, wasRight ? null : '1');
      applyPins(rightBox);
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
      applyPins(presetBox);
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
      var collapsed = groupRow.classList.toggle('collapsed');
      var caret = groupRow.querySelector('.fa');
      if (caret) caret.className = collapsed ? 'fa fa-caret-right' : 'fa fa-caret-down';
      var next = groupRow.nextElementSibling;
      while (next && !next.classList.contains('exm-group-row')) {
        next.classList.toggle('exm-row-hidden', collapsed);
        // Same rule the page filter follows: a row nobody can see must
        // not stay in the selection, or a batch action reaches rows the
        // user has no way of unticking.
        if (collapsed) uncheckBox(next.querySelector('.grid-row-checkbox'));
        next = next.nextElementSibling;
      }
      if (collapsed) setTimeout(syncBulk, 0);
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
      runBulkExport(closest(bulkExportBtn, '.exm-bulkbar'));
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

  function initAll() {
    initDensity();
    initRefresh();
    // Grouping moves rows around, so it runs before the pins are measured.
    initGroup();
    initPin();
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
