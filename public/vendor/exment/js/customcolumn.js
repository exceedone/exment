var Exment;
(function (Exment) {
    /**
    * Column Column Script.
    */
    class CustomColumnEvent {
        static AddEvent() {
        }
        static AddEventOnce() {
            $(document).off('click.exment_custom_column', '[data-contentname="options_calc_formula"] .button-addcalcitem').on('click.exment_custom_column', '[data-contentname="options_calc_formula"] .button-addcalcitem', {}, CustomColumnEvent.calcButtonAddItemEvent);
            $(document).off('click.exment_custom_column', '#validateFormula').on('click.exment_custom_column', '#validateFormula', {}, CustomColumnEvent.validateFormula);
            $(document).off('keydown.exment_custom_column', '#calc_formula_input').on('keydown.exment_custom_column', '#calc_formula_input', {}, CustomColumnEvent.inputFormulaEvent);
            $(document).off('focus.exment_custom_column', '#calc_formula_input').on('focus.exment_custom_column', '#calc_formula_input', {}, CustomColumnEvent.focusFormulaEvent);
            $(document).on('pjax:complete', function (event) {
                CustomColumnEvent.AddEvent();
            });
        }
        /**
         * Set formula input area
         * @param text
         */
        static setCalcInput(text) {
            let area = $('#calc_formula_input').get(0);
            let point = null;
            if (hasValue(CustomColumnEvent.formulaInputselection)) {
                point = CustomColumnEvent.formulaInputselection;
            }
            else {
                point = area.selectionStart;
            }
            let afterPoint = area.value.substr(0, point).length + text.trim().length;
            area.value = area.value.substr(0, point)
                + text.trim()
                + area.value.substr(point);
            // set area.selectionStart point
            CustomColumnEvent.formulaInputselection = afterPoint;
        }
        static GetSettingValText() {
            let formula = $('#calc_formula_input').val();
            // replace ${XXX} string as column name
            formula = formula.replace(/\$\{.+?\}/g, function (match) {
                let $target = $('.col-target-block-column button[data-val="' + match + '"]');
                if (!hasValue($target)) {
                    return match;
                }
                return $target.data('displayText');
            });
            return { value: $('#calc_formula_input').val(), text: formula };
        }
        static inputFormulaEvent(e) {
            if (e && e.key == 'Enter') {
                return false;
            }
            $('.modal .modal-submit').prop('disabled', true);
            $('.modal #validateResult > span').hide();
        }
        static focusFormulaEvent(e) {
            CustomColumnEvent.formulaInputselection = null;
        }
        static validateFormula() {
            let result = true;
            let formula = $('#calc_formula_input').val();
            if (!hasValue(formula)) {
                result = false;
            }
            else {
                // replace ${XXX} string as 1
                let replaceFormula = formula.replace(/\$\{.+?\}/g, function (match) {
                    // find key value
                    let $target = $('.col-target-block-column button[data-val="' + match + '"]');
                    if (!hasValue($target)) {
                        result = false;
                    }
                    return '1';
                });
                if (result) {
                    result = Exment.CalcEvent.validateMathFormula(replaceFormula);
                }
                // if match \$\{.+?\} \$\{.+?\}, return false
                if (formula.match(/\$\{.+?\} *\$\{.+?\}/g)) {
                    result = false;
                }
            }
            $('.modal .modal-submit').prop('disabled', !result);
            $('.modal #validateResult > span').hide();
            if (result) {
                $('.modal #validateResultSuccess').show();
            }
            else {
                $('.modal #validateResultError').show();
            }
        }
    }
    /**
     * Add calc event
     * @param ev
     */
    CustomColumnEvent.calcButtonAddItemEvent = (ev) => {
        let target = $(ev.target).closest('.button-addcalcitem');
        CustomColumnEvent.setCalcInput(target.data('val').trim());
        $('#calc_formula_input').trigger('keydown.exment_custom_column');
    };
    Exment.CustomColumnEvent = CustomColumnEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomColumnEvent.AddEvent();
    Exment.CustomColumnEvent.AddEventOnce();
});
