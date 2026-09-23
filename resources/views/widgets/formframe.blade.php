{{--
    One record's edit form, drawn for a window of somebody else's.
    The screen that opens the window already carries a title, a close button
    and the rest of the chrome, so nothing is added here: only the outer box
    of the admin layout is taken off, which would otherwise draw a second
    frame inside the first one.

    $standalone is set when this is a page of its own - a frame, or a saved
    link - and never when it is going into a page that is already open, where
    a rule on <body> or .wrapper would be a rule on that page.
--}}
@isset($standalone)
<style>
    body { background: #fff; }
    /* The admin layout wraps the page in a box of its own with the overflow
       taken off. It never scrolls - the page behind it does - but it is the
       innermost such box, which is the one a sticky element is measured
       against, so the footer below would never stick while it is there.
       !important: the skin sets it with a heavier hand than a page style. */
    .wrapper { overflow: visible !important; }
</style>
@endisset
<style>
    .exm-formframe { padding: 8px 12px 16px; background: #fff; }
    .exm-formframe > .box,
    .exm-formframe .box.box-primary { border: 0; box-shadow: none; margin: 0; }
    .exm-formframe .box-body { padding-left: 0; padding-right: 0; }
    /* The save stays on screen. A record with twenty fields is a long way
       to scroll to reach a button, and the window around this one carries
       nothing but a close. */
    .exm-formframe .box-footer {
        position: sticky;
        bottom: 0;
        z-index: 3;
        border-top: 1px solid #e4e8ed;
        /* !important: the footer carries background-color:inherit as an
           inline style, and the form scrolls underneath it now */
        background-color: #fff !important;
        box-shadow: 0 -2px 6px rgba(0, 0, 0, .06);
    }
    /* "edit" is what the window above already says, and every one of those
       buttons leaves for a screen that cannot be shown at this size - the
       record list, the table settings. What is left is the form and its save.
       Tabs are not in here: they are drawn under the header, not in it. */
    /* !important because the tool bar carries bootstrap's own d-flex, which
       is !important itself */
    .exm-formframe .box-header .box-title,
    .exm-formframe .box-header .box-tools { display: none !important; }
    .exm-formframe .box-header { padding: 0; min-height: 0; border-bottom: 0; }
</style>
<div class="exm-formframe">
    {!! $content !!}
</div>
