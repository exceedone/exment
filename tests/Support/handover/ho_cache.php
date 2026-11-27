<?php
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** Sheet "Đề xuất EXMENT_USE_CACHE": what the flag does, what is verified, old measurements, a staging plan, rollback, open decisions. */
function build_cache_sheet(Worksheet $ws, int $r, array $C): int
{
    sectionBar($ws, "A$r:C$r", 'E. Đề xuất bật EXMENT_USE_CACHE (cache bền của Exment)', $C); $r++;
    $ws->mergeCells("A$r:C$r");
    $ws->setCellValue("A$r", 'Phần này là ĐỀ XUẤT để ra quyết định, không phải hướng dẫn đã được phê duyệt. Số đo bên dưới là số đo CŨ lấy từ tài liệu dự án (nhánh feature/performance-improvements-v7.0.2, DB loopback), không được đo lại cho bản này; phần đã kiểm trên bản hiện tại ghi riêng.');
    wrapTop($ws, "A$r:C$r"); $ws->getRowDimension($r)->setRowHeight(46); $ws->getStyle("A$r:C$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C['warn']); $r++;

    $rows3 = function (array $rows, array $heads) use ($ws, &$r, $C) {
        if (($heads[3] ?? '') !== '') { $heads[2] .= ' / ' . $heads[3]; }
        headerRow($ws, $r, array_slice($heads, 0, 3), $C); $r++;
        foreach ($rows as $row) {
            if (($row[3] ?? '') !== '') { $row[2] = trim($row[2] . "`n" . $row[3]); }
            $row = array_slice($row, 0, 3);
            foreach ($row as $i => $v) { $ws->setCellValueByColumnAndRow($i + 1, $r, $v); }
            wrapTop($ws, "A$r:C$r"); $ws->getStyle("A$r")->getFont()->setBold(true);
            $h = 18;
            foreach ($row as $i => $v) { $h = max($h, 13.5 * est((string)$v, [34, 62, 62][$i] - 4) + 4); }
            $ws->getRowDimension($r)->setRowHeight(min(400, $h));
            borders($ws, "A$r:C$r"); $r++;
        }
        $r++;
    };

    sectionBar($ws, "A$r:C$r", '1. Đề xuất', $C); $r++;
    $rows3([
        ['Đề xuất', "Bật EXMENT_USE_CACHE=true trên STAGING để (a) đo hiệu năng thật của cache và (b) kiểm cache cùng Hybrid Storage (chuyển trạng thái, tạo bảng physical, sửa cột). CHƯA đề xuất bật ở production cho đến khi có số đo trên staging và (nếu có nhiều máy chủ) đã kiểm nhiều node.", 'Lý do: không có số đo hiệu năng của cache hiện tại trên nhánh này; số đo cũ thuộc cache khác (v7.0.2); mọi số đo DB cũ ở loopback.', 'Cần bạn xác nhận (mục 7).'],
        ['Không bắt buộc để dùng Hybrid', 'Hybrid Storage hoạt động độc lập với cờ này: trạng thái lưu trữ nằm trong bảng custom_tables của DB; các quyết định quan trọng (chuyển trạng thái, kiểm bảng còn physical_read khi đổi cột, kiểm bảng rỗng khi khởi tạo, cổng ghi) đọc thẳng DB; việc chọn đọc từ cột vật lý hay JSON dùng model bảng đã nạp, được xoá cache sau mỗi lần chuyển trạng thái. Cờ chỉ ảnh hưởng tốc độ và nguy cơ cache cũ. Các nghiệm thu giao diện (S, P, H) chạy khi cờ ở mặc định (tắt); cache bền đã kiểm riêng với Stage B trên MySQL 8 (PF08).', 'Cờ này là cấu hình chung của Exment, không phải cờ của Hybrid.', ''],
        ['Điều kiện áp dụng', 'Cache này lưu metadata và cài đặt (bảng, cột, form, view, quyền), không gắn với chế độ lưu trữ nên áp dụng cho cả bảng JSON lẫn bảng physical. Đã kiểm: bảng chuyển từ JSON sang physical (MySQL 8, bản build trước giai đoạn 2). CHƯA kiểm: bảng tạo trực tiếp ở physical và thêm/sửa/xoá cột khi cờ bật - phải kiểm cho CẢ HAI loại bảng trước khi dùng (mục 5, bước 4).', 'Yêu cầu từ chủ sở hữu: chỉ chấp nhận khi áp dụng được cho cả JSON và bảng physical.', ''],
    ], ['Mục', 'Nội dung', 'Ghi chú', '']);

    sectionBar($ws, "A$r:C$r", '2. Cờ này làm gì (theo mã nguồn của nhánh hiện tại)', $C); $r++;
    $rows3([
        ['Mặc định', 'config/exment.php: use_cache = env(EXMENT_USE_CACHE, false) - TẮT.', 'Khi tắt: chỉ có bộ nhớ trong request (L0); không đọc/ghi store; System::clearCache() chỉ xoá bộ nhớ request.', ''],
        ['Khi bật', 'Giá trị cache (cài đặt hệ thống, metadata bảng/cột/form/view, quyền...) được giữ trong cache store của site với khoá exm:<thế hệ>:<khoá>, thời hạn 3600 giây.', 'Store lấy từ CACHE_STORE của site; nếu là file thì Exment dùng thư mục riêng "exment" bên trong. Khi metadata thay đổi: System::clearCache() tạo thế hệ mới (lưu trong DB) rồi dọn dữ liệu đã giữ của máy chủ này (file: xoá thư mục riêng; database: xoá dòng có tiền tố exm:; redis/memcached: để tự hết hạn).', ''],
        ['Liên quan Hybrid', 'Chuyển trạng thái lưu trữ (storage_mode/state/generation) cập nhật bảng custom_tables trực tiếp rồi gọi System::clearCacheAfterCommit(); các quyết định quan trọng của dịch vụ lưu trữ đọc DB trực tiếp (TableStorage::fresh), định tuyến truy vấn đọc trạng thái từ model bảng đã cache.', 'Đã kiểm: test "mọi bước chuyển, dọn dẹp, gỡ cột được request mới thấy ngay qua cache bền" (PF08).', ''],
        ['Cách đặt cờ', 'Đặt EXMENT_USE_CACHE=true trong .env, hoặc chọn mục exment_cache ở màn hình Tuning (Admin Setting > Tuning) - mục này ghi đúng biến trên vào .env. Máy chủ khoá Tuning (EXMENT_FEATURES_LOCKED=tuning) thì không dùng được màn hình này.', 'Ghi .env xoá config cache: chạy lại php artisan config:cache nếu đang dùng. Khởi động lại queue worker (php artisan queue:restart) để đọc cấu hình mới.', ''],
    ], ['Mục', 'Nội dung', 'Chi tiết', '']);

    sectionBar($ws, "A$r:C$r", '3. Cái gì đã kiểm trên bản hiện tại, cái gì chưa', $C); $r++;
    $rows3([
        ['ĐÃ kiểm', "• Cache bền bật + Stage B và 25 tệp test Hybrid trên MySQL 8 (PF08, build cũ).\n• Trên bản hiện tại (B2 + 3 sửa lỗi cache): StoragePhysicalCreationTest (18) và Stage B (16) với cache BẬT đạt trên MariaDB, MySQL 8, SQL Server (PF18); bộ hồi quy JSON đạt khi cache bật và tắt (MariaDB).\n• Cache cho cả bảng tạo trực tiếp ở physical lẫn bảng chuyển đổi: request kế tiếp thấy ngay trạng thái và danh sách cột ở mọi bước, kể cả rollback, huỷ, dọn, gỡ cột (PF17).\n• Ba sửa lỗi cache đã đưa vào nhánh (PF15, PF16): cách ly cache model theo người dùng (trước sửa 6/7 test lỗi), không giữ giá trị chưa commit trong store, bộ xoá cache cho View/Notify/Organization.", 'Sheet 06: PF08, PF15-PF18.', 'MariaDB, MySQL 8, SQL Server'],
        ['CHƯA kiểm', "• Hiệu năng của cache bền trên nhánh này (PF09); phần cải tiến request-level còn lại của cache v7.0.2 chưa được lấy (PF19).\n• Nhiều máy chủ/worker dùng chung store; redis, memcached; SQL Server làm store (PF10).\n• Plugin dựa vào hành vi xoá cache.", 'Sheet 06: PF09, PF10, PF19 (NOT TESTED).', ''],
        ['Lưu ý quan trọng về nguồn gốc', 'Nhánh hiện tại dùng cache của upstream 7.2.x (thế hệ trong DB). Cache chọn lọc (selective) và các số đo ở mục 4 thuộc nhánh v7.0.2; commit cache của nhánh đó (406b925d3) KHÔNG được đưa vào (xung đột kiến trúc ở System.php). Các lệnh exment:cache-prune và biến EXMENT_CACHE_CLEAR_MODE trong Cache_v7.0.2\\05_Operations.md KHÔNG có trong bản này; exment:cache-clear có.', 'Hybrid Progress: "Performance v7.0.2 - kiểm tra phần import/export trên kiến trúc lưu bảng".', ''],
    ], ['Mục', 'Nội dung', 'Bằng chứng', 'DB'])

    ;
    sectionBar($ws, "A$r:C$r", '4. Số đo CŨ (không đo lại cho bản này; nhánh feature/performance-improvements-v7.0.2, DB loopback)', $C); $r++;
    $rows3([
        ['Cache (v7.0.2, N=10)', "off −8…−17%; cold −15…−36%; warm −16…−38% (so với snapshot P3 của nhánh đó). Peak bộ nhớ warm 238–422 → 68–90 MB. Byte đọc warm 21,6–51,8 → 1,7–3,4 MB. Query warm +0…+4. Nội dung trang giống hệt.", 'Đường ghi: chế độ selective 0 flush (trước 10 flush khi lưu ngoài transaction). Race "đọc dữ liệu cũ sau flush": flush 3/3 bị stale, selective 0/3 (MySQL đa tiến trình). Nguồn: Cache_v7.0.2\\04_Progress.md, poc\\RESULT.md §13.', 'Sheet 06: PF07'],
        ['Import - validation', 'Bước 1: 1000 dòng 48,16 s → 4,32 s (11,2×); 2000 dòng 183,5 s → 7,92 s (23,2×). Bước 2: thêm −47…−51% thời gian validation toàn sheet. Bước 4: query validation 508 → 9.', 'Import trình duyệt 1000 dòng 54,9 s → 11,8 s (sau bước 1). Lỗi giống hệt bản cũ (md5).', 'PF01, PF02, PF04'],
        ['Import - tra cứu parent', 'Bước 3: conversion 9.377 → 1.546 ms (−83,5%); query 6.002 → 5; import đầy đủ (3000 dòng con) 29.195 → 21.438 ms (−27%).', '1000 cha + sheet con 3000 dòng.', 'PF03'],
        ['Export', 'Bước 6: query fetch cold 3.572 → 82 (admin); thời gian fetch cold −21…−31% (không đạt mục tiêu ≥ 50%); warm 0…+10%.', 'Người dùng hạn chế: 306 / 589 query. Peak bộ nhớ không tăng.', 'PF05, PF06'],
        ['Điều kiện đo', 'MariaDB 10.4 scratch loopback, PHP 8.2.35 không OPcache/Xdebug, cache array, 1 tiến trình/lần, median 3 lần xen kẽ.', 'Cần đo lại trên staging (DB từ xa, OPcache bật) trước khi kết luận về production.', ''],
    ], ['Hạng mục', 'Số đo', 'Ghi chú', 'Test ID']);

    sectionBar($ws, "A$r:C$r", '5. Kế hoạch bật và kiểm trên staging (đề xuất)', $C); $r++;
    $rows3([
        ['1. Chuẩn bị', 'Đảm bảo store cache phù hợp: nhiều node thì dùng store DÙNG CHUNG (redis hoặc database), không dùng file cục bộ từng node. Sao lưu .env. Ghi lại thời gian trang, số query, bộ nhớ của vài màn hình chuẩn (danh sách lớn, form có nhiều cột, import/export) khi cờ TẮT làm mốc.', 'Cùng DB/OPcache với staging thật.', ''],
        ['2. Bật', 'Đặt EXMENT_USE_CACHE=true (.env hoặc màn hình Tuning) → php artisan config:clear (hoặc config:cache) → php artisan queue:restart → php artisan exment:cache-clear.', 'Lệnh exment:cache-clear tạo thế hệ cache mới.', ''],
        ['3. Đo', 'Lặp lại các phép đo ở bước 1 (lần đầu = cold, lần sau = warm). So sánh với mốc cờ TẮT.', 'Tiêu chí đề xuất: warm không chậm hơn cờ tắt; nội dung trang giống hệt.', ''],
        ['4. Kiểm cùng Hybrid', "• Chuyển một bảng nhỏ JSON → physical (sheet 03) và kiểm từ một phiên/tab khác sau MỖI bước: trạng thái trên màn hình 「テーブル保存方式の変換」 khớp DB (custom_tables.storage_state).\n• Tạo bảng physical, thêm/sửa/xoá cột (sheet 02): danh sách cột, form và danh sách dữ liệu của phiên khác thấy ngay.\n• Quay về JSON (sheet 04) và kiểm tương tự.", 'Đây là phần đã kiểm ở mức test tự động (PF08); giao diện nhiều phiên chưa kiểm.', ''],
        ['5. Nhiều node (nếu có)', 'Sửa metadata/chuyển trạng thái ở node A và kiểm node B (và worker) thấy ngay; kiểm sau khi khởi động lại worker.', 'Chưa kiểm ở bất kỳ môi trường nào (PF10).', ''],
        ['6. Quyết định', 'Nếu đạt cả đo hiệu năng lẫn kiểm Hybrid: đề xuất bật ở production theo quy trình riêng (ngoài tài liệu này). Nếu không đạt: tắt lại (mục 6) và ghi kết quả vào sheet 06.', '', ''],
    ], ['Bước', 'Việc cần làm', 'Ghi chú', '']);

    sectionBar($ws, "A$r:C$r", '6. Quay lại (rollback)', $C); $r++;
    $rows3([
        ['Tắt cờ', 'Đặt EXMENT_USE_CACHE=false (hoặc xoá biến) → php artisan config:clear (hoặc config:cache) → php artisan queue:restart.', 'Khi tắt, Exment không đọc store nữa; dữ liệu đã giữ tự hết hạn (≤ 3600 giây) hoặc dọn bằng php artisan exment:cache-clear trước khi tắt.', ''],
        ['Không ảnh hưởng dữ liệu', 'Cache chỉ là bản sao đọc nhanh của dữ liệu trong DB; bật/tắt không đổi dữ liệu hay trạng thái lưu trữ của bảng.', 'Hybrid đọc DB trực tiếp ở các quyết định quan trọng (TableStorage::fresh).', ''],
    ], ['Mục', 'Cách làm', 'Ghi chú', '']);

    sectionBar($ws, "A$r:C$r", '7. Rủi ro và quyết định cần chốt', $C); $r++;
    $rows3([
        ['Rủi ro', "• Dữ liệu cache cũ sau khi đổi metadata/trạng thái nếu node không dùng chung store hoặc không dọn (file cục bộ nhiều node).\n• Plugin phụ thuộc hành vi xoá cache.\n• Chưa có số đo: lợi ích hiệu năng của cache hiện tại là kỳ vọng, chưa được chứng minh.", 'Giảm thiểu: store dùng chung, kiểm nhiều node, đo trước khi bật production.', ''],
        ['Quyết định cần chốt', "1. Có đồng ý bật EXMENT_USE_CACHE=true trên staging theo mục 5 không?\n2. Store nào cho staging/production (file, database, redis)?\n3. Có triển khai nhiều node/worker không (quyết định cần kiểm nhiều node)?\n4. Có đưa cache chọn lọc v7.0.2 vào nhánh này (cần xử lý xung đột kiến trúc ở System.php) hay giữ cache upstream?", 'Các câu hỏi Q1-Q9 trong Cache_v7.0.2 cũng chưa có câu trả lời (BL1).', ''],
    ], ['Mục', 'Nội dung', 'Ghi chú', '']);

    sectionBar($ws, "A$r:C$r", '8. Tài liệu nguồn', $C); $r++;
    $docs = [
        ['Cache v7.0.2 - tiến độ và số đo', 'C:\\laravel12-exment-core\\laravel-vector\\Documents\\40_Copilot\\Codex\\Cache_v7.0.2\\04_Progress.md'],
        ['Cache v7.0.2 - hướng dẫn vận hành', 'C:\\laravel12-exment-core\\laravel-vector\\Documents\\40_Copilot\\Codex\\Cache_v7.0.2\\05_Operations.md'],
        ['Import/Export v7.0.2 - tiến độ và số đo', 'C:\\laravel12-exment-core\\laravel-vector\\Documents\\40_Copilot\\Codex\\ImportExport_v7.0.2\\04_Progress.md'],
        ['Hybrid - kiểm tra cache bền và import/export trên nhánh hiện tại', 'C:\\laravel12-exment-core\\laravel-vector\\Documents\\40_Copilot\\Codex\\HybridStorage_v7.1\\04_Progress.md'],
    ];
    foreach ($docs as [$k, $p]) {
        $ws->setCellValue("A$r", $k); $ws->mergeCells("B$r:C$r");
        xlink($ws, "B$r", $p, 'file:///' . str_replace([' ', '\\'], ['%20', '/'], $p));
        wrapTop($ws, "A$r:C$r"); $ws->getStyle("A$r")->getFont()->setBold(true); borders($ws, "A$r:C$r"); $r++;
    }
    return $r;
}
