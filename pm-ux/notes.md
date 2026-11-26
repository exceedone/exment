# ExmentPM UX review (screens)

ExmentPM 1.7.17 (exment-plugins main e335c2d) on Exment 7.2.6, sample data only (3 projects WEB/OPS/APP, 32 issues, users admin/user1/user2/user3). Screenshots at 1440 px and 390 px (full page). Times are click → network idle (1440 / 390). No console errors anywhere.

Note: in full-page shots the fixed 「コメントを追加」 bar appears where the viewport bottom was (mid-page) — a capture artifact, not a layout bug.

## Per screen

- **01 PM dashboard** (2.1 / 2.4 s): project names are cut off (「コーポレー…」) although the row has room; 進捗 / 未完了 / 期限切れ / 自分 have no explanation; 「最近の更新」 is an endless list with no grouping or paging.
- **02 Project home** (2.3 / 2.9 s): on a phone the PM top bar (5 rows), the project header and the tab row (3 rows, with a second 「課題の追加」) take about 720 px before any content; the activity feed is 3,300 px long.
- **03 Issue list + filters** (0.45 / 0.4 s): 17 chips in two rows + 6 dropdowns + ツリー / 保存した条件 / CSV is dense; a filter (自分の課題) stays on when you come back, shown only by a highlighted chip; 「保存した条件」 popover runs off the left edge at 390; the copy icon under each key adds noise.
- **04 Board** (0.36 / 0.38 s): at 1440 the last column (完了) is cut off with no scroll hint; at 390 each lane shows one column plus a sliver; 「+ クイック追加 (Enter)」 in every cell (20) is the loudest thing on the page.
- **05 Gantt** (0.36 / 0.38 s): fine at 1440; at 390 only the left list shows — the chart and due-date column are cut off and nothing says it scrolls sideways.
- **06 Burndown** (0.34 s): OK.
- **07 Issue detail** (0.42 / 0.52 s): attributes are split (a strip under the title + a separate 基本情報 box); actions split between buttons and 「…その他」; comments are at the very bottom after 子課題 / 添付 / 関連課題.
- **08 Comment dock** (0.42 / 0.46 s): powerful (comment + who to notify + quick-edit fields) but looks like a second edit form — two ways to change the same fields; 「期限の時刻」 is a separate box next to 期限日.
- **09 Issue edit** (0.36 / 1.3 s, slowest phone step): long form (4,850 px on a phone); 19 fields hidden behind 「その他の項目」.
- **10 New issue** (0.1 / 0.26 s): the top-bar 「課題の追加 ▾」 asks for the project even inside a project, the green in-project button doesn't — same name, different behaviour; at 390 the header 「✓ 保存」 is clipped off the right edge (the bottom 保存 works).
- **11 List quick edit** (double-click a date): only discoverable by knowing to double-click — no hint or pencil on hover.
- **12 Project settings** (0.36 s): 11 sections; turning tabs (ガント, Wiki, ファイル…) on/off is under 「利用する機能」, hard to find.
- **13/14 Wiki** (0.4 s): clean; at 1440 the page tree takes a third of the width for 2 pages.
- **15 Files** (0.36 s): OK (needs the file server; empty here).
- **16 My tasks**: two places with different counts and wording (担当 4 / 自分の課題 4 / 自分が作成 5); no single "my work" page.
- **17 「通知」 in the PM bar** (0.33 s): it is a setting for how to receive notifications (ベルだけ / メール / Teams), not a list — the name suggests a list.
- **18/19 Exment bell** : the dropdown works at 1440; at 390 the bell is hidden (hidden-xs), so phone users have no bell; the /notify_navbar list takes 2.1–2.2 s.
- **20 Ranking** (2.0 s): OK.

## Overall top 5

1. **Phone navigation eats the first screen**: PM top bar + project header + 3-row tab bar on every page. Collapse to one row (menu + current project + 「＋」).
2. **Two of everything**: two 「課題の追加」 that behave differently; two ways to edit an issue (編集 vs the comment-dock quick edit); two notification things (PM 「通知」 setting vs the Exment bell, hidden on phones); two "my issues" entry points.
3. **Wide views clip on phones with no scroll cue** (board, gantt): one status column at a time or a list fallback, and a visible horizontal scroll bar or arrows.
4. **Issue detail splits its attributes and buries comments**: a right-hand attribute column at 1440, comments directly under the description.
5. **Slow first loads**: dashboard / project home 2.1–2.9 s, notification list 2.2 s, ranking 2.0 s (tab switches ~0.4 s); the long 「最近の更新」 feed is the likely cost — page it (e.g. 20 items + 「もっと見る」).

Timings per screen: `timings-1440.txt`, `timings-390.txt` (ms, scrollWidth / innerWidth, page height, console errors).
