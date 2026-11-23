{{-- "Copy from (table)" on the new-record form (#1828). The button opens the search
     dialog of the copy-from table, the same one as the search button of a select-table
     column; the record picked there is written to the hidden select, and the change
     opens the new-record form that starts from it. --}}
<div class="form-group btn-group float-end p-0 mb-0">
    <select class="{{ $target_class }} d-none" data-copy-from-url="{{ $url }}" aria-hidden="true" tabindex="-1"></select>
    <a href="javascript:void(0);" class="btn btn-sm btn-default justify-content-center align-items-center d-flex" style="margin-right:5px; font-size:12px !important;"
        data-widgetmodal_url="{{ $modal_url }}"
        data-widgetmodal_expand="{{ $expand }}"
        title="{{ $label }}">
        <i class="fa fa-copy p-1"></i>
        <span class="d-none d-lg-block">&nbsp;{{ $label }}</span>
    </a>
</div>
