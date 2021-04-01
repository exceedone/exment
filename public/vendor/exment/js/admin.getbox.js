var Exment;
(function (Exment) {
    class GetBoxAdmin extends Exment.GetBox {
        getBox() {
            if (hasValue($('.publicformapi'))) {
                return $('.form-horizontal');
            }
            return $('.box-body');
        }
    }
    Exment.GetBoxAdmin = GetBoxAdmin;
})(Exment || (Exment = {}));
