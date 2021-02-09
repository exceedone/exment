var Exment;
(function (Exment) {
    class GetBoxAdmin extends Exment.GetBox {
        getBox() {
            return $('.box-body');
        }
    }
    Exment.GetBoxAdmin = GetBoxAdmin;
})(Exment || (Exment = {}));
