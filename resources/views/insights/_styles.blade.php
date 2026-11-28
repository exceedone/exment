{{-- The styles of the insights pages. --}}
    <style>
        .exm-insights .exm-in-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        .exm-insights .exm-in-bar form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 0; }
        .exm-insights .exm-in-bar input[type=date] { width: auto; }
        .exm-insights .exm-in-spacer { flex: 1 1 auto; }
        .exm-insights .exm-in-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .exm-insights .exm-in-tile { border: 1px solid rgba(0,0,0,.1); border-radius: 6px; padding: 10px 12px; background: var(--bs-body-bg, #fff); }
        .exm-insights .exm-in-tile .exm-in-num { font-size: 24px; font-weight: bold; line-height: 1.2; }
        .exm-insights .exm-in-tile .exm-in-sub { color: #777; font-size: 12px; }
        .exm-insights .exm-in-tile.is-warn .exm-in-num { color: #d97706; }
        .exm-insights .exm-in-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 12px; }
        .exm-insights .exm-in-card { margin: 0; }
        .exm-insights .exm-in-card .box-header { display: flex; align-items: center; gap: 8px; }
        .exm-insights .exm-in-card .box-title { flex: 1 1 auto; }
        .exm-insights .exm-in-card .exm-in-source { font-size: 12px; white-space: nowrap; }
        .exm-insights .exm-in-chart { position: relative; height: 220px; }
        .exm-insights .exm-in-help { color: #777; font-size: 12px; margin: 6px 0 0; }
        .exm-insights table.exm-in-table { width: 100%; font-size: 13px; margin-top: 4px; }
        .exm-insights table.exm-in-table td, .exm-insights table.exm-in-table th { padding: 3px 6px; border-bottom: 1px solid rgba(0,0,0,.06); }
        .exm-insights table.exm-in-table td.num, .exm-insights table.exm-in-table th.num { text-align: right; white-space: nowrap; }
        .exm-insights .exm-in-empty { color: #999; font-size: 13px; padding: 8px 0; }
        .exm-insights h3.exm-in-section { font-size: 16px; margin: 18px 0 8px; font-weight: bold; }
        .exm-insights .exm-in-feature { display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 10px; font-size: 12px; border: 1px solid rgba(0,0,0,.15); color: #888; }
        .exm-insights .exm-in-feature.is-on { border-color: #22c55e; color: #15803d; background: rgba(34,197,94,.08); }
        .exm-insights .exm-in-mark { display: inline-block; margin-left: 4px; padding: 0 6px; border-radius: 8px; font-size: 11px; color: #b45309; background: rgba(245,158,11,.12); }
        .exm-insights .exm-in-light { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; background: #94a3b8; }
        .exm-insights .exm-in-light.is-ok { background: #22c55e; }
        .exm-insights .exm-in-light.is-warning { background: #f59e0b; }
        .exm-insights .exm-in-light.is-ng { background: #ef4444; }
        @media (max-width: 560px) { .exm-insights .exm-in-grid { grid-template-columns: 1fr; } }
    </style>
