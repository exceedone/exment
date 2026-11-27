<?php
// Static content of the handover workbook (Vietnamese; Japanese screen labels as they appear in the UI).
// Step = [action, expected, screenshot key (or null), note]

$BUILD = [
    'head' => 'Gói bàn giao HS-HANDOVER-2026-11-27.1 (ngày 2026-11-27). Nhánh feature/hybrid-storage-v7.3 (repo packages/exceedone/exment), chưa push/merge/deploy. Commit mã sản phẩm: d123119b721820c097625af28a0ae2a321d68603 (cache), ec90d96062f70d1caf1479d19df70f2c1a2d9adb (SQL Server: join/tổng hợp/kiểm tra tham chiếu), 11fa6fb0038f42bc20d3e72e5c7c074f5158130a (import parent), 31d48bb4d8eefdd95ebbe1f09acf9bc3a2ee1963 (tạo bảng physical, màn hình chuyển đổi); 0772f659767d4af7e50fd2b2f7844e197946fb84 (fixture s36 + bộ sinh workbook). Mã ứng dụng (src, resources) không đổi sau 31d48bb4d; commit kế tiếp chỉ chạm tests/Support/handover (hash đầy đủ trong MANIFEST.txt của bản đóng gói bất biến và trong 04_Progress.md). Chuyển đổi production: TẮT.',
    'b1' => 'B1 = HEAD 5ccb2dcf0, trước khi thêm tính năng tạo bảng physical (nghiệm thu chuyển đổi 2026-11-25) - LỊCH SỬ',
    'b2' => 'B2 = HEAD 5ccb2dcf0 + thay đổi chưa commit lúc đó (giai đoạn 2; 2026-11-25/26) - LỊCH SỬ. Các kết quả A1-A32 chạy trên các bản build làm việc này; hai ngày cuối không còn thay đổi mã sản phẩm nào sau A35.',
    'b3' => 'B3 = cây mã tại 31d48bb4d (= bản làm việc đã chạy A33-A36 ở 2026-11-27/30; nội dung giống hệt, chỉ tách commit). Các kết quả A33/A36 (join pivot đa trị, nghiệm thu trình duyệt) thuộc B3.',
];

