<?php
require (getenv('HANDOVER_AUTOLOAD') ?: 'vendor/autoload.php');
$f=(getenv('HANDOVER_DOC_DIR') ?: __DIR__).'/Hybrid_Storage_Handover_Tester_User_vi.xlsx';
$wb=\PhpOffice\PhpSpreadsheet\IOFactory::load($f);
foreach($wb->getWorksheetIterator() as $s){ echo $s->getTitle(),": rows ",$s->getHighestRow()," images ",count($s->getDrawingCollection()),"\n"; }
// missing screenshots and statuses
$missing=[]; foreach(['02_Tạo bảng và quản lý cột','03_Chuyển JSON sang Physical','04_Quay lại JSON và phục hồi'] as $n){ $s=$wb->getSheetByName($n); foreach($s->getRowIterator() as $r){ $v=$s->getCell('D'.$r->getRowIndex())->getValue(); if(is_string($v)&&strpos($v,'ảnh không có')!==false) $missing[]=$n.' row '.$r->getRowIndex(); } }
echo "missing: ",json_encode($missing,JSON_UNESCAPED_UNICODE),"\n";
$s=$wb->getSheetByName('06_Test cases và kết quả'); $cnt=[];
for($r=5;$r<=$s->getHighestRow();$r++){ $id=$s->getCell("A$r")->getValue(); $st=$s->getCell("I$r")->getValue(); if(!$id) continue; $cnt[$st]=($cnt[$st]??0)+1; echo $id,' ',$st,' | ',$s->getCell("D$r")->getValue(),' | ',$s->getCell("M$r")->getValue(),"\n"; }
echo json_encode($cnt),"\n";
echo 'autofilter: ',$s->getAutoFilter()->getRange(),' freeze: ',$s->getFreezePane(),"\n";
