namespace Exment {
    /**
     * Getting box info
     */
    export abstract class GetBox {
        /**
         * Get object model
         */
        public static make() : GetBox
        {
            if(Exment.GetBoxAdmin !== undefined){
                return new GetBoxAdmin();
            }
            if(Exment.GetBoxPublicForm !== undefined){
                return new GetBoxPublicForm();
            }
            return null;
        }

        /**
         * Get Box
         */
        public abstract getBox() : JQuery<HTMLElement>;
    }
}
