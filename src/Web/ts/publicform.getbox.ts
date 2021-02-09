namespace Exment {
    export class GetBoxPublicForm extends GetBox {
        public getBox() : JQuery<HTMLElement>{
            return $('.form-horizontal');
        }
    }
}
