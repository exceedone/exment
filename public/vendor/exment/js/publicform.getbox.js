var Exment;
(function (Exment) {
    class GetBoxPublicForm extends Exment.GetBox {
        getBox() {
            return $('.form-horizontal');
        }
    }
    Exment.GetBoxPublicForm = GetBoxPublicForm;
})(Exment || (Exment = {}));