$guide02 = [
    ['id' => 'A', 'title' => 'A. Chọn chế độ lưu trữ khi tạo bảng nghiệp vụ mới', 'steps' => [
        ['Mở 設定：データと画面 > カスタムテーブル rồi bấm tạo mới (/admin/table/create). Kéo xuống cuối form: ô 「保存方式」 có hai lựa chọn: 「JSON（標準）」 (mặc định) và 「物理列」.',
         'Ô 「保存方式」 hiển thị với giá trị mặc định 「JSON（標準）」. Giải thích trên màn hình: chỉ chọn được khi tạo bảng; 「物理列」 tạo bảng lưu cột đơn trị vào cột vật lý mà không cần quy trình chuyển đổi.',
         'create_form_available', 'Ô này CHỈ hiện khi chuyển đổi khả dụng (môi trường kiểm thử, xem sheet 01). Mặc định luôn là JSON; chưa có quyết định đổi mặc định.'],
        ['Kiểm tra cùng màn hình trên môi trường KHÔNG bật chuyển đổi (giống production).',
         'Không có ô 「保存方式」; bảng luôn được tạo ở dạng JSON.',
         'create_form_unavailable', 'Đây là hành vi production hiện tại.'],
        ['Chọn 「物理列」, nhập テーブル名(英数字) và テーブル表示名, bấm 保存.',
         'Hệ thống tạo bảng, tự khởi tạo schema và chuyển tới màn hình 「カスタム列設定」 của bảng. Trong DB: storage_mode = physical, storage_state = physical_read, generation = 2, cột value_original đã có, bảng rỗng.',
         'after_create_physical', 'Không cần chạy quy trình chuyển đổi thủ công. Chế độ chỉ chọn một lần lúc tạo; bảng hệ thống luôn JSON; bảng đã có không đổi.'],
        ['Mở màn hình 「テーブル保存方式の変換」 (/admin/storage_conversion) và vào chi tiết bảng vừa tạo.',
         'Trạng thái 「物理列から読み取り」; các thao tác hiển thị: 「スキーマを再調整する」, 「検証を開始・再開する」, 「JSONからの読み取りに戻す」. Có ghi chú rằng bảng được khởi tạo khi tạo (không qua chuyển đổi).',
         'detail_direct_physical', 'Màn hình này không có mục trên menu bên trái; mở bằng địa chỉ trực tiếp.'],
    ]],
    ['id' => 'B', 'title' => 'B. Thêm, sửa, xoá cột và nhập dữ liệu trên bảng physical', 'steps' => [
        ['Mở 「カスタム列設定」 của bảng, thêm cột (列名, 列表示名, 列種類...) rồi 保存. Lặp lại cho các cột khác (ví dụ số nguyên, văn bản, ngày, lựa chọn).',
         'Mỗi cột đơn trị có một cột vật lý pcol_* trong DB; cột đa trị chỉ lưu ở JSON. Danh sách cột hiển thị đủ các cột đã thêm.',
         'column_added_first', 'Tên cột và loại cột không đổi được sau khi tạo (màn hình chỉ hiển thị).'],
        ['Danh sách cột của bảng.', 'Đủ các cột; không có lỗi.', 'column_list', null],
        ['Mở màn hình dữ liệu của bảng, bấm 新規 và nhập bản ghi đầu tiên rồi 保存.',
         'Bản ghi được lưu: giá trị nằm trong cột vật lý VÀ trong bóng JSON; trang chi tiết hiển thị đúng giá trị.',
         'first_record_detail', 'View mặc định của bảng mới tạo chưa có cột tuỳ chỉnh (giống bảng JSON): thêm cột vào view nếu muốn hiển thị trong danh sách.'],
        ['Khi đã có dữ liệu: thêm một cột mới, sửa 列表示名 của một cột, xoá một cột.',
         'Thêm cột: bản ghi cũ giữ nguyên, ô mới để trống. Sửa nhãn: không ảnh hưởng schema. Xoá cột: cột vật lý bị gỡ, khoá JSON bị xoá ở mọi dòng (kể cả bản ghi đã xoá mềm); danh sách vẫn hoạt động.',
         'list_after_schema_changes', 'Xoá cột là thao tác không đảo ngược được.'],
        ['Mở một cột lựa chọn đã có dữ liệu, bật 「複数選択を許可する」 rồi 保存.',
         'Bị từ chối: thông báo 「操作は拒否されました。」 kèm lý do (cột đang có giá trị nên không thể chuyển đơn trị ↔ đa trị). Metadata và schema không đổi.',
         'multi_switch_refused', 'GIỚI HẠN: chỉ đổi được khi chưa dòng nào (kể cả đã xoá mềm) có giá trị ở cột đó. Việc chuyển dữ liệu giữa cột vật lý và danh sách JSON chưa được triển khai.'],
    ]],
    ['id' => 'C', 'title' => 'C. Khởi tạo bị dừng giữa chừng (trạng thái 初期化中)', 'steps' => [
        ['Khi khởi tạo một bảng physical bị dừng (ví dụ lỗi DDL), bảng ở trạng thái 「初期化中」. Mở màn hình 「テーブル保存方式の変換」 của bảng đó.',
         'Hiển thị trạng thái 「初期化中」 và thông báo 「このテーブルは物理列として作成され、スキーマを準備中です。準備が完了するまで、データの参照・登録はできません。」. Có hai thao tác: 「初期化を再実行する」 và 「初期化を中止してJSONに戻す」.',
         'initializing_detail', 'Trạng thái này được tạo trong kiểm thử bằng cách dừng khởi tạo sau bước khoá; trong thực tế xảy ra khi DDL lỗi.'],
        ['Thử mở màn hình dữ liệu của bảng đang 初期化中.',
         'Không phục vụ dữ liệu (đọc và ghi đều bị chặn); quản trị (danh sách cột, xoá bảng, màn hình chuyển đổi) vẫn dùng được.',
         'initializing_data_refused', 'Trang lỗi mặc định (chưa có màn hình riêng).'],
        ['Thử thêm một cột khi bảng đang 初期化中.', 'Bị từ chối với thông báo; không có cột nào được tạo.', 'initializing_column_refused', null],
        ['Bấm 「初期化を再実行する」.', 'Khởi tạo tiếp từ schema thật (idempotent) và công bố trạng thái 「物理列から読み取り」; dữ liệu dùng lại được.', 'initializing_retried', 'Có thể bấm nhiều lần an toàn.'],
        ['Với một bảng khác còn rỗng: bấm 「初期化を中止してJSONに戻す」.', 'Bảng về JSON, các cột vật lý rỗng bị gỡ.', 'initializing_cancelled', 'Chỉ khi bảng không có bản ghi nào.'],
    ]],
    ['id' => 'D', 'title' => 'D. Copy bảng', 'steps' => [
        ['Trong danh sách カスタムテーブル, mở thao tác copy của một bảng JSON. Hộp thoại có thêm ô 「保存方式」 (mặc định JSON); chọn 「物理列」, nhập tên mới rồi thực hiện.',
         'Bản copy chỉ gồm lược đồ (bảng, cột, tuỳ chọn; KHÔNG copy bản ghi) và được khởi tạo ở physical (physical_read, 0 bản ghi, đủ cột vật lý). Bảng nguồn giữ nguyên chế độ.',
         'table_list_after_copy', 'Hộp thoại copy được kiểm bằng địa chỉ của nó (ô có mặt) và yêu cầu copy; chưa chụp màn hình hộp thoại. Copy bảng nguồn physical cho ra bản copy JSON nếu không chọn.'],
    ]],
    ['id' => 'E', 'title' => 'E. Import template', 'steps' => [
        ['Mở màn hình import template (/admin/template). Có ô 「保存方式」 chỉ khi chuyển đổi khả dụng.',
         'Ô hiển thị trên môi trường kiểm thử và không có trên môi trường không bật.',
         'template_import_form', 'CHƯA bấm import thật trên màn hình. Kiểm bằng test tự động ở mức service: bảng MỚI do import tạo được khởi tạo physical sau khi lược đồ xong và TRƯỚC khi nạp dữ liệu mẫu; bảng đã có giữ chế độ; cột mới thêm vào bảng physical đi qua vòng đời cột; lỗi khởi tạo làm import dừng, không nạp dữ liệu. DDL không rollback trong giao dịch import: dùng 「スキーマを再調整する」 để dọn cột mồ côi.'],
    ]],
];

