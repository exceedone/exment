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
    var _a;
    /**
     * Execute culc event.
     *
     * $formulaBox : Getting calc result box.
     * $triggerBox : Called event field box.
     * $targetBox : Set calc result field.
     */
    class CalcEvent {
        /**
         * set calc
         * data : has "to" and "options". options has properties "val" and "type"
         *
         */
        static setCalc(calc_formula, $trigger = null, columns = []) {
            return __awaiter(this, void 0, void 0, function* () {
                if (!hasValue(calc_formula)) {
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
                // console.log("---------------------------");
                let $targetBoxs = Exment.CommonEvent.getBlockElement(calc_formula.target_block);
                // get to list. if 1:n form and target is n, $tos is multiple.
                let $tos = CalcEvent.getTargetFields($trigger, $targetBoxs, calc_formula);

                let force_caculate = false;
                // loop for calc target.
                for (let j = 0; j < $tos.length; j++) {
                    let $to = $tos.eq(j);
                    if (calc_formula.target_block) {                        
                        for (let column of columns[calc_formula.target_block]) {
                            if(column == $to[0].id) {
                                force_caculate = true;
                            }
                        }
                    }
                    for (let i = 0; i < calc_formula.formulas.length; i++) {
                        let formula = calc_formula.formulas[i];
                        // for creating array contains object "value0" and "calc_type" and "value1".
                        let formula_string = formula.formula_string;
                        if (!hasValue(formula_string)) {
                            continue;
                        }
                        // console.log("loopcount : " + CalcEvent.loopcount + ", target_block : " + calc_formula.target_block);
                        // console.log("loopcount : " + CalcEvent.loopcount + ", target_column : " + calc_formula.target_column);
                        // console.log($to);
                        // get options 
                        let options = formula.params;
                        let $targetBox = CalcEvent.getBlockByField($to, $targetBoxs);
                        let precision = yield CalcEvent.executeCalc(formula_string, options, $targetBox, force_caculate);
                        Exment.CommonEvent.setValue($to, precision);
                    }
                }
            });
        }
        /**
         * Execute calc
         * @param formula_string formula string. Replace number etc.
         * @param params calc parameter
         * @param $targetBox triggered box
         */
        static executeCalc(formula_string, params, $targetBox, force_caculate = false) {
            return __awaiter(this, void 0, void 0, function* () {
                // console.log("loopcount : " + CalcEvent.loopcount + ", formula_string : " + formula_string);
                let notCalc = false;
                for (let j = 0; j < params.length; j++) {
                    let val = 0;
                    // calc option
                    let param = params[j];
                    // console.log("loopcount : " + CalcEvent.loopcount + ", j : " + j + ", formula_column : " + param.formula_column + ", type : " + param.type);
                    // when dynamic value, get value
                    if (param.type == 'dynamic') {
                        val = rmcomma($targetBox.find(Exment.CommonEvent.getClassKey(param.formula_column)).val());
                        if (!hasValue(val)) {
                            if (force_caculate == true) {
                                val = 0;
                            } else {
                                notCalc = true;
                                break;
                            }
                        }
                    }
                    // when summary value, get value
                    else if (param.type == 'summary' || param.type == 'sum') {
                        let sum_count = 0;
                        let box = Exment.GetBox.make();
                        box.getBox().find('.has-many-' + param.child_relation_name + '-form:visible, .has-many-table-' + param.child_relation_name + '-row:visible')
                            .find(Exment.CommonEvent.getClassKey(param.formula_column))
                            .each(function () {
                            if (hasValue($(this).val())) {
                                sum_count += pFloat($(this).val());
                            }
                        });
                        val = sum_count;
                    }
                    // when count value, get count
                    else if (param.type == 'count') {
                        let box = Exment.GetBox.make();
                        val = box.getBox().find('.has-many-' + param.child_relation_name + '-form:visible, .has-many-table-' + param.child_relation_name + '-row:visible').length;
                        if (!hasValue(val)) {
                            val = 0;
                        }
                    }
                    // when select_table value, get value from table
                    else if (param.type == 'select_table') {
                        // find select target table
                        let $select = $targetBox.find(Exment.CommonEvent.getClassKey(param.select_pivot_column));
                        let table_name = $select.data('target_table_name');
                        // get selected table model
                        let model = yield Exment.CommonEvent.findModel(table_name, $select.val());
                        // get value
                        if (hasValue(model)) {
                            val = model['value'][param.formula_column];
                            if (!hasValue(val)) {
                                if (force_caculate == true) {
                                    val = 0;
                                } else {
                                    notCalc = true;
                                    break;
                                }
                            }
                        }
                        else {
                            if (force_caculate == true) {
                                val = 0;
                            } else {
                                notCalc = true;
                                break;
                            }
                        }
                    }
                    // when parent value, get value from parent_id or parent form
                    else if (param.type == 'parent') {
                        // find parent target table
                        let $select = $('.parent_id[data-parent_id]');
                        // if has $select, this default form, so call CommonEvent.findModel
                        if (hasValue($select)) {
                            let table_name = $select.data('target_table_name');
                            // get selected table model
                            let model = yield Exment.CommonEvent.findModel(table_name, $select.val());
                            // get value
                            if (hasValue(model)) {
                                val = model['value'][param.formula_column];
                                if (!hasValue(val)) {
                                    if (force_caculate == true) {
                                        val = 0;
                                    } else {
                                        notCalc = true;
                                        break;
                                    }
                                }
                            }
                            else {
                                if (force_caculate == true) {
                                    val = 0;
                                } else {
                                    notCalc = true;
                                    break;
                                }
                            }
                        }
                        // if not parent id, almost 1:n form, so get parent form
                        else {
                            let $parentBox = Exment.CommonEvent.getBlockElement('');
                            val = rmcomma($parentBox.find(Exment.CommonEvent.getClassKey(param.formula_column)).val());
                            if (!hasValue(val)) {
                                if (force_caculate == true) {
                                    val = 0;
                                } else {
                                    notCalc = true;
                                    break;
                                }
                            }
                        }
                    }
                    // replace value
                    formula_string = formula_string.replace(param.key, val);
                }
                if (notCalc) {
                    return null;
                }
                let result = math.evaluate(formula_string);
                if (result === Infinity || result === -Infinity || result === NaN) {
                    return null;
                }
                return result;
            });
        }
        /**
         * Get form block erea by event element. (hasmany or default form)
         * @param $target event called target
         */
        static getBlockByField($target, $parent) {
            // if has has-many-table-row or has-many-form, get parent 
            let $closest = $target.closest('.has-many-table-row,.has-many-form');
            if (hasValue($closest)) {
                return $closest;
            }
            if (hasValue($parent)) {
                return $parent;
            }
            return Exment.CommonEvent.getDefaultBox();
        }
        /**
         * Get target field.
         * (1) calc_formula is summary or count, return parent.
         * (2) form is 1:n and trigger is n, return closest child.
         * (3) form is 1:n and trigger is 1, return children item.
         * (4) Otherwise, return 1.
         */
        static getTargetFields($trigger, $targetBox, calc_formula) {
            if (calc_formula.type == 'sum' || calc_formula.type == 'summary' || calc_formula.type == 'count') {
                return Exment.CommonEvent.getDefaultBox().find(Exment.CommonEvent.getClassKey(calc_formula.target_column));
            }
            // if has has-many-table-row or has-many-form, only return child to 
            let $closest = $trigger.closest('.has-many-table-row,.has-many-form');
            if (hasValue($closest)) {
                return $closest.find(Exment.CommonEvent.getClassKey(calc_formula.target_column));
                ;
            }
            // get to list. if 1:n form and target is n and trigger is 1, $tos is multiple.
            return $targetBox.find(Exment.CommonEvent.getClassKey(calc_formula.target_column));
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
                return false;
            }
        }
        static resetLoopConnt() {
            CalcEvent.loopcount = 0;
        }
    }
    _a = CalcEvent;
    CalcEvent.calcDataList = [];
    CalcEvent.loopcount = 0;
    CalcEvent.showLoopError = false;
    /**
     * Set calc event
     */
    CalcEvent.setCalcEvent = (datalist, columns = []) => {
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
                let $triggerBox = Exment.CommonEvent.getBlockElement(calc_formula.trigger_block);
                $triggerBox.on('change.exment_calc', Exment.CommonEvent.getClassKey(calc_formula.trigger_column), { calc_formula: calc_formula }, (ev) => __awaiter(_a, void 0, void 0, function* () {
                    if (ev.originalEvent && ev.originalEvent.isTrusted) {
                        CalcEvent.resetLoopConnt();
                    }
                    yield CalcEvent.setCalc(ev.data.calc_formula, $(ev.target), columns);
                }));
                // set event for plus minus button
                $triggerBox.on('click.exment_calc_plusminus', '.btn-number-plus,.btn-number-minus', { data: blockData, calc_formula: calc_formula }, (ev) => __awaiter(_a, void 0, void 0, function* () {
                    // call only has $target. $target is autocalc's key
                    let $target = $(ev.target).closest('.input-group').find(Exment.CommonEvent.getClassKey(ev.data.data.trigger_column));
                    if (!hasValue($target)) {
                        return;
                    }
                    yield $target.trigger('change.exment_calc');
                }));
                // set event for row add remove event to child block if type is parent
                if (calc_formula.type == 'parent') {
                    let $targetBoxChild = Exment.CommonEvent.getBlockElement(calc_formula.target_block);
                    $targetBoxChild.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { data: blockData, calc_formula: calc_formula }, (ev) => __awaiter(_a, void 0, void 0, function* () {
                        yield CalcEvent.setCalc(ev.data.calc_formula, $(ev.target), columns);
                    }));
                }
                // set event for row add remove event to child block if type is summary
                if (calc_formula.type == 'sum' || calc_formula.type == 'summary') {
                    let $triggerBoxChild = Exment.CommonEvent.getBlockElement(calc_formula.trigger_block);
                    $triggerBoxChild.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { data: blockData, calc_formula: calc_formula }, (ev) => __awaiter(_a, void 0, void 0, function* () {
                        yield CalcEvent.setCalc(ev.data.calc_formula, $(ev.target), columns);
                    }));
                }
            }
            // count event
            for (let child_relation_key in blockData.calc_counts) {
                let calc_count = blockData.calc_counts[child_relation_key];
                let box = Exment.GetBox.make();
                let $childbox = box.getBox().find('.hasmanyblock-' + calc_count.child_relation_name);
                // add laravel-admin row plusminus event
                $childbox.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { calc_count: calc_count }, (ev) => __awaiter(_a, void 0, void 0, function* () {
                    yield CalcEvent.setCalc(ev.data.calc_count, $(ev.target), columns);
                }));
            }
        }
    };
    Exment.CalcEvent = CalcEvent;
})(Exment || (Exment = {}));
