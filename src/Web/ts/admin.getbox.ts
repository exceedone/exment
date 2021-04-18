namespace Exment {
    export class GetBoxAdmin extends GetBox {
        public getBox() : JQuery<HTMLElement>
        {
            if(hasValue($('.publicformapi'))){
                return $('.form-horizontal');
            }
            return $('.box-body');
        }
    }
}