$guide03 = [
    ['id' => 'A', 'title' => 'A. Trước khi bắt đầu', 'steps' => [
        ['Chuẩn bị theo sheet 01: sao lưu DB và tệp, queue worker đang chạy, đăng nhập bằng System Administrator.', 'Đã có bản sao lưu; worker chạy.', null, 'Không có ảnh: đây là bước chuẩn bị môi trường.'],
        ['Trên môi trường production (không bật chuyển đổi), mở bất kỳ bảng nào trong màn hình chuyển đổi.',
         'Hiển thị 「変換の実行は現在利用できません。」; mọi nút thao tác bị vô hiệu và bị từ chối nếu gửi trực tiếp.',
         'unavailable_banner', 'Đây là trạng thái production của bản này.'],
        ['Mở 「テーブル保存方式の変換」 (/admin/storage_conversion).',
         'Danh sách bảng nghiệp vụ với cột テーブル / 読み取り元 / 状態 / 世代 và liên kết 詳細; bảng hệ thống ghi 「システムテーブル（変換対象外）」.',
         'conv_list', null],
    ]],
    ['id' => 'B', 'title' => 'B. Chọn bảng và xem 事前チェック', 'steps' => [
        ['Bấm 詳細 của bảng cần chuyển.',
         'Trang chi tiết: 読み取り元 = json, 状態 = JSON, 世代 = 0; mục 「事前チェック（参照のみ）」 cho biết đủ điều kiện hay không; các hành động theo trạng thái (chỉ 「物理列を準備する」).',
         'conv_detail_json', 'Bảng đủ điều kiện: 「変換の前提条件を満たしています。」.'],
        ['Mở chi tiết một bảng không đủ điều kiện (ví dụ vượt giới hạn kích thước hàng).',
         'Hiển thị 「変換の前提条件を満たしていません。」 kèm lý do (row_size_exceeded); thao tác bị dịch vụ từ chối.',
         'detail_blocked_table', 'Bảng hệ thống và bảng không có kiểu vật lý cho cột đơn trị cũng không đủ điều kiện.'],
    ]],
    ['id' => 'C', 'title' => 'C. Thực hiện chuyển đổi (queue worker phải chạy)', 'steps' => [
        ['Bấm 「物理列を準備する」.', 'Thông báo 「処理を登録しました。進捗はこの画面で確認できます。」. Sau vài giây: 状態 = 物理列準備済み; DB có đủ cột pcol_* và value_original; JSON vẫn là nguồn đọc/ghi duy nhất.', 'conv_provisioned', null],
        ['Bấm 「二重書き込みを開始する」.', 'Trạng thái 「二重書き込み中」, 世代 tăng; từ đây mỗi lần ghi cập nhật cả JSON và cột vật lý.', 'conv_dualwrite', 'Chờ ít nhất 1 giây trước bước tiếp theo.'],
        ['Tick toàn bộ mục trong 「直接書き込みの停止・除外の確認」, nhập 「実施した内容」 rồi bấm 「バックフィルを開始する」.',
         'Job backfill chạy; mục 「進捗」 hiện các dòng đã xử lý. Hoàn tất: dòng 「バックフィル #1」 trạng thái completed.',
         'conv_backfilled', 'Các mục xác nhận KHÔNG được hệ thống kiểm chứng (người vận hành tự bảo đảm không còn tiến trình ghi trực tiếp).'],
        ['Bấm 「検証を開始・再開する」 và đợi kết quả.', 'Dòng 「検証 #2」: completed / passed (JSON và cột vật lý khớp nhau).', 'verified', 'Nếu failed: sửa nguyên nhân rồi chạy lại.'],
        ['Bấm 「確認待ちにする」 và đọc mục 「変換結果の確認事項（L5）」.',
         'Trạng thái 「確認待ち」. Báo cáo L5 liệt kê (chỉ số lượng, không có giá trị) các ô không hợp kiểu và lý do, kèm phiên bản báo cáo.',
         'conv_l5_report', 'Ý nghĩa L5: giá trị không biểu diễn được bằng cột có kiểu vẫn hiển thị nguyên văn, nhưng lọc/sắp xếp/tổng hợp coi như rỗng.'],
        ['Tick 「上記の確認事項（このレポートのバージョン）を確認しました。」 và các mục xác nhận rồi bấm 「物理列からの読み取りに切り替える」.',
         'Trạng thái 「物理列から読み取り」, 読み取り元 = physical, 世代 tăng. DB: storage_mode = physical, storage_state = physical_read (đã đối chiếu trực tiếp trong DB, không chỉ dựa vào thông báo).',
         'conv_physical_read', 'Không có bước JSON cleanup trong bản này: JSON được giữ làm bóng.'],
    ]],
    ['id' => 'D', 'title' => 'D. Sau khi chuyển', 'steps' => [
        ['Kiểm tra các màn hình nghiệp vụ theo sheet 05 (danh sách, lọc, sắp xếp, tổng hợp, chi tiết, tìm kiếm, export, tạo/sửa/xoá).',
         'Kết quả giống trước khi chuyển đối với dữ liệu hợp lệ.', 'biz_list_physical', 'Khác biệt có chủ đích (L5) chỉ với các ô không hợp kiểu.'],
        ['Mở bản ghi có giá trị không hợp kiểu (ví dụ chữ trong cột số).', 'Giá trị vẫn hiển thị nguyên văn (không bị trống) nhờ value_original.', 'record_odd_physical_read', 'Bản ghi này được tạo thô để mô phỏng trường hợp L5.'],
    ]],
];

