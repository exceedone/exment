<style>
.{{ $login_provider['btn_name'] }}{
    {{ isset($login_provider['background_color']) ? 'background-color:'.$login_provider['background_color'].';' : ''}}
    {{ isset($login_provider['font_color']) ? 'color:'.$login_provider['font_color'].';' : '' }}
}
.{{ $login_provider['btn_name'] }}:hover, .{{ $login_provider['btn_name'] }}:focus,.{{ $login_provider['btn_name'] }}:active{
    {{ isset($login_provider['background_color_hover']) ? 'background-color:'.$login_provider['background_color_hover'].';' : ''}}              
    @if(isset($login_provider['font_color']) || !isset($login_provider['font_color_hover']))
    color: {{ isset($login_provider['font_color_hover']) ? $login_provider['font_color_hover'] : $login_provider['font_color'] }};              
    @endif
}
</style>
