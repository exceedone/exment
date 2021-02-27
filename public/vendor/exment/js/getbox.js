var Exment;
(function (Exment) {
    /**
     * Getting box info
     */
    class GetBox {
        /**
         * Get object model
         */
        static make() {
            if (Exment.GetBoxAdmin !== undefined) {
                return new Exment.GetBoxAdmin();
            }
            if (Exment.GetBoxPublicForm !== undefined) {
                return new Exment.GetBoxPublicForm();
            }
            return null;
        }
    }
    Exment.GetBox = GetBox;
})(Exment || (Exment = {}));
