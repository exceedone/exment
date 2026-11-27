<?php
// Automated pipelines that exercise import, summary, joins and workflow. They run on JSON tables: they are regression evidence that these features
// did not change for JSON tables; they are NOT evidence for physical tables (recorded explicitly).
$mk = function (string $id, string $db, string $actual, string $date) {
    return ['id' => $id, 'group' => 'Tự động (PHPUnit)', 'role' => 'Tự động (không có vai trò giao diện)', 'db' => $db,
        'prereq' => 'Snapshot DB cô lập (Stage B) khôi phục trước mỗi tệp test; bảng thử ở chế độ JSON.',
        'steps' => 'Chạy ImportStep2CompatibilityTest (pipeline import), SummarySelectTableJoinTest (tổng hợp có join select_table), V5JoinIssuesTest (join), WorkflowTaskServiceTest (workflow). PHẠM VI: kiểm hành vi trên bảng JSON, KHÔNG chạy trên bảng physical.',
        'expected' => 'Tất cả test đạt (test bị bỏ qua được nêu rõ); hành vi import/tổng hợp/join/workflow của bảng JSON không đổi.',
        'actual' => $actual, 'status' => 'PASS', 'evidence' => [], 'evdir' => null, 'tester' => 'Claude Code (PHPUnit)', 'date' => $date, 'build' => 'B2'];
};
return [
    $mk('A11', 'MariaDB 10.4.32', 'Import 1 test/24 assertion OK; Summary 4/24 OK; V5Join 2/10 OK; Workflow 85 test/2299 assertion OK, 1 bị bỏ qua.', '2026-11-26'),
    $mk('A12', 'MySQL 8.0.46', 'Import 1/24 OK; Summary 4/24 OK; V5Join 2/10 OK; Workflow 85/2299 OK, 1 bị bỏ qua.', '2026-11-26'),
    $mk('A13', 'SQL Server (LocalDB)', 'Import 1/24 OK; Summary 4/22 OK; V5Join 2/7 OK; Workflow 85/2294 OK, 3 bị bỏ qua.', '2026-11-26'),
];
