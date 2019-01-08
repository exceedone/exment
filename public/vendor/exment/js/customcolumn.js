var Exment;
(function (Exment) {
    /**
    * Column Column Script.
    */
    var CustomColumnEvent = /** @class */ (function () {
        function CustomColumnEvent() {
        }
        CustomColumnEvent.AddEvent = function () {
        };
        CustomColumnEvent.AddEventOnce = function () {
            $(document).off('click', '.button-addcalcitem').on('click', '.button-addcalcitem', {}, CustomColumnEvent.calcButtonAddItemEvent);
            $(document).off('click', '.button-setting').on('click', '.button-setting', {}, CustomColumnEvent.calcButtonSettingEvent);
            $(document).off('click', '.button-reset').on('click', '.button-reset', {}, CustomColumnEvent.calcButtonResetEvent);
            $(document).off('click', '.col-value-item-remove').on('click', '.col-value-item-remove', {}, CustomColumnEvent.calcRemoveItemEvent);
            $(document).on('pjax:complete', function (event) {
                CustomColumnEvent.AddEvent();
            });
        };
        CustomColumnEvent.calcButtonAddItemEvent = function (ev) {
            var target = $(ev.target).closest('.button-addcalcitem');
            // get target type
            var type = target.data('type');
            // get template
            var template = document.querySelector('.col-value-template');
            // create clone
            var clone = document.importNode(template.content, true);
            clone.querySelector('.col-value-item').dataset.type = type;
            ///// switch using type
            switch (type) {
                case 'dynamic':
                case 'symbol':
                    // set data-val and text
                    clone.querySelector('.col-value-item').dataset.val = target.data('val');
                    clone.querySelector('span').textContent = target.text();
                    break;
                case 'fixed':
                    // set data-val from col-target-fixedval
                    var fixedval = target.closest('.row').find('.col-target-fixedval').val();
                    if (!hasValue(fixedval)) {
                        return;
                    }
                    clone.querySelector('.col-value-item').dataset.val = fixedval;
                    clone.querySelector('span').textContent = fixedval;
                    break;
            }
            // set item
            $('.calc_formula_area').append(clone);
        };
        CustomColumnEvent.calcButtonSettingEvent = function (ev) {
            // get col value item list
            var values = $(ev.target).closest('.modal').find('.calc_formula_area').find('.col-value-item');
            // get items and texts
            var items = [];
            var texts = [];
            for (var i = 0; i < values.length; i++) {
                // get value
                var val = values.eq(i);
                // push value
                items.push({ 'type': val.data('type'), 'val': val.data('val') });
                // push text
                texts.push(val.text());
            }
            // set value and text
            $('.options_calc_formula').val(JSON.stringify(items));
            $('#calc_formula').find('.text-valuemodal').text(texts.join(' '));
            $('.modal').modal('hide');
        };
        CustomColumnEvent.calcButtonResetEvent = function (ev) {
            // remove item
            $(ev.target).closest('.modal').find('.calc_formula_area').children().remove();
            // set value and text
            $('.options_calc_formula').val(null);
            $('#calc_formula').find('.text-valuemodal').text('');
            $('.modal').modal('hide');
        };
        CustomColumnEvent.calcRemoveItemEvent = function (ev) {
            // remove item
            $(ev.target).closest('.col-value-item').remove();
        };
        return CustomColumnEvent;
    }());
    Exment.CustomColumnEvent = CustomColumnEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomColumnEvent.AddEvent();
    Exment.CustomColumnEvent.AddEventOnce();
});
