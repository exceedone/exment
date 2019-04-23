<div class="box box-{{ $style }}">
    <div class="box-header with-border">
        <h3 class="box-title">{{ $title }}</h3>

        <div class="box-tools">
            {!! $tools !!}
        </div>
    </div>
    <!-- /.box-header -->
    <!-- form start -->
    <div class="form-horizontal">

        <div class="box-body">

            <div class="fields-group">
                @if($fields->count() > 1)
                    <div class="row">
                    @foreach($fields as $column)
                        <div class="col-xs-12 col-md-{{12 / $fields->count()}}">
                        @foreach($column as $field)
                            {!! $field->render() !!}
                        @endforeach
                        </div>
                    @endforeach
                    </div>
                @else
                    @foreach($fields as $column)
                        @foreach($column as $field)
                            {!! $field->render() !!}
                        @endforeach
                    @endforeach
                @endif
            </div>

        </div>
        <!-- /.box-body -->
    </div>
</div>