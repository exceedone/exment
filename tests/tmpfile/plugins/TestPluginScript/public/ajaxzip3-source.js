/* ================================================================ *
    ajaxzip3.js ---- AjaxZip3 郵便番号→住所変換ライブラリ

    Copyright (c) 2008-2015 Ninkigumi Co.,Ltd.
    http://ajaxzip3.github.io/

    Copyright (c) 2006-2007 Kawasaki Yusuke <u-suke [at] kawa.net>
    http://www.kawa.net/works/ajax/AjaxZip2/AjaxZip2.html

    Permission is hereby granted, free of charge, to any person
    obtaining a copy of this software and associated documentation
    files (the "Software"), to deal in the Software without
    restriction, including without limitation the rights to use,
    copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the
    Software is furnished to do so, subject to the following
    conditions:

    The above copyright notice and this permission notice shall be
    included in all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
    EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
    OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
    NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
    HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
    WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
    FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
    OTHER DEALINGS IN THE SOFTWARE.
* ================================================================ */

AjaxZip3 = function(){};
AjaxZip3.VERSION = '0.51';
AjaxZip3.JSONDATA = 'https://yubinbango.github.io/yubinbango-data/data';
AjaxZip3.CACHE = [];
AjaxZip3.prev = '';
AjaxZip3.nzip = '';
AjaxZip3.fzip1 = '';
AjaxZip3.fzip2 = '';
AjaxZip3.fpref = '';
AjaxZip3.addr = '';
AjaxZip3.fstrt = '';
AjaxZip3.farea = '';
AjaxZip3.ffocus = true;
AjaxZip3.onSuccess = null;
AjaxZip3.onFailure = null;

AjaxZip3.PREFMAP = [
    null,       '北海道',   '青森県',   '岩手県',   '宮城県',
    '秋田県',   '山形県',   '福島県',   '茨城県',   '栃木県',
    '群馬県',   '埼玉県',   '千葉県',   '東京都',   '神奈川県',
    '新潟県',   '富山県',   '石川県',   '福井県',   '山梨県',
    '長野県',   '岐阜県',   '静岡県',   '愛知県',   '三重県',
    '滋賀県',   '京都府',   '大阪府',   '兵庫県',   '奈良県',
    '和歌山県', '鳥取県',   '島根県',   '岡山県',   '広島県',
    '山口県',   '徳島県',   '香川県',   '愛媛県',   '高知県',
    '福岡県',   '佐賀県',   '長崎県',   '熊本県',   '大分県',
    '宮崎県',   '鹿児島県', '沖縄県'
];
AjaxZip3.zip2addr = function ( azip1, azip2, apref, aaddr, aarea, astrt, afocus ) {
    AjaxZip3.fzip1 = AjaxZip3.getElementByName(azip1);
    AjaxZip3.fzip2 = AjaxZip3.getElementByName(azip2,AjaxZip3.fzip1);
    AjaxZip3.fpref = AjaxZip3.getElementByName(apref,AjaxZip3.fzip1);
    AjaxZip3.faddr = AjaxZip3.getElementByName(aaddr,AjaxZip3.fzip1);
    AjaxZip3.fstrt = AjaxZip3.getElementByName(astrt,AjaxZip3.fzip1);
    AjaxZip3.farea = AjaxZip3.getElementByName(aarea,AjaxZip3.fzip1);
    AjaxZip3.ffocus = afocus === undefined ? true : afocus;
    if ( ! AjaxZip3.fzip1 ) return;
    if ( ! AjaxZip3.fpref ) return;
    if ( ! AjaxZip3.faddr ) return;

    // 郵便番号を数字のみ7桁取り出す
//    var zipoptimize = function(AjaxZip3.fzip1, AjaxZip3.fzip2){
        var vzip = AjaxZip3.fzip1.value;
        if ( AjaxZip3.fzip2 && AjaxZip3.fzip2.value ) vzip += AjaxZip3.fzip2.value;
        if ( ! vzip ) return;
        AjaxZip3.nzip = '';
        for( var i=0; i<vzip.length; i++ ) {
            var chr = vzip.charCodeAt(i);
            if ( chr < 48 ) continue;
            if ( chr > 57 ) continue;
            AjaxZip3.nzip += vzip.charAt(i);
        }
        if ( AjaxZip3.nzip.length < 7 ) return;
//    };

    // 前回と同じ値＆フォームならキャンセル
    var uniqcheck = function(){
        var uniq = AjaxZip3.nzip+AjaxZip3.fzip1.name+AjaxZip3.fpref.name+AjaxZip3.faddr.name;
        if ( AjaxZip3.fzip1.form ) uniq += AjaxZip3.fzip1.form.id+AjaxZip3.fzip1.form.name+AjaxZip3.fzip1.form.action;
        if ( AjaxZip3.fzip2 ) uniq += AjaxZip3.fzip2.name;
        if ( AjaxZip3.fstrt ) uniq += AjaxZip3.fstrt.name;
        if ( uniq == AjaxZip3.prev ) return;
        AjaxZip3.prev = uniq;
    };


    // 郵便番号上位3桁でキャッシュデータを確認
    var zip3 = AjaxZip3.nzip.substr(0,3);
    var data = AjaxZip3.CACHE[zip3];
    if ( data ) return AjaxZip3.callback( data );

    AjaxZip3.zipjsonpquery();

};

