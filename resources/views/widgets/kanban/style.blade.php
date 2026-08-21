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
/* the tools are the same ones the data grid renders: they bring their own
   .exm-grid-tool margin, so only the wrap gap is set here */
.exment-kanban .kb-toolbar-right{display:flex;align-items:center;flex-wrap:wrap;gap:5px 0;margin-left:auto;justify-content:flex-end;}
.exment-kanban .kb-toolbar-right .exm-grid-tool{margin-bottom:0;}
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
/* the interruption lane, pinned to the top and coloured so it is read first */
.exment-kanban .kb-swimlane.kb-expedite-lane > .kb-lane-head{color:#c0392b;border-bottom-color:#f0b7ae;}
.exment-kanban .kb-swimlane.kb-expedite-lane > .kb-lane-head .fa{color:#e74c3c;}
.exment-kanban .kb-swimlane.kb-expedite-lane > .kb-lane-head .kb-lane-count{background:#fadbd8;color:#a93226;}
.exment-kanban .kb-row{display:flex;gap:12px;align-items:flex-start;min-width:max-content;}

/* ------------------------------------------------------------------ column */
.exment-kanban .kb-col{flex:0 0 272px;width:272px;background:#f4f6f9;border:1px solid var(--kb-border);
  border-radius:4px;display:flex;flex-direction:column;max-height:74vh;}
.exment-kanban .kb-col-head{display:flex;align-items:center;gap:8px;padding:8px 10px;background:#fff;
  border-bottom:2px solid var(--kb-blue);border-radius:4px 4px 0 0;font-weight:700;color:#444;}
.exment-kanban .kb-col-title{flex:1 1 auto;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.exment-kanban .kb-col-count{background:#e7edf3;color:#5a6b7b;border-radius:10px;padding:0 8px;font-size:12px;font-weight:600;}
.exment-kanban .kb-col-wip{margin-left:2px;font-size:12px;color:#9aa6b2;font-variant-numeric:tabular-nums;font-weight:600;}
.exment-kanban .kb-fold{flex:0 0 auto;border:0;background:none;padding:0;color:#b0b8c1;font-size:11px;line-height:1;cursor:pointer;}
.exment-kanban .kb-fold:hover{color:#3c8dbc;}
.exment-kanban .kb-col-policy{flex:0 0 auto;color:#3c8dbc;font-size:12px;cursor:help;}
.exment-kanban .kb-col-blocked{background:#f2e3f7;color:#7d3c98;border-radius:10px;padding:0 7px;
  font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
/* folded away: still one click from being read, and never off the board */
.exment-kanban .kb-col.kb-folded{flex:0 0 44px;width:44px;background:#eef1f5;}
.exment-kanban .kb-col.kb-folded .kb-col-head{flex-direction:column;align-items:center;
  justify-content:flex-start;gap:8px;padding:8px 4px;height:100%;border-bottom:0;
  border-right:2px solid var(--kb-blue);border-radius:4px;}
.exment-kanban .kb-col.kb-folded .kb-col-title{flex:0 1 auto;writing-mode:vertical-rl;
  max-height:210px;overflow:hidden;text-overflow:ellipsis;}
.exment-kanban .kb-col.kb-folded .kb-col-policy,
.exment-kanban .kb-col.kb-folded .kb-col-blocked,
.exment-kanban .kb-col.kb-folded .kb-col-wip,
.exment-kanban .kb-col.kb-folded .kb-col-stats,
.exment-kanban .kb-col.kb-folded .kb-list,
.exment-kanban .kb-col.kb-folded .kb-more,
.exment-kanban .kb-col.kb-folded .kb-quickadd{display:none;}
.exment-kanban .kb-col-head.over-wip{border-bottom-color:#dd4b39;background:#fdecea;color:#c0392b;}
.exment-kanban .kb-col-head.over-wip .kb-col-count{background:#f7c7c0;color:#a93226;}
.exment-kanban .kb-col-head.over-wip .kb-col-wip{color:#c0392b;}
/* a total and an average, kept out of the WIP badge so each says one thing */
.exment-kanban .kb-col-stats{display:flex;align-items:center;gap:8px;padding:5px 10px;background:#f7f9fb;
  border-bottom:1px solid var(--kb-line);font-size:12px;}
.exment-kanban .kb-col-sum{font-weight:700;color:#2c3e50;font-variant-numeric:tabular-nums;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.exment-kanban .kb-col-age{margin-left:auto;display:inline-flex;align-items:center;gap:4px;color:#8a949e;
  white-space:nowrap;}
.exment-kanban .kb-col-total{color:#8a949e;white-space:nowrap;}
.exment-kanban .kb-col-sum + .kb-col-total{padding-left:8px;border-left:1px solid #dfe4ea;}
/* load the rest of one column, without dragging the whole board along */
.exment-kanban .kb-more{padding:0 8px 8px;}
.exment-kanban .kb-more-btn{width:100%;border:1px dashed var(--kb-border);background:#fff;color:#3c8dbc;
  border-radius:3px;padding:5px 8px;font-size:12px;cursor:pointer;}
.exment-kanban .kb-more-btn:hover{background:#eaf3fb;border-style:solid;}
.exment-kanban .kb-more-btn[disabled]{color:#aab4be;cursor:default;background:#fff;}
/* the box is off asking the server for matches further down the columns */
.exment-kanban input.kb-searching{border-color:#3c8dbc;box-shadow:inset 0 0 0 1px #3c8dbc;}
.exment-kanban .kb-col-head.over-wip + .kb-col-stats{background:#fdf2f0;}
.exment-kanban .kb-list{min-height:48px;padding:8px;display:flex;flex-direction:column;gap:8px;flex:1 1 auto;overflow-y:auto;}
.exment-kanban .kb-list.drag-over{background:#eaf3fb;outline:2px dashed var(--kb-blue);outline-offset:-4px;border-radius:0 0 4px 4px;}
/* folded, and still a place to put a card */
.exment-kanban .kb-col.kb-folded.drag-over{background:#dceaf7;outline:2px dashed var(--kb-blue);outline-offset:-3px;}
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
/* Work that stopped, and work that jumped the queue. Drawn as an inset
   outline so the left edge is left to say how long the card has waited. */
.exment-kanban .kb-card.kb-expedite{box-shadow:inset 0 0 0 2px #e67e22,0 1px 1px rgba(0,0,0,.08);}
.exment-kanban .kb-card.kb-blocked{box-shadow:inset 0 0 0 2px #8e44ad,0 1px 1px rgba(0,0,0,.08);background:#fdfaff;}
.exment-kanban .kb-card-flags{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:5px;}
/* not .kb-flag: that class already belongs to a card field drawn as a
   flag, and a scoped rule of the same name would restyle it */
.exment-kanban .kb-mark{display:inline-flex;align-items:center;gap:4px;border-radius:3px;
  padding:1px 6px;font-size:11px;font-weight:700;line-height:1.6;}
.exment-kanban .kb-mark-exp{background:#fdebd0;color:#b9770e;}
.exment-kanban .kb-mark-blk{background:#f2e3f7;color:#7d3c98;}
.exment-kanban .kb-card-top{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.exment-kanban .kb-handle{color:#b0b8c1;cursor:grab;font-size:12px;}
.exment-kanban .kb-num{font-size:12.5px;color:var(--kb-blue);font-weight:600;}
.exment-kanban .kb-num:hover{text-decoration:underline;}
.exment-kanban .kb-card-top .kb-chip-wrap{margin-left:auto;display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.exment-kanban .kb-card-title{font-weight:600;color:#2c3e50;line-height:1.35;margin:5px 0;word-break:break-word;}
/* not scoped to the board: the detail drawer draws the same bar, and it
   is attached to <body> */
.kb-progress{position:relative;height:14px;border-radius:7px;background:#e7edf3;
  overflow:hidden;margin:2px 0 6px;}
.kb-progress-bar{position:absolute;left:0;top:0;bottom:0;background:#3c8dbc;
  border-radius:7px;transition:width .2s;}
.kb-progress.half .kb-progress-bar{background:#f39c12;}
.kb-progress.full .kb-progress-bar{background:#27ae60;}
.kb-progress-txt{position:relative;display:block;text-align:center;font-size:10px;
  line-height:14px;font-weight:700;color:#3d4b57;}
.kb-drawer-body>.kb-progress{margin:0 0 12px;}
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

/* ------------------------------------------- cover, labels, corner badge */
/* the negative margins stretch the image over the card padding; the colored
   left border (age / unassigned) stays visible on purpose */
.exment-kanban .kb-cover{margin:-8px -10px 8px;overflow:hidden;border-radius:0 2px 0 0;background:#eef1f4;}
.exment-kanban .kb-cover img{display:block;width:100%;height:110px;object-fit:cover;}
.exment-kanban .kb-cover.contain img{object-fit:contain;}
.kb-labels{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:5px;}
.kb-label{font-size:10.5px;font-weight:600;border:1px solid;border-radius:9px;padding:0 7px;line-height:16px;
  white-space:nowrap;max-width:130px;overflow:hidden;text-overflow:ellipsis;}
.kb-label-bar{width:32px;height:7px;border-radius:4px;display:inline-block;}
.kb-badge{display:inline-block;background:#34495e;color:#fff;border-radius:9px;font-size:10.5px;font-weight:700;
  padding:0 7px;line-height:17px;white-space:nowrap;max-width:110px;overflow:hidden;text-overflow:ellipsis;vertical-align:middle;}
.kb-drawer-cover{margin:-16px -16px 12px;background:#eef1f4;}
.kb-drawer-cover img{display:block;width:100%;max-height:220px;object-fit:cover;}
.kb-drawer-tags{display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin:0 0 12px;}
.kb-drawer-tags .kb-labels{margin-bottom:0;}

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

/* move control of a column board: the only way through on a touch screen */
.kb-move{display:flex;align-items:center;gap:10px;margin-top:14px;background:#f4f7fa;
  border:1px solid #e0e6ec;border-radius:4px;padding:10px 12px;}
.kb-move label{margin:0;font-size:12px;font-weight:600;color:#5a6b7b;white-space:nowrap;}
.kb-move-sel{flex:1 1 auto;min-width:0;height:32px;border:1px solid #ccd4dc;border-radius:3px;
  padding:4px 8px;font-size:13px;background:#fff;color:#2c3e50;}
.kb-move-sel:focus{outline:0;border-color:#66afe9;}
/* ----------------------------------------------------------------- history */
.kb-hist{background:#f7f8fa;border:1px solid #e4e8ec;border-radius:4px;padding:12px;margin-top:14px;}
.kb-hist-head{display:flex;align-items:center;gap:8px;font-weight:600;color:#4a5562;margin-bottom:8px;}
.kb-hist-body{max-height:260px;overflow-y:auto;}
.kb-hist-list{list-style:none;margin:0;padding:0;border-left:2px solid #dde3e9;}
.kb-hist-list li{position:relative;padding:0 0 12px 14px;}
.kb-hist-list li:last-child{padding-bottom:0;}
.kb-hist-list li::before{content:'';position:absolute;left:-5px;top:5px;width:8px;height:8px;
  border-radius:50%;background:#3c8dbc;border:2px solid #fff;}
.kb-hist-line{display:flex;align-items:center;gap:8px;}
.kb-hist-act{font-weight:600;color:#2c3e50;font-size:13px;}
.kb-hist-at{margin-left:auto;font-size:11px;color:#9aa6b2;white-space:nowrap;}
.kb-hist-flow{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;color:#6b7783;margin-top:2px;}
.kb-hist-who{display:inline-flex;align-items:center;gap:4px;margin-left:auto;color:#8a949e;}
.kb-hist-cmt{margin-top:4px;font-size:12px;color:#4a5562;background:#fff;border:1px solid #e4e8ec;
  border-radius:3px;padding:5px 7px;word-break:break-word;}
.kb-hist-none{font-size:12px;color:#7d8891;}

/* the my-cards toggle, on while it is filtering */
.exment-kanban .kb-mine-btn.active{background:#3c8dbc;border-color:#367fa9;color:#fff;}
.exment-kanban .kb-mine-btn.active:hover{background:#367fa9;}

/* a status this card cannot reach, or a column already full, while dragging */
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
