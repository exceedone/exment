<style>
    body,
    .wrapper {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    footer {
        margin-top: auto;
    }
    .content-wrapper, .right-side, .main-footer{
        margin-left: 0;
    }

    .main-header{
        border-bottom:1px solid #d2d6de;
    }
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
        margin: 20px auto;
        padding: 10px;
        min-height: calc(100vh - {{$container_height}}px);
    }


    .g-recaptcha > div{
        margin: 10px auto;
    }
    
@media (max-width: 767px)
{
    body.body-container #container-inner{
        margin: 5px;
    }
}
</style>
