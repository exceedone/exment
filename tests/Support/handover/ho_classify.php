<?php
// Classification of every FAIL / BLOCKED result (handover A38). Statuses in sheet 06 are NOT changed:
// a later fix or decision does not turn a recorded FAIL into PASS without a re-run on the same scope.

$CLASS_KINDS = [
    'unverified' => 'Chưa xác định (harness hay hành vi thật) - cần tester kiểm tay',
    'testenv' => 'Lỗi test / dữ liệu test / môi trường (không phải lỗi sản phẩm đã xác nhận)',
    'historical' => 'Lịch sử, đã xử lý một phần - phần còn lại chưa chạy lại',
    'accepted' => 'Sai lệch so với mục tiêu đã được chủ sở hữu chấp nhận',
    'unsupported' => 'Chức năng chưa có (ngoài phạm vi) - cần quyết định trước khi bật production',
];

// id => [kind, feature, db/build, evidence, impact, next action, blocks handover, blocks release]
$CLASS = [
    'S08' => ['unverified', 'Chuyển đổi: bấm 「バックフィルを開始する」 khi worker dừng', 'MariaDB 10.4.32, B1 (2026-11-25)',
        'Ảnh 12, 13 (sheet 07); 04_Progress.md (mục nghiệm thu giao diện 17 kịch bản S01-S17)',
        'Chỉ thông báo 「処理を登録しました。」 không quan sát được; 6/7 kiểm tra đạt (trạng thái DB, job nằm trong hàng đợi, tự tiếp tục khi worker chạy lại). Không mất / không chạy trùng dữ liệu.',
        'Tester kiểm tay thông báo khi worker dừng (harness đã sửa nhưng CHƯA chạy lại). Nếu thông báo vắng thật: lỗi hiển thị mức thấp.', 'KHÔNG', 'KHÔNG (cần xác nhận trước khi bật chuyển đổi)'],
    'H01' => ['unverified', 'Tìm kiếm từ khoá (tham số query) của danh sách', 'MariaDB 10.4.32, B2 (2026-11-26)',
        'Ảnh 201-203; sheet 05 dòng "Tìm kiếm từ khoá"',
        'Trả 0 bản ghi ngay cả trên bảng JSON nền, nên không liên quan chuyển đổi và không chứng minh được tính tương đương JSON/physical của tìm kiếm từ khoá trên giao diện. 10/11 kiểm tra còn lại của H01 đạt. (Các test đơn vị LIKE của Kanban/Workflow đã đạt ở phạm vi riêng, KHÔNG thay thế phép kiểm giao diện.)',
        'Tester tìm từ khoá trên bảng JSON nền rồi trên bảng physical; xác định cách dùng ô tìm kiếm đúng. Chưa có tái hiện độc lập.', 'KHÔNG', 'CÓ (điều kiện kích hoạt: tương đương tìm kiếm từ khoá phải được xác nhận)'],
    'A09' => ['testenv', 'FormViewSettingsCoverageTest::testViewEditKeepsASortTarget (hồi quy JSON)', 'SQL Server LocalDB, B2 (2026-11-25)',
        'Kết quả PHPUnit (sheet 07 mục Kiểm tra tự động); 04_Progress.md (mục tổng hợp sheet 06, 46 test case)',
        'Dữ liệu test (tên view) dài hơn cột view_view_name: "String or binary data would be truncated". Lỗi nằm ở dữ liệu test, không liên quan Hybrid Storage; CHƯA đối chứng trên bản trước thay đổi và chưa chạy lại.',
        'Rút ngắn tên view trong test hoặc đối chứng baseline khi có lượt dọn test SQL Server (ngoài phạm vi bàn giao).', 'KHÔNG', 'KHÔNG'],
    'PF06' => ['accepted', 'Hiệu năng export (v7.0.2): giảm thời gian fetch ≥ 50%', 'MariaDB 10.4 cô lập, B0 (nhánh performance v7.0.2; số đo cũ, không chạy lại)',
        'ImportExport_v7.0.2\\04_Progress.md (Bước 6, Quyết định phạm vi); sheet 01 mục E.8',
        'Cold −21…−31% (mục tiêu −50%), warm chậm hơn nhẹ 0…+10% (CPU PHP). Số query đạt (PF05). Không phải lỗi chức năng; không thuộc Hybrid Storage.',
        'Không. Chủ sở hữu đã chấp nhận và kết thúc tối ưu.', 'KHÔNG', 'KHÔNG (sai lệch hiệu năng đã được chấp nhận)'],
    'PF13' => ['testenv', 'ExportReferencePreloadTest: 1/6 phụ thuộc thứ tự chạy', 'MariaDB 10.4 và MySQL 8.0, trước HEAD 5ccb2dcf0 (chưa chạy lại)',
        '04_Progress.md (mục test import/export trên nhánh hiện tại)',
        'testNothingIsRequestedWithoutAnExportColumnOrParentExportColumn đạt khi chạy riêng, lỗi khi chạy cả lớp (ngân sách đếm truy vấn metadata phụ thuộc thứ tự). Là hạn chế của test, không phải sai kết quả export.',
        'Chỉnh ngân sách / cô lập trạng thái trong test khi dọn test (chưa lên lịch).', 'KHÔNG', 'KHÔNG'],
    'PF14' => ['historical', 'SQL Server: ImportParentLookupTest và ImportValidationDescriptorsTest', 'SQL Server LocalDB, trước HEAD 5ccb2dcf0 (số liệu lịch sử, chưa chạy lại)',
        '04_Progress.md: mục test import/export (ghi lỗi); A30 (ImportParentLookupTest đã xử lý, SQL Server 10 test / 2675 assertion); A31 (sửa bind số của ParentItem, commit 11fa6fb00)',
        'ImportParentLookupTest: ĐÃ XỬ LÝ (lỗi test/môi trường ở A30 và lỗi sản phẩm bind số ở A31; có test hồi quy). ImportValidationDescriptorsTest (1 lỗi tràn int với \'12345678901234567890\'): CHƯA chạy lại trên SQL Server nên giữ FAIL.',
        'Chạy lại ImportValidationDescriptorsTest trên SQL Server khi có lượt kiểm tiếp; hiện chưa xác định là lỗi test hay sản phẩm.', 'KHÔNG', 'KHÔNG (phần ParentItem đã đóng; phần Descriptors cần chạy lại nếu dùng import trên SQL Server)'],
    'S16' => ['unsupported', 'JSON cleanup (dọn giá trị đã chuyển khỏi JSON)', 'MariaDB 10.4.32, B1',
        'Sheet 06 dòng S16; sheet 00 mục "KHÔNG thuộc phạm vi"',
        'Chưa có chức năng (các trạng thái cleaning/cleaned chưa triển khai): bóng JSON luôn được giữ, dung lượng DB tăng.',
        'Quyết định sản phẩm: có cần JSON cleanup trước khi bật production hay chấp nhận giữ bóng JSON.', 'KHÔNG', 'QUYẾT ĐỊNH (phải chốt trước khi bật)'],
    'S17' => ['unsupported', 'Chặn kỹ thuật người ghi trực tiếp vào DB/plugin', 'MariaDB 10.4.32, B1',
        'Sheet 06 dòng S17; sheet 00 mục 5',
        'Màn hình chỉ thu xác nhận của quản trị viên, không có cơ chế chặn. Ghi thẳng `value` khi bảng ở physical làm lệch cột vật lý (hạn chế đã biết).',
        'Quyết định vận hành: chấp nhận kiểm soát bằng quy trình/xác nhận hay phát triển cơ chế chặn.', 'KHÔNG', 'QUYẾT ĐỊNH (phải chốt trước khi bật)'],
];

foreach ($TC as &$t) {
    if (isset($CLASS[$t['id']]) && in_array($t['status'], ['FAIL', 'BLOCKED'], true)) {
        $c = $CLASS[$t['id']];
        $t['actual'] = '[PHÂN LOẠI 2026-11-27: ' . $CLASS_KINDS[$c[0]] . '. Chặn bàn giao: ' . $c[6] . '; chặn release: ' . $c[7] . ". Trạng thái giữ nguyên, KHÔNG đổi thành PASS.]\n" . $t['actual'];
    }
}
unset($t);
