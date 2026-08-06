{{--
    Kanban board styling.
    Inlined on purpose: no asset publish step is needed, and the board keeps
    working after a pjax navigation. Every rule is scoped under .exment-kanban
    or .kb-drawer so nothing here can leak into the rest of the admin.
--}}
<style>
.exment-kanban{--kb-blue:#3c8dbc;--kb-border:#d2d6de;--kb-line:#f4f4f4;}

/* ---------------------------------------------------------------- toolbar */
.exment-kanban .kb-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:6px;width:100%;}
.exment-kanban .kb-toolbar-left{display:flex;align-items:center;flex-wrap:wrap;gap:5px;}
.exment-kanban .kb-toolbar-right{display:flex;align-items:center;flex-wrap:wrap;gap:5px;margin-left:auto;justify-content:flex-end;}
.exment-kanban .kb-search{width:230px;}
.exment-kanban .kb-filterbox{padding:10px 12px;border-top:1px solid var(--kb-border);background:#fbfcfd;}
.exment-kanban .kb-filterbox .kb-f{display:inline-block;vertical-align:top;margin:0 10px 8px 0;min-width:170px;}
.exment-kanban .kb-filterbox label{display:block;font-size:12px;color:#7b8794;margin-bottom:2px;}
.exment-kanban .kb-filterbox select,
.exment-kanban .kb-filterbox input[type=text]{width:100%;height:30px;font-size:13px;padding:2px 6px;border:1px solid var(--kb-border);border-radius:3px;background:#fff;}
.exment-kanban .kb-filter-checks{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding-top:2px;}
.exment-kanban .kb-filter-checks label{display:inline-flex;align-items:center;gap:5px;font-size:13px;color:#444;margin:0;cursor:pointer;}
.exment-kanban .kb-reset{margin-left:auto;}

/* -------------------------------------------------------------------- KPI */
.exment-kanban .kb-kpis{display:flex;gap:10px;flex-wrap:wrap;padding:10px 12px 0;}
.exment-kanban .kb-kpi{flex:1 1 150px;display:flex;align-items:center;gap:10px;background:#fff;
  border:1px solid var(--kb-border);border-left:3px solid var(--kb-blue);border-radius:3px;padding:9px 12px;min-width:150px;}
.exment-kanban .kb-kpi-icon{width:42px;height:42px;border-radius:4px;color:#fff;display:flex;
  align-items:center;justify-content:center;font-size:18px;flex:0 0 auto;}
.exment-kanban .kb-kpi-num{font-size:22px;font-weight:700;color:#2c3e50;line-height:1.1;}
.exment-kanban .kb-kpi-label{font-size:12px;color:#888;}

/* --------------------------------------------------------------- bulk bar */
.exment-kanban .kb-bulkbar{display:flex;align-items:center;gap:10px;background:#e1f0fb;border:1px solid #b9ddf4;
  border-radius:3px;padding:8px 12px;margin:10px 12px 0;flex-wrap:wrap;}
.exment-kanban .kb-bulk-count{font-weight:600;color:#1c6ea4;}
.exment-kanban .kb-bulkbar select{height:30px;font-size:13px;border:1px solid #b9ddf4;border-radius:3px;padding:2px 6px;background:#fff;}

/* ------------------------------------------------------------ board frame */
.exment-kanban .kb-scroll{overflow-x:auto;padding:12px;}
.exment-kanban .kb-board{display:flex;flex-direction:column;gap:16px;min-width:max-content;}
.exment-kanban .kb-swimlane{display:flex;flex-direction:column;}
.exment-kanban .kb-lane-head{display:flex;align-items:center;gap:8px;font-weight:600;color:#34495e;
  padding:4px 2px 8px;border-bottom:1px solid var(--kb-border);margin-bottom:10px;}
.exment-kanban .kb-lane-head .fa{color:#95a5a6;}
.exment-kanban .kb-lane-count{background:#e7edf3;color:#5a6b7b;border-radius:10px;padding:0 8px;font-size:12px;font-weight:600;}
.exment-kanban .kb-row{display:flex;gap:12px;align-items:flex-start;min-width:max-content;}

/* ------------------------------------------------------------------ column */
.exment-kanban .kb-col{flex:0 0 272px;width:272px;background:#f4f6f9;border:1px solid var(--kb-border);
  border-radius:4px;display:flex;flex-direction:column;max-height:74vh;}
.exment-kanban .kb-col-head{display:flex;align-items:center;gap:8px;padding:8px 10px;background:#fff;
  border-bottom:2px solid var(--kb-blue);border-radius:4px 4px 0 0;font-weight:700;color:#444;}
.exment-kanban .kb-col-title{flex:1 1 auto;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.exment-kanban .kb-col-count{background:#e7edf3;color:#5a6b7b;border-radius:10px;padding:0 8px;font-size:12px;font-weight:600;}
.exment-kanban .kb-col-wip{margin-left:2px;font-size:12px;color:#9aa6b2;font-variant-numeric:tabular-nums;font-weight:600;}
.exment-kanban .kb-col-head.over-wip{border-bottom-color:#dd4b39;background:#fdecea;color:#c0392b;}
.exment-kanban .kb-col-head.over-wip .kb-col-count{background:#f7c7c0;color:#a93226;}
.exment-kanban .kb-col-head.over-wip .kb-col-wip{color:#c0392b;}
.exment-kanban .kb-list{min-height:48px;padding:8px;display:flex;flex-direction:column;gap:8px;flex:1 1 auto;overflow-y:auto;}
.exment-kanban .kb-list.drag-over{background:#eaf3fb;outline:2px dashed var(--kb-blue);outline-offset:-4px;border-radius:0 0 4px 4px;}
.exment-kanban .kb-empty{color:#aab4be;font-size:12px;text-align:center;padding:14px 4px;}
.exment-kanban .kb-quickadd{padding:8px;border-top:1px solid var(--kb-border);}
.exment-kanban .kb-quick{width:100%;height:30px;font-size:12.5px;border:1px solid var(--kb-border);border-radius:3px;padding:4px 8px;}
.exment-kanban .kb-quick:focus{outline:0;border-color:#66afe9;}

/* -------------------------------------------------------------------- card */
.exment-kanban .kb-card{background:#fff;border:1px solid var(--kb-border);border-left:4px solid #cfd6de;
  border-radius:3px;box-shadow:0 1px 1px rgba(0,0,0,.08);padding:8px 10px;cursor:pointer;font-size:13px;
  transition:box-shadow .12s,border-color .12s;}
.exment-kanban .kb-card:hover{box-shadow:0 2px 7px rgba(0,0,0,.14);border-color:#b9c6d4;}
.exment-kanban .kb-card.dragging{opacity:.5;}
.exment-kanban .kb-card.saving{opacity:.55;pointer-events:none;}
.exment-kanban .kb-card.sel{outline:2px solid var(--kb-blue);outline-offset:-1px;background:#f3f9fd;}
.exment-kanban .kb-card.unassigned{border-left-color:#e0b96b;}
.exment-kanban .kb-card.kb-age-1{border-left-color:#f1c40f;}
.exment-kanban .kb-card.kb-age-2{border-left-color:#e67e22;}
.exment-kanban .kb-card.kb-age-3{border-left-color:#c0392b;}
.exment-kanban .kb-card-top{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.exment-kanban .kb-handle{color:#b0b8c1;cursor:grab;font-size:12px;}
.exment-kanban .kb-num{font-size:12.5px;color:var(--kb-blue);font-weight:600;}
.exment-kanban .kb-num:hover{text-decoration:underline;}
.exment-kanban .kb-card-top .kb-chip-wrap{margin-left:auto;display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.exment-kanban .kb-card-title{font-weight:600;color:#2c3e50;line-height:1.35;margin:5px 0;word-break:break-word;}
.exment-kanban .kb-card-meta,
.exment-kanban .kb-card-meta2,
.exment-kanban .kb-card-foot{display:flex;flex-wrap:wrap;align-items:center;gap:5px;}
.exment-kanban .kb-card-meta2{margin-top:4px;}
.exment-kanban .kb-card-foot{margin-top:6px;padding-top:6px;border-top:1px solid var(--kb-line);}

/* ------------------------------------------------------------- card chips */
.exment-kanban .kb-auto{font-size:12px;line-height:1.6;width:100%;word-break:break-word;}
.exment-kanban .kb-auto-label{color:#888;margin-right:6px;}
.kb-tag{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;background:#f2f4f6;color:#4a5562;border:1px solid #e1e6ea;white-space:nowrap;}
.kb-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:10px;font-size:12px;
  background:#eef2f7;color:#5a6b7b;border:1px solid #d6dee7;white-space:nowrap;}
.kb-pill i{font-size:11px;color:#7d8da0;}
.kb-prio{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-size:11.5px;font-weight:600;}
.kb-prio .kb-sq{width:10px;height:10px;border-radius:2px;display:inline-block;flex:0 0 auto;}
.kb-lvl{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-size:12px;}
.kb-lvl .kb-cir{width:9px;height:9px;border-radius:50%;display:inline-block;flex:0 0 auto;}
.kb-state{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;line-height:1.5;white-space:nowrap;border:1px solid;}
.kb-chip{font-size:11px;color:#5a6b7b;background:#eef2f7;border:1px solid #d6dee7;border-radius:10px;padding:1px 7px;white-space:nowrap;}
.kb-point{font-size:11px;color:#2f7a4d;background:#eef7f0;border:1px solid #cfe6d6;border-radius:10px;padding:1px 7px;
  white-space:nowrap;display:inline-flex;align-items:center;gap:4px;}
.kb-icontext{display:inline-block;font-size:13px;color:#34495e;white-space:nowrap;}
.kb-icontext i{color:#95a5a6;margin-right:5px;}
.kb-flag{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;white-space:nowrap;}
.kb-flag.on{color:#1f8f4d;}
.kb-flag.off{color:#c0392b;}
.kb-assignee{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;font-size:12.5px;}
.kb-av{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
  color:#fff;font-size:11px;font-weight:700;flex:0 0 auto;}
.kb-unassigned{font-size:11px;color:#b9770a;background:#fdf3e0;border:1px dashed #e0b96b;border-radius:10px;
  padding:1px 8px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;}
.kb-ai{font-size:11px;color:#1c6ea4;background:#e1f0fb;border:1px solid #b9ddf4;border-radius:10px;
  padding:1px 8px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;}
.kb-ai.suggested{color:#8e5b00;background:#fff5e0;border-color:#f0d18a;}

/* SLA timer badge */
.kb-sla{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;border-radius:10px;padding:1px 8px;white-space:nowrap;}
.kb-sla.ok{background:#e3f6ea;color:#1f8f4d;border:1px solid #bfe8cd;}
.kb-sla.warn{background:#fdf3e0;color:#b9770a;border:1px solid #f6e0b3;}
.kb-sla.breach{background:#fdecea;color:#c0392b;border:1px solid #f5c6c0;animation:kbPulse 1.7s infinite;}
.kb-sla.done{background:#eceff1;color:#6b7a82;border:1px solid #d4dade;}
@keyframes kbPulse{0%,100%{opacity:1}50%{opacity:.55}}

/* ------------------------------------------------------------------ drawer */
.kb-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:1055;opacity:0;transition:opacity .18s;}
.kb-backdrop.show{opacity:1;}
.kb-drawer{position:fixed;top:0;right:0;bottom:0;width:440px;max-width:92vw;background:#fff;z-index:1060;
  box-shadow:-3px 0 16px rgba(0,0,0,.2);transform:translateX(100%);transition:transform .2s ease-out;
  display:flex;flex-direction:column;}
.kb-drawer.show{transform:translateX(0);}
.kb-drawer-head{background:#3c8dbc;color:#fff;padding:12px 16px;display:flex;align-items:center;gap:10px;}
.kb-drawer-head .kb-num{color:#fff;font-size:15px;}
.kb-drawer-close{margin-left:auto;background:none;border:0;color:#fff;font-size:20px;line-height:1;cursor:pointer;opacity:.85;}
.kb-drawer-close:hover{opacity:1;}
.kb-drawer-body{padding:16px;overflow-y:auto;flex:1 1 auto;}
.kb-drawer-title{font-weight:600;color:#2c3e50;margin:2px 0 12px;font-size:15px;line-height:1.4;}
.kb-fields{display:grid;grid-template-columns:120px 1fr;gap:7px 10px;margin:0;}
.kb-fields dt{color:#8a949e;font-size:12px;font-weight:600;margin:0;}
.kb-fields dd{font-size:13px;margin:0;color:#2c3e50;word-break:break-word;}
.kb-ai-rec{background:#f4f8fb;border:1px solid #e1ecf4;border-radius:4px;padding:12px;margin-top:14px;}
.kb-ai-rec-head{display:flex;align-items:center;gap:8px;font-weight:600;color:#1c6ea4;margin-bottom:8px;}
.kb-ai-rec-who{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.kb-ai-meter{height:8px;background:#dde7ef;border-radius:4px;overflow:hidden;margin:6px 0;}
.kb-ai-meter>span{display:block;height:100%;background:#3c8dbc;}
.kb-ai-hint{font-size:12px;color:#777;margin-bottom:10px;}
.kb-drawer-foot{padding:12px 16px;border-top:1px solid #e6eaee;}

/* ---------------------------------------------------------------- workflow */
.kb-wf{background:#f6f8f6;border:1px solid #e0e8e0;border-radius:4px;padding:12px;margin-top:14px;}
.kb-wf-head{display:flex;align-items:center;gap:8px;font-weight:600;color:#2e7d32;margin-bottom:8px;}
.kb-wf-btn{display:flex;align-items:center;width:100%;margin-bottom:6px;text-align:left;}
.kb-wf-btn:last-child{margin-bottom:0;}
.kb-wf-to{margin-left:auto;opacity:.85;font-size:12px;white-space:nowrap;padding-left:8px;}
.kb-wf-none{font-size:12px;color:#7d8891;}
/* a status this card cannot reach, while it is dragged */
.exment-kanban .kb-col.kb-nodrop{opacity:.4;}
.exment-kanban .kb-col.kb-nodrop .kb-list{background:repeating-linear-gradient(45deg,#f2f4f6,#f2f4f6 6px,#e9edf1 6px,#e9edf1 12px);}

/* ------------------------------------------------------------------- toast */
.kb-toast{position:fixed;right:18px;bottom:70px;z-index:1080;min-width:290px;max-width:380px;background:#fff;
  border:1px solid #d2d6de;border-left:4px solid #3c8dbc;border-radius:4px;box-shadow:0 3px 14px rgba(0,0,0,.18);
  padding:11px 14px;display:flex;align-items:center;gap:10px;font-size:13px;color:#333;}
.kb-toast.success{border-left-color:#00a65a;}
.kb-toast.danger{border-left-color:#dd4b39;}
.kb-toast .fa{font-size:16px;color:#3c8dbc;}
.kb-toast.success .fa{color:#00a65a;}
.kb-toast.danger .fa{color:#dd4b39;}
.kb-toast-msg{flex:1 1 auto;}
.kb-toast .kb-undo{color:#3c8dbc;font-weight:600;cursor:pointer;white-space:nowrap;}
.kb-toast .kb-undo:hover{text-decoration:underline;}
</style>