$guide04 = [
    ['id' => 'A', 'title' => 'A. Quay lại JSON (rollback nhanh)', 'steps' => [
        ['Trên bảng 「物理列から読み取り」: bấm 「検証を開始・再開する」 và đợi kết quả passed.', 'Dòng 「検証」 completed / passed (xác nhận JSON nhất quán với cột vật lý).', 'verified_direct', 'Bảng tạo trực tiếp không có lịch sử chuyển đổi: bước này là điều kiện để rollback.'],
        ['Bấm 「JSONからの読み取りに戻す」 KHÔNG tick 「JSONの値を正とすることを承認します。」 khi chưa có kết quả passed.', 'Bị từ chối nếu chưa có xác minh passed của thế hệ hiện tại.', 'rollback_refused', 'Có thể tick ô chấp nhận JSON là nguồn để bỏ qua xác minh (được ghi lại).'],
        ['Sau khi đã có xác minh passed: bấm 「JSONからの読み取りに戻す」 (tick các mục xác nhận).', 'Trạng thái 「二重書き込み中」, 読み取り元 = json, 世代 tăng; cột vật lý vẫn còn (không bị xoá); ghi vẫn cập nhật cả hai.', 'after_rollback', 'Rollback chỉ đổi nguồn đọc; không xoá cột, không xoá dữ liệu.'],
    ]],
    ['id' => 'B', 'title' => 'B. Huỷ chuyển đổi', 'steps' => [
        ['Bấm 「変換を中止する」.', 'Trạng thái 「物理列準備済み」: JSON là nguồn duy nhất; cột vật lý và dữ liệu staging vẫn còn.', 'cancelled_provisioned', 'Huỷ cũng dùng được từ 二重書き込み中 / バックフィル中 / 検証中 / 確認待ち.'],
    ]],
    ['id' => 'C', 'title' => 'C. Dọn dữ liệu staging', 'steps' => [
        ['Nhập tên bảng vào ô xác nhận rồi bấm 「ステージングデータを削除する」; đợi job hoàn tất.', 'Chỉ dữ liệu của các cột vật lý và value_original bị xoá (cột vẫn còn); giá trị JSON không đổi.', 'cleaned', 'Job chạy qua queue; chạy lại được nếu bị gián đoạn.'],
    ]],
    ['id' => 'D', 'title' => 'D. Gỡ cột vật lý (deprovision)', 'steps' => [
        ['Nhập tên bảng rồi bấm 「物理列を削除してJSONに戻す」.', 'Các cột pcol_* và value_original rỗng bị gỡ (DDL, không hoàn tác được); bảng là bảng JSON bình thường. Bị từ chối nếu cột còn giá trị.', 'final_json', 'Bốn thao tác (rollback, huỷ, dọn staging, gỡ cột) là riêng biệt, chạy theo thứ tự này.'],
        ['Kết quả cho bảng tạo trực tiếp sau toàn bộ chuỗi.', 'Bảng JSON, không còn cột vật lý; bản ghi và giá trị JSON nguyên vẹn; màn hình dữ liệu liệt kê lại các bản ghi.', 'returned_to_json', null],
    ]],
    ['id' => 'E', 'title' => 'E. Phục hồi khi bị gián đoạn', 'steps' => [
        ['Queue worker dừng khi bạn bấm thao tác dùng job (ví dụ 「バックフィルを開始する」).', 'UI báo đã đăng ký; job nằm trong hàng đợi; DB giữ trạng thái; khi worker chạy lại, job tự tiếp tục và hoàn tất (không mất, không chạy trùng).', 'backfill_queued_worker_stopped', 'Bấm lại 「バックフィルを再開する」 nếu cần: tiếp tục từ checkpoint.'],
        ['Khởi tạo bảng physical dừng giữa chừng.', 'Xem sheet 02 mục C: 「初期化を再実行する」 hoặc 「初期化を中止してJSONに戻す」.', 'initializing_detail', null],
        ['Thêm/xoá cột trên bảng physical bị gián đoạn giữa chừng (ví dụ lỗi DDL).', 'Bấm 「スキーマを再調整する」: thêm cột vật lý còn thiếu và gỡ cột vật lý không còn cột nào sở hữu (idempotent).', 'detail_direct_physical', 'Thao tác đã kiểm bằng test tự động trên 3 DB; chưa chụp màn hình sau khi bấm.'],
        ['Job bị kill giữa một lô xử lý.', 'Chưa kiểm: bảng thử xử lý một lô trong khoảng 1 giây nên không dừng đúng lúc được.', null, 'NOT TESTED - nên thử trên bảng lớn trước khi dùng cho dữ liệu thật.'],
    ]],
];