AjaxZip3.callback = function(data){
        function onFailure( ){
            if( typeof AjaxZip3.onFailure === 'function' ) AjaxZip3.onFailure();
        }
        var array = data[AjaxZip3.nzip];
        // Opera バグ対策：0x00800000 を超える添字は +0xff000000 されてしまう
        var opera = (AjaxZip3.nzip-0+0xff000000)+"";
        if ( ! array && data[opera] ) array = data[opera];
        if ( ! array ) {
            onFailure();
            return;
        }
        var pref_id = array[0];                 // 都道府県ID
        if ( ! pref_id ) {
            onFailure();
            return;
        }
        var jpref = AjaxZip3.PREFMAP[pref_id];  // 都道府県名
        if ( ! jpref ) {
            onFailure();
            return;
        }

        var jcity = array[1];
        if ( ! jcity ) jcity = '';              // 市区町村名
        var jarea = array[2];
        if ( ! jarea ) jarea = '';              // 町域名
        var jstrt = array[3];
        if ( ! jstrt ) jstrt = '';              // 番地

        var cursor = AjaxZip3.faddr;
        var jaddr = jcity;                      // 市区町村名

        if ( AjaxZip3.fpref.type == 'select-one' || AjaxZip3.fpref.type == 'select-multiple' ) {
            // 都道府県プルダウンの場合
            var opts = AjaxZip3.fpref.options;
            for( var i=0; i<opts.length; i++ ) {
                var vpref = opts[i].value;
                var tpref = opts[i].text;
                opts[i].selected = ( vpref == pref_id || vpref == jpref || tpref == jpref );
                
                if(opts[i].selected){
                    $(AjaxZip3.fpref).trigger('change');
                }
            }
        } else {
            if ( AjaxZip3.fpref.name == AjaxZip3.faddr.name ) {
                // 都道府県名＋市区町村名＋町域名合体の場合
                jaddr = jpref + jaddr;
            } else {
                // 都道府県名テキスト入力の場合
                AjaxZip3.fpref.value = jpref;
            }
        }
        if ( AjaxZip3.farea ) {
            cursor = AjaxZip3.farea;
            AjaxZip3.farea.value = jarea;
        } else {
            jaddr += jarea;
        }
        if ( AjaxZip3.fstrt ) {
            cursor = AjaxZip3.fstrt;
            if ( AjaxZip3.faddr.name == AjaxZip3.fstrt.name ) {
                // 市区町村名＋町域名＋番地合体の場合
                jaddr = jaddr + jstrt;
            } else if ( jstrt ) {
                // 番地テキスト入力欄がある場合
                AjaxZip3.fstrt.value = jstrt;
            }
        }
        AjaxZip3.faddr.value = jaddr;

        if( typeof AjaxZip3.onSuccess === 'function' ) AjaxZip3.onSuccess();

        // patch from http://iwa-ya.sakura.ne.jp/blog/2006/10/20/050037
        // update http://www.kawa.net/works/ajax/AjaxZip2/AjaxZip2.html#com-2006-12-15T04:41:22Z
        if ( !AjaxZip3.ffocus ) return;
        if ( ! cursor ) return;
        if ( ! cursor.value ) return;
        var len = cursor.value.length;
        cursor.focus();
        if ( cursor.createTextRange ) {
            var range = cursor.createTextRange();
            range.move('character', len);
            range.select();
        } else if (cursor.setSelectionRange) {
            cursor.setSelectionRange(len,len);
        }

};

// Safari 文字化け対応
// http://kawa.at.webry.info/200511/article_9.html
AjaxZip3.getResponseText = function ( req ) {
    var text = req.responseText;
    if ( navigator.appVersion.indexOf('KHTML') > -1 ) {
        var esc = escape( text );
        if ( esc.indexOf('%u') < 0 && esc.indexOf('%') > -1 ) {
            text = decodeURIComponent( esc );
        }
    }
    return text;
}

// フォームnameから要素を取り出す
AjaxZip3.getElementByName = function ( elem, sibling ) {
    if ( typeof(elem) == 'string' ) {
        var list = document.getElementsByName(elem);
        if ( ! list ) return null;
        if ( list.length > 1 && sibling && sibling.form ) {
            var form = sibling.form.elements;
            var item = null;
            for( var i=0; i<form.length; i++ ) {
                if ( form[i].name == elem ) {
                    item = form[i];
                }
            }
            return item;
        } else {
            return list[0];
        }
    }
    return elem;
}

AjaxZip3.zipjsonpquery = function(){
    var url = AjaxZip3.JSONDATA+'/'+AjaxZip3.nzip.substr(0,3)+'.js';
    var scriptTag = document.createElement("script");
    scriptTag.setAttribute("type", "text/javascript");
    scriptTag.setAttribute("charset", "UTF-8");
    scriptTag.setAttribute("src", url);
    document.getElementsByTagName("head").item(0).appendChild(scriptTag);
   };

function $yubin(data){
    AjaxZip3.callback(data);
};
