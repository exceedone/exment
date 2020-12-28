$(function () {
    $(window).off('exment:form_loaded', setAddressEvent).on('exment:form_loaded', setAddressEvent);

    function setAddressEvent(){
        $('.value_zip01,.value_zip02').off('change', setAddressKeyup).on('change', setAddressKeyup);
    }

    function setAddressKeyup(){
        const value_zip01 = $('.value_zip01').val();
        const value_zip02 = $('.value_zip02').val();

        if(!value_zip01 || !value_zip02){
            $('.value_addr01').val('');
            $('.value_addr02').val('');
            $('.value_pref').val('');
            return;
        }

        AjaxZip3.zip2addr('value[zip01]','value[zip02]','value[pref]','value[addr01]','value[addr02]');
    }
});
