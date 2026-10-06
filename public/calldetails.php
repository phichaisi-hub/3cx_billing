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

// 3. กำหนด Timezone PHP เป็นเวลาประเทศไทย (GMT+7)
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
    // กำหนด Timezone สำหรับการ Query ใน MySQL ให้เป็น +07:00 (เวลาไทย)
    $pdo->exec("SET time_zone = '+07:00';");
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// รับค่า Filter
$start_date   = $_GET['start_date'] ?? date('Y-m-01');
$end_date     = $_GET['end_date'] ?? date('Y-m-t');
$agent_filter = $_GET['agent'] ?? 'all';
$type_filter  = $_GET['call_type'] ?? 'all';

$all_agents = ['4901', '4902', '4903', '4904', '4905', '4906', '4907', '4908', '4909'];

// Query ดึงข้อมูลสายภายนอก
$sql = "
    SELECT 
        time_start,
        TRIM(from_dn) AS agent_ext,
        dial_no,
        final_number,
        duration
    FROM cdr_raw
    WHERE TRIM(from_dn) IN ('4901', '4902', '4903', '4904', '4905', '4906', '4907', '4908', '4909')
      AND DATE(time_start) BETWEEN :start_date AND :end_date
      AND (dial_no LIKE '9%' OR final_number LIKE '9%')
";

$params = [
    ':start_date' => $start_date,
    ':end_date'   => $end_date
];

if ($agent_filter !== 'all' && in_array($agent_filter, $all_agents)) {
    $sql .= " AND TRIM(from_dn) = :agent ";
    $params[':agent'] = $agent_filter;
}

$sql .= " ORDER BY time_start ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_rows = $stmt->fetchAll();

$processed_rows = [];
$total_cost = 0.0; // สะสมยอดค่าบริการจริงทั้งหมด

