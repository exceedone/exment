namespace Exment {
    export class CalcEvent {
        private static calcDataList = [];
        private static loopcount : number = 0;
        private static showLoopError : boolean = false;

        /**
         * Set calc event
         */
        public static setCalcEvent = (datalist) => {
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

                    // target box is sets result calc.
                    let $box = CalcEvent.getBlockElement(block_name);
                    // formula box is triggered calc.
                    let $formulaBox = CalcEvent.getBlockElement(calc_formula.trigger_block);
    
                    // data change event
                    $formulaBox.on('change.exment_calc', CommonEvent.getClassKey(calc_formula.trigger_column), { data: blockData, calc_formula: calc_formula, box:$box }, async (ev) => {
                        if(ev.originalEvent && (ev.originalEvent as any).isTrusted){
                            CalcEvent.loopcount = 0;
                        }
                        await CalcEvent.setCalc($formulaBox, ev.data.calc_formula, $(ev.target), ev.data.box);
                    });

                    // set event for plus minus button
                    $box.on('click.exment_calc_plusminus', '.btn-number-plus,.btn-number-minus', { data: blockData, calc_formula: calc_formula }, async (ev) => {
                        // call only has $target. $target is autocalc's key
                        let $target = $(ev.target).closest('.input-group').find(CommonEvent.getClassKey(ev.data.data.trigger_column));
                        if(!hasValue($target)){
                            return;
                        }
                        await $target.trigger('change.exment_calc');
                    });
                }
    
                // count event
                for(let child_relation_name in blockData.calc_counts){
                    let $box = CalcEvent.getBlockElement(block_name);
                    let calc_count = blockData.calc_counts[child_relation_name];
                    let $childbox = $('.box-body').find('.hasmanyblock-' + child_relation_name);
                    
                    // add laravel-admin row plusminus event
                    $childbox.on('admin_hasmany_row_change', '.add.btn, .remove.btn', { calc_count: calc_count }, async (ev) => {
                        await CalcEvent.setCalc($box, ev.data.calc_count, $(ev.target));
                    });
                }
            }
        }
        

        /**
         * set calc 
         * data : has "to" and "options". options has properties "val" and "type"
         * 
         */
        private static async setCalc($formulaBox: JQuery<Element>, calc_formulas, $target: JQuery<Element> = null, $targetBox: JQuery<Element> = null) {
            if (!hasValue(calc_formulas)) {
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

            $formulaBox = CalcEvent.getBlockByField($target);

            if(!hasValue($targetBox)){
                $targetBox = $formulaBox;
            }

            // loop for calc target.
            for (let i = 0; i < calc_formulas.formulas.length; i++) {
                let calc_formula = calc_formulas.formulas[i];
                let notCalc = false;
                // for creating array contains object "value0" and "calc_type" and "value1".
                let formula_string = calc_formula.formula_string;
                if(!hasValue(formula_string)){
                    continue;
                }

                let $to = $targetBox.find(CommonEvent.getClassKey(calc_formula.target_column));

                // get options 
                let options = calc_formula.params;
                let precision = await CalcEvent.executeCalc(formula_string, options, $formulaBox);
                CommonEvent.setValue($to, precision);
            }
        }


        public static async executeCalc(formula_string, options, $formulaBox) : Promise<any>{
            let notCalc = false;
            for (let j = 0; j < options.length; j++) {
                let val: any = 0;
                // calc option
                let option = options[j];
                
                // when dynamic value, get value
                if (option.type == 'dynamic') {
                    val = rmcomma($formulaBox.find(CommonEvent.getClassKey(option.formula_column)).val());
                    if (!hasValue(val)) { 
                        notCalc = true;
                        break;
                    }
                }
                // when summary value, get value
                else if (option.type == 'summary' || option.type == 'sum') {
                    let sum_count = 0;
                    $('.box-body').find('.has-many-' + option.child_relation_name + '-form:visible, .has-many-table-' + option.child_relation_name + '-row:visible')
                        .find(CommonEvent.getClassKey(option.formula_column))
                        .each(function(){
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
                    let $select = $formulaBox.find(CommonEvent.getClassKey(option.select_pivot_column));
                    let table_name = $select.data('target_table_name');
                    // get selected table model
                    let model = await CommonEvent.findModel(table_name, $select.val());
                    // get value
                    if (hasValue(model)) {
                        val = model['value'][option.formula_column];
                        if (!hasValue(val)) { 
                            notCalc = true;
                            break;
                        }
                    }else{
                        notCalc = true;
                        break;
                    }
                }
                // when parent value, get value from parent_id or parent form
                else if (option.type == 'parent') {
                    // find parent target table
                    let $select = $formulaBox.find('.parent_id');
                    // if has $select, this default form, so call CommonEvent.findModel
                    if(hasValue($select)){
                        let table_name = $select.data('target_table_name');
                        // get selected table model
                        let model = await CommonEvent.findModel(table_name, $select.val());
                        // get value
                        if (hasValue(model)) {
                            val = model['value'][option.formula_column];
                            if (!hasValue(val)) { 
                                notCalc = true;
                                break;
                            }
                        }else{
                            notCalc = true;
                            break;
                        }
                    }
                    // if not parent id, almost 1:n form, so get parent form
                    else{
                        let $parentBox = CalcEvent.getBlockElement('');
                        val = rmcomma($parentBox.find(CommonEvent.getClassKey(option.formula_column)).val());
                        if (!hasValue(val)) { 
                            notCalc = true;
                            break;
                        }
                    }
                }

                // replace value
                formula_string = formula_string.replace(option.key, val);
            }

            if(notCalc){
                return null;
            }

            return math.evaluate(formula_string);
        }


        /**
         * Get form block erea. (hasmany or default form)
         * @param block_name block name
         */
        private static getBlockElement(block_name) : JQuery<HTMLElement>{
            if(!hasValue(block_name) || block_name == 'default'){
                return $('.box-body >.fields-group > .embed-value');
            }
            if(block_name == 'parent_id'){
                return $('.box-body .parent_id').closest('.form-group');
            }
            return $('.box-body .hasmanyblock-' + block_name);
        }

        /**
         * Get form block erea by event element. (hasmany or default form)
         * @param $target event called target
         */
        private static getBlockByField($target : JQuery<HTMLElement>) : JQuery<HTMLElement>{
            // if has has-many-table-row or has-many-form, get parent 
            let $parent = $target.closest('.has-many-table-row,.has-many-form');
            if(hasValue($parent)){
                return $parent;
            }
            return $('.box-body >.fields-group > .embed-value');
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
                if(e instanceof SyntaxError){
                    return false;
                }
                throw e;
            }
        }
    }
}
