<?php
// Test cases, business-regression rows and evidence rows of the handover workbook (data from the three recorded sessions + automated runs).
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$DOCX = getenv('HANDOVER_DOC_DIR') ?: __DIR__;
$EV1 = "$DOCX/evidence/2026-11-25_ui_acceptance";
$EV2 = "$DOCX/evidence/2026-11-25_ui_acceptance_physical_creation";
$EV3 = "$DOCX/evidence/2026-11-26_handover_regression";
$R1 = json_decode(file_get_contents("$EV1/results.json"), true)['results'];
$R2 = json_decode(file_get_contents("$EV2/results2.json"), true)['results'];
$R3 = is_file("$EV3/results3.json") ? json_decode(file_get_contents("$EV3/results3.json"), true)['results'] : [];
$RES = [];
foreach ([[$R1, $EV1, 'B1', '2026-11-25'], [$R2, $EV2, 'B2', '2026-11-25'], [$R3, $EV3, 'B2', '2026-11-26']] as [$list, $dir, $build, $date]) {
    foreach ($list as $x) { $x['_dir'] = $dir; $x['_build'] = $build; $x['_date'] = $date; $RES[$x['id']] = $x; }
}
function rstatus(array $RES, string $id): string { return $RES[$id]['status'] ?? 'NOT TESTED'; }
function rsum(array $x): string
{
    $ok = count(array_filter($x['checks'], fn ($c) => $c['ok']));
    $s = $x['status'] === 'NOT TESTED' ? '' : "$ok/" . count($x['checks']) . ' kiểm tra đạt';
    foreach ($x['checks'] as $c) { if (!$c['ok']) { $s .= "\n✗ " . $c['what']; } }
    foreach (array_slice($x['notes'] ?? [], 0, 2) as $n) { if (strpos($n, 'INTENDED') === 0) { $s .= "\nKhác biệt có chủ đích (L5): " . trim(substr($n, 9), ': '); } }
    return trim($s);
}

$DBN = 'MariaDB 10.4.32';
// id => [group, role, prerequisites/data, steps, expected]
$META = [
 'S01' => ['Chuyển đổi (giao diện)', 'System Administrator', 'Snapshot nghiệm thu: bảng custom_value_edit (104 bản ghi, 12 cột), JSON; chuyển đổi bật (kiểm thử).', "1. Đăng nhập admin.\n2. Mở /admin/storage_conversion.\n3. Mở chi tiết bảng vượt giới hạn kích thước hàng.", 'Tiêu đề 「テーブル保存方式の変換」 và nhãn tiếng Nhật; bảng hệ thống ghi 「システムテーブル（変換対象外）」; bảng không đủ điều kiện hiển thị 「変換の前提条件を満たしていません。」 (row_size_exceeded).'],
 'S02' => ['Chuyển đổi (giao diện)', 'System Administrator', 'Như S01.', "1. Từ danh sách bấm 詳細.\n2. Bấm 「一覧へ戻る」.", 'Điều hướng PJAX không tải lại trang; các nhãn 事前チェック / 進捗 / 操作 / 読み取り元 / 状態 / 世代 hiển thị; không có lỗi JavaScript.'],
 'S03' => ['Chuyển đổi (giao diện)', 'System Administrator', 'Bảng ở trạng thái JSON.', '1. Xem các hành động ở trạng thái JSON.\n2. Gửi trực tiếp các hành động không hợp lệ.', 'Chỉ 「物理列を準備する」 được cung cấp; hành động không hợp lệ bị từ chối, DB không đổi.'],
 'S04' => ['Production-like', 'System Administrator', 'Máy chủ KHÔNG bật chuyển đổi (mô phỏng production).', '1. Mở chi tiết bảng trên máy chủ không bật.', 'Hiển thị 「変換の実行は現在利用できません。」; mọi nút bị vô hiệu.'],
 'S05' => ['Phân quyền', 'Người dùng hạn chế (user2)', 'Người dùng thường chỉ thấy 10/104 bản ghi.', "1. Đăng nhập user2.\n2. Mở màn hình chuyển đổi.\n3. Gửi POST 物理列を準備する.", 'Chỉ thấy dữ liệu được phép; màn hình chuyển đổi và POST bị từ chối với 「権限がありません。」; DB không đổi.'],
 'S06' => ['Nghiệp vụ trên JSON', 'System Administrator', 'Bảng JSON trước chuyển đổi.', "1. Nhập sai giá trị vào form.\n2. Tạo và sửa bản ghi.\n3. Chụp bộ so sánh trước khi chuyển (danh sách 4 view, chi tiết, export).", 'Form báo lỗi nhập sai; tạo/sửa thành công; bộ so sánh được ghi lại.'],
 'S07' => ['Chuyển đổi (giao diện)', 'System Administrator', 'Queue worker thật chạy.', "1. 物理列を準備する.\n2. 二重書き込みを開始する.", 'Thông báo 「処理を登録しました。」; DB: provisioned (12 cột pcol_* + value_original), rồi dualwrite generation 1.'],
 'S08' => ['Phục hồi', 'System Administrator', 'Dừng worker trước khi bấm.', "1. Dừng worker.\n2. Bấm 「バックフィルを開始する」.\n3. Chạy lại worker.", 'UI báo đã đăng ký; job nằm trong hàng đợi; sau khi worker chạy lại, backfill tự hoàn tất (không mất, không chạy trùng).'],
 'S09' => ['Chuyển đổi (giao diện)', 'System Administrator', 'Tiếp S08.', "1. バックフィル hoàn tất.\n2. 検証を開始・再開する.\n3. 確認待ちにする; đọc báo cáo L5.\n4. Tick xác nhận, bấm 「物理列からの読み取りに切り替える」.", 'Verification passed; báo cáo L5 hiển thị; DB: storage_mode = physical, storage_state = physical_read, generation 2.'],
 'S10' => ['Hồi quy nghiệp vụ', 'System Administrator', 'Bảng đã chuyển; 4 view (lọc số, lọc ngày, sắp xếp, tất cả cột); 3 bản ghi có giá trị không hợp kiểu.', "1. Đọc danh sách của 4 view, chi tiết, export trước và sau chuyển.\n2. So sánh.", 'Dữ liệu hợp lệ giống hệt; giá trị không hợp kiểu vẫn hiển thị nguyên văn; khác biệt có chủ đích (L5) và thứ tự bản ghi cùng khoá được ghi riêng, không tính là lỗi.'],
 'S11' => ['Nghiệp vụ trên physical + phân quyền', 'System Administrator, user2', 'Bảng physical_read.', "1. Tạo / sửa bản ghi bằng form.\n2. Người dùng hạn chế xem danh sách.", 'Dữ liệu ghi vào cột vật lý và bóng JSON; người dùng hạn chế thấy đúng các dòng được phép.'],
 'S12' => ['Quay lại', 'System Administrator', 'Bảng physical_read.', "1. Rollback không xác minh.\n2. Xác minh rồi rollback.", 'Đọc từ JSON trở lại; cột không bị xoá; điều kiện không đủ thì bị từ chối.'],
 'S13' => ['Quay lại', 'System Administrator', 'Tiếp S12.', "1. 変換を中止する.\n2. ステージングデータを削除する (nhập tên bảng).\n3. 物理列を削除してJSONに戻す.", 'Từng bước đúng thứ tự; thao tác sai điều kiện bị từ chối; cuối cùng JSON thuần, dữ liệu JSON nguyên vẹn.'],
 'S14' => ['Chẩn đoán', 'System Administrator', 'Một máy chủ cấu hình sai (không phát open-admin.js).', '1. Mở trang trên máy chủ sai.\n2. So sánh với máy chủ đúng.', 'Lỗi "admin is not defined" tái hiện khi tài nguyên open-admin.js không được phát; không phải lỗi sản phẩm.'],
 'S15' => ['Phục hồi', 'System Administrator', 'Bảng lớn đủ để dừng đúng giữa một lô.', 'Kill worker đang xử lý một lô.', 'Job tiếp tục từ checkpoint khi chạy lại.'],
 'S16' => ['Ngoài phạm vi', 'System Administrator', 'Chức năng JSON cleanup.', 'Chạy JSON cleanup.', 'Giá trị đã chuyển được dọn khỏi JSON.'],
 'S17' => ['Ngoài phạm vi', 'System Administrator', 'Cơ chế chặn kỹ thuật người ghi trực tiếp.', 'Thử ghi trực tiếp bằng SQL/plugin.', 'Bị chặn về mặt kỹ thuật.'],
 'P01' => ['Tạo bảng', 'System Administrator', 'Chuyển đổi bật (máy chủ A) và tắt (máy chủ B).', "1. Mở /admin/table/create trên máy chủ B: không có ô 保存方式.\n2. Trên máy chủ A: tạo bảng mặc định (JSON) và bảng chọn 物理列.", 'Ô 保存方式 chỉ hiện khi khả dụng, mặc định JSON; bảng physical: physical_read, generation 2, value_original có, bảng rỗng.'],
 'P02' => ['Quản lý cột', 'System Administrator', 'Bảng physical rỗng.', "1. Thêm 4 cột (integer, text, date, select) bằng màn hình カスタム列設定.", 'Mỗi cột đơn trị có cột vật lý pcol_*; không lỗi.'],
 'P03' => ['Nghiệp vụ trên physical', 'System Administrator', 'Bảng physical có 4 cột.', "1. Tạo bản ghi đầu bằng form.\n2. Xem danh sách và chi tiết.", 'Giá trị ở cột vật lý và bóng JSON; giá trị công khai = JSON.'],
 'P04' => ['Quản lý cột', 'System Administrator', 'Bảng physical có dữ liệu.', "1. Thêm cột khi có dữ liệu.\n2. Sửa nhãn cột.\n3. Bật 複数選択を許可する cho cột có giá trị.\n4. Xoá cột.", 'Thêm/sửa/xoá thành công, dữ liệu giữ nguyên; chuyển đơn→đa trị khi cột có giá trị bị từ chối với 「操作は拒否されました。」 và không đổi gì.'],
 'P05' => ['Phục hồi', 'System Administrator', 'Hai bảng ở trạng thái 初期化中 (khởi tạo dở).', "1. Mở dữ liệu và thêm cột.\n2. Bấm 初期化を再実行する (bảng 1).\n3. Bấm 初期化を中止してJSONに戻す (bảng 2).", 'Dữ liệu và thêm cột bị chặn, quản trị dùng được; chạy lại → physical_read đủ cột; huỷ → JSON, không còn cột vật lý.'],
 'P06' => ['Copy bảng', 'System Administrator', 'Bảng nguồn JSON có 2 cột.', "1. Kiểm hộp thoại copy có ô 保存方式.\n2. Copy chọn 物理列.", 'Bản copy: physical_read, chỉ lược đồ (0 bản ghi), 2 cột vật lý; nguồn vẫn JSON; ghi được bản ghi vào bản copy.'],
 'P07' => ['Import template', 'System Administrator', 'Hai máy chủ như P01.', 'Mở màn hình import template trên hai máy chủ.', 'Ô 保存方式 có trên máy chủ bật, không có trên máy chủ tắt. (Import bấm thật chưa thực hiện.)'],
 'P08' => ['Quay lại', 'System Administrator', 'Bảng physical tạo trực tiếp, 2 bản ghi, không có lịch sử chuyển đổi.', "1. Rollback khi chưa xác minh.\n2. 検証 → passed.\n3. Rollback, huỷ, dọn staging, gỡ cột.", 'Rollback bị từ chối khi chưa xác minh; sau xác minh passed rollback thành công; mỗi bước đúng điều kiện; cuối cùng JSON thuần, 2 bản ghi nguyên vẹn.'],
 'P09' => ['Phân quyền', 'Người dùng hạn chế (user2)', 'Bảng 初期化中.', "1. Mở màn hình chuyển đổi.\n2. Gửi POST initialize / cancel_initialize / reconcile.", 'Bị từ chối (権限がありません。); trạng thái lưu trữ không đổi.'],
 'H01' => ['Hồi quy nghiệp vụ', 'System Administrator', 'Hai bảng 12 bản ghi (ngày khác nhau): một bảng JSON, một bảng tạo trực tiếp physical; 5 view (lọc số, lọc ngày, sắp xếp, tất cả cột, tổng hợp).', "1. Đọc danh sách các view, chi tiết, tìm kiếm, sắp xếp, export của bảng JSON.\n2. Đọc cùng các màn hình của bảng physical tạo trực tiếp.\n3. So sánh.", 'Bảng physical tạo trực tiếp cho kết quả giống hệt bảng JSON (danh sách, tổng hợp, chi tiết, tìm kiếm, sắp xếp, export).'],
 'H02' => ['Chuyển đổi (giao diện)', 'System Administrator', 'Bảng JSON 12 bản ghi; queue worker thật.', "1. 物理列を準備する → 二重書き込みを開始する → バックフィルを開始する.\n2. 検証を開始・再開する → 確認待ちにする (xem L5).\n3. 物理列からの読み取りに切り替える.", 'Mỗi bước đạt trạng thái mong đợi trong DB; verification passed; cuối cùng physical_read (storage_mode = physical).'],
 'H03' => ['Hồi quy nghiệp vụ', 'System Administrator', 'Bảng vừa chuyển ở H02.', 'Đọc lại các màn hình như H01 và so sánh với kết quả trước chuyển.', 'Danh sách, lọc, sắp xếp, tổng hợp, chi tiết, tìm kiếm, export giống hệt trước chuyển.'],
 'H04' => ['Nghiệp vụ (CRUD)', 'System Administrator', 'Bảng đã chuyển và bảng tạo trực tiếp.', "1. Tạo bản ghi bằng form.\n2. Sửa giá trị.\n3. Xoá.", 'Bản ghi xuất hiện/đổi/biến mất đúng trên danh sách và chi tiết; DB: giá trị công khai = bóng JSON.'],
 'H05' => ['Nghiệp vụ (import)', 'System Administrator', 'Bảng đã chuyển và bảng tạo trực tiếp; tệp CSV 2 bản ghi theo mẫu export (header + dòng nhãn).', "1. Gọi hộp thoại インポート của bảng.\n2. Gửi tệp CSV.\n3. Xem danh sách.", 'Import hoàn tất; 2 bản ghi được thêm, hiển thị đúng giá trị; DB nhất quán.'],
];

