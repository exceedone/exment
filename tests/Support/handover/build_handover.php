<?php
// Builds the handover workbook (8 sheets) from the verified evidence. Run: php build_handover.php
require (getenv('HANDOVER_AUTOLOAD') ?: 'vendor/autoload.php');
require __DIR__ . '/ho_content.php';
require __DIR__ . '/ho_tests.php';
require __DIR__ . '/ho_cache.php';
require __DIR__ . '/ho_classify.php';


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$DOC = getenv('HANDOVER_DOC_DIR') ?: __DIR__;
$E1 = "$DOC/evidence/2026-11-25_ui_acceptance";
$E2 = "$DOC/evidence/2026-11-25_ui_acceptance_physical_creation";
$E3 = "$DOC/evidence/2026-11-26_handover_regression";
$IMGDIR = "$DOC/evidence/handover_images";
$OUT = getenv("HO_OUT") ?: "$DOC/Hybrid_Storage_Handover_Tester_User_vi.xlsx";
@mkdir($IMGDIR, 0777, true);
$EVDIRS = [$E3, $E2, $E1]; // current build first

const FONT = 'Meiryo UI';
$C = ['pass' => ['bg' => 'C6EFCE', 'fg' => '006100'], 'fail' => ['bg' => 'FFC7CE', 'fg' => '9C0006'], 'blocked' => ['bg' => 'D9D9D9', 'fg' => '404040'],
      'nt' => ['bg' => 'FFEB9C', 'fg' => '9C5700'], 'head' => '1F3864', 'sub' => 'D9E2F3', 'grey' => 'F2F2F2', 'warn' => 'FCE4D6'];
$SHEETS = ['00_Tổng quan', '01_Điều kiện sử dụng', '02_Tạo bảng và quản lý cột', '03_Chuyển JSON sang Physical', '04_Quay lại JSON và phục hồi', '05_Kiểm tra nghiệp vụ', '06_Test cases và kết quả', '07_Evidence'];

// ------------------------------------------------------------------ helpers
function findShot(array $dirs, string $key): ?string
{
    foreach ($dirs as $d) {
        $m = glob("$d/*_{$key}.png");
        if ($m) { return $m[0]; }
    }
    return null;
}
function evidenceBuild(string $path): string
{
    return strpos($path, '2026-11-25_ui_acceptance/') !== false ? 'B1' : 'B2';
}
/** Readable JPEG copy: sidebar cut; full pages of the conversion screen keep status / progress / L5 / actions / history cards. */
function prepImage(string $png, string $dir, int $maxW): ?array
{
    $src = @imagecreatefrompng($png);
    if (!$src) { return null; }
    $w = imagesx($src); $h = imagesy($src);
    $x0 = 236; $cw = $w - $x0;
    $segs = []; $in = false; $st = 0;
    for ($y = 0; $y < $h; $y++) {
        $inCard = (imagecolorat($src, 245, $y) & 0xffffff) !== 0xffffff;
        if ($inCard && !$in) { $in = true; $st = $y; }
        if (!$inCard && $in) { $in = false; $segs[] = [$st, $y - $st]; }
    }
    if ($in) { $segs[] = [$st, $h - $st]; }
    $segs = array_values(array_filter($segs, fn ($sg) => $sg[1] > 40));
    $pick = [];
    if ($h > 1300 && count($segs) >= 5) {
        $isIndexCard = function (array $sg) use ($src) {
            for ($yy = $sg[0]; $yy < $sg[0] + $sg[1]; $yy += 4) { for ($xx = 300; $xx < 1300; $xx += 20) { $c = imagecolorat($src, $xx, $yy); if ((($c >> 16) & 255) < 30 && (($c >> 8) & 255) > 170 && ($c & 255) > 210) { return true; } } }
            return false;
        };
        foreach ($segs as $i => $sg) { if ($i === 1 || $isIndexCard($sg)) { continue; } $pick[] = $sg; }
    } elseif ($h > 1300) {
        $pick = [[60, min($h - 60, 1000)]];
    } else {
        $pick = [[50, $h - 50]];
    }
    $gap = 12; $th = 0;
    foreach ($pick as $sg) { $th += $sg[1] + $gap; }
    $d0 = imagecreatetruecolor($cw, $th);
    imagefill($d0, 0, 0, 0xffffff);
    $y = 0;
    foreach ($pick as $sg) { imagecopy($d0, $src, 0, $y, $x0, $sg[0], $cw, $sg[1]); $y += $sg[1] + $gap; }
    $scale = min($maxW / $cw, 1.0);
    $nw = (int)round($cw * $scale); $nh = (int)round($th * $scale);
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $d0, 0, 0, 0, 0, $nw, $nh, $cw, $th);
    $out = "$dir/" . pathinfo($png, PATHINFO_FILENAME) . "_w{$maxW}.jpg";
    imagejpeg($dst, $out, 92);
    return [$out, $nw, $nh];
}
function est(string $text, int $chars): int
{
    $n = 0;
    foreach (preg_split('/\R/u', $text) as $p) { $n += max(1, (int)ceil(mb_strwidth($p) / max(1, $chars))); }
    return $n;
}
function statusStyle(Worksheet $ws, string $cell, string $status, array $C): void
{
    $k = ['PASS' => 'pass', 'FAIL' => 'fail', 'BLOCKED' => 'blocked'][$status] ?? 'nt';
    $ws->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C[$k]['bg']);
    $ws->getStyle($cell)->getFont()->setBold(true)->getColor()->setRGB($C[$k]['fg']);
    $ws->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_TOP);
}
function titleBar(Worksheet $ws, string $range, string $text, array $C, int $size = 15): void
{
    $ws->mergeCells($range);
    $first = explode(':', $range)[0];
    $ws->setCellValue($first, $text);
    $ws->getStyle($range)->getFont()->setBold(true)->setSize($size)->getColor()->setRGB('FFFFFF');
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C['head']);
    $ws->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $ws->getRowDimension((int)preg_replace('/\D/', '', $first))->setRowHeight(30);
}
function sectionBar(Worksheet $ws, string $range, string $text, array $C): void
{
    $ws->mergeCells($range);
    $first = explode(':', $range)[0];
    $ws->setCellValue($first, $text);
    $ws->getStyle($range)->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('1F3864');
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C['sub']);
    $ws->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension((int)preg_replace('/\D/', '', $first))->setRowHeight(22);
}
function borders(Worksheet $ws, string $range): void
{
    $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFBFBF');
}
function wrapTop(Worksheet $ws, string $range): void
{
    $ws->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
}
function headerRow(Worksheet $ws, int $r, array $heads, array $C, int $col = 1): void
{
    foreach ($heads as $i => $h) { $ws->setCellValueByColumnAndRow($col + $i, $r, $h); }
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + count($heads) - 1);
    $first = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
    $ws->getStyle("$first$r:$last$r")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle("$first$r:$last$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
    $ws->getStyle("$first$r:$last$r")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension($r)->setRowHeight(24);
}
function xlink(Worksheet $ws, string $cell, string $text, string $target): void
{
    $ws->setCellValue($cell, $text);
    $ws->getCell($cell)->getHyperlink()->setUrl($target);
    $ws->getStyle($cell)->getFont()->setUnderline(true)->getColor()->setRGB('0563C1');
}
function pic(Worksheet $ws, string $path, string $cell, int $w, int $h): void
{
    $d = new Drawing();
    $d->setPath($path); $d->setResizeProportional(false); $d->setWidth($w); $d->setHeight($h);
    $d->setCoordinates($cell); $d->setOffsetX(4); $d->setOffsetY(4); $d->setWorksheet($ws);
}
/** Text rows of a key/value table. */
function kv(Worksheet $ws, int &$r, array $rows, array $C, int $lw = 1, string $lastCol = 'E'): void
{
    foreach ($rows as [$k, $v]) {
        $ws->setCellValue("A$r", $k); $ws->mergeCells("B$r:$lastCol$r"); $ws->setCellValue("B$r", $v);
        $ws->getStyle("A$r")->getFont()->setBold(true);
        wrapTop($ws, "A$r:$lastCol$r");
        $ws->getRowDimension($r)->setRowHeight(max(18, 13.5 * max(est($k, 26), est($v, 120)) + 4));
        borders($ws, "A$r:$lastCol$r"); $r++;
    }
}
/**
 * One step: A Bước | B Hành động | C Kết quả mong đợi | D Ảnh chụp | E Ghi chú. The block spans as many rows as the image needs;
 * the text cells are merged over the block.
 */
