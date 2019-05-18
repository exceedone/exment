<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}" style="margin-bottom:30px;">

        <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>
    
        <div class="{{$viewClass['field']}}">
    
            @include('admin::form.error')
    
            <div id="tile-{{$column}}" class="" style="margin:0;">
                <div class="" style="margin: 0 -10px;">
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-search fa-fw"></i></span>
                        <input type="text" id="template_search" name="template_search" value="" class="form-control template_search" placeholder="{{trans('admin.search')}}">
                    </div>
                </div>
                <div class="overlay-wrapper">
                    <div class="tile-group-items row-eq-height row tile-group"></div>
                    <div class="overlay dark">
                        <i class="fa fa-refresh fa-spin"></i>
                    </div>
                </div>
            </div>
    
            @include('admin::form.help-block')
    
        </div>
    </div>
    