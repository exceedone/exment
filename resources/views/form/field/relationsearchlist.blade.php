<div class="{{$viewClass['form-group']}}">
    <label class="{{$viewClass['label']}} control-label text-lg-end pt-2">{{$label}}</label>
    <div class="{{$viewClass['field']}}">
        @if(count($rows) === 0)
        <p class="form-control-static">{{ exmtrans('custom_table.relation_search.none') }}</p>
        @else
        <table class="table table-bordered exm-relation-search" id="exm-relation-search-{{ $id }}">
            <thead>
            <tr class="active">
                <th>{{ exmtrans('custom_table.relation_search.table') }}</th>
                <th class="text-center" style="width:120px;">{{ exmtrans('custom_table.relation_search.show') }}</th>
                <th class="text-center" style="width:100px;">{{ trans('admin.action') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($rows as $row)
            <tr>
                <td style="vertical-align:middle;">
                    {{ $row['label'] }}
                    <input type="hidden" name="{{ $name }}[{{ $loop->index }}][key]" value="{{ $row['key'] }}" />
                </td>
                <td class="text-center" style="vertical-align:middle;">
                    {{-- unticked sends the hidden 1, ticked sends 0 after it --}}
                    <input type="hidden" name="{{ $name }}[{{ $loop->index }}][hidden]" value="1" />
                    <input type="checkbox" name="{{ $name }}[{{ $loop->index }}][hidden]" value="0" {{ $row['hidden'] ? '' : 'checked' }} />
                </td>
                <td class="text-center" style="vertical-align:middle;">
                    <a href="javascript:void(0);" class="btn btn-xs btn-primary exm-rs-move exm-rs-down" title="{{ exmtrans('common.row_down') }}"><i class="fa fa-arrow-down"></i></a>
                    <a href="javascript:void(0);" class="btn btn-xs btn-success exm-rs-move exm-rs-up" title="{{ exmtrans('common.row_up') }}"><i class="fa fa-arrow-up"></i></a>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
        @endif
        @include('admin::form.help-block')
    </div>
</div>
<script>
$(function () {
    // the order the rows stand in is the order the related search shows them in
    $('#exm-relation-search-{{ $id }}').off('click.exm_rs').on('click.exm_rs', '.exm-rs-move', function () {
        var row = $(this).closest('tr');
        if ($(this).hasClass('exm-rs-up')) {
            row.insertBefore(row.prev('tr'));
        } else {
            row.insertAfter(row.next('tr'));
        }
        row.stop().css('background-color', '#FFFFCC').animate({ backgroundColor: 'rgba(0,0,0,0.0)' }, 1000);
    });
});
</script>