function stepBlock(Worksheet $ws, int &$r, string $no, array $step, array $dirs, string $imgDir, array $C, array &$used, string $sheetName): void
{
    [$action, $expected, $key, $note] = $step;
    $img = null;
    if ($key) {
        $p = findShot($dirs, $key);
        if ($p) { $img = prepImage($p, $imgDir, 640); $used[] = [$key, $p, $no, $sheetName]; }
    }
    $rowH = 36;
    $textRows = max(est($action, 50), est($expected, 40), est((string)$note, 46));
    $need = (int)ceil((13.5 * $textRows + 8) / $rowH);
    $imgRows = $img ? (int)ceil(($img[2] * 0.75 + 8) / $rowH) : 0;
    $n = max(1, $need, $imgRows);
    $end = $r + $n - 1;
    for ($i = $r; $i <= $end; $i++) { $ws->getRowDimension($i)->setRowHeight($rowH); }
    foreach (['A', 'B', 'C', 'E'] as $col) { if ($n > 1) { $ws->mergeCells("$col$r:$col$end"); } }
    if ($n > 1) { $ws->mergeCells("D$r:D$end"); }
    $ws->setCellValue("A$r", $no); $ws->setCellValue("B$r", $action); $ws->setCellValue("C$r", $expected);
    $ws->setCellValue("E$r", $note === null ? '' : $note);
    if ($img) { pic($ws, $img[0], "D$r", $img[1], $img[2]); } else { $ws->setCellValue("D$r", $key ? '(ảnh không có trong evidence)' : '(không có ảnh chụp cho bước này)'); $ws->getStyle("D$r")->getFont()->setItalic(true)->getColor()->setRGB('7F7F7F'); }
    wrapTop($ws, "A$r:E$end");
    $ws->getStyle("A$r")->getFont()->setBold(true);
    borders($ws, "A$r:E$end");
    $r = $end + 1;
}
function guideSheet(Worksheet $ws, string $title, string $intro, array $sections, array $dirs, string $imgDir, array $C, array &$used, string $prefix, string $sheetName, array $extraTop = []): void
{
    $ws->setShowGridLines(false);
    foreach (['A' => 7, 'B' => 52, 'C' => 42, 'D' => 92, 'E' => 48] as $c => $w) { $ws->getColumnDimension($c)->setWidth($w); }
    titleBar($ws, 'A1:E1', $title, $C);
    $ws->mergeCells('A2:E2'); $ws->setCellValue('A2', $intro); wrapTop($ws, 'A2:E2');
    $ws->getStyle('A2:E2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C['warn']);
    $ws->getRowDimension(2)->setRowHeight(13.5 * est($intro, 230) + 8);
    xlink($ws, 'A3', '« Về 00_Tổng quan', "#'00_Tổng quan'!A1");
    $r = 5;
    foreach ($extraTop as $fn) { $fn($ws, $r); }
    foreach ($sections as $sec) {
        sectionBar($ws, "A$r:E$r", $sec['title'], $C); $r++;
        headerRow($ws, $r, ['Bước', 'Hành động', 'Kết quả mong đợi', 'Ảnh chụp màn hình', 'Ghi chú / giới hạn'], $C); $r++;
        foreach ($sec['steps'] as $i => $st) { stepBlock($ws, $r, $prefix . $sec['id'] . ($i + 1), $st, $dirs, $imgDir, $C, $used, $sheetName); }
        $r++;
    }
    $ws->freezePane('A5');
}

$wb = new Spreadsheet();
$wb->getDefaultStyle()->getFont()->setName(FONT)->setSize(10);
$used = []; // [shot key, file, step no, sheet]

// ------------------------------------------------------------------ results (loaded by ho_tests.php): $TC rows, $counts
// ================================================================== 00_Tổng quan
$ws0 = $wb->getActiveSheet(); $ws0->setTitle($SHEETS[0]); $ws0->setShowGridLines(false);
foreach (['A' => 30, 'B' => 44, 'C' => 44, 'D' => 20, 'E' => 24] as $c => $w) { $ws0->getColumnDimension($c)->setWidth($w); }
titleBar($ws0, 'A1:E1', 'Hybrid Storage (lưu bảng JSON / cột vật lý) - Tài liệu bàn giao cho tester và người dùng', $C, 16);
$r = 3;
sectionBar($ws0, "A$r:E$r", '1. Mục đích', $C); $r++;
kv($ws0, $r, [
    ['Tài liệu này dùng để', 'Hướng dẫn tester và người vận hành thao tác, kiểm tra và đánh giá chức năng lưu giá trị của bảng dữ liệu Exment bằng CỘT VẬT LÝ (physical) thay vì chỉ lưu trong cột JSON `value`. Đọc được độc lập, không cần đọc lại trao đổi phát triển.'],
    ['Nguyên tắc', 'Chỉ mô tả chức năng ĐÃ triển khai. Mỗi kết quả ghi rõ DB và build đã chạy; phần chưa kiểm ghi NOT TESTED, phần chưa có chức năng ghi BLOCKED. Không có ảnh hay kết quả nào được dựng lại: mọi ảnh là ảnh chụp thật từ lần chạy (thư mục evidence ở sheet 07).'],
    ['Phạm vi quan trọng', 'Chuyển đổi và tạo bảng physical CHƯA được bật ở production (môi trường production giữ trạng thái không khả dụng). Các hướng dẫn trong tài liệu áp dụng cho môi trường KIỂM THỬ cô lập; xem sheet 01.'],
], $C);
$r++;
sectionBar($ws0, "A$r:E$r", '2. Bản build / release', $C); $r++;
kv($ws0, $r, [
    ['Nhánh / commit', $BUILD['head']],
    ['Build của các kết quả', $BUILD['b1'] . "\n" . $BUILD['b2'] . "\n" . $BUILD['b3']],
    ['Ngày kiểm', '2026-11-25 (nghiệm thu chuyển đổi và tạo bảng physical) và 2026-11-26 (hồi quy nghiệp vụ, kiểm tra bàn giao)'],
    ['Giao diện kiểm', 'Microsoft Edge (headless), giao diện tiếng Nhật (APP_LOCALE=ja), DB MariaDB 10.4.32 cô lập, queue worker thật (QUEUE_CONNECTION=database).'],
], $C);
$r++;
sectionBar($ws0, "A$r:E$r", '3. Phạm vi', $C); $r++;
kv($ws0, $r, [
    ['ĐÃ triển khai', "• Chuyển bảng nghiệp vụ JSON → physical bằng giao diện 「テーブル保存方式の変換」 (chuẩn bị → ghi đôi → backfill → xác minh → báo cáo L5 → chuyển đọc).\n• Tạo bảng nghiệp vụ mới trực tiếp ở dạng physical (ô 「保存方式」), kèm trạng thái 「初期化中」 để phục hồi khi khởi tạo dở.\n• Thêm / sửa / xoá cột trên bảng physical (đơn↔đa trị chỉ khi cột rỗng).\n• Copy bảng và import template với lựa chọn chế độ lưu trữ cho bảng mới.\n• Quay lại JSON: rollback nhanh, huỷ, dọn staging, gỡ cột (bốn thao tác riêng)."],
    ['Hiệu năng cache, import, export', 'Số đo CŨ của nhánh performance v7.0.2 (cache, tối ưu import, export) được ghi ở sheet 06 (PF01-PF14, kèm nguồn/DB/build) và sheet 05; KHÔNG đo lại cho bản này. Đề xuất bật EXMENT_USE_CACHE nằm ở sheet 01 (mục E).'],
    ['KHÔNG thuộc phạm vi', "• Bật chuyển đổi ở production; triển khai.\n• JSON cleanup (xoá giá trị đã chuyển khỏi JSON): chưa có chức năng.\n• Chặn kỹ thuật người ghi trực tiếp vào DB: chưa có (chỉ có xác nhận của người vận hành).\n• Đổi mặc định bảng mới sang physical: chưa quyết định (mặc định vẫn là JSON).\n• Chuyển đổi dữ liệu khi đổi cột đơn trị ↔ đa trị đã có giá trị."],
], $C);
$r++;
sectionBar($ws0, "A$r:E$r", '3b. Backlog hiện hành, khoảng trống nghiệm thu và quyết định (cập nhật 2026-11-27)', $C); $r++;
kv($ws0, $r, [
    ['Lỗi sản phẩm đã xác nhận còn mở', 'KHÔNG CÓ. Các mục lịch sử (V5-2, join pivot đa trị A33, ItemTrait/API/Dashboard, workflow đa trị theo tổ chức, parent_id/parent_type) đã sửa hoặc bị bác bỏ; xem sheet 06 (giữ nguyên bằng chứng lịch sử).'],
    ['Khoảng trống nghiệm thu (NOT TESTED)', "• Nghiệm thu trình duyệt view tổng hợp pivot đa trị trên MySQL 8 và SQL Server (chỉ có MariaDB).\n• Pivot đa trị CÓ index trên bảng con ở dạng PHYSICAL (cột đa trị luôn là JSON nên đây là tình huống cần kiểm, KHÔNG phải không áp dụng).\n• Giá trị select_primary_key sai khi import qua request chỉnh tay (mối lo chưa kiểm chứng, mức thấp)."],
    ['Chưa xác định nguyên nhân', 'Cảnh báo 「物理列が不足」 của bảng 39: KHÔNG tái hiện được; nguyên nhân gốc CHƯA xác định (không phân loại là lỗi môi trường đã xác nhận). Bảng 39 hiện ở trạng thái verifying, schema đủ. Suy luận chưa kiểm chứng: cửa sổ ngắn khi provision() chiếm trạng thái provisioned trước DDL.'],
    ['Cần quyết định nghiệp vụ / phân quyền', 'Số liệu view tổng hợp qua pivot (đơn trị và đa trị) ĐẾM CẢ các bản ghi con mà người dùng không có quyền xem (truy vấn con không lọc theo quyền; không lộ chi tiết bản ghi). Hành vi giữ nguyên; cần chủ sản phẩm duyệt về phân quyền. Ngoài ra các quyết định đã ghi: V-10, D-1…D-9, cột đa trị trên bảng đã chuyển, số 0 đứng đầu, hạn mức index tự động, và quyết định bật chuyển đổi production.'],
    ['failed_jobs 1-5', 'Lịch sử (worker thiếu biến EXMENT_TEST_*, lỗi conversion is not available); giữ nguyên, KHÔNG retry (1-2 là provision bảng 38 chưa được yêu cầu; 3-5 đã lỗi thời so với trạng thái hiện tại của bảng 39).'],
], $C);
$r++;
sectionBar($ws0, "A$r:E$r", '3c. Phân loại 6 FAIL và 2 BLOCKED (2026-11-27; trạng thái ở sheet 06 giữ nguyên)', $C); $r++;
headerRow($ws0, $r, ['ID - tính năng', 'Phân loại và bằng chứng', 'Tác động và bước tiếp theo', 'DB / build', 'Chặn bàn giao / chặn release'], $C); $r++;
foreach ($CLASS as $cid => $c) {
    $cells = [$cid . ' - ' . $c[1], $CLASS_KINDS[$c[0]] . "\nBằng chứng: " . $c[3], $c[4] . "\nBước tiếp: " . $c[5], $c[2], "Bàn giao: {$c[6]}\nRelease: {$c[7]}"];
    foreach ($cells as $i => $v) { $ws0->setCellValueByColumnAndRow($i + 1, $r, $v); }
    wrapTop($ws0, "A$r:E$r"); $ws0->getStyle("A$r")->getFont()->setBold(true);
    $ws0->getRowDimension($r)->setRowHeight(min(400, max(30, 13.5 * max(est($cells[1], 42), est($cells[2], 42), est($cells[0], 28), est($cells[3], 18), est($cells[4], 22)) + 6)));
    borders($ws0, "A$r:E$r"); $r++;
}
$nA = count(array_filter($TC, fn ($t) => $t['status'] === 'FAIL'));
kv($ws0, $r, [
    ['Kết luận phân loại', "Lỗi sản phẩm HIỆN HÀNH đã xác nhận: 0. Trong $nA FAIL: 2 chưa xác định cần tester kiểm tay (S08, H01); 2 lỗi test/dữ liệu test (A09, PF13); 1 lịch sử đã xử lý một phần (PF14); 1 sai lệch hiệu năng đã được chấp nhận (PF06). 2 BLOCKED là chức năng chưa có, cần quyết định (S16, S17). Không FAIL nào được coi là lỗi đang mở của bản này; cũng không FAIL nào được đổi thành PASS vì chưa chạy lại cùng phạm vi."],
], $C);
$r++;
sectionBar($ws0, "A$r:E$r", '4. JSON và physical khác nhau thế nào', $C); $r++;
headerRow($ws0, $r, ['Khía cạnh', 'Bảng JSON (mặc định)', 'Bảng physical', 'Ghi chú'], $C); $ws0->mergeCells("D$r:E$r"); $r++;
$cmp = [
    ['Nơi lưu giá trị', 'Cột JSON `value` của bảng giá trị', 'Cột vật lý có kiểu pcol_<mã cột> cho từng cột đơn trị, VÀ bóng JSON `value`', 'Cột đa trị luôn chỉ ở JSON.'],
    ['Nguồn đọc (読み取り元)', 'json', 'physical (trạng thái 「物理列から読み取り」)', 'Hiển thị trong màn hình chuyển đổi.'],
    ['Ghi dữ liệu', 'Chỉ ghi JSON', 'Ghi cột vật lý, value_original VÀ bóng JSON trong cùng một lệnh', 'Plugin/SQL ghi thẳng `value` vẫn thấy dữ liệu nhưng cột vật lý không được cập nhật: không được ghi trực tiếp.'],
    ['Giá trị không hợp kiểu', 'Hiển thị nguyên văn', 'Hiển thị nguyên văn nhờ value_original (không bị trống)', 'Khác biệt có chủ đích (L5): lọc/sắp xếp/tổng hợp coi ô đó là rỗng.'],
    ['Màn hình người dùng', 'Như trước', 'Không đổi (xem sheet 05)', 'Thứ tự dòng có cùng giá trị khoá sắp xếp không được đảm bảo.'],
    ['Quay lại', '-', 'Có (sheet 04)', 'Rollback không xoá cột; gỡ cột là thao tác riêng.'],
];
foreach ($cmp as $row) {
    foreach ($row as $i => $v) { $ws0->setCellValueByColumnAndRow($i + 1, $r, $v); }
    $ws0->mergeCells("D$r:E$r"); wrapTop($ws0, "A$r:E$r"); $ws0->getRowDimension($r)->setRowHeight(max(18, 13.5 * max(est($row[1], 42), est($row[2], 42), est($row[3], 40)) + 4)); $ws0->getStyle("A$r")->getFont()->setBold(true); borders($ws0, "A$r:E$r"); $r++;
}
$r++;
sectionBar($ws0, "A$r:E$r", '5. Bóng JSON (JSON shadow) được giữ lại', $C); $r++;
kv($ws0, $r, [
    ['Là gì', 'Trên bảng physical, mọi lần ghi vẫn cập nhật đầy đủ cột JSON `value` bên cạnh cột vật lý. Bản JSON này là "bóng": luôn khớp với dữ liệu để (a) quay lại JSON nhanh, (b) plugin/mã cũ đọc `value` vẫn thấy đủ dữ liệu, (c) đối chiếu nhất quán (検証).'],
    ['Hệ quả cho người dùng', 'Dung lượng DB tăng (dữ liệu được lưu ở hai nơi). Bản này KHÔNG có bước dọn JSON (JSON cleanup) - bóng JSON luôn được giữ.'],
    ['Điều cần tránh', 'Không ghi trực tiếp vào bảng bằng SQL hoặc plugin ghi thẳng `value` khi bảng đang chuyển đổi / ở physical: hệ thống không chặn được về mặt kỹ thuật; người vận hành xác nhận điều này ở các hộp kiểm 「直接書き込みの停止・除外の確認」.'],
], $C);
$r++;
sectionBar($ws0, "A$r:E$r", '6. Trạng thái của một bảng (màn hình 状態)', $C); $r++;
headerRow($ws0, $r, ['Trạng thái (JA)', 'Giá trị DB', 'Ý nghĩa', 'Đọc từ / ghi vào', ''], $C); $r++;
$states = [
    ['JSON', 'json', 'Bảng bình thường', 'JSON / JSON'],
    ['物理列準備済み', 'provisioned', 'Đã tạo cột vật lý rỗng; chưa dùng', 'JSON / JSON'],
    ['二重書き込み中', 'dualwrite', 'Ghi đồng thời JSON và cột vật lý', 'JSON / cả hai'],
    ['バックフィル中', 'backfilling', 'Đang đổ dữ liệu cũ vào cột vật lý', 'JSON / cả hai'],
    ['検証中', 'verifying', 'Đang đối chiếu JSON với cột vật lý', 'JSON / cả hai'],
    ['確認待ち', 'awaiting_confirmation', 'Chờ System Administrator xác nhận L5 để chuyển đọc', 'JSON / cả hai'],
    ['物理列から読み取り', 'physical_read', 'Đọc từ cột vật lý; vẫn ghi bóng JSON', 'physical / cả hai + JSON'],
    ['初期化中', 'initializing', 'Bảng tạo trực tiếp ở physical đang chuẩn bị schema: chặn dữ liệu', '(chặn) / (chặn)'],
];
foreach ($states as $row) {
    foreach ($row as $i => $v) { $ws0->setCellValueByColumnAndRow($i + 1, $r, $v); }
    wrapTop($ws0, "A$r:D$r"); $ws0->getRowDimension($r)->setRowHeight(max(18, 13.5 * est($row[2], 42) + 4)); borders($ws0, "A$r:D$r"); $r++;
}
$r++;
sectionBar($ws0, "A$r:E$r", '7. Tổng hợp kết quả kiểm (số liệu lấy từ sheet 06)', $C); $r++;
headerRow($ws0, $r, ['Trạng thái', 'Số test case', 'Ý nghĩa', '', ''], $C); $r++;
foreach ([['PASS', 'Đã chạy và đạt'], ['FAIL', 'Đã chạy và không đạt (xem Kết quả thực tế)'], ['BLOCKED', 'Không chạy được vì chức năng chưa tồn tại / điều kiện bắt buộc không có'], ['NOT TESTED', 'Chưa chạy']] as [$st, $mean]) {
    $ws0->setCellValue("A$r", $st); statusStyle($ws0, "A$r", $st, $C);
    $ws0->setCellValue("B$r", (string)count(array_filter($TC, fn ($t) => $t['status'] === $st))); $ws0->setCellValue("C$r", $mean);
    wrapTop($ws0, "B$r:C$r"); borders($ws0, "A$r:C$r"); $r++;
}
$ws0->setCellValue("A$r", 'Tổng'); $ws0->setCellValue("B$r", (string)count($TC)); $ws0->getStyle("A$r:B$r")->getFont()->setBold(true); borders($ws0, "A$r:C$r"); $r++;
$ws0->setCellValue("A$r", 'Lỗi hiện hành'); $ws0->setCellValue("B$r", '0');
$ws0->setCellValue("C$r", "Số FAIL/BLOCKED KHÔNG đồng nghĩa lỗi đang mở: FAIL = 2 chưa xác định + 2 lỗi test + 1 lịch sử một phần + 1 sai lệch đã chấp nhận; BLOCKED = 2 chức năng chưa có (xem mục 3c). Lỗi sản phẩm hiện hành đã xác nhận: 0.");
$ws0->getStyle("A$r")->getFont()->setBold(true); wrapTop($ws0, "A$r:C$r"); $ws0->getRowDimension($r)->setRowHeight(58); borders($ws0, "A$r:C$r"); $r += 2;
sectionBar($ws0, "A$r:E$r", '8. Bản đồ các sheet', $C); $r++;
$map = [
    [$SHEETS[1], 'Vai trò, môi trường được hỗ trợ, sao lưu, queue, điều kiện bảng, kích hoạt kiểm thử so với vận hành production, đề xuất bật EXMENT_USE_CACHE (mục E)'],
    [$SHEETS[2], 'Chọn chế độ lưu trữ khi tạo bảng, thêm/sửa/xoá cột, khởi tạo dở (初期化中), copy bảng, import template'],
    [$SHEETS[3], 'Các bước chuyển JSON → physical trên giao diện, kèm ảnh và điều kiện kiểm tra'],
    [$SHEETS[4], 'Rollback, huỷ, dọn staging, gỡ cột (bốn thao tác riêng) và phục hồi sau gián đoạn'],
    [$SHEETS[5], 'Hồi quy nghiệp vụ: CRUD, tìm kiếm, lọc, sắp xếp, tổng hợp, import/export, quan hệ, workflow'],
    [$SHEETS[6], 'Danh sách test case, kết quả thực tế, trạng thái, evidence, DB và build (có bộ lọc)'],
    [$SHEETS[7], 'Ảnh chụp thật và kiểm tra DB, liên kết với test ID và bước hướng dẫn'],
];
foreach ($map as [$sn, $desc]) { xlink($ws0, "A$r", $sn, "#'$sn'!A1"); $ws0->mergeCells("B$r:E$r"); $ws0->setCellValue("B$r", $desc); wrapTop($ws0, "A$r:E$r"); $ws0->getRowDimension($r)->setRowHeight(max(18, 13.5 * est($desc, 120) + 4)); borders($ws0, "A$r:E$r"); $r++; }
$r++;
kv($ws0, $r, [['Bảo mật', 'Tài liệu và evidence không chứa mật khẩu, token hay thông tin xác thực. Mật khẩu người dùng thử là giá trị ngẫu nhiên tạm thời, được đặt lại sau mỗi lần chạy.']], $C);

// ================================================================== 01_Điều kiện sử dụng
$ws1 = $wb->createSheet(); $ws1->setTitle($SHEETS[1]); $ws1->setShowGridLines(false);
foreach (['A' => 34, 'B' => 62, 'C' => 62] as $c => $w) { $ws1->getColumnDimension($c)->setWidth($w); }
titleBar($ws1, 'A1:C1', '01 - Điều kiện sử dụng', $C);
xlink($ws1, 'A2', '« Về 00_Tổng quan', "#'00_Tổng quan'!A1");
$r = 4;
sectionBar($ws1, "A$r:C$r", 'A. Vai trò', $C); $r++;
headerRow($ws1, $r, ['Vai trò', 'Được làm', 'Giới hạn'], $C); $r++;
foreach ($roles as $row) { foreach ($row as $i => $v) { $ws1->setCellValueByColumnAndRow($i + 1, $r, $v); } wrapTop($ws1, "A$r:C$r"); $ws1->getRowDimension($r)->setRowHeight(max(18, 13.5 * max(est($row[1], 60), est($row[2], 60), est($row[0], 32)) + 4)); borders($ws1, "A$r:C$r"); $r++; }
$r++;
sectionBar($ws1, "A$r:C$r", 'B. Môi trường được hỗ trợ (theo kết quả kiểm)', $C); $r++;
headerRow($ws1, $r, ['Môi trường', 'Mức kiểm chứng', 'Ghi chú'], $C); $r++;
foreach ($envs as $row) { foreach ($row as $i => $v) { $ws1->setCellValueByColumnAndRow($i + 1, $r, $v); } wrapTop($ws1, "A$r:C$r"); $ws1->getRowDimension($r)->setRowHeight(max(18, 13.5 * max(est($row[1], 60), est($row[2], 60), est($row[0], 32)) + 4)); borders($ws1, "A$r:C$r"); $r++; }
$r++;
sectionBar($ws1, "A$r:C$r", 'C. Sao lưu, queue và điều kiện bảng', $C); $r++;
headerRow($ws1, $r, ['Hạng mục', 'Yêu cầu', 'Cách kiểm tra / lưu ý'], $C); $r++;
$prereq = [
    ['Sao lưu', 'Sao lưu DB và thư mục tệp (storage/app/admin) trước khi chuyển đổi hoặc thử trên bảng có dữ liệu thật. Rollback KHÔNG khôi phục dữ liệu đã bị xoá bởi thao tác khác.', 'MariaDB/MySQL: dump DB. SQL Server: Exment backup/restore không khả dụng - dùng công cụ sao lưu DB riêng. Khi kiểm thử: khôi phục snapshot trước mỗi lần chạy.'],
    ['Queue worker', 'Các thao tác 「物理列を準備する」, 「バックフィルを開始する」, 「検証を開始・再開する」, 「ステージングデータを削除する」 chạy bằng job: cần QUEUE_CONNECTION=database và worker (php artisan queue:work) đang chạy bằng mã hiện tại; khởi động lại worker sau khi cập nhật mã.', 'Đã kiểm với database queue + worker thật. Chưa kiểm với QUEUE_CONNECTION=sync. Khi worker dừng, job nằm trong hàng đợi và chạy tiếp khi worker chạy lại.'],
    ['Điều kiện bảng', 'Chỉ bảng nghiệp vụ (không phải bảng hệ thống, không có cờ system_flg); đã có bảng giá trị; mọi cột đơn trị có kiểu vật lý; tổng kích thước hàng không vượt giới hạn của DB; trạng thái đang là JSON.', 'Xem mục 「事前チェック（参照のみ）」 trên màn hình chi tiết: nêu rõ lý do (ví dụ row_size_exceeded). Bảng không đủ điều kiện: thao tác bị từ chối.'],
    ['Người ghi trực tiếp', 'Không để plugin, script SQL hay công cụ ngoài ghi thẳng vào bảng trong lúc chuyển đổi.', 'Hệ thống chỉ ghi nhận xác nhận của người vận hành (không chặn kỹ thuật).'],
    ['Địa chỉ ứng dụng', 'APP_URL phải khớp địa chỉ đang dùng để truy cập.', 'Khi APP_URL trỏ máy chủ khác, liên kết/PJAX đi sai máy chủ.'],
    ['Cache bền (EXMENT_USE_CACHE)', 'Mặc định TẮT. Hybrid Storage không cần bật cờ này. Nếu muốn kiểm cache cùng Hybrid trên staging: xem mục E bên dưới.', 'Đổi cờ cần config:clear và queue:restart.'],
    ['Menu 「テーブル保存方式の変換」 sau khi nạp snapshot cũ', 'Các snapshot snap_clean / snap_fixture / snap_stageb chụp TRƯỚC migration 2026_12_04_000000_add_storage_conversion_menu_under_admin nên thiếu menu storage_conversion (nạp lại snapshot sẽ làm mất menu). Cách khôi phục được hỗ trợ: sau khi nạp snapshot chạy `php artisan migrate --force` (migration idempotent: chỉ thêm menu khi chưa có, ghi vào bảng migrations), rồi `php artisan cache:clear`. Chỉ System Administrator thấy menu, và chỉ ở site có chuyển đổi khả dụng.', 'Đã kiểm trên DB scratch khôi phục từ snap_clean: 0 menu -> migrate -> 1 menu; chạy lần hai: "Nothing to migrate", vẫn 1 menu. Lưu ý migrate áp dụng MỌI migration còn thiếu của snapshot, không riêng menu. Hoặc mở thẳng /admin/storage_conversion (URL luôn dùng được).'],
    ['Fixture s36 (nghiệm thu view tổng hợp pivot đa trị)', 'Đang được GIỮ trong DB kiểm thử MariaDB: con 101 ["3","3"] (phần tử lặp), 102 ["1","10"], 103 ["2","zzz"] (id sai); cha 11 không có con; RoleGroup hyb_s36_rg + chia sẻ quyền xem cho user2 (cha 1, 3, 10; con 101, 102); view tổng hợp 181 "s36 multi pivot summary". Tạo lại: nạp snapshot fixture rồi chạy tests/Support/provision-s36-summary-fixture.php qua `php artisan tinker --execute="require ...;"` (chỉ chạy được với APP_ENV=testing và DB có chữ "test"; idempotent). View: tạo trên giao diện (nhóm theo ID bảng cha; tổng hợp 件数 của cột "parent_multi : ID").', 'Số kỳ vọng độc lập: php tests/Support/s36-expected-summary.php <host> <port> <db> <user> (tính bằng PHP từ JSON, array_unique mỗi con). Nạp snapshot sẽ xoá fixture này: dùng bản sao lưu C:\hyb_p1_work\snap_pre_s36_20261127.sql (trước fixture) hoặc snap_user_ui_20261127.sql để quay lại.'],
    ['Tự động tạo index', 'Mặc định tắt (chưa đặt giới hạn exment.storage_auto_index_budget): mục 「自動インデックス」 báo 「インデックスの上限数が設定されていないため、自動インデックスは無効です」.', 'Không cần cấu hình để chuyển đổi.'],
];
foreach ($prereq as $row) { foreach ($row as $i => $v) { $ws1->setCellValueByColumnAndRow($i + 1, $r, $v); } wrapTop($ws1, "A$r:C$r"); $ws1->getStyle("A$r")->getFont()->setBold(true); $ws1->getRowDimension($r)->setRowHeight(max(18, 13.5 * max(est($row[1], 60), est($row[2], 60)) + 4)); borders($ws1, "A$r:C$r"); $r++; }
$r++;
sectionBar($ws1, "A$r:C$r", 'D. Kích hoạt: KIỂM THỬ cô lập so với VẬN HÀNH production', $C); $r++;
headerRow($ws1, $r, ['', 'Kích hoạt kiểm thử cô lập (cách tài liệu này đã kiểm)', 'Vận hành production (bản hiện tại)'], $C); $r++;
$act = [
    ['Trạng thái chuyển đổi', 'Khả dụng chỉ trong tiến trình có đủ điều kiện bảo vệ bên dưới.', 'KHÔNG khả dụng (UnavailableConversion). Màn hình hiển thị 「変換の実行は現在利用できません。」, mọi nút bị vô hiệu và mọi thao tác bị từ chối; chỉ xem được trạng thái.'],
    ['Điều kiện bảo vệ', "• Biến môi trường EXMENT_TEST_ENABLE_CONVERSION=1\n• Provider kiểm thử (IsolatedConversionExecutionProvider) đã đăng ký trong bootstrap/providers.php của máy kiểm thử\n• APP_ENV=testing\n• Máy chủ DB là loopback (127.0.0.1/localhost/LocalDB)\n• Tên DB chứa chữ \"test\" và trùng EXMENT_TEST_CONVERSION_DATABASE (cổng trùng EXMENT_TEST_CONVERSION_PORT nếu đặt)", 'Không có cấu hình nào trong bản này để bật chuyển đổi ở production.'],
    ['Ô 「保存方式」 khi tạo bảng/copy/import', 'Hiển thị.', 'Không hiển thị; bảng luôn là JSON.'],
    ['Việc cần làm trước khi đưa vào vận hành thật', '-', 'Cần quyết định và phê duyệt riêng (bật chuyển đổi, quy trình sao lưu, kiểm trên đúng DB/phiên bản của khách hàng). Tài liệu này KHÔNG phải quy trình bật production.'],
];
foreach ($act as $row) { foreach ($row as $i => $v) { $ws1->setCellValueByColumnAndRow($i + 1, $r, $v); } wrapTop($ws1, "A$r:C$r"); $ws1->getStyle("A$r")->getFont()->setBold(true); $ws1->getRowDimension($r)->setRowHeight(max(18, 13.5 * max(est($row[1], 60), est($row[2], 60)) + 4)); borders($ws1, "A$r:C$r"); $r++; }
$r++; $r = build_cache_sheet($ws1, $r, $C);
$ws1->freezePane('A4');

// ================================================================== 02 / 03 / 04 guides
$ws2 = $wb->createSheet(); $ws2->setTitle($SHEETS[2]);
guideSheet($ws2, '02 - Tạo bảng và quản lý cột', 'Chức năng đã triển khai và đã kiểm trên bản build B2 (xem sheet 00). Các bước chỉ khả dụng trong môi trường kiểm thử (sheet 01). Ảnh là ảnh chụp thật; chi tiết ở sheet 07.', $guide02, $EVDIRS, $IMGDIR, $C, $used, '2', $SHEETS[2]);
$ws3 = $wb->createSheet(); $ws3->setTitle($SHEETS[3]);
guideSheet($ws3, '03 - Chuyển bảng JSON sang Physical', 'Luồng chuyển bảng đang có dữ liệu. Các ảnh bước 1-5 và 7 chụp trên bản build hiện tại (B2); ảnh 「検証」 (bước xác minh) và 「記録 không hợp kiểu」 từ lần nghiệm thu B1 (màn hình không đổi). Dữ liệu thử: bảng nhỏ 12 bản ghi (B2) và bảng custom_value_edit 104 bản ghi (B1).', $guide03, $EVDIRS, $IMGDIR, $C, $used, '3', $SHEETS[3]);
$ws4 = $wb->createSheet(); $ws4->setTitle($SHEETS[4]);
$opsTable = function (Worksheet $ws, int &$r) use ($ops, $C) {
    sectionBar($ws, "A$r:E$r", 'Bốn thao tác riêng biệt để quay lại JSON (thực hiện theo thứ tự này; mỗi thao tác có điều kiện riêng)', $C); $r++;
    headerRow($ws, $r, ['#', 'Thao tác (JA)', 'Điều kiện', 'Tác dụng', 'Hoàn tác?'], $C); $r++;
    foreach ($ops as $i => [$name, $ja, $cond, $effect, $undo]) {
        $ws->setCellValue("A$r", (string)($i + 1)); $ws->setCellValue("B$r", "$name\n$ja"); $ws->setCellValue("C$r", $cond); $ws->setCellValue("D$r", $effect); $ws->setCellValue("E$r", $undo);
        wrapTop($ws, "A$r:E$r"); $ws->getStyle("B$r")->getFont()->setBold(true); $ws->getRowDimension($r)->setRowHeight(max(32, 13.5 * max(est($cond, 40), est($effect, 90), est("$name\n$ja", 50)) + 4)); borders($ws, "A$r:E$r"); $r++;
    }
    $r++;
};
guideSheet($ws4, '04 - Quay lại JSON và phục hồi', 'Rollback, huỷ, dọn staging và gỡ cột là các thao tác RIÊNG, không thực hiện một lần. Ảnh từ lần nghiệm thu B1 (bảng chuyển đổi) và B2 (bảng tạo trực tiếp).', $guide04, $EVDIRS, $IMGDIR, $C, $used, '4', $SHEETS[4], [$opsTable]);

// ================================================================== 05 / 06 / 07 are built by ho_tests.php helpers
build_business_sheet($wb, $SHEETS[5], $C, $BIZ);
build_testcase_sheet($wb, $SHEETS[6], $C, $TC, $SHEETS);
build_evidence_sheet($wb, $SHEETS[7], $C, $EVID, $used, $EVDIRS, $IMGDIR, $SHEETS);

$wb->setActiveSheetIndex(0);
foreach ($wb->getAllSheets() as $s) {
    $s->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    $s->getPageSetup()->setFitToPage(true);
}
try {
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb))->save($OUT);
} catch (\Throwable $e) {
    $OUT = preg_replace('/\.xlsx$/', '_rev3.xlsx', $OUT);
    echo "original is locked, writing $OUT
";
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb))->save($OUT);
}
echo "written: $OUT (" . round(filesize($OUT) / 1024) . " KB), sheets: " . count($wb->getAllSheets()) . "\n";
