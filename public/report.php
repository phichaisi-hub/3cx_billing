<?php
session_start();

// 1. ป้องกัน Cache
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. ตรวจสอบ Login Guard
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Asia/Bangkok');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = ''; 
$db_name = 'cdr_3cx';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');

$all_agents = ['4901', '4902', '4903', '4904', '4905', '4906', '4907', '4908', '4909'];

$sql = "
    SELECT 
        TRIM(from_dn) AS agent_ext,
        dial_no,
        final_number,
        duration
    FROM cdr_raw
    WHERE TRIM(from_dn) IN ('4901', '4902', '4903', '4904', '4905', '4906', '4907', '4908', '4909')
      AND DATE(time_start) BETWEEN :start_date AND :end_date
      AND (dial_no LIKE '9%' OR final_number LIKE '9%')
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':start_date' => $start_date,
    ':end_date'   => $end_date
]);
$raw_rows = $stmt->fetchAll();

// สะสมยอดทศนิยมจริงของแต่ละ Agent
$agent_totals_raw = [];
foreach ($all_agents as $ag) {
    $agent_totals_raw[$ag] = [
        'local'    => 0.0,
        'mobile'   => 0.0,
        'inter'    => 0.0,
        'overseas' => 0.0
    ];
}

foreach ($raw_rows as $row) {
    $ag = $row['agent_ext'];
    if (!isset($agent_totals_raw[$ag])) continue;

    $dial  = $row['dial_no'] ?? '';
    $final = $row['final_number'] ?? '';
    
    $dial_clean  = preg_replace('/^9+/', '', trim($dial));
    $final_clean = preg_replace('/^9+/', '', trim($final));

    $dur_parts = explode(':', $row['duration'] ?? '00:00:00');
    $dur_sec = 0;
    if (count($dur_parts) === 3) {
        $dur_sec = ((int)$dur_parts[0] * 3600) + ((int)$dur_parts[1] * 60) + (int)$dur_parts[2];
    }

    $cost = 0.0;
    $call_type = '';

    if ($dur_sec > 0) {
        if (strpos($dial_clean, '02') === 0 || strpos($final_clean, '02') === 0) {
            $call_type = 'local';
            $cost = 3.0;
        } 
        elseif (preg_match('/^0[689]/', $dial_clean) || preg_match('/^0[689]/', $final_clean)) {
            $call_type = 'mobile';
            $cost = $dur_sec * (3.0 / 60.0);
        } 
        elseif (preg_match('/^0[13457]/', $dial_clean) || preg_match('/^0[13457]/', $final_clean)) {
            $call_type = 'inter';
            $cost = $dur_sec * (3.0 / 60.0);
        } 
        elseif (strpos($dial_clean, '001') === 0 || strpos($dial_clean, '009') === 0 || 
                strpos($final_clean, '001') === 0 || strpos($final_clean, '009') === 0) {
            $call_type = 'overseas';
            $cost = $dur_sec * (9.0 / 60.0);
        }
    }

    if ($cost > 0 && $call_type !== '') {
        $agent_totals_raw[$ag][$call_type] += $cost;
    }
}

// นำยอดรวมจริงมาปัดเศษ (>= 0.5 ปัดขึ้น, < 0.5 ปัดลง) ในระดับ Agent
$report_data = [];
$grand_local = $grand_mobile = $grand_inter = $grand_overseas = $grand_total = 0;

