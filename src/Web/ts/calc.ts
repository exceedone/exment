namespace Exment {
    /**
     * Execute culc event.
     * 
     * $formulaBox : Getting calc result box.
     * $triggerBox : Called event field box.
     * $targetBox : Set calc result field.
     */
    export class CalcEvent {
        private static calcDataList = [];
        private static loopcount : number = 0;
        private static showLoopError : boolean = false;

        /**
         * Set calc event
         */
        public static setCalcEvent = (datalist, columns = []) => {
            CalcEvent.calcDataList = datalist;

            // reset calc event
            $(document).off('change.exment_calc').off('click.exment_calc_plusminus');

            // get block_name
            for(let block_name in datalist){
                let blockData = CalcEvent.calcDataList[block_name];
                if(!hasValue(blockData)){
                    continue;
                }
    
                // set calc event
                for(let calc_formula_key in blockData.calc_formulas){
                    let calc_formula = blockData.calc_formulas[calc_formula_key];

                    let $triggerBox = CommonEvent.getBlockElement(calc_formula.trigger_block);
                    $triggerBox.on('change.exment_calc', CommonEvent.getClassKey(calc_formula.trigger_column), { calc_formula: calc_formula }, async (ev) => {
                        if(ev.originalEvent && (ev.originalEvent as any).isTrusted){
                            CalcEvent.resetLoopConnt();
                        }
                        await CalcEvent.setCalc(ev.data.calc_formula, $(ev.target), columns);
                    });

                    // set event for plus minus button
                    $triggerBox.on('click.exment_calc_plusminus', '.btn-number-plus,.btn-number-minus', { data: blockData, calc_formula: calc_formula }, async (ev) => {
                        // call only has $target. $target is autocalc's key
                        let $target = $(ev.target).closest('.input-group').find(CommonEvent.getClassKey(ev.data.data.trigger_column));
                        if(!hasValue($target)){
                            return;
                        }
                        await $target.trigger('change.exment_calc');
                    });
                    
                    // set event for row add remove event to child block if type is parent
                    if(calc_formula.type == 'parent'){
                        let $targetBoxChild = CommonEvent.getBlockElement(calc_formula.target_block);
                        $targetBoxChild.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { data: blockData, calc_formula: calc_formula }, async (ev) => {
                            await CalcEvent.setCalc(ev.data.calc_formula, $(ev.target), columns);
                        });
                    }
                    // set event for row add remove event to child block if type is summary
                    if(calc_formula.type == 'sum' || calc_formula.type == 'summary'){
                        let $triggerBoxChild = CommonEvent.getBlockElement(calc_formula.trigger_block);
                        $triggerBoxChild.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { data: blockData, calc_formula: calc_formula }, async (ev) => {
                            await CalcEvent.setCalc(ev.data.calc_formula, $(ev.target), columns);
                        });
                    }
                }
    
                // count event
                for(let child_relation_key in blockData.calc_counts){
                    let calc_count = blockData.calc_counts[child_relation_key];
                    let box = GetBox.make();
                    let $childbox = box.getBox().find('.hasmanyblock-' + calc_count.child_relation_name);
                    
                    // add laravel-admin row plusminus event
                    $childbox.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { calc_count: calc_count }, async (ev) => {
                        await CalcEvent.setCalc(ev.data.calc_count, $(ev.target), columns);
                    });
                }
            }
        }
        

        /**
         * set calc 
         * data : has "to" and "options". options has properties "val" and "type"
         * 
         */
        private static async setCalc(calc_formula, $trigger: JQuery<HTMLElement> = null, columns = []) {
            if (!hasValue(calc_formula)) {
                return;
            }
            if(CalcEvent.loopcount > 100){
                if(!CalcEvent.showLoopError){
                    alert('calc loop count is over 100. Please check calc setting.');
                    CalcEvent.showLoopError = true;
                }
                throw 'calc loop count is over 100. Please check calc setting.';
            }
            CalcEvent.loopcount++;
            // console.log("---------------------------");

            let $targetBoxs = CommonEvent.getBlockElement(calc_formula.target_block);

            // get to list. if 1:n form and target is n, $tos is multiple.
            let $tos = CalcEvent.getTargetFields($trigger, $targetBoxs, calc_formula);

            let force_caculate = false;
            // loop for calc target.
            for(let j = 0; j < $tos.length; j++){
                let $to = $tos.eq(j);
                if (columns[calc_formula.target_block]) {
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
                    if(!hasValue(formula_string)){
                        continue;
                    }
    
                    // console.log("loopcount : " + CalcEvent.loopcount + ", target_block : " + calc_formula.target_block);
                    // console.log("loopcount : " + CalcEvent.loopcount + ", target_column : " + calc_formula.target_column);
                    // console.log($to);

                    // get options 
                    let options = formula.params;
                    let $targetBox = CalcEvent.getBlockByField($to, $targetBoxs);
                    let precision = await CalcEvent.executeCalc(formula_string, options, $targetBox, force_caculate);
                    CommonEvent.setValue($to, precision);
                }
            }
        }


        /**
         * Execute calc
         * @param formula_string formula string. Replace number etc.
         * @param params calc parameter
         * @param $targetBox triggered box
         */
        public static async executeCalc(formula_string, params, $targetBox: JQuery<HTMLElement>, force_caculate = false) : Promise<any>{
            // console.log("loopcount : " + CalcEvent.loopcount + ", formula_string : " + formula_string);

            let notCalc = false;
            for (let j = 0; j < params.length; j++) {
                let val: any = 0;
                // calc option
                let param = params[j];
                
                // console.log("loopcount : " + CalcEvent.loopcount + ", j : " + j + ", formula_column : " + param.formula_column + ", type : " + param.type);

                // when dynamic value, get value
                if (param.type == 'dynamic') {
                    val = rmcomma($targetBox.find(CommonEvent.getClassKey(param.formula_column)).val());
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
                    let box = GetBox.make();
                    box.getBox().find('.has-many-' + param.child_relation_name + '-form:visible, .has-many-table-' + param.child_relation_name + '-row:visible')
                        .find(CommonEvent.getClassKey(param.formula_column))
                        .each(function(){
                            if (hasValue($(this).val())) {
                                sum_count += pFloat($(this).val());
                            }
                    });
                    val = sum_count;
                }
                // when count value, get count
                else if (param.type == 'count') {
                    let box = GetBox.make();
                    val = box.getBox().find('.has-many-' + param.child_relation_name + '-form:visible, .has-many-table-' + param.child_relation_name + '-row:visible').length;
                    if (!hasValue(val)) {
                        val = 0;
                    }
                }
                // when select_table value, get value from table
                else if (param.type == 'select_table') {
                    // find select target table
                    let $select = $targetBox.find(CommonEvent.getClassKey(param.select_pivot_column));
                    let table_name = $select.data('target_table_name');
                    // get selected table model
                    let model = await CommonEvent.findModel(table_name, $select.val());
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
                    } else {
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
                    if(hasValue($select)){
                        let table_name = $select.data('target_table_name');
                        // get selected table model
                        let model = await CommonEvent.findModel(table_name, $select.val());
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
                        } else {
                            if (force_caculate == true) {
                                val = 0;
                            } else {
                                notCalc = true;
                                break;
                            }
                        }
                    }
                    // if not parent id, almost 1:n form, so get parent form
                    else{
                        let $parentBox = CommonEvent.getBlockElement('');
                        val = rmcomma($parentBox.find(CommonEvent.getClassKey(param.formula_column)).val());
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

            if(notCalc){
                return null;
            }

            let result = math.evaluate(formula_string);
            if(result === Infinity || result === -Infinity || result === NaN){
                return null;
            }

            return result;
        }


        /**
         * Get form block erea by event element. (hasmany or default form)
         * @param $target event called target
         */
        private static getBlockByField($target : JQuery<HTMLElement>, $parent : JQuery<HTMLElement>) : JQuery<HTMLElement>{
            // if has has-many-table-row or has-many-form, get parent 
            let $closest = $target.closest('.has-many-table-row,.has-many-form');
            if(hasValue($closest)){
                return $closest;
            }

            if(hasValue($parent)){
                return $parent;
            }
            return CommonEvent.getDefaultBox();
        }


        /**
         * Get target field. 
         * (1) calc_formula is summary or count, return parent.
         * (2) form is 1:n and trigger is n, return closest child.
         * (3) form is 1:n and trigger is 1, return children item.
         * (4) Otherwise, return 1.
         */
        private static getTargetFields($trigger : JQuery<HTMLElement>, $targetBox : JQuery<HTMLElement>, calc_formula) : JQuery<HTMLElement>{
            if(calc_formula.type == 'sum' || calc_formula.type == 'summary' || calc_formula.type == 'count'){
                return CommonEvent.getDefaultBox().find(CommonEvent.getClassKey(calc_formula.target_column));
            }
            
            // if has has-many-table-row or has-many-form, only return child to 
            let $closest = $trigger.closest('.has-many-table-row,.has-many-form');
            if(hasValue($closest)){
                return $closest.find(CommonEvent.getClassKey(calc_formula.target_column));;
            }

            // get to list. if 1:n form and target is n and trigger is 1, $tos is multiple.
            return $targetBox.find(CommonEvent.getClassKey(calc_formula.target_column));
        }


        /**
         * validate formula string
         * @param formula 
         */
        public static validateMathFormula(formula) : boolean {
            try{
                if(!hasValue(formula)){
                    return false;
                }

                let result = math.evaluate(formula);
                return true;
            }catch(e){
                return false;
            }
        }


        public static resetLoopConnt(){
            CalcEvent.loopcount = 0;
        }

    }
}
