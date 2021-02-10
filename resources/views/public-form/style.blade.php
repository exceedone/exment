<style>
    .main-header .navbar, .main-header .logo{
        color: {{$header_text_color}};
        background-color: {{$header_background_color}};
    }
    .main-footer{
        background-color: {{$footer_background_color}};
    }
    .main-footer, .main-footer a{
        color: {{$footer_text_color}};
    }
    body.body-container, body.body-container .wrapper{
        background-color: {{$background_color_outer}};
    }

    body #container-inner{
        background-color: {{$background_color}};
        min-height: calc(100vh - {{$container_height}}px);
    }
</style>