foreach ($all_agents as $ag) {
    $totals = $agent_totals_raw[$ag];

    $val_local    = (int)round($totals['local']);
    $val_mobile   = (int)round($totals['mobile']);
    $val_inter    = (int)round($totals['inter']);
    $val_overseas = (int)round($totals['overseas']);

    $agent_row_total = $val_local + $val_mobile + $val_inter + $val_overseas;

    $report_data[$ag] = [
        'local'    => $val_local,
        'mobile'   => $val_mobile,
        'inter'    => $val_inter,
        'overseas' => $val_overseas,
        'total'    => $agent_row_total
    ];

    $grand_local    += $val_local;
    $grand_mobile   += $val_mobile;
    $grand_inter    += $val_inter;
    $grand_overseas += $val_overseas;
    $grand_total    += $agent_row_total;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>3CX Summary Billing Call Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; color: #0000FF; background-color: #f8f9fa; }
        .print-container { max-width: 920px; margin: 20px auto; padding: 30px; background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        .header-title { text-align: center; font-weight: 700; font-size: 24px; color: #0000FF; margin-bottom: 4px; }
        .company-title { text-align: center; font-weight: 700; font-size: 20px; color: #0000FF; margin-bottom: 25px; }
        .meta-info { font-size: 15px; color: #0000FF; margin-bottom: 15px; font-weight: 600; line-height: 1.5; }
        
        .report-table { width: 100%; border-collapse: collapse; font-size: 14px; font-weight: 600; color: #0000FF; }
        .report-table th { padding: 8px 4px; font-size: 14px; font-weight: 700; border-bottom: 2px solid #0000FF; border-top: 2px solid #0000FF; white-space: nowrap; }
        .report-table td { padding: 8px 4px; vertical-align: middle; white-space: nowrap; }
        .report-table tbody tr { border-bottom: 1px solid #ffffff; }
        .row-space td { padding-top: 15px; border-top: 2px solid #0000FF; font-weight: 700; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; margin: 0; padding: 0; }
            .print-container { width: 100% !important; max-width: 100% !important; padding: 0 !important; box-shadow: none !important; }
            .table-responsive { overflow: visible !important; display: block !important; }
            .report-table { width: 100% !important; table-layout: fixed !important; }
            @page { size: A4 portrait; margin: 10mm; }
        }
    </style>
</head>
<body>

<!-- ส่วนค้นหาและปุ่มควบคุม (ซ่อนตอนพิมพ์) -->
<div class="container max-width-920 my-3 no-print">
    <div class="card p-3 shadow-sm border-0">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold text-dark">วันที่เริ่มต้น:</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold text-dark">วันที่สิ้นสุด:</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-md-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold">🔍 ค้นหา</button>
                <a href="report.php" class="btn btn-outline-secondary w-100">รีเซ็ต</a>
                <a href="calldetails.php" class="btn btn-info w-100 text-white fw-bold">📋 ดูรายละเอียด</a>
                <button type="button" onclick="printReport()" class="btn btn-success w-100 fw-bold">🖨️ พิมพ์</button>
                <a href="logout.php" class="btn btn-danger w-100 fw-bold">🚪 ออกจากระบบ</a>
            </div>
        </form>
    </div>
</div>

<!-- ส่วนสรุปรายงานแสดงผล 7 คอลัมน์ -->
<div class="print-container">
    <div class="header-title">สรุปปริมาณการใช้โทรศัพท์</div>
    <div class="company-title">IMPACT EXHIBITION MANAGEMENT CO.,LTD.</div>

    <div class="d-flex justify-content-between meta-info">
        <div>
            <div>วันเวลาที่พิมพ์ : <?= date('d/m/Y H:i:s') ?></div>
            <div>ค่าโทรศัพท์เริ่ม : <?= date('d/m/Y 00:00:00', strtotime($start_date)) ?> ถึง <?= date('d/m/Y 23:59:59', strtotime($end_date)) ?></div>
        </div>
        <div class="text-end">หน้าที่ 1</div>
    </div>

    <div class="table-responsive">
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 15%; text-align: left;">รายการ</th>
                    <th style="width: 13%; text-align: right;">ค่าบริการ</th>
                    <th style="width: 18%; text-align: right;">โทรในจังหวัด</th>
                    <th style="width: 18%; text-align: right;">โทรมือถือ</th>
                    <th style="width: 18%; text-align: right;">ต่างจังหวัด</th>
                    <th style="width: 18%; text-align: right;">ต่างประเทศ</th>
                    <th style="width: 20%; text-align: right;">รวมทั้งหมด</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_data as $ag => $val): ?>
                <tr>
                    <td style="text-align: left;"><?= $ag ?></td>
                    <td style="text-align: right;">-</td>
                    <td style="text-align: right;"><?= $val['local'] > 0 ? number_format($val['local']) : '-' ?></td>
                    <td style="text-align: right;"><?= $val['mobile'] > 0 ? number_format($val['mobile']) : '-' ?></td>
                    <td style="text-align: right;"><?= $val['inter'] > 0 ? number_format($val['inter']) : '-' ?></td>
                    <td style="text-align: right;"><?= $val['overseas'] > 0 ? number_format($val['overseas']) : '-' ?></td>
                    <td style="text-align: right;"><?= $val['total'] > 0 ? number_format($val['total']) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="row-space">
                    <td style="text-align: left;">รวมทั้งหมด</td>
                    <td style="text-align: right;"></td>
                    <td style="text-align: right;"><?= $grand_local > 0 ? number_format($grand_local) : '-' ?></td>
                    <td style="text-align: right;"><?= $grand_mobile > 0 ? number_format($grand_mobile) : '-' ?></td>
                    <td style="text-align: right;"><?= $grand_inter > 0 ? number_format($grand_inter) : '-' ?></td>
                    <td style="text-align: right;"><?= $grand_overseas > 0 ? number_format($grand_overseas) : '-' ?></td>
                    <td style="text-align: right;"><?= $grand_total > 0 ? number_format($grand_total) : '-' ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function printReport() {
    const originalTitle = document.title;
    document.title = '3CX Summary Billing Call Center';
    window.print();
    setTimeout(() => { document.title = originalTitle; }, 1000);
}
</script>

</body>
</html>
