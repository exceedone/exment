
namespace Exment {
    export class CustomFromItem {
        ///// Now this accessible is public.
        public form_block_type;
        public form_block_target_table_id;
        
        public form_column_type;
        public column_no;
        public form_column_target_id;
        public header_column_name;

        public form_column_view_name;
        public read_only;
        public view_only;
        public hidden;
        public required;

        public changedata_target_column_id;
        public changedata_column_id;
        public relation_filter_target_column_id;


        /**
         * Initialize by hidden element 
         * @param $elem 
         */
        public static makeByHidden($elem: JQuery<Element>) : CustomFromItem
        {
            let result = new CustomFromItem();

            //TODO: how no set best way
            result.form_block_type = $elem.find('.form_block_type').val();
            result.form_block_target_table_id = $elem.find('.form_block_target_table_id').val();

            result.form_column_type = $elem.find('.form_column_type').val();
            result.column_no = $elem.find('.column_no').val();
            result.form_column_target_id = $elem.find('.form_column_target_id').val();
            result.header_column_name = $elem.find('.header_column_name').val();
            
            result.form_column_view_name = $elem.find('.form_column_view_name').val();
            result.required = $elem.find('.required').val();
            result.read_only = $elem.find('.read_only').val();
            result.view_only = $elem.find('.view_only').val();
            result.hidden = $elem.find('.hidden').val();
            result.changedata_target_column_id = $elem.find('.changedata_target_column_id').val();
            result.changedata_column_id = $elem.find('.changedata_column_id').val();
            result.relation_filter_target_column_id = $elem.find('.relation_filter_target_column_id').val();

            return result;
        }

        /**
         * Initialize by modal window.
         * *For only set option value.*
         */
        public static makeByModal() : CustomFromItem
        {
            let result = new CustomFromItem();

            let $modal = $('#modal-showmodal');

            //TODO: how no set best way
            result.form_column_view_name = $modal.find('.form_column_view_name').val();
            result.required = $modal.find('.required').val();
            result.read_only = $modal.find('.field_showing_type:checked').val() == 'read_only';
            result.view_only = $modal.find('.field_showing_type:checked').val() == 'view_only';
            result.hidden = $modal.find('.field_showing_type:checked').val() == 'hidden';

            result.changedata_target_column_id = $modal.find('.changedata_target_column_id').val();
            result.changedata_column_id = $modal.find('.changedata_column_id').val();
            result.relation_filter_target_column_id = $modal.find('.relation_filter_target_column_id').val();

            return result;
        }


        /**
         * get option
         */
        public getOption()
        {
            return {
                form_column_view_name: this.form_column_view_name,
                read_only: this.read_only,
                view_only: this.view_only,
                hidden: this.hidden,
                changedata_target_column_id:this.changedata_target_column_id,
                changedata_column_id:this.changedata_column_id,
                relation_filter_target_column_id:this.relation_filter_target_column_id,

                html: this.html,
                text: this.text,
                image_aslink: this.image_aslink,
            };
        }



        /**
         * Show setting modal
         */
        public showSettingModal($target: JQuery<Element>){
            // TODO: best way
            const formData = {
                form_block_type:this.form_block_type,
                form_block_target_table_id:this.form_block_target_table_id,

                form_column_type:this.form_column_type,
                column_no:this.column_no,
                required:this.required,
                form_column_target_id:this.form_column_target_id,
                header_column_name:this.header_column_name,
                read_only:this.read_only,
                view_only:this.view_only,
                hidden:this.hidden,
                changedata_target_column_id:this.changedata_target_column_id,
                changedata_column_id:this.changedata_column_id,
                relation_filter_target_column_id:this.relation_filter_target_column_id,
            };

            Exment.ModalEvent.ShowModal($target, URLJoin($('#formroot').val(), 'settingModal'), formData);
        }
    }
}
