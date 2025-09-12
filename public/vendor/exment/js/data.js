$(function () {
    $(window).off("exment:loaded", run);
    $(window).on("exment:loaded", run);
});
function run() {
    const lhref = $(".logo").attr("href");
    var href = window.location.href;
    changeUI01(lhref, href, "execution_logs");


}

function  changeUI01(lhref, href, ui) {

    if (href.includes(`/data/${ui}`) && !href.includes(`/data/${ui}/`)) {
        $(".pull-right").hide();

    }else if(href.includes(`/data/${ui}/`)){
        $(
            ".fields-group > .row:nth-child(1) > .gridsow-columns.col-md-12"
         ).hide();

         $(".box-tools").hide();
             $(".form-group").has("label:contains('実行ユーザ')").css("width", "115%");


    }
}
