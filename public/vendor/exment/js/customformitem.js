var Exment;
(function (Exment) {
    class CustomFromItem {
        /**
         * Initialize by hidden element
         * @param $elem
         */
        static makeByHidden($elem) {
            let result = new CustomFromItem();
            //TODO: how no set best way
            result.form_block_type = $elem.find('.form_block_type').val();
            result.form_block_target_table_id = $elem.find('.form_block_target_table_id').val();
            result.form_column_type = $elem.find('.form_column_type').val();
            result.column_no = $elem.find('.column_no').val();
            result.required = $elem.find('.required').val();
            result.form_column_target_id = $elem.find('.form_column_target_id').val();
            result.header_column_name = $elem.find('.header_column_name').val();
            result.read_only = $elem.find('.read_only').val();
            result.view_only = $elem.find('.view_only').val();
            result.hidden = $elem.find('.hidden').val();
            result.changedata_target_column_id = $elem.find('.changedata_target_column_id').val();
            result.changedata_column_id = $elem.find('.changedata_column_id').val();
            result.relation_filter_target_column_id = $elem.find('.relation_filter_target_column_id').val();
            return result;
        }
        /**
         * Show setting modal
         */
        showSettingModal($target) {
            // TODO: best way
            const formData = {
                form_block_type: this.form_block_type,
                form_block_target_table_id: this.form_block_target_table_id,
                form_column_type: this.form_column_type,
                column_no: this.column_no,
                required: this.required,
                form_column_target_id: this.form_column_target_id,
                header_column_name: this.header_column_name,
                read_only: this.read_only,
                view_only: this.view_only,
                hidden: this.hidden,
                changedata_target_column_id: this.changedata_target_column_id,
                changedata_column_id: this.changedata_column_id,
                relation_filter_target_column_id: this.relation_filter_target_column_id,
            };
            Exment.ModalEvent.ShowModal($target, URLJoin($('#formroot').val(), 'settingModal'), formData);
        }
    }
    Exment.CustomFromItem = CustomFromItem;
})(Exment || (Exment = {}));
