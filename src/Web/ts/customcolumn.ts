
namespace Exment {
    /**
    * Column Column Script.
    */
    export class CustomColumnEvent {
        public static AddEvent() {
        }

        public static AddEventOnce() {
            $(document).off('click.exment_custom_column', '[data-contentname="options_calc_formula"] .button-addcalcitem').on('click.exment_custom_column', '[data-contentname="options_calc_formula"] .button-addcalcitem', {}, CustomColumnEvent.calcButtonAddItemEvent);
            $(document).off('click.exment_custom_column', '#validateFormula').on('click.exment_custom_column', '#validateFormula', {}, CustomColumnEvent.validateFormula);
            $(document).off('keydown.exment_custom_column', '#calc_formula_input').on('keydown.exment_custom_column', '#calc_formula_input', {}, CustomColumnEvent.inputFormulaEvent);

            $(document).on('pjax:complete', function (event) {
                CustomColumnEvent.AddEvent();
            });
        }


        /**
         * Add calc event
         * @param ev 
         */
        private static calcButtonAddItemEvent = (ev) => {
            let target = $(ev.target).closest('.button-addcalcitem');
            CustomColumnEvent.setCalcInput(target.data('val').trim());

            $('#calc_formula_input').trigger('keydown.exment_custom_column');
        }


        /**
         * Set formula input area
         * @param text
         */
        private static setCalcInput(text){
            let area : HTMLInputElement = $('#calc_formula_input').get(0) as HTMLInputElement;
            area.value = area.value.substr(0, area.selectionStart)
                    + text.trim()
                    + area.value.substr(area.selectionStart);
        }

        public static GetSettingValText(){
            let formula = $('#calc_formula_input').val();
        
            // replace ${XXX} string as column name
            formula = formula.replace(/\$\{.+?\}/g, function (match) {
                    let $target = $('.col-target-block-column button[data-val="' + match + '"]');
                    if(!hasValue($target)){
                        return match;
                    }

                    return $target.data('displayText');
                });

            return {value: $('#calc_formula_input').val(), text: formula};
        }


        private static inputFormulaEvent(e){
            if (e && e.key == 'Enter') {
                return false;
            }

            $('.modal .modal-submit').prop('disabled', true);
            $('.modal #validateResult > span').hide();
        }

        private static validateFormula(){
            let result = true;
            let formula = $('#calc_formula_input').val();
            if(!hasValue(formula)){
                result = false;
            }else{
                // replace ${XXX} string as 1
                let replaceFormula = formula.replace(/\$\{.+?\}/g, function (match) {
                    // find key value
                    let $target = $('.col-target-block-column button[data-val="' + match + '"]');
                    if(!hasValue($target)){
                        result = false;
                    }
                    return 1;
                });
                    
                if(result){
                    result = CalcEvent.validateMathFormula(replaceFormula);
                }
                
                // if match \$\{.+?\} \$\{.+?\}, return false
                if(formula.match(/\$\{.+?\} *\$\{.+?\}/g)){
                    result = false;
                }

            }

            $('.modal .modal-submit').prop('disabled', !result);

            $('.modal #validateResult > span').hide();
            if(result){
                $('.modal #validateResultSuccess').show();
            }
            else{
                $('.modal #validateResultError').show();
            }
        }
    }
}
$(function () {
    Exment.CustomColumnEvent.AddEvent();
    Exment.CustomColumnEvent.AddEventOnce();
});
