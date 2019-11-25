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
                    @if(isset($fields['8']))
                    <div class="">
                    @foreach($fields['8'] as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                    @endif
                    @if(isset($fields['1']) || isset($fields['2']))
                    <div class="">
                        @if(isset($fields['1']) && isset($fields['2']))
                        <div class="col-xs-12 col-md-6">
                        @endif
                            @if(isset($fields['1']))
                            @foreach($fields['1'] as $field)
                                {!! $field->render() !!}
                            @endforeach
                            @endif
                        @if(isset($fields['1']) && isset($fields['2']))
                        </div>
                        <div class="col-xs-12 col-md-6">
                        @endif
                            @if(isset($fields['2']))
                            @foreach($fields['2'] as $field)
                                {!! $field->render() !!}
                            @endforeach
                            @endif
                        @if(isset($fields['1']) && isset($fields['2']))
                        </div>
                        @endif
                    </div>
                    @endif
                    @if(isset($fields['9']))
                    <div class="">
                    @foreach($fields['9'] as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                    @endif
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