foreach ($raw_rows as $row) {
    $dial  = $row['dial_no'] ?? '';
    $final = $row['final_number'] ?? '';

    // ตัดเลข 9 นำหน้าออก
    $dial_clean  = preg_replace('/^9+/', '', trim($dial));
    $final_clean = preg_replace('/^9+/', '', trim($final));

    // คำนวณ duration เป็นวินาที
    $dur_parts = explode(':', $row['duration'] ?? '00:00:00');
    $dur_sec = 0;
    if (count($dur_parts) === 3) {
        $dur_sec = ((int)$dur_parts[0] * 3600) + ((int)$dur_parts[1] * 60) + (int)$dur_parts[2];
    }

    $cost = 0.0;
    $call_type_code = '';
    $call_type_label = '';

    if ($dur_sec > 0) {
        // 1. เบอร์บ้านในจังหวัด (ขึ้นต้นด้วย 02) -> เหมาจ่าย 3.00 บาท
        if (strpos($dial_clean, '02') === 0 || strpos($final_clean, '02') === 0) {
            $call_type_code  = 'local';
            $call_type_label = 'ในจังหวัด';
            $cost = 3.0;
        } 
        // 2. เบอร์มือถือ (ขึ้นต้นด้วย 06, 08, 09) -> นาทีละ 3 บาท (0.05/วินาที)
        elseif (preg_match('/^0[689]/', $dial_clean) || preg_match('/^0[689]/', $final_clean)) {
            $call_type_code  = 'mobile';
            $call_type_label = 'โทรมือถือ';
            $cost = $dur_sec * (3.0 / 60.0);
        } 
        // 3. ต่างจังหวัด (ขึ้นต้นด้วย 01, 03, 04, 05, 07) -> นาทีละ 3 บาท (0.05/วินาที)
        elseif (preg_match('/^0[13457]/', $dial_clean) || preg_match('/^0[13457]/', $final_clean)) {
            $call_type_code  = 'inter';
            $call_type_label = 'ต่างจังหวัด';
            $cost = $dur_sec * (3.0 / 60.0);
        } 
        // 4. ต่างประเทศ (ขึ้นต้นด้วย 001, 009) -> นาทีละ 9 บาท (0.15/วินาที)
        elseif (strpos($dial_clean, '001') === 0 || strpos($dial_clean, '009') === 0 || 
                strpos($final_clean, '001') === 0 || strpos($final_clean, '009') === 0) {
            $call_type_code  = 'overseas';
            $call_type_label = 'ต่างประเทศ';
            $cost = $dur_sec * (9.0 / 60.0);
        }
    }

    if ($cost <= 0) continue;

    // Filter ประเภทการโทร
    if ($type_filter !== 'all' && $type_filter !== $call_type_code) continue;

    $total_cost += $cost; // รวมค่าบริการสะสม

    // แปลงเวลา time_start ให้ออกมาเป็นโครงสร้าง DateTime เวลาไทย
    $dt = new DateTime($row['time_start'], new DateTimeZone('Asia/Bangkok'));

    $processed_rows[] = [
        'time_start'      => $dt->format('d/m/Y H:i:s'),
        'agent_ext'       => $row['agent_ext'],
        'destination'     => !empty($dial_clean) ? $dial_clean : $final_clean,
        'duration'        => $row['duration'],
        'call_type_label' => $call_type_label,
        'cost'            => $cost
    ];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>3CX Billing Call Center Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; color: #0000FF; background-color: #f8f9fa; }
        .print-container { max-width: 920px; margin: 20px auto; padding: 30px; background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        .header-title { text-align: center; font-weight: 700; font-size: 22px; color: #0000FF; margin-bottom: 2px; }
        .company-title { text-align: center; font-weight: 700; font-size: 18px; color: #0000FF; margin-bottom: 15px; }
        .meta-info { font-size: 14px; color: #0000FF; font-weight: 600; line-height: 1.4; margin-bottom: 10px; }
        
        .report-table { width: 100%; border-collapse: collapse; font-size: 14px; font-weight: 600; color: #0000FF; table-layout: fixed; }
        .report-table th { padding: 6px 4px; font-size: 14px; font-weight: 700; border-bottom: 2px solid #0000FF; white-space: nowrap; }
        .report-table td { padding: 5px 4px; vertical-align: middle; white-space: nowrap; }
        .row-space td { padding-top: 15px; border-top: 2px solid #0000FF; }
        
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; margin: 0; padding: 0; }
            .print-container { width: 100% !important; max-width: 100% !important; padding: 0 !important; box-shadow: none !important; }
            
            @page { 
                size: A4 portrait; 
                margin: 15mm 10mm 15mm 10mm;
                @bottom-right {
                    content: "หน้าที่ " counter(page);
                    font-family: 'Sarabun', sans-serif;
                    font-size: 12px;
                    color: #0000FF;
                }
            }
            
            thead {
                display: table-header-group;
            }
            
            tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<!-- ส่วนค้นหาและปุ่มควบคุม (ซ่อนตอนพิมพ์) -->
<div class="container max-width-920 my-3 no-print">
    <div class="card p-3 shadow-sm border-0">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-primary mb-0">📋 รายละเอียดการโทร (Call Details)</h5>
            <div>
                <a href="report.php" class="btn btn-outline-primary fw-bold me-2">📊 ดูรายงานสรุป</a>
                <a href="logout.php" class="btn btn-danger fw-bold">🚪 ออกจากระบบ</a>
            </div>
        </div>
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-bold text-dark">วันที่เริ่มต้น:</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold text-dark">วันที่สิ้นสุด:</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold text-dark">Agent:</label>
                <select name="agent" class="form-select">
                    <option value="all">-- ทั้งหมด --</option>
                    <?php foreach ($all_agents as $ag): ?>
                        <option value="<?= $ag ?>" <?= $agent_filter === $ag ? 'selected' : '' ?>><?= $ag ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold text-dark">ประเภท:</label>
                <select name="call_type" class="form-select">
                    <option value="all">-- ทั้งหมด --</option>
                    <option value="local" <?= $type_filter === 'local' ? 'selected' : '' ?>>ในจังหวัด</option>
                    <option value="mobile" <?= $type_filter === 'mobile' ? 'selected' : '' ?>>โทรมือถือ</option>
                    <option value="inter" <?= $type_filter === 'inter' ? 'selected' : '' ?>>ต่างจังหวัด</option>
                    <option value="overseas" <?= $type_filter === 'overseas' ? 'selected' : '' ?>>ต่างประเทศ</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary px-3 fw-bold">ค้นหา</button>
                <a href="calldetails.php" class="btn btn-outline-secondary">รีเซ็ต</a>
                <button type="button" onclick="printReport()" class="btn btn-success ms-auto px-3 fw-bold">🖨️ พิมพ์ (Print)</button>
            </div>
        </form>
    </div>
</div>

<!-- ส่วนเอกสารพิมพ์รายงาน -->
<div class="print-container">
    <div class="header-title">รายงานรายละเอียดการใช้โทรศัพท์ (เฉพาะรายการมีค่าบริการ)</div>
    <div class="company-title">IMPACT EXHIBITION MANAGEMENT CO.,LTD.</div>

    <div class="d-flex justify-content-between meta-info">
        <div>
            <div>วันเวลาที่พิมพ์ : <?= date('d/m/Y H:i:s') ?></div>
            <div>ช่วงเวลาข้อมูล : <?= date('d/m/Y 00:00:00', strtotime($start_date)) ?> ถึง <?= date('d/m/Y 23:59:59', strtotime($end_date)) ?></div>
            <div>Agent : <?= $agent_filter === 'all' ? 'ทั้งหมด (4901-4909)' : htmlspecialchars($agent_filter) ?></div>
        </div>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 12%; text-align: center;">Agent</th>
                <th style="width: 23%; text-align: center;">เลขหมายปลายทางที่โทร</th>
                <th style="width: 17%; text-align: center;">ประเภทการโทร</th>
                <th style="width: 21%; text-align: center;">วันเวลาที่โทร</th>
                <th style="width: 14%; text-align: center;">ระยะเวลา</th>
                <th style="width: 13%; text-align: right;">ค่าบริการ (บาท)</th>
            </tr>
        </thead>
        
        <tbody>
            <?php if (count($processed_rows) > 0): ?>
                <?php foreach ($processed_rows as $row): ?>
                <tr>
                    <td style="text-align: center;"><?= htmlspecialchars($row['agent_ext']) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($row['destination']) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($row['call_type_label']) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($row['time_start']) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($row['duration']) ?></td>
                    <td style="text-align: right;"><?= number_format($row['cost'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="row-space" style="font-weight: 700;">
                    <td style="text-align: right;" colspan="5">รวมค่าบริการทั้งหมด</td>
                    <td style="text-align: right;"><?= number_format($total_cost, 2) ?></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px 0;">ไม่พบข้อมูลการโทรตามเงื่อนไขที่ระบุ</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function printReport() {
    const originalTitle = document.title;
    document.title = '3CX Billing Call Center Details';
    window.print();
    setTimeout(() => { document.title = originalTitle; }, 1000);
}
</script>

</body>
</html>
