var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
var Exment;
(function (Exment) {
    class CalcEvent {
        /**
         * set calc
         * data : has "to" and "options". options has properties "val" and "type"
         *
         */
        static setCalc($formulaBox, calc_formulas, $targetBox = null) {
            return __awaiter(this, void 0, void 0, function* () {
                if (!hasValue(calc_formulas)) {
                    return;
                }
                if (CalcEvent.loopcount > 100) {
                    if (!CalcEvent.showLoopError) {
                        alert('calc loop count is over 100. Please check calc setting.');
                        CalcEvent.showLoopError = true;
                    }
                    throw 'calc loop count is over 100. Please check calc setting.';
                }
                CalcEvent.loopcount++;
                if (!hasValue($targetBox)) {
                    $targetBox = $formulaBox;
                }
                // loop for calc target.
                for (let i = 0; i < calc_formulas.formulas.length; i++) {
                    let calc_formula = calc_formulas.formulas[i];
                    let notCalc = false;
                    // for creating array contains object "value0" and "calc_type" and "value1".
                    let formula_string = calc_formula.formula_string;
                    if (!hasValue(formula_string)) {
                        continue;
                    }
                    let $to = $targetBox.find(Exment.CommonEvent.getClassKey(calc_formula.target_column));
                    // get options 
                    let options = calc_formula.params;
                    for (let j = 0; j < options.length; j++) {
                        let val = 0;
                        // calc option
                        let option = options[j];
                        // when dynamic value, get value
                        if (option.type == 'dynamic') {
                            val = rmcomma($formulaBox.find(Exment.CommonEvent.getClassKey(option.formula_column)).val());
                            if (!hasValue(val)) {
                                notCalc = true;
                                break;
                            }
                        }
                        // when summary value, get value
                        else if (option.type == 'summary' || option.type == 'sum') {
                            let sum_count = 0;
                            $('.box-body').find('.has-many-' + option.child_relation_name + '-form:visible, .has-many-table-' + option.child_relation_name + '-row:visible')
                                .find(Exment.CommonEvent.getClassKey(option.formula_column))
                                .each(function () {
                                if (hasValue($(this).val())) {
                                    sum_count += pInt($(this).val());
                                }
                            });
                            val = sum_count;
                        }
                        // when count value, get count
                        else if (option.type == 'count') {
                            val = $('.box-body').find('.has-many-' + option.child_relation_name + '-form:visible, .has-many-table-' + option.child_relation_name + '-row:visible').length;
                            if (!hasValue(val)) {
                                val = 0;
                            }
                        }
                        // when select_table value, get value from table
                        else if (option.type == 'select_table') {
                            // find select target table
                            var $select = $formulaBox.find(Exment.CommonEvent.getClassKey(option.formula_column));
                            var table_name = $select.data('target_table_name');
                            // get selected table model
                            var model = yield Exment.CommonEvent.findModel(table_name, $select.val());
                            // get value
                            if (hasValue(model)) {
                                val = model['value'][option.pivot_column];
                                if (!hasValue(val)) {
                                    notCalc = true;
                                    break;
                                }
                            }
                            else {
                                notCalc = true;
                                break;
                            }
                        }
                        // replace value
                        formula_string = formula_string.replace(option.key, val);
                    }
                    if (notCalc || !CalcEvent.validateMathFormula(formula_string)) {
                        Exment.CommonEvent.setValue($to, null);
                        continue;
                    }
                    let precision = math.evaluate(formula_string);
                    Exment.CommonEvent.setValue($to, precision);
                }
            });
        }
        /**
         * Get form block erea. (hasmany or default form)
         * @param block_name block name
         */
        static getBlockElement(block_name) {
            return !hasValue(block_name) || block_name == 'default' ?
                $('.box-body >.fields-group > .embed-value') :
                $('.box-body .hasmanyblock-' + block_name);
        }
        /**
         * Get block name by element
         * @param $elem
         */
        static getblock_name($elem) {
            let $hasmany = $elem.closest('.has-many-table,.has-many');
            // not hasmany, return empty
            if (!hasValue($hasmany)) {
                return '';
            }
            let classList = $hasmany.get(0).classList;
            for (let key in classList) {
                let c = classList[key];
                let found = c.match('/^hasmanyblock-/g');
                if (found) {
                    return c.replace('hasmanyblock-', '');
                }
            }
            return '';
        }
        /**
         * validate formula string
         * @param formula
         */
        static validateMathFormula(formula) {
            try {
                if (!hasValue(formula)) {
                    return false;
                }
                let result = math.evaluate(formula);
                return true;
            }
            catch (e) {
                if (e instanceof SyntaxError) {
                    return false;
                }
                throw e;
            }
        }
    }
    CalcEvent.calcDataList = [];
    CalcEvent.loopcount = 0;
    CalcEvent.showLoopError = false;
    /**
     * Set calc event
     */
    CalcEvent.setCalcEvent = (datalist) => {
        CalcEvent.calcDataList = datalist;
        // reset calc event
        $(document).off('change.exment_calc').off('click.exment_calc_plusminus');
        // get block_name
        for (let block_name in datalist) {
            let blockData = CalcEvent.calcDataList[block_name];
            if (!hasValue(blockData)) {
                continue;
            }
            // set calc event
            for (let calc_formula_key in blockData.calc_formulas) {
                let calc_formula = blockData.calc_formulas[calc_formula_key];
                // target box is sets result calc.
                let $box = CalcEvent.getBlockElement(block_name);
                // formula box is triggered calc.
                let $formulaBox = CalcEvent.getBlockElement(calc_formula.block_key);
                // data change event
                $formulaBox.on('change.exment_calc', Exment.CommonEvent.getClassKey(calc_formula.formula_column), { data: blockData, calc_formula: calc_formula, box: $box }, (ev) => __awaiter(this, void 0, void 0, function* () {
                    if (ev.originalEvent && ev.originalEvent.isTrusted) {
                        CalcEvent.loopcount = 0;
                    }
                    yield CalcEvent.setCalc($formulaBox, ev.data.calc_formula, ev.data.box);
                }));
                // set event for plus minus button
                $box.on('click.exment_calc_plusminus', '.btn-number-plus,.btn-number-minus', { data: blockData, calc_formula: calc_formula }, (ev) => __awaiter(this, void 0, void 0, function* () {
                    // call only has $target. $target is autocalc's key
                    let $target = $(ev.target).closest('.input-group').find(Exment.CommonEvent.getClassKey(ev.data.data.formula_column));
                    if (!hasValue($target)) {
                        return;
                    }
                    yield $target.trigger('change.exment_calc');
                }));
            }
            // calc event
            for (let child_relation_name in blockData.calc_counts) {
                let $box = CalcEvent.getBlockElement(block_name);
                let calc_count = blockData.calc_counts[child_relation_name];
                let $childbox = $('.box-body').find('.hasmanyblock-' + child_relation_name);
                // add laravel-admin row plusminus event
                $childbox.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { calc_count: calc_count }, (ev) => __awaiter(this, void 0, void 0, function* () {
                    yield CalcEvent.setCalc($box, ev.data.calc_count);
                }));
            }
        }
    };
    Exment.CalcEvent = CalcEvent;
})(Exment || (Exment = {}));
