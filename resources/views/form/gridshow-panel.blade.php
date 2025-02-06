<div class="box box-{{ $style }} card p-2 custom-border-info">
    <div class="box-header with-border d-flex justify-content-between border-bottom p-1 pb-3">
        <h4 class="box-title">{{ $title }}</h4>

        <div class="box-tools d-flex flex-row-reverse">
            {!! $tools !!}
        </div>
    </div>
    <!-- /.box-header -->
    <!-- form start -->
    <div class="form-horizontal">

        <div class="box-body">

            <div class="fields-group">

            @if(isset($gridShows) && boolval($gridShows))
            @include('exment::form.gridshow-item')
            @elseif(isset($fields))
                @foreach($fields as $field)
                    {!! $field->render() !!}
                @endforeach        
            @endif



            </div>

        </div>
        <!-- /.box-body -->
    </div>
</div>