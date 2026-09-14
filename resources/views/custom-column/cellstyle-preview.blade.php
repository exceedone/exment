{{--
    Live sample of the cell appearance being configured.

    Only the frame is rendered here; cellstyle_preset.js paints the samples
    from whatever is currently typed in the fields below it. The settings
    change on every keystroke, and asking the server to redraw each time
    would make the screen feel broken.
--}}
<div class="form-group row pt-2">
    <label class="col-md-2 control-label text-lg-end pt-2">{{ exmtrans('cell_style_preset.preview') }}</label>
    <div class="col-md-8">
        <div class="exm-cellstyle-preview" data-cellstyle-preview="column">
            <div class="exm-cellstyle-preview-body"></div>
            <div class="exm-cellstyle-preview-note">{{ exmtrans('cell_style_preset.preview_note') }}</div>
        </div>
    </div>
</div>
