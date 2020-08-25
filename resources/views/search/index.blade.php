<div class="box box-info box-search">
    <div class="box-header with-border">
        <h3 class="box-title">{{ sprintf(exmtrans('search.result_label'), $query) }}</h3>
    </div>
    <!-- /.box-header -->

    <div class="box-body">
        @foreach($tables as $table)
        <div class="box box-search" style="{{ $table['box_sytle'] }}" data-box_key="{{ $table['box_key'] }}">
            <div class="overlay"><i class="fa fa-refresh fa-spin"></i></div>
            <div class="box-header with-border">
                <h3 class="box-title">
                    @if(isset($table['icon']))
                    <i class="fa {{ $table['icon'] }}"></i>
                    @endif
                    {{ $table['table_view_name'] }}
                </h3>
                <div class="box-tools pull-right">
                    <button type="button" class="btn btn-box-tool" data-widget="collapse">
                        <i class="fa fa-minus"></i>
                    </button>
                    <button type="button" class="btn btn-box-tool" data-widget="remove"><i class="fa fa-times"></i></button>
                </div>
            </div>
            <div class="box-body" style="min-height:100px;">
                <div class="box-body-inner">
                    <div class="box-body-inner-header box-body-inneritem"></div>
                    <div class="table-responsive" style="width:100%; padding-top:0.5em;">
                        <div class="box-body-inner-body box-body-inneritem"></div>
                    </div>
                    <div class="box-body-inner-footer box-body-inneritem"></div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    <!-- /.box-body -->

    @if(isset($query))
    <input type="hidden" class="base_query" value="{{ $query }}" />
    @endif
    @if(isset($value_id))
    <input type="hidden" class="value_id" value="{{ $value_id }}" />
    @endif
    @if(isset($table_name))
    <input type="hidden" class="table_name" value="{{ $table_name }}" />
    @endif
    <input type="hidden" class="tables" value="{{ json_encode($tables) }}" />
</div>

<style type="text/css">
</style>
<script type="text/javascript">
    
</script>