// ------------------------------------------------------------------ automated runs (PHPUnit) - recorded outputs of 2026-11-25/26
$AUTO = [
 ['A01', 'Tự động (PHPUnit)', 'StoragePhysicalCreationTest: tạo bảng physical, khởi tạo dở/huỷ, vòng đời cột, copy, import template, quay lại JSON, phân quyền', 'MariaDB 10.4.32', 'PASS', 'OK (18 tests, 119 assertions)', '2026-11-25'],
 ['A02', 'Tự động (PHPUnit)', 'StoragePhysicalCreationTest', 'MySQL 8.0.46', 'PASS', 'OK (18 tests, 119 assertions)', '2026-11-25'],
 ['A03', 'Tự động (PHPUnit)', 'StoragePhysicalCreationTest', 'SQL Server (LocalDB)', 'PASS', 'OK (18 tests, 119 assertions)', '2026-11-25'],
 ['A04', 'Tự động (PHPUnit)', 'StorageStageBIntegrationTest: vòng đời chuyển đổi, hủy/dọn/gỡ, index tự động, Kanban và export/người dùng hạn chế ở physical_read, cache liên request', 'MariaDB 10.4.32', 'PASS', 'OK, 16 tests, 412 assertions, 1 bị bỏ qua (test chỉ dành cho SQL Server)', '2026-11-25'],
 ['A05', 'Tự động (PHPUnit)', 'StorageStageBIntegrationTest', 'MySQL 8.0.46', 'PASS', 'OK, 16 tests, 412 assertions, 1 bị bỏ qua (chỉ SQL Server)', '2026-11-26'],
 ['A06', 'Tự động (PHPUnit)', 'StorageStageBIntegrationTest', 'SQL Server (LocalDB)', 'PASS', 'OK (16 tests, 421 assertions)', '2026-11-26'],
 ['A07', 'Tự động (PHPUnit)', 'Hồi quy JSON: cài đặt bảng, template (thumbnail, xoá, export), copy bảng, console, form/view, locale, CustomTable, query key, provider kích hoạt cô lập', 'MariaDB 10.4.32', 'PASS', 'Tất cả tệp OK', '2026-11-25'],
 ['A08', 'Tự động (PHPUnit)', 'Hồi quy JSON (cùng nhóm A07)', 'MySQL 8.0.46', 'PASS', 'Tất cả tệp OK (IsolatedConversionExecutionProviderTest đạt khi không đặt biến bật chuyển đổi)', '2026-11-25'],
 ['A09', 'Tự động (PHPUnit)', 'Hồi quy JSON (cùng nhóm A07)', 'SQL Server (LocalDB)', 'FAIL', "1 lỗi: FormViewSettingsCoverageTest::testViewEditKeepsASortTarget... - dữ liệu test (tên view) dài hơn cột view_view_name (String or binary data would be truncated). Lỗi nằm ở việc chèn dữ liệu test, không liên quan thay đổi này; CHƯA đối chứng trên bản trước thay đổi.", '2026-11-25'],
 ['A10', 'Tự động (PHPUnit)', 'IsolatedConversionExecutionProviderTest (production binding vẫn UnavailableConversion) khi không đặt biến bật chuyển đổi', 'SQL Server (LocalDB)', 'NOT TESTED', 'Lần chạy đầu bị đặt biến bật chuyển đổi nên báo lỗi; đã đạt khi chạy đúng điều kiện trên MariaDB và MySQL 8, chưa chạy lại trên SQL Server.', '2026-11-25'],
 ['A11', 'Tự động (PHPUnit)', 'StorageMixedModeTest: 4 tổ hợp cha/con JSON-physical - lọc, sắp xếp sau join (SearchService, CustomView, header grid), lookup, giá trị public, restricted user (user2 chỉ xem dòng được chia sẻ)', 'MariaDB 10.4.32', 'PASS', 'OK (2 tests, 30 assertions); 4 tổ hợp giống hệt nhau', '2026-11-26'],
 ['A12', 'Tự động (PHPUnit)', 'StorageMixedModeTest (như A11)', 'MySQL 8.0.46', 'PASS', 'OK (2 tests, 30 assertions); 4 tổ hợp giống nhau. where value->memo=ALPHA rỗng ở cả 4 (JSON-path phân biệt hoa/thường)', '2026-11-26'],
 ['A13', 'Tự động (PHPUnit)', 'StorageMixedModeTest (như A11)', 'SQL Server (LocalDB)', 'PASS', 'OK (2 tests, 30 assertions) sau khi không lưu id "abc" bằng bypass. FINDING có sẵn: id không phải số trong JSON con làm join lỗi 22018; join_multi_rows lỗi cú pháp "near as" ở cả 4 tổ hợp (chưa xử lý)', '2026-11-26'],
 ['A14', 'Tự động (PHPUnit)', 'Sửa sắp xếp cột JSON của bảng cha sau join: RelationTableJoinQueryTest, V5JoinIssuesTest, SummarySelectTableJoinTest', 'MariaDB 10.4.32', 'PASS', 'RelationTableJoin 11/36, V5JoinIssues 2/10, SummarySelectTableJoin 4/24 (chạy lại 2026-11-27 sau sửa, vẫn PASS cùng MariaDB)', '2026-11-27'],
 ['A15', 'Tự động (PHPUnit)', 'RelationTableJoinQueryTest, V5JoinIssuesTest, SummarySelectTableJoinTest', 'MySQL 8.0.46', 'PASS', 'RelationTableJoin 11/36, V5JoinIssues 2/10, SummarySelectTableJoin 4/24 (chạy lại 2026-11-27 sau sửa, vẫn PASS cùng MariaDB)', '2026-11-27'],
 ['A16', 'Tự động (PHPUnit)', 'RelationTableJoinQueryTest (đã chuyển sang test di động, dùng DriverSql; giữ assert alias, binding, kết quả, DDL)', 'SQL Server (LocalDB) hyb_ss_test', 'PASS', 'OK (11 tests, 36 assertions) sau khi sửa SqlServerGrammar/RelationTable; trước đó FAIL 11/11 do assert kiểu MySQL', '2026-11-27'],
 ['A17', 'Tự động (PHPUnit)', 'DrainAttestationTest: measures tùy chọn; thiếu người xác nhận/mã xác nhận thì bị từ chối', 'MariaDB, MySQL 8, SQL Server', 'PASS', 'OK (2 tests, 3 assertions) trên cả ba DB', '2026-11-26'],
 ['A18', 'Tự động (PHPUnit)', 'StorageCacheVisibilityTest, StoragePhysicalCreationTest, StorageConversionHttpTest, StorageStageBIntegrationTest sau thay đổi', 'MariaDB, MySQL 8, SQL Server', 'PASS', 'CacheVisibility 2, PhysicalCreation 20, Http 8 (1 skip), StageB 16 (1 skip; SQL Server 0 skip). Mỗi file chạy sau khi khôi phục snapshot', '2026-11-26'],
 ['A19', 'Đo collation', 'Collation JSON-path / cột ảo / cột typed', 'MySQL 8.0.46, MariaDB 10.4.32', 'PASS', 'MySQL 8: json->> = utf8mb4_bin (phân biệt hoa/thường, dấu), cột ảo/typed = utf8mb4_unicode_ci. MariaDB: json_extract = utf8_general_ci. Tài liệu 02_ArchitectureDesign §11.3 đã sửa', '2026-11-26'],
 ['A20', 'Đo collation', 'Collation SQL Server: json_value vs cột nvarchar typed (bảng scratch)', 'SQL Server (LocalDB) hyb_ss_test (SQL_Latin1_General_CP1_CI_AS) và hyb_ss_jp_test (Japanese_CI_AS)', 'PASS', 'Hai đường giống nhau ở cả hai DB: = \'ALPHA\' ra alpha,ALPHA; like, sort giống (alpha,ALPHA,Beta,ea,éa). json_value kế thừa collation của DB. Không có khác biệt kiểu MySQL 8. Probe: C:\hyb_ss_work\collation_probe_ss.php', '2026-11-27'],
 ['A21', 'Tự động (PHPUnit)', 'Id tham chiếu không hợp lệ (\'abc\') ở bảng con: join đơn trị INNER/LEFT, filter, getValue/hiển thị, restricted user; giá trị public vẫn là \'abc\'', 'MariaDB 10.4.32, MySQL 8.0.46, SQL Server (LocalDB)', 'PASS', 'StorageMixedModeTest 2 tests/36 assertions trên cả ba DB; INNER bỏ dòng \'abc\', LEFT giữ với cha NULL. Sửa: TRY_CAST(.. AS bigint) trong join, so sánh text trong setQuerySelectTable, CustomTable::getValueModel/hasCustomValueInDB trả null/false cho id không phải số', '2026-11-27'],
 ['A22', 'Tự động (PHPUnit)', 'Join cột đa trị: 1 khác 11, rỗng/thiếu, phần tử không hợp lệ; giữ hành vi trùng dòng (không DISTINCT)', 'MariaDB, MySQL 8, SQL Server', 'PASS', '4 tổ hợp JSON/physical cho cùng kết quả: LEFT c1,c1,c2,c3,c4,c5,c6; INNER c1,c1,c2,c6. Nguyên nhân gốc SQL Server: bảng tự tham chiếu trùng alias (lỗi cú pháp) và json_value trả NULL cho mảng JSON; sửa bằng EXISTS + STRING_SPLIT + COALESCE(JSON_QUERY, JSON_VALUE)', '2026-11-27'],
 ['A23', 'Tự động (PHPUnit)', 'Bộ liên quan sau sửa: DrainAttestation 2/3, V5JoinIssues, SummarySelectTableJoin, CacheVisibility, PhysicalCreation, ConversionHttp, StageB, Mixed', 'MariaDB, MySQL 8, SQL Server', 'PASS', 'Mỗi file chạy sau khi khôi phục snapshot. Giữ nguyên số lượng: CacheVisibility 2/23, PhysicalCreation 20/124, Http 8 (1 skip), StageB 16 (1 skip; SQL Server 0 skip, 421 assertion), V5JoinIssues 2, SummarySelectTableJoin 4', '2026-11-27'],
 ['A24', 'UI/PJAX (trình duyệt thật)', 'Trang /admin/storage_conversion/41 tải lại và PJAX quay về danh sách trên build hiện tại, DB MariaDB hyb_p1_test đã khôi phục dump UI (170 bảng, #41 hyb_json_conv ở physical_read, 10 login_users)', 'MariaDB (hyb_p1_host, cổng 8085)', 'PASS', 'Trang hiển thị đúng; queue worker khởi động lại (PID 33048) trên code hiện tại. Phạm vi giới hạn ở màn hình chuyển đổi', '2026-11-27'],
 ['A25', 'UI/HTTP (trình duyệt thật + curl)', 'Danh sách bảng con (hyb_mxc_pp99167, physical/physical) sắp xếp theo cột bảng cha bằng view đã lưu; user2 (restricted: chỉ xem dòng được chia sẻ) đăng nhập qua HTTP', 'MariaDB 10.4.32 hyb_p1_test (cổng 8085)', 'PASS', 'Admin: 6 dòng, thứ tự 5,6,2,4,1,3 (dòng id tham chiếu \'abc\' vẫn hiện, không lỗi 500). user2: danh sách chỉ 1,3,4,6 theo thứ tự 6,4,1,3; bảng cha 1,2,3; mở dòng 3 được, dòng 2 bị chặn; không có lỗi SQL. MySQL 8 và SQL Server: NOT TESTED (không có server UI)', '2026-11-27'],
    ['A26', 'Toàn vẹn dữ liệu: xoá dòng còn bị tham chiếu', 'CustomTable::validateValueDestroy với tham chiếu chỉ nằm trong cột select_table đa trị (khớp từng phần tử, không khớp tiền tố; dòng không được tham chiếu vẫn xoá được)', 'MariaDB, MySQL 8, SQL Server LocalDB (R4ExplicitJsonLookupTest)', 'PASS', 'R4ExplicitJsonLookupTest sau khi port sang DriverSql: MariaDB 10/10, MySQL 8 10/10, SQL Server 10/10 (assert SQL, binding, phạm vi, kết quả). UI xoá qua trình duyệt: NOT TESTED', '2026-11-28'],
    ['A27', 'Toàn vẹn dữ liệu: xoá dòng bị tham chiếu bởi dòng bị ẩn', 'StorageReferenceIntegrityTest (4 tổ hợp cha/con JSON-physical): xoá web đơn, xoá web hàng loạt, xoá webapi đơn/hàng loạt bởi user2 hạn chế; tham chiếu từ dòng con bị ẩn (select_table đơn trị, đa trị) và từ bảng khác trỏ tới con bị ẩn của dòng bị xoá; đối chứng dòng không được tham chiếu vẫn xoá được', 'MariaDB 10.4.32, MySQL 8, SQL Server LocalDB (build hiện tại, DB cô lập)', 'PASS', 'Mỗi tổ hợp: dòng bị tham chiếu giữ nguyên, phản hồi chỉ có thông báo chung reference_error (không lộ tên dòng bị ẩn), xoá hàng loạt không xoá dòng nào, webapi trả 400. Mỗi DB 4/4 tổ hợp (43/46/46/49 assert). Kiểm đột biến: bỏ withoutGlobalScope trong checkReferenced thì test FAIL. Nguyên nhân gốc: checkReferenced chạy với CustomValueModelScope nên bỏ sót dòng tham chiếu mà user không thấy (đã sửa)', '2026-11-28'],
    ['A28', 'Import: tra cứu parent_id theo nhãn (ParentItem::getImportValue)', 'CustomTableAction::import với cài đặt parent_id -> label, cha và con trùng id/nhãn chéo nhau; khớp, không khớp (nhãn chỉ có ở bảng con, cha đã xoá mềm, nhãn không có), cha tạo sau khi chuyển đổi, user2 hạn chế (cha bị ẩn)', 'MariaDB 10.4.32, MySQL 8, SQL Server LocalDB (build hiện tại, DB cô lập)', 'PASS', 'Qua caller thật, lookup chạy trên đúng bảng cha (DataImportExportServiceTrait dựng ParentItem từ cột parent_type) ở cả 4 tổ hợp JSON/physical; không tái hiện lỗi "tra sai bảng". Test ghim cũ testParentImportLookupUsesTheJsonPathOfTheSettingKey dựng ParentItem trên bảng con nên không phản ánh caller. Giới hạn chưa kiểm/chưa sửa: parent_type trong file không được đối chiếu với quan hệ đã cấu hình (nếu ghi tên bảng đang import thì tra trên bảng đó) - NOT TESTED, chưa đổi hợp đồng validation', '2026-11-28'],
    ['A29', 'Hồi quy helper SQL Server (list expression) và join', 'StorageMixedModeTest, RelationTableJoinQueryTest, R4, SummarySelectTableJoinTest trên SQL Server', 'SQL Server LocalDB (build hiện tại, DB cô lập)', 'PASS', 'SQL Server: StorageMixedModeTest 2/2, RelationTableJoinQueryTest 11/11, SummarySelectTableJoinTest 4/4, R4 10/10. 4 file còn lại (ReadKey, Workflow, Kanban, ImportParentLookup) đã xử lý ở A30', '2026-11-28'],
    ['A30', 'Phân loại và xử lý 4 file test SQL Server (ReadKey, Workflow, Kanban, ImportParentLookup)', 'R3MultiValueReadKeyTest; R3MultiValueWorkflowAuthorityTest (snapshot fixture p10 dựng bằng provision_p10.ps1); KanbanMineKeysTest (so với baseline HEAD của SqlServerGrammar/CustomTable, cùng snapshot snap_stageb); ImportParentLookupTest; chạy lại ReadKey/Kanban/ImportParentLookup trên MariaDB và MySQL 8; kiểm không có test bị skip', 'MariaDB 10.4.32, MySQL 8, SQL Server LocalDB (build hiện tại, DB cô lập)', 'PASS', 'Cả 4 file đã giải quyết, đều là lỗi test/môi trường, KHÔNG phải hồi quy. ReadKey: port DriverSql (SQL Server 2 tests/26 assertions, MariaDB 2/26, MySQL 8 2/26). Workflow: fixture p10 dựng tái lập bằng C:\hyb_ss_work\provision_p10.ps1 (restore snap_stageb, chạy provision-hybrid-p1-fixture.php --yes, dump snap_fixture), SQL Server 5/40 không skip. Kanban: assert kiểu MySQL (FIND_IN_SET, backtick, scope tuple-IN), lọc log theo FIND_IN_SET, và cột index SQL Server là cột computed json_value() nên danh sách JSON không đọc được ở chế độ indexed (chế độ JSON dùng JSON_QUERY đọc được) nên test theo driver; SQL Server 7/39, MariaDB 7/35, MySQL 8 7/35. Baseline HEAD (SqlServerGrammar/CustomTable gốc, cùng snapshot): helper cũ dùng json_value thuần nên chế độ JSON không đọc được danh sách (lỗi có sẵn, được sửa bởi thay đổi helper chung), không phải hồi quy. ImportParentLookup: chỉ hỗ trợ mysql/mariadb theo thiết kế (isSupported); trên SQL Server resolve() luôn null, caller thật processCustomValue chạy truy vấn gốc từng dòng; test ghim fallback (null, batchCount 0, đáp án bằng truy vấn gốc, 1 truy vấn/dòng): SQL Server 10/2675, MariaDB 10/2680, MySQL 8 10/2680. Lỗi sản phẩm có sẵn, ghi nhận riêng, chưa sửa: truy vấn gốc ParentItem::getImportValue trên SQL Server với parent_id là số (vd 5300) so với cột text sinh json_value = 5300 gây Conversion failed nvarchar sang int khi có giá trị chữ; dòng số này không chạy trên SQL Server trong test', '2026-11-29'],
    ['A31', 'Sửa lỗi SQL Server: parent_id số bị so sánh với cột text (ParentItem::getImportValue)', 'Qua caller thật DataImportExportService::processCustomValue: input số PHP (5300) và chuỗi số ("5300"), khớp/không khớp, cột text lẫn chữ và số, số 0 đứng đầu ("05301"), cha đã xoá mềm, user2 hạn chế; ImportParentLookupTest (11 tests) và StorageReferenceIntegrityTest (4 tests)', 'SQL Server LocalDB, MariaDB 10.4.32, MySQL 8 (build hiện tại, DB cô lập)', 'PASS', 'Nguyên nhân: binding số (int/float) khiến SQL Server chuyển cả cột json_value (nvarchar) sang int, lỗi Conversion failed khi có chữ. Sửa: trên SQL Server, int/float được bind thành chuỗi (strval) trong ParentItem::getImportValue; MySQL/MariaDB giữ nguyên. Baseline (HEAD) tái hiện đúng lỗi; khôi phục file xác minh bằng hash. Kết quả: SQL Server 11/2698 và 4/184; MariaDB 11/2703 và 4/184; MySQL 8 11/2703 và 4/184. Ngữ nghĩa số 0 đứng đầu: chuỗi so sánh dạng text (05301 chỉ khớp 05301; 5301 không khớp), số PHP 5301 cũng không khớp "05301" trên SQL Server (MySQL so sánh số nên khớp) - khác biệt đã ghi nhận. Chưa kiểm: UI import thật qua trình duyệt; batching SQL Server không làm trong lô này', '2026-11-29'],
    ['A32', 'Import: parent_type ngoài quan hệ cấu hình (DataImportExportServiceTrait::processCustomValue)', 'Qua CustomTableAction::import (4 tổ hợp JSON/physical): parent_type hợp lệ, bảng không liên quan (nhãn/ID chồng nhau), chính bảng đang import, bảng không tồn tại, thiếu/rỗng parent_type, file lẫn dòng hợp lệ và dòng sai, sửa/xoá quan hệ của dòng đã có, user2 không có quyền; StorageImportParentTypeTest (4 tests)', 'SQL Server LocalDB, MariaDB 10.4.32, MySQL 8 (build hiện tại, DB cô lập)', 'PASS', 'Hợp đồng: bảng con chỉ có MỘT bảng cha cấu hình (quan hệ 1-n); validateValue() chỉ nhận parent_type = bảng cha đó hoặc rỗng, bảng không có cha thì không nhận gì; kiểm trước khi lưu nên file bị từ chối không ghi gì. Đã kiểm: bảng không liên quan/chính nó bị từ chối đúng, không ghi dữ liệu, KHÔNG phải lỗ hổng tạo quan hệ ngoài cấu hình. LỖI XÁC NHẬN: parent_type là bảng không tồn tại (hoặc rỗng kèm parent_id nhãn) làm ParentItem::getItem(null) ném ErrorException (crash thay vì thông báo validation). Sửa: processCustomValue chỉ dựng ParentItem khi tìm được bảng, ngược lại bỏ tra cứu để validateValue từ chối bình thường. Kết quả: SQL Server, MariaDB, MySQL 8 đều OK 4 tests/160 assertions; StorageReferenceIntegrityTest 4/184 và ImportParentLookupTest (11) vẫn PASS; baseline HEAD tái hiện ErrorException, file khôi phục xác minh bằng hash. Chưa xác minh/không sửa: parent_id số kèm parent_type rỗng được chấp nhận và lưu parent_id không có type (không tạo quan hệ vì hasParent cần cả hai; đổi sẽ đổi hợp đồng validation); user2 chỉ kiểm trường hợp không có quyền bảng con, quyền theo bản ghi cha đã có ở A28', '2026-11-29'],
    ['A33', 'Join tổng hợp với pivot select_table ĐA TRỊ (tách khỏi V5-2 đơn trị đã sửa) - đã sửa (A35)', 'RelationTable::setSummaryChildJoinSelectTable() với pivot đa trị: JSON (4 tổ hợp cha/con JSON-physical) và có index (bảng con JSON); trùng phần tử, 1 vs 11, abc/rỗng/thiếu, cha không có con, LEFT/INNER, user2 không thấy cha p2; SummarySelectTableJoinTest (4 tests), StorageMixedModeTest (3 tests) + RelationTableJoinQueryTest, V5JoinIssuesTest, R3MultiValueReadKeyTest, R3MultiValueWorkflowAuthorityTest', 'MariaDB 10.4.32, MySQL 8, SQL Server LocalDB (build hiện tại, DB cô lập); lịch sử: MariaDB 2026-11-24', 'PASS', 'KẾT QUẢ MỚI (test tự động): PASS trên cả 3 driver (Summary 4/29, 4/29, 4/27; StorageMixedModeTest 3/72 mỗi driver; các file join/read-key/workflow đều OK). Nguyên nhân: (1) _getTableExment() trả Expression của joinSub => TypeError; (2) trên SQL Server sub query chọn khoá JSON mảng bằng json_value => NULL, tổng mọi cha NULL. Sửa: _getTableExment trả chuỗi rỗng khi không phải chuỗi; SQL Server chọn/nhóm khoá đa trị bằng COALESCE(JSON_QUERY, JSON_VALUE). Hợp đồng: mỗi con tính một lần cho mỗi cha (phần tử lặp = 1), không DISTINCT. UI: bằng chứng trình duyệt thật ở A36 (MariaDB 8085 only; MySQL 8 và SQL Server browser = NOT TESTED). BẰNG CHỨNG LỊCH SỬ (giữ nguyên): trước sửa, MariaDB mức API ghi nhận TypeError (test ghim testMultiplePivotSummaryJoinFailsToBuildAsBefore, nay thay bằng test kỳ vọng đúng); SQL Server lần chạy đầu sau khi hết TypeError cho tổng NULL trước khi sửa lớp 2', '2026-11-27'],
    ['A34', 'Đối chiếu backlog: select_primary_key không hợp lệ khi import (CustomTable::getMatchedCustomValues với value.<cột không tồn tại>)', 'Truy vết caller: GridBase::import (select_primary_key từ request, server không kiểm lại) và PatchDataCommand (khoá cố định); R4ExplicitJsonLookupTest ghim ErrorException ở mức hàm', 'Chưa chạy qua HTTP trên DB nào', 'NOT TESTED', 'Giao diện chỉ cho chọn khoá mặc định + cột unique nên đường thông thường không sinh khoá thiếu cột. Ngoại lệ là hành vi hiện hành, không làm sai dữ liệu/quyền; chỉ xảy ra khi request chỉnh tay hoặc form cũ sau khi cột bị xoá => 500 thay vì thông báo validation. Phân loại: mối lo chưa kiểm chứng, mức thấp; không sửa', '2026-11-29'],
    ['A36', 'Nghiệm thu trình duyệt thật: view tổng hợp pivot select_table ĐA TRỊ (A35) + cảnh báo "cột vật lý chưa đủ" bảng 39 + failed_jobs 4/5', 'Trình duyệt thật (Playwright) trên MariaDB 8085: tạo/lưu view 181 (nhóm theo ID cha, 件数 của parent_multi : ID), mở bằng admin và user2; fixture thêm con 101 [3,3] (lặp), 102 [1,10], 103 [2,zzz], cha 11 không con; so với số tính độc lập bằng PHP từ JSON (array_unique)', 'MariaDB 10.4.32 (UI); MySQL 8 và SQL Server: NOT TESTED (không dựng UI server)', 'PASS', 'PHẠM VI ĐÓNG: test tự động trên 3 DB (A33) + nghiệm thu trình duyệt thật trên MariaDB (A36); build B3 (cây mã commit 31d48bb4d). NOT TESTED: trình duyệt MySQL 8 và SQL Server; pivot đa trị có index trên bảng con physical. BẰNG CHỨNG TRÌNH DUYỆT: admin: 1=26,2=15,3=21,4=23,5=20,6=15,7=16,8=19,9=19,10=14,11=rỗng - khớp 100% kỳ vọng độc lập; user2 (RoleGroup + chia sẻ cha 1,3,10): chỉ thấy 1=26,3=21,10=14, khớp; không lỗi console/HTTP/laravel.log. CẦN DUYỆT PHÂN QUYỀN NGHIỆP VỤ: sub query con KHÔNG lọc theo quyền user nên số đếm gồm cả con user không thấy (giống hành vi pivot đơn trị; không lộ chi tiết bản ghi; hành vi giữ nguyên, chưa đổi). Lần đầu user2 chỉ thấy cha 3 do LỖI FIXTURE của tôi (chia sẻ cho id 2=user1 thay vì 3=user2), đã sửa fixture, không phải lỗi sản phẩm. Ảnh và bằng chứng (đã sao chép vào tài liệu): evidence\2026-11-27_s36_summary_acceptance\ (s36_admin_summary.png, s36_user2_summary.png, s36_expected_admin.txt, t39_*.txt, failed_jobs_decoded.txt). BẢNG 39 (KHÔNG TÁI HIỆN; NGUYÊN NHÂN GỐC CHƯA XÁC ĐỊNH - không phân loại là lỗi môi trường hay lỗi sản phẩm đã xác nhận): hiện storage_state=verifying gen 1 (KHÔNG phải provisioned); lịch sử chuyển trạng thái json>provisioned>dualwrite>backfilling>verifying; schema thật đủ (pcol_a0a6fedf3aa32ff3a792 + value_original), provision_complete=true, plan->toAdd rỗng; cảnh báo chỉ render khi state=provisioned và provision_complete rỗng nên không thể hiện ở trạng thái hiện tại; KHÔNG tái hiện được trên DB scratch (provision bảng 38 và 8). Suy luận (chưa kiểm chứng): cửa sổ ngắn provision() chiếm state=provisioned TRƯỚC DDL. Không đổi code/cờ/dữ liệu. FAILED_JOBS 1-5: lịch sử, đều "conversion is not available" (worker thiếu EXMENT_TEST_*); 1-2 = provision bảng 38 gen 0, 3-5 = bảng 39 gen 0 (nay gen 1/verifying) => không retry. SNAPSHOT: snap_clean/snap_fixture/snap_stageb thiếu menu storage_conversion (migration 2026_12_04) - phát hiện môi trường; khôi phục bằng php artisan migrate --force (đã kiểm, xem sheet 01 mục C). COVERAGE: bảng con PHYSICAL với pivot đa trị có index vẫn là JSON nên trường hợp này NOT TESTED (không phải không áp dụng). Đã xoá DB scratch hyb_p1_test_t39', '2026-11-27'],
    ['A37', 'Nghiệm thu trình duyệt view tổng hợp pivot select_table ĐA TRỊ trên MySQL 8 và SQL Server', 'Chưa dựng máy chủ giao diện cho MySQL 8 / SQL Server; test tự động A33 đã PASS trên cả hai', 'MySQL 8, SQL Server LocalDB: chưa chạy trình duyệt', 'NOT TESTED', 'Khoảng trống nghiệm thu còn lại của A33/A36. Không suy ra PASS từ test tự động.', '2026-11-27'],
    ['A38', 'Pivot select_table đa trị CÓ index trên bảng con ở dạng PHYSICAL', 'Cột đa trị luôn là JSON kể cả khi bảng sở hữu là physical, nên tình huống này cần kiểm riêng (KHÔNG phải không áp dụng)', 'Chưa chạy trên DB nào', 'NOT TESTED', 'Đã kiểm: pivot đa trị trên bảng con JSON (có index) và 4 tổ hợp cha/con JSON-physical (A33); chưa kiểm bảng con physical với index trên cột đa trị.', '2026-11-27'],
];
$BIZAUTO = []; // filled from ho_biz_runs.php when present
if (is_file(__DIR__ . '/ho_biz_runs.php')) { $BIZAUTO = require __DIR__ . '/ho_biz_runs.php'; }

