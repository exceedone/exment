<div class="form-group exm-section-nav" style="display:none;">
    <nav aria-label="{{ $navLabel }}">
        <div class="exm-section-nav-title">{{ $navLabel }}</div>
        <div class="exm-section-nav-list"></div>
    </nav>
</div>
<style>
    /* The jump buttons: one compact row that stays at the top of the screen. */
    .exm-section-nav {
        margin: 0 0 16px;
        padding: 8px 0;
        background: var(--exm-ui-bg, #fff);
        border-bottom: 1px solid var(--exm-ui-line, #e5e7eb);
    }
    .exm-section-nav.exm-section-nav-fixed {
        position: fixed;
        top: 0;
        z-index: 1030;
        padding: 6px 12px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, .08);
    }
    .exm-section-nav.exm-section-nav-fixed .exm-section-nav-title {
        display: none;
    }
    /* Fixed, it keeps to one line and scrolls sideways, so it never covers the page. */
    .exm-section-nav.exm-section-nav-fixed .exm-section-nav-list {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    .exm-section-nav.exm-section-nav-fixed .exm-section-nav-item {
        flex: none;
    }
    .exm-section-nav .exm-section-nav-title {
        margin-bottom: 4px;
        font-size: 12px;
        color: var(--exm-ui-text-faint, #6b7280);
    }
    .exm-section-nav .exm-section-nav-list {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }
    .exm-section-nav .exm-section-nav-item {
        padding: 2px 10px;
        font-size: 12px;
        line-height: 1.6;
        border-radius: 999px;
    }

    /* One box per section: the title and "back to top" in the head, the fields in the body. */
    .exm-section-box {
        margin: 0 0 16px;
        background: var(--exm-ui-bg, #fff);
        border: 1px solid var(--exm-ui-line, #e5e7eb);
        border-radius: var(--exm-ui-radius-md, 8px);
        box-shadow: none;
        scroll-margin-top: 80px;
    }
    .exm-section-box > .exm-section-head {
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 16px;
        background: var(--exm-ui-bg-sub, #f9fafb);
        border-bottom: 1px solid var(--exm-ui-line, #e5e7eb);
        border-radius: var(--exm-ui-radius-md, 8px) var(--exm-ui-radius-md, 8px) 0 0;
    }
    .exm-section-box > .exm-section-head .box-title {
        flex: 1 1 auto;
        width: auto;
        float: none;
        text-align: left;
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: var(--exm-ui-text, #111827);
    }
    .exm-section-box > .exm-section-head .exm-section-nav-back {
        flex: none;
        margin-left: auto;
        font-weight: normal;
        white-space: nowrap;
    }
    .exm-section-box > .exm-section-body {
        padding: 12px 16px 4px;
    }

    /* Checkbox groups on one line with their label, wrapping evenly. */
    .exm-sectioned .exm-section-body .form-group > div > .icheck {
        display: inline-block;
        margin: 7px 16px 0 0;
    }
    .exm-sectioned .exm-section-body .form-group > div > .icheck .checkbox-inline {
        margin: 0;
        padding-left: 0;
    }

    /* The YES / NO switch: the knob is a visible part, not a blank half. */
    .exm-sectioned .bootstrap-switch {
        border: 1px solid var(--exm-ui-line, #d1d5db);
    }
    .exm-sectioned .bootstrap-switch .bootstrap-switch-label {
        background: var(--exm-ui-bg-sub, #f3f4f6);
        box-shadow: inset 1px 0 0 var(--exm-ui-line, #d1d5db), inset -1px 0 0 var(--exm-ui-line, #d1d5db);
    }
</style>
