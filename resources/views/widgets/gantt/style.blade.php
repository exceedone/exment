<style>
/* ==== gantt view ==================================================== */
.exment-gantt .gt-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;}
.exment-gantt .gt-toolbar-left{flex:1;display:flex;align-items:center;flex-wrap:wrap;gap:8px;min-width:0;}
.exment-gantt .gt-toolbar-right{display:flex;align-items:center;gap:6px;}
.exment-gantt .gt-legend{display:flex;align-items:center;flex-wrap:wrap;gap:4px 14px;font-size:12px;color:#6b7a88;}
.exment-gantt .gt-legend .sw{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px;}
.exment-gantt .gt-legend .today-line{color:#d9534f;font-weight:700;}
.exment-gantt .gt-legend .dia{color:#8e44ad;}

.exment-gantt .gt-scroll{overflow-x:auto;border:1px solid #dfe5ea;border-radius:4px;background:#fff;}
.exment-gantt .gt-inner{position:relative;}
.exment-gantt .gt-row{display:flex;border-bottom:1px solid #eef2f5;height:34px;}
.exment-gantt .gt-row:last-child{border-bottom:0;}
.exment-gantt .gt-left{position:sticky;left:0;z-index:3;background:#fff;border-right:1px solid #dfe5ea;
  display:flex;align-items:center;gap:8px;padding:0 10px;flex:none;box-sizing:border-box;}
.exment-gantt .gt-row.gt-grp{background:#f3f6f8;height:32px;}
.exment-gantt .gt-row.gt-grp .gt-left{background:#f3f6f8;font-weight:700;color:#4d5d6b;cursor:pointer;}
.exment-gantt .gt-row.gt-grp .gt-left .fa{color:#8e44ad;}
.exment-gantt .gt-row.gt-grp .gt-left .gt-caret{color:#98a5b1;width:12px;}
.exment-gantt .gt-row.gt-grp .gt-left .gt-cnt{color:#8b99a5;font-weight:400;font-size:11px;}
.exment-gantt .gt-row.gt-head{background:#f9fbfc;height:44px;border-bottom:1px solid #dfe5ea;}
.exment-gantt .gt-row.gt-head .gt-left{background:#f9fbfc;font-weight:700;color:#5b6b79;font-size:12px;}
.exment-gantt .gt-canvas{position:relative;flex:none;box-sizing:border-box;}
.exment-gantt .gt-mon{position:absolute;top:4px;font-weight:700;color:#4d5d6b;font-size:11px;white-space:nowrap;}
.exment-gantt .gt-wk{position:absolute;bottom:4px;color:#8b99a5;font-size:10px;white-space:nowrap;}
.exment-gantt .gt-line{position:absolute;top:0;bottom:0;width:1px;background:#e9eef2;}
.exment-gantt .gt-we{position:absolute;top:0;bottom:0;background:rgba(120,140,160,.08);}
.exment-gantt .gt-today{position:absolute;top:0;bottom:0;width:2px;background:#d9534f;z-index:2;}
.exment-gantt .gt-today-label{position:absolute;top:26px;color:#d9534f;font-size:10px;font-weight:700;white-space:nowrap;}
.exment-gantt .gt-bar{position:absolute;height:16px;top:8px;border-radius:4px;opacity:.92;cursor:pointer;box-sizing:border-box;}
.exment-gantt .gt-bar:hover{opacity:1;box-shadow:0 1px 4px rgba(20,40,60,.3);}
.exment-gantt .gt-bar>i{position:absolute;left:0;top:0;bottom:0;background:rgba(0,0,0,.24);border-radius:4px 0 0 4px;}
.exment-gantt .gt-bar.gt-over{box-shadow:0 0 0 2px #d9534f;}
.exment-gantt .gt-dia{position:absolute;top:11px;width:10px;height:10px;background:#8e44ad;transform:rotate(45deg);}
.exment-gantt .gt-label{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12.5px;}
.exment-gantt .gt-label a{color:#24323f;text-decoration:none;}
.exment-gantt .gt-label a:hover{color:#3c8dbc;}
.exment-gantt .gt-label .gt-indent{color:#98a5b1;font-size:11px;}
.exment-gantt .gt-dot{display:inline-block;width:8px;height:8px;border-radius:2px;flex:none;}
.exment-gantt .gt-av{display:inline-flex;width:22px;height:22px;border-radius:50%;color:#fff;align-items:center;
  justify-content:center;font-size:10px;font-weight:700;flex:none;}
.exment-gantt .gt-due{flex:none;width:44px;text-align:right;color:#6b7a88;font-size:11px;white-space:nowrap;}
.exment-gantt .gt-due.gt-overdue{color:#d9534f;font-weight:700;}
.exment-gantt .gt-hidden{display:none;}
.exment-gantt .gt-empty{padding:28px 12px;text-align:center;color:#8b99a5;font-size:13px;}
</style>
