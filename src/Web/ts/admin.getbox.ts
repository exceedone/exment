namespace Exment {
    export class GetBoxAdmin extends GetBox {
        public getBox() : JQuery<HTMLElement>{
            return $('.box-body');
        }
    }
}