// Operations that return a table to JSON: separate, ordered
$ops = [
    ['Rollback nhanh', 'JSONからの読み取りに戻す', 'Bảng đang 物理列から読み取り; có xác minh passed của thế hệ hiện tại, hoặc tick chấp nhận JSON là nguồn; xác nhận không còn tiến trình ghi trực tiếp', 'Đổi nguồn đọc về JSON (二重書き込み中). Không xoá cột, không xoá dữ liệu', 'Có (chuyển đổi lại được)'],
    ['Huỷ', '変換を中止する', 'Bảng ở 二重書き込み中 / バックフィル中 / 検証中 / 確認待ち', 'Về 物理列準備済み; JSON là nguồn duy nhất; cột và dữ liệu staging còn', 'Có'],
    ['Dọn dữ liệu staging', 'ステージングデータを削除する', 'Bảng 物理列準備済み (sau huỷ); gõ đúng tên bảng', 'Xoá DỮ LIỆU của cột vật lý và value_original; JSON không đổi; cột còn', 'Dữ liệu staging mất (dựng lại bằng backfill)'],
    ['Gỡ cột', '物理列を削除してJSONに戻す', 'Bảng 物理列準備済み; các cột vật lý đều rỗng; gõ đúng tên bảng', 'Xoá các cột pcol_* và value_original (DDL); bảng thành JSON bình thường', 'Không hoàn tác được (phải chuyển đổi lại)'],
];