$PERF_TC = []; $PERF_BIZ = [];
if (is_file(__DIR__ . '/ho_perf.php')) { require __DIR__ . '/ho_perf.php'; }
$TC = [];
$tcNo = 0;
$ordered = array_merge(array_keys(array_filter($META, fn ($k) => true, ARRAY_FILTER_USE_KEY)));
foreach ($ordered as $id) {
    $m = $META[$id];
    if (!isset($RES[$id])) { continue; }
    $x = $RES[$id];
    $status = $x['status'];
    $actual = rsum($x);
    if (in_array($id, ['S16', 'S17'], true)) { $status = 'BLOCKED'; $actual = 'Chức năng chưa tồn tại trong bản này (' . implode('; ', $x['notes']) . ')'; }
    if ($id === 'S08') { $actual .= "
Nguyên nhân chưa phân định được (quan sát của kịch bản hay hành vi thật); trạng thái DB, job nằm trong hàng đợi và việc tự tiếp tục khi worker chạy lại đều đạt. Cần tester kiểm tay thông báo này khi worker dừng."; }
    if ($id === 'H01') { $actual .= "
Các kiểm tra còn lại của H01 đạt (các view, 4 kiểu sắp xếp, export, bảng tạo trực tiếp giống bảng JSON). Tìm kiếm từ khoá: xem sheet 05."; }
    if ($id === 'S15') { $actual = 'Chưa chạy: ' . implode('; ', $x['notes']); }
    $dbn = $DBN;
    $TC[] = [
        'id' => $id, 'group' => $m[0], 'role' => $m[1], 'db' => $dbn, 'prereq' => $m[2], 'steps' => $m[3], 'expected' => $m[4],
        'actual' => $actual, 'status' => $status, 'evidence' => $x['evidence'] ?? [], 'evdir' => $x['_dir'], 'tester' => 'Claude Code (kịch bản tự động hoá, trình duyệt thật)',
        'date' => $x['_date'], 'build' => $x['_build'],
    ];
}
foreach ($AUTO as [$id, $group, $desc, $dbn, $status, $actual, $date]) {
    $TC[] = ['id' => $id, 'group' => $group, 'role' => 'Tự động (không có vai trò giao diện)', 'db' => $dbn, 'prereq' => 'Snapshot DB cô lập (Stage B) khôi phục trước mỗi tệp test.', 'steps' => $desc, 'expected' => 'Tất cả test đạt (test bị bỏ qua được nêu rõ).', 'actual' => $actual, 'status' => $status, 'evidence' => [], 'evdir' => null, 'tester' => 'Claude Code (PHPUnit)', 'date' => $date, 'build' => 'B2'];
}
foreach ($BIZAUTO as $row) { $TC[] = $row; }
// manual acceptance by a human tester: not executed
foreach ($PERF_TC as $row) { $TC[] = $row; }
$TC[] = ['id' => 'M01', 'group' => 'Nghiệm thu thủ công', 'role' => 'Tester / người dùng', 'db' => '(chọn DB của khách hàng)', 'prereq' => 'Môi trường kiểm thử riêng với dữ liệu mẫu của khách hàng.', 'steps' => 'Thực hiện các bước ở sheet 02-04 trên dữ liệu thật mô phỏng.', 'expected' => 'Như từng sheet.', 'actual' => 'Chưa thực hiện (chưa có người nhận bàn giao chạy).', 'status' => 'NOT TESTED', 'evidence' => [], 'evdir' => null, 'tester' => '(chưa phân công)', 'date' => '', 'build' => ''];
$TC[] = ['id' => 'M02', 'group' => 'Nghiệm thu thủ công', 'role' => 'Tester', 'db' => 'MySQL 8 / SQL Server', 'prereq' => 'Giao diện + queue worker trên DB tương ứng.', 'steps' => 'Chạy lại luồng giao diện S/P/H trên MySQL 8 và SQL Server.', 'expected' => 'Như MariaDB.', 'actual' => 'Chưa chạy giao diện trên hai DB này (chỉ test tự động A02, A03, A05, A06).', 'status' => 'NOT TESTED', 'evidence' => [], 'evdir' => null, 'tester' => '(chưa phân công)', 'date' => '', 'build' => ''];

// ------------------------------------------------------------------ business regression rows [feature, how, expected, converted status/ids, direct status/ids, note]
function bizst(array $RES, array $ids): string
{
    $sts = array_map(fn ($i) => rstatus($RES, $i), $ids);
    if (in_array('FAIL', $sts, true)) { return 'FAIL'; }
    if (in_array('PASS', $sts, true) && !in_array('NOT TESTED', $sts, true)) { return 'PASS'; }
    return in_array('PASS', $sts, true) ? 'PASS' : 'NOT TESTED';
}
/** Status of a feature from the specific checks of a scenario (not from the status of the whole scenario). */
function chkst(array $RES, string $id, array $patterns): string
{
    if (!isset($RES[$id])) { return 'NOT TESTED'; }
    $all = true; $found = false;
    foreach ($patterns as $p) {
        foreach ($RES[$id]['checks'] as $c) {
            if (preg_match($p, $c['what'])) { $found = true; if (!$c['ok']) { $all = false; } }
        }
    }
    return !$found ? 'NOT TESTED' : ($all ? 'PASS' : 'FAIL');
}
$BIZ = [
 ['Xem danh sách và chi tiết bản ghi', 'Mở danh sách của các view và trang chi tiết.', 'Giống hệt trước khi chuyển đổi đối với dữ liệu hợp lệ.', [bizst($RES, ['S10', 'H03']), 'S10 (B1), H03 (B2)'], [chkst($RES, 'H01', ['/directly created physical table/']), 'H01 (kiểm tra riêng của mục này)'], 'Ô không hợp kiểu vẫn hiển thị nguyên văn (value_original); xem ghi chú L5.'],
 ['Tạo bản ghi (form)', 'Bấm 新規, nhập, 保存.', 'Bản ghi lưu; giá trị công khai = bóng JSON; cột vật lý có giá trị.', [bizst($RES, ['S11', 'H04']), 'S11 (B1), H04 (B2)'], [bizst($RES, ['P03', 'H04']), 'P03, H04'], null],
 ['Sửa bản ghi', 'Mở bản ghi, sửa giá trị, 保存.', 'Giá trị mới hiển thị; DB nhất quán.', [bizst($RES, ['H04']), 'H04'], [bizst($RES, ['H04']), 'H04'], null],
 ['Xoá bản ghi', 'Xoá từ danh sách (xoá mềm).', 'Bản ghi biến mất khỏi danh sách.', [bizst($RES, ['H04']), 'H04'], [bizst($RES, ['H04']), 'H04'], null],
 ['Tìm kiếm từ khoá', 'Nhập từ khoá vào ô tìm kiếm của danh sách (cột có index).', 'Kết quả giống nhau trước/sau chuyển.', ['NOT TESTED', 'H01, H03 (xem ghi chú)'], ['NOT TESTED', 'H01 (xem ghi chú)'], 'Ô tìm kiếm (tham số query) trả 0 bản ghi ngay cả trên bảng JSON nền với từ khoá khớp nguyên văn một ô của cột có 検索インデックス, nên việc so sánh "rỗng = rỗng" giữa JSON và physical không chứng minh được gì. Nguyên nhân chưa xác định (hành vi tìm kiếm của ứng dụng hay cách kiểm của harness). Cần tester kiểm tay trước khi kết luận.'],
 ['Lọc (view lọc số / ngày)', 'Mở view có điều kiện lọc số (>=) và ngày (sau ngày).', 'Cùng tập bản ghi trước/sau chuyển.', [bizst($RES, ['S10', 'H03']), 'S10, H03'], [chkst($RES, 'H01', ['/number filter/', '/directly created physical table/']), 'H01 (kiểm tra riêng của mục này)'], 'Ô không hợp kiểu được coi là rỗng khi lọc (khác biệt có chủ đích).'],
 ['Sắp xếp (tiêu đề cột và view)', 'Bấm sắp xếp trên tiêu đề cột có index và mở view có sắp xếp.', 'Thứ tự giống nhau.', [bizst($RES, ['S10', 'H03']), 'S10, H03'], [chkst($RES, 'H01', ['/sort links/', '/the sorts order/', '/directly created physical table/']), 'H01 (kiểm tra riêng của mục này)'], 'Các bản ghi cùng giá trị khoá không có thứ tự đảm bảo. Cột không có index không sắp xếp được trên tiêu đề (như bảng JSON).'],
 ['Tổng hợp (view tổng hợp)', 'Mở view tổng hợp (nhóm theo cột lựa chọn, cộng số).', 'Cùng các nhóm và tổng.', [bizst($RES, ['H03']), 'H03'], [chkst($RES, 'H01', ['/summary view was created/', '/directly created physical table/']), 'H01 (kiểm tra riêng của mục này)'], null],
 ['Import CSV', 'Dùng インポート với tệp theo mẫu export.', 'Bản ghi thêm đúng; DB nhất quán.', [rstatus($RES, 'H05'), 'H05'], [rstatus($RES, 'H05'), 'H05'], 'Kiểm bằng cách gửi đúng form của hộp thoại インポート (không bấm chọn tệp trong trình duyệt). Bộ test tự động của pipeline import chỉ kiểm bảng JSON (A11-A13).'],
 ['Export CSV', 'Dùng export của danh sách.', 'Nội dung giống trước/sau chuyển.', [bizst($RES, ['S10', 'H03']), 'S10, H03'], [chkst($RES, 'H01', ['/export of the grid/', '/directly created physical table/']), 'H01 (kiểm tra riêng của mục này)'], 'Tệp export là CSV (hoặc ZIP khi có tệp đính kèm).'],
 ['Phân quyền dữ liệu', 'Người dùng hạn chế xem danh sách.', 'Thấy đúng các dòng được phép, như bảng JSON.', [bizst($RES, ['S05', 'S11']), 'S05, S11, A01-A03'], [bizst($RES, ['P09']), 'A01-A03 (test tự động)'], 'Quyền màn hình chuyển đổi: chỉ System Administrator.'],
 ['Cột đa trị', 'Cột lựa chọn cho phép chọn nhiều.', 'Lưu ở JSON, hiển thị đúng.', ['NOT TESTED', 'Chưa kiểm bằng giao diện (có kiểm ở A01-A03: ghi/đọc cột đa trị qua model)'], ['NOT TESTED', 'như bên cạnh'], 'Cột đa trị luôn ở JSON.'],
 ['Quan hệ (cha-con, select_table, join)', 'Danh sách có cột tham chiếu bảng khác, view có join.', 'Giống nhau trước/sau chuyển.', ['NOT TESTED', 'Chưa kiểm trên bảng physical. (A11-A13 chỉ kiểm join/tổng hợp trên bảng JSON)'], ['NOT TESTED', 'chưa kiểm'], 'Cần kiểm trước khi chuyển bảng có quan hệ.'],
 ['Workflow', 'Bảng gắn workflow.', 'Hoạt động như trước.', ['NOT TESTED', 'Chưa kiểm trên bảng physical. (A11-A13 chỉ kiểm workflow trên bảng JSON)'], ['NOT TESTED', 'chưa kiểm'], 'Cần kiểm trước khi chuyển bảng có workflow.'],
 ['Tệp đính kèm, bình luận, lịch sử thay đổi (revision)', 'Thao tác trên một bản ghi.', 'Hoạt động như trước.', ['NOT TESTED', 'Chưa kiểm'], ['NOT TESTED', 'Chưa kiểm'], null],
 ['Bảng Kanban', 'Bảng Kanban của bảng đã chuyển.', 'Giống bảng JSON.', ['PASS', 'A04-A06 (test tự động)'], ['NOT TESTED', 'chưa kiểm'], null],
];

$BIZ = array_merge($BIZ, $PERF_BIZ);

// ------------------------------------------------------------------ evidence descriptions (key => text)
$DESC = [
 'list' => 'Danh sách bảng trong 「テーブル保存方式の変換」 (nhãn JA, bảng hệ thống, 詳細)', 'detail_blocked_table' => 'Chi tiết bảng không đủ điều kiện (row_size_exceeded)', 'detail_json' => 'Chi tiết bảng ở trạng thái JSON', 'detail_json_actions' => 'Hành động có ở trạng thái JSON',
 'unavailable_banner' => 'Máy chủ không bật chuyển đổi: banner 「変換の実行は現在利用できません。」', 'restricted_denied' => 'Người dùng hạn chế bị từ chối (権限がありません。)', 'create_invalid_json' => 'Form tạo bản ghi nhập sai (bảng JSON)', 'record_created_json' => 'Bản ghi vừa tạo (JSON)', 'list_json' => 'Danh sách bảng JSON',
 'provisioned' => 'Sau 物理列を準備する (物理列準備済み)', 'dualwrite' => 'Sau 二重書き込みを開始する', 'backfill_queued_worker_stopped' => 'Worker dừng: backfill đã đăng ký, chờ trong hàng đợi', 'backfill_completed' => 'Backfill hoàn tất', 'verified' => 'Xác minh hoàn tất (passed)', 'l5_report' => 'Báo cáo L5 + biểu mẫu xác nhận chuyển đọc', 'physical_read' => 'Trạng thái 物理列から読み取り',
 'list_physical_read' => 'Danh sách sau chuyển đọc', 'record_odd_physical_read' => 'Bản ghi có giá trị không hợp kiểu vẫn hiển thị nguyên văn', 'record_created_physical' => 'Bản ghi tạo khi bảng ở physical_read', 'restricted_physical_read' => 'Người dùng hạn chế xem bảng physical_read', 'after_rollback' => 'Sau 「JSONからの読み取りに戻す」 (二重書き込み中, cột còn)', 'cancelled_provisioned' => 'Sau 「変換を中止する」', 'cleaned' => 'Sau 「ステージングデータを削除する」', 'final_json' => 'Sau 「物理列を削除してJSONに戻す」: JSON', 'detail_normal' => 'Máy chủ cấu hình đúng: trang tải được', 'admin_not_defined_script_blocked' => 'Tái hiện "admin is not defined" khi open-admin.js bị chặn',
 'create_form_unavailable' => 'Form tạo bảng trên máy chủ KHÔNG bật chuyển đổi: không có ô 保存方式', 'create_form_available' => 'Form tạo bảng với ô 「保存方式」 (JSON（標準）/ 物理列)', 'after_create_physical' => 'Sau khi tạo bảng physical: trang cột', 'detail_direct_physical' => 'Màn hình chuyển đổi của bảng tạo trực tiếp (物理列から読み取り)', 'column_added_first' => 'Sau khi thêm cột đầu tiên', 'column_list' => 'Danh sách cột của bảng physical', 'first_record_list' => 'Danh sách sau bản ghi đầu tiên', 'first_record_detail' => 'Chi tiết bản ghi đầu tiên', 'multi_switch_refused' => 'Từ chối 複数選択を許可する khi cột đã có dữ liệu (thông báo 操作は拒否されました。)', 'list_after_schema_changes' => 'Danh sách sau khi thêm/xoá cột khi có dữ liệu',
 'initializing_detail' => 'Màn hình chuyển đổi của bảng 初期化中 (2 thao tác)', 'initializing_data_refused' => 'Màn hình dữ liệu của bảng 初期化中 bị chặn', 'initializing_column_refused' => 'Thêm cột khi 初期化中 bị từ chối', 'initializing_retried' => 'Sau 初期化を再実行する', 'initializing_cancelled' => 'Sau 初期化を中止してJSONに戻す', 'table_list_after_copy' => 'Danh sách bảng sau khi copy (bản copy physical)', 'template_import_form' => 'Màn hình import template với ô 保存方式', 'rollback_refused' => 'Rollback khi chưa xác minh bị từ chối', 'verified_direct' => 'Xác minh JSON của bảng tạo trực tiếp (passed)', 'returned_to_json' => 'Bảng đã quay về JSON, dữ liệu liệt kê lại',
 'biz_list_json' => 'Danh sách bảng nghiệp vụ (JSON) - 12 bản ghi', 'biz_summary_json' => 'View tổng hợp (JSON)', 'biz_list_direct_physical' => 'Danh sách bảng tạo trực tiếp physical (cùng dữ liệu)', 'conv_list' => 'Danh sách chuyển đổi (build hiện tại)', 'conv_detail_json' => 'Chi tiết bảng JSON, 事前チェック (build hiện tại)', 'conv_provisioned' => 'Sau 物理列を準備する (build hiện tại)', 'conv_dualwrite' => 'Sau 二重書き込みを開始する (build hiện tại)', 'conv_backfilled' => 'Backfill hoàn tất (build hiện tại)', 'conv_l5_report' => 'Báo cáo L5 và biểu mẫu xác nhận (build hiện tại)', 'conv_physical_read' => 'Sau 物理列からの読み取りに切り替える (build hiện tại)',
 'biz_list_physical' => 'Danh sách sau chuyển đổi (cùng view, build hiện tại)', 'biz_summary_physical' => 'View tổng hợp sau chuyển đổi', 'crud_record_converted' => 'Bản ghi sửa (bảng đã chuyển)', 'crud_record_direct' => 'Bản ghi sửa (bảng tạo trực tiếp)', 'import_converted' => 'Danh sách sau import (bảng đã chuyển)', 'import_direct' => 'Danh sách sau import (bảng tạo trực tiếp)',
];

// evidence rows: every screenshot referenced by a result, with the tests that reference it
$EVID = [];
foreach ($RES as $id => $x) {
    foreach (($x['evidence'] ?? []) as $f) {
        $path = $x['_dir'] . '/' . $f;
        if (!is_file($path)) { continue; }
        if (!isset($EVID[$path])) { $EVID[$path] = ['path' => $path, 'file' => $f, 'tests' => [], 'build' => $x['_build'], 'key' => preg_replace('/^\d+_/', '', pathinfo($f, PATHINFO_FILENAME))]; }
        $EVID[$path]['tests'][] = $id;
    }
}
ksort($EVID);
$EVID = array_values($EVID);

// DB evidence
$DBFILES = [];
foreach ([[$EV1, 'db_*.json', 'B1', 'S'], [$EV2, 'db2_*.json', 'B2', 'P'], [$EV3, 'db2_*.json', 'B2', 'H']] as [$dir, $pat, $b, $px]) {
    foreach (glob("$dir/$pat") as $f) {
        $n = basename($f);
        if (preg_match('/db_(tmp|0[1-5]_|03[bcd]_)/', $n)) { continue; }
        $DBFILES[] = [$f, $n, $b];
    }
}

// ------------------------------------------------------------------ sheet builders
function build_business_sheet(Spreadsheet $wb, string $name, array $C, array $BIZ): void
{
    $ws = $wb->createSheet(); $ws->setTitle($name); $ws->setShowGridLines(false);
    foreach (['A' => 30, 'B' => 38, 'C' => 38, 'D' => 15, 'E' => 26, 'F' => 15, 'G' => 26, 'H' => 52] as $c => $w) { $ws->getColumnDimension($c)->setWidth($w); }
    titleBar($ws, 'A1:H1', '05 - Kiểm tra nghiệp vụ (hồi quy): màn hình và thao tác hiện có phải giữ nguyên', $C);
    $ws->mergeCells('A2:H2');
    $ws->setCellValue('A2', 'Mục tiêu: sau khi chuyển sang physical hoặc tạo bảng ở physical, các màn hình và thao tác của người dùng vẫn như trước. Kết quả lấy từ các test case ở sheet 06 (ID ở cột "Test ID"); mục NOT TESTED là phần chưa được kiểm trên bảng physical. Khác biệt có chủ đích (L5): giá trị không hợp kiểu vẫn hiển thị nguyên văn nhưng được coi là rỗng khi lọc/sắp xếp/tổng hợp; thứ tự bản ghi cùng giá trị khoá sắp xếp không đảm bảo.');
    wrapTop($ws, 'A2:H2'); $ws->getRowDimension(2)->setRowHeight(58); $ws->getStyle('A2:H2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C['warn']);
    xlink($ws, 'A3', '« Về 00_Tổng quan', "#'00_Tổng quan'!A1");
    $r = 5;
    headerRow($ws, $r, ['Chức năng', 'Cách kiểm', 'Kỳ vọng', 'Bảng đã chuyển (JSON→physical)', 'Test ID', 'Bảng tạo trực tiếp (physical)', 'Test ID', 'Ghi chú / giới hạn'], $C);
    $hdr = $r; $r++;
    foreach ($BIZ as [$f, $how, $exp, $conv, $dir, $note]) {
        $ws->setCellValue("A$r", $f); $ws->setCellValue("B$r", $how); $ws->setCellValue("C$r", $exp);
        $ws->setCellValue("D$r", $conv[0]); $ws->setCellValue("E$r", $conv[1]);
        $ws->setCellValue("F$r", $dir[0]); $ws->setCellValue("G$r", $dir[1]); $ws->setCellValue("H$r", $note ?? '');
        wrapTop($ws, "A$r:H$r"); $ws->getStyle("A$r")->getFont()->setBold(true);
        statusStyle($ws, "D$r", $conv[0], $C); statusStyle($ws, "F$r", $dir[0], $C);
        $ws->getRowDimension($r)->setRowHeight(max(30, 13.5 * max(est($how, 36), est($exp, 36), est((string)$note, 50), est($conv[1], 24), est($dir[1], 24)) + 4));
        borders($ws, "A$r:H$r"); $r++;
    }
    $ws->setAutoFilter("A$hdr:H" . ($r - 1));
    $ws->freezePane('B6');
}
function build_testcase_sheet(Spreadsheet $wb, string $name, array $C, array $TC, array $SHEETS): void
{
    $ws = $wb->createSheet(); $ws->setTitle($name); $ws->setShowGridLines(false);
    $widths = ['A' => 8, 'B' => 18, 'C' => 20, 'D' => 17, 'E' => 34, 'F' => 46, 'G' => 44, 'H' => 52, 'I' => 13, 'J' => 22, 'K' => 26, 'L' => 12, 'M' => 8];
    foreach ($widths as $c => $w) { $ws->getColumnDimension($c)->setWidth($w); }
    titleBar($ws, 'A1:M1', '06 - Test cases và kết quả', $C);
    $ws->mergeCells('A2:M2');
    $ws->setCellValue('A2', 'PASS = đã chạy và đạt; FAIL = đã chạy, không đạt; BLOCKED = không chạy được vì chức năng chưa có; NOT TESTED = chưa chạy. Build: B1 = HEAD 5ccb2dcf0 trước tính năng tạo bảng physical; B2 = HEAD 5ccb2dcf0 + thay đổi chưa commit hiện tại (xem sheet 00). "Người thực hiện" là công cụ đã chạy; chưa có tester người nào xác nhận lại (xem M01, M02). Lọc theo cột bằng nút bộ lọc ở hàng tiêu đề.');
    wrapTop($ws, 'A2:M2'); $ws->getRowDimension(2)->setRowHeight(46); $ws->getStyle('A2:M2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C['warn']);
    xlink($ws, 'A3', '« Về 00_Tổng quan', "#'00_Tổng quan'!A1");
    $hdr = 4;
    headerRow($ws, $hdr, ['ID', 'Nhóm', 'Vai trò', 'Database', 'Điều kiện / dữ liệu', 'Các bước', 'Kết quả mong đợi', 'Kết quả thực tế', 'Trạng thái', 'Evidence', 'Người thực hiện', 'Ngày thực hiện', 'Build'], $C);
    $r = $hdr + 1;
    foreach ($TC as $t) {
        $evText = '';
        if ($t['evidence']) {
            $evText = count($t['evidence']) . ' ảnh: ' . implode(', ', array_map(fn ($f) => preg_replace('/_.*$/', '', $f) . '…', array_slice($t['evidence'], 0, 4))) . (count($t['evidence']) > 4 ? ' …' : '');
        } elseif (in_array($t['group'], ['Tự động (PHPUnit)'], true)) { $evText = 'Kết quả PHPUnit (sheet 07, mục Kiểm tra tự động)'; }
        elseif (strpos($t['id'], 'PF') === 0) { $evText = 'Tài liệu nguồn (sheet 01, mục E.8)'; }
        $vals = [$t['id'], $t['group'], $t['role'], $t['db'], $t['prereq'], $t['steps'], $t['expected'], $t['actual'], $t['status'], $evText, $t['tester'], $t['date'], $t['build']];
        foreach ($vals as $i => $v) { $ws->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $r, (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); }
        wrapTop($ws, "A$r:M$r"); $ws->getStyle("A$r")->getFont()->setBold(true);
        statusStyle($ws, "I$r", $t['status'], $C);
        $target = strpos($t['id'], 'PF') === 0 ? "#'{$SHEETS[1]}'!A1" : "#'{$SHEETS[7]}'!A1";
        xlink($ws, "J$r", $evText ?: '-', $target); wrapTop($ws, "J$r");
        $ws->getRowDimension($r)->setRowHeight(min(300, max(30, 13.5 * max(est($t['prereq'], 32), est($t['steps'], 44), est($t['expected'], 42), est($t['actual'], 50), est($t['role'], 18)) + 6)));
        borders($ws, "A$r:M$r"); $r++;
    }
    $ws->setAutoFilter("A$hdr:M" . ($r - 1));
    $ws->freezePane('B5');
}
function build_evidence_sheet(Spreadsheet $wb, string $name, array $C, array $EVID, array $used, array $dirs, string $imgDir, array $SHEETS): void
{
    global $DESC, $DBFILES, $RES, $EV1, $EV2, $EV3;
    $ws = $wb->createSheet(); $ws->setTitle($name); $ws->setShowGridLines(false);
    foreach (['A' => 9, 'B' => 38, 'C' => 8, 'D' => 22, 'E' => 24, 'F' => 40, 'G' => 92] as $c => $w) { $ws->getColumnDimension($c)->setWidth($w); }
    titleBar($ws, 'A1:G1', '07 - Evidence: ảnh chụp thật và kiểm tra DB', $C);
    $ws->mergeCells('A2:G2');
    $ws->setCellValue('A2', 'Mỗi dòng là một ảnh chụp thật từ lần chạy (ảnh gốc PNG ở thư mục evidence, bấm tên tệp để mở; ảnh trong sheet là bản cắt bỏ menu bên trái, giữ độ phân giải đọc được). DB: MariaDB 10.4.32 cho mọi ảnh. Build: B1 hoặc B2 (xem sheet 00). Không có ảnh nào được dựng lại hay chỉnh sửa nội dung.');
    wrapTop($ws, 'A2:G2'); $ws->getRowDimension(2)->setRowHeight(46); $ws->getStyle('A2:G2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C['warn']);
    xlink($ws, 'A3', '« Về 00_Tổng quan', "#'00_Tổng quan'!A1");
    // which guide steps use each shot key
    $stepsOf = [];
    foreach ($used as [$key, $file, $no, $sheet]) { $stepsOf[$key][] = substr($sheet, 0, 2) . ' / ' . $no; }
    $r = 5;
    sectionBar($ws, "A$r:G$r", 'A. Ảnh chụp màn hình', $C); $r++;
    headerRow($ws, $r, ['ID', 'Tệp gốc (bấm để mở)', 'Build', 'Test ID', 'Bước hướng dẫn', 'Mô tả', 'Ảnh'], $C); $hdrA = $r; $r++;
    foreach ($EVID as $i => $e) {
        $id = sprintf('E%03d', $i + 1);
        $img = prepImage($e['path'], $imgDir, 700);
        $ih = $img ? $img[2] : 40;
        $ws->setCellValue("A$r", $id);
        xlink($ws, "B$r", $e['file'], 'file:///' . str_replace(' ', '%20', $e['path']));
        $ws->setCellValue("C$r", $e['build']);
        $ws->setCellValue("D$r", implode(', ', array_unique($e['tests'])));
        $ws->setCellValue("E$r", implode("\n", array_unique($stepsOf[$e['key']] ?? [])) ?: '-');
        $ws->setCellValue("F$r", $DESC[$e['key']] ?? $e['key']);
        wrapTop($ws, "A$r:G$r"); $ws->getStyle("A$r")->getFont()->setBold(true);
        if ($img) { pic($ws, $img[0], "G$r", $img[1], $img[2]); }
        $ws->getRowDimension($r)->setRowHeight(min(409, max(40, $ih * 0.75 + 8)));
        borders($ws, "A$r:G$r"); $r++;
    }
    $ws->setAutoFilter("A$hdrA:G" . ($r - 1));
    $r++;
    sectionBar($ws, "A$r:G$r", 'B. Kiểm tra DB (đọc trực tiếp từ cơ sở dữ liệu, không dựa vào thông báo trên màn hình)', $C); $r++;
    headerRow($ws, $r, ['#', 'Tệp (bấm để mở)', 'Build', 'mode / state / generation', 'Cột pcol_* | value_original', 'Dòng | công khai ≠ JSON | queue', 'Run (backfill/verify/cleanup)'], $C); $r++;
    foreach ($DBFILES as $i => [$f, $n, $b]) {
        $j = json_decode(file_get_contents($f), true);
        if (!$j) { continue; }
        $runs = [];
        foreach ((array)($j['runs'] ?? []) as $k => $run) { $runs[] = (is_string($k) ? $k : 'run') . '#' . ($run['id'] ?? '?') . ' ' . ($run['status'] ?? '?') . (!empty($run['result']) ? '/' . $run['result'] : ''); }
        $ws->setCellValue("A$r", (string)($i + 1));
        xlink($ws, "B$r", $n, 'file:///' . str_replace(' ', '%20', $f));
        $ws->setCellValue("C$r", $b);
        $ws->setCellValue("D$r", ($j['table'] ?? '?') . ': ' . ($j['storage']['mode'] ?? '?') . ' / ' . ($j['storage']['state'] ?? '?') . ' / gen ' . ($j['storage']['generation'] ?? '?'));
        $ws->setCellValue("E$r", ($j['physical']['staging_columns'] ?? 0) . ' cột (value_original: ' . (!empty($j['physical']['value_original_column']) ? ($j['value_original']['rows'] ?? 0) . ' dòng' : 'không có') . ')');
        $ws->setCellValue("F$r", ($j['rows']['total'] ?? '?') . ' dòng | ' . ($j['public_vs_json']['mismatching_rows'] ?? '?') . ' | queue chờ ' . ($j['queue']['jobs_waiting'] ?? '?') . ', lỗi ' . ($j['queue']['failed_jobs'] ?? '?'));
        $ws->setCellValue("G$r", $runs ? implode('; ', $runs) : '-');
        wrapTop($ws, "A$r:G$r"); $ws->getRowDimension($r)->setRowHeight(max(28, 13.5 * max(est($ws->getCell("D$r")->getValue(), 22), est($ws->getCell("E$r")->getValue(), 24), est($ws->getCell("F$r")->getValue(), 40)) + 4));
        borders($ws, "A$r:G$r"); $r++;
    }
    $r++;
    sectionBar($ws, "A$r:G$r", 'C. Kiểm tra tự động (PHPUnit) và lượt so sánh trước/sau (tệp dữ liệu)', $C); $r++;
    $files = [
        ["$EV1/battery_1_json.json", 'So sánh danh sách/chi tiết trước chuyển (bảng custom_value_edit, B1)'], ["$EV1/battery_2_physical_read.json", 'So sánh sau chuyển (B1)'], ["$EV1/export_1_json.csv", 'Export trước chuyển (B1)'], ["$EV1/export_2_physical_read.csv", 'Export sau chuyển (B1)'],
        ["$EV3/battery3_conv_json.json", 'Bảng 12 bản ghi: đọc trước chuyển (B2)'], ["$EV3/battery3_conv_physical.json", 'Cùng bảng sau chuyển (B2)'], ["$EV3/battery3_direct_physical.json", 'Bảng tạo trực tiếp physical (B2)'],
        ["$EV3/export3_conv_json.csv", 'Export bảng JSON (B2)'], ["$EV3/export3_conv_physical.csv", 'Export sau chuyển (B2)'], ["$EV3/export3_direct_physical.csv", 'Export bảng tạo trực tiếp (B2)'],
    ];
    foreach ($files as [$f, $d]) {
        if (!is_file($f)) { continue; }
        xlink($ws, "B$r", basename($f), 'file:///' . str_replace(' ', '%20', $f)); $ws->setCellValue("A$r", '-'); $ws->mergeCells("C$r:G$r"); $ws->setCellValue("C$r", $d); wrapTop($ws, "A$r:G$r"); borders($ws, "A$r:G$r"); $r++;
    }
    $ws->setCellValue("A$r", '-'); $ws->setCellValue("B$r", 'Kết quả PHPUnit'); $ws->mergeCells("C$r:G$r");
    $ws->setCellValue("C$r", 'Kết quả (tóm tắt từng DB) nằm ở sheet 06 (A01-A13) và 04_Progress.md; script chạy: evidence\2026-11-26_handover_regression\harness\run_pc.ps1, run_reg.ps1, run_biz.ps1.');
    wrapTop($ws, "A$r:G$r"); $ws->getRowDimension($r)->setRowHeight(32); borders($ws, "A$r:G$r");
    $ws->freezePane('A5');
}