// Prerequisite sheet
$roles = [
    ['System Administrator (システム管理者)', 'Mở và thao tác trên 「テーブル保存方式の変換」; chọn 保存方式 khi tạo bảng/copy/import template; thêm/sửa/xoá cột.', 'Thao tác của người dùng khác bị từ chối với 「権限がありません。」.'],
    ['Người dùng nghiệp vụ', 'Dùng các màn hình dữ liệu như trước (xem, tạo, sửa, xoá, tìm, lọc, export theo quyền hiện có).', 'Không vào được màn hình chuyển đổi; quyền dữ liệu của bảng physical giống bảng JSON (đã kiểm với người dùng hạn chế).'],
    ['Tester / người vận hành kiểm thử', 'Cần quyền quản trị hệ thống, quyền chạy queue worker và khôi phục snapshot DB trên môi trường cô lập.', 'Không thực hiện trên production.'],
];
$envs = [
    ['MariaDB 10.4.32', 'ĐÃ kiểm: giao diện thật (nghiệm thu S, P, H) + test tự động', 'Môi trường nghiệm thu giao diện. Queue = database, UI tiếng Nhật.'],
    ['MySQL 8.0.46', 'ĐÃ kiểm bằng test tự động (không kiểm giao diện)', 'StoragePhysicalCreationTest 18/18; StorageStageBIntegrationTest 16 test.'],
    ['SQL Server (LocalDB MSSQLLocalDB)', 'ĐÃ kiểm bằng test tự động (không kiểm giao diện)', 'Exment backup/restore không khả dụng trên SQL Server: dùng sao lưu DB riêng. Một lỗi không liên quan ở FormViewSettingsCoverageTest (dữ liệu test dài hơn cột).'],
    ['MySQL khác 8.0, MariaDB phiên bản khác, SQL Server bản/edition khác', 'CHƯA kiểm', 'Không dùng cho dữ liệu thật khi chưa kiểm.'],
];
