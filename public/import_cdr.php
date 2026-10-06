<?php
// 1. กำหนด Timezone ของ PHP เป็น Asia/Bangkok (+07:00)
date_default_timezone_set('Asia/Bangkok');

// ตั้งค่าการเชื่อมต่อฐานข้อมูล
$host = 'localhost';
$dbname = 'cdr_3cx';
$username = 'root';
$password = '';

$message = '';

/**
 * ฟังก์ชันสำหรับแปลงเวลาดิบจาก 3CX (UTC) ให้เป็นเวลาประเทศไทย (+07:00)
 */
function convertToThailandTime($raw_datetime_str) {
    if (empty($raw_datetime_str)) return null;
    $raw_datetime_str = trim($raw_datetime_str);
    if ($raw_datetime_str === '') return null;

    try {
        // อ่านค่าเวลาเดิมตาม Timezone UTC
        $dt = new DateTime($raw_datetime_str, new DateTimeZone('UTC'));
        // แปลงเป็นเวลาประเทศไทย
        $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $raw_datetime_str; // หากแปลงไม่ผ่านให้ใช้ค่าเดิม
    }
}

// ตรวจสอบเมื่อมีการกดปุ่ม Submit Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cdr_file'])) {
    $file = $_FILES['cdr_file'];

    // ตรวจสอบข้อผิดพลาดในการอัปโหลด
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = "<div style='color: red;'>เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Error code: {$file['error']})</div>";
    } else {
        try {
            // เชื่อมต่อ Database
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // กำหนด MySQL Timezone Session ให้เป็น +07:00
            $pdo->exec("SET time_zone = '+07:00';");

            $nullify = function ($val) {
                $val = trim($val);
                return $val === '' ? null : $val;
            };

            // --- 1. อ่านไฟล์รอบแรกเพื่อรวบรวม historyid ทั้งหมดไว้ตรวจสอบซ้ำ ---
            $file_path = $file['tmp_name'];
            $file_lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            $file_history_ids = [];
            foreach ($file_lines as $line) {
                $data = explode(',', $line);
                $hid = $nullify($data[0] ?? '');
                if ($hid !== null) {
                    $file_history_ids[] = $hid;
                }
            }

            // ดึง historyid จากฐานข้อมูลที่มีอยู่แล้วตามรายการที่อยู่ในไฟล์ (Batch Check)
            $existing_history_ids = [];
            if (!empty($file_history_ids)) {
                $unique_ids = array_unique($file_history_ids);
                
                // แบ่ง Chunk ทำการ SELECT เพื่อป้องกันกรณีไฟล์มีขนาดใหญ่เกินไป
                $chunks = array_chunk($unique_ids, 1000);
                foreach ($chunks as $chunk) {
                    $in_clause = implode(',', array_fill(0, count($chunk), '?'));
                    $check_sql = "SELECT historyid FROM cdr_raw WHERE historyid IN ($in_clause)";
                    $check_stmt = $pdo->prepare($check_sql);
                    $check_stmt->execute($chunk);
                    
                    // แก้ไขการดึงข้อมูลเพื่อป้องกัน Syntax Error 500
                    $fetched_ids = $check_stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($fetched_ids as $fid) {
                        $existing_history_ids[$fid] = true;
                    }
                }
            }

            // --- 2. เตรียม SQL Insert ---
            $sql = "INSERT INTO cdr_raw (
                historyid, callid, duration, time_start, answer_time, time_end,
                reason_terminated, from_no, to_no, from_dn, to_dn, dial_no,
                reason_changed, final_number, final_dn, bill_code, bill_rate, bill_cost,
                bill_name, chain
            ) VALUES (
                :historyid, :callid, :duration, :time_start, :answer_time, :time_end,
                :reason_terminated, :from_no, :to_no, :from_dn, :to_dn, :dial_no,
                :reason_changed, :final_number, :final_dn, :bill_code, :bill_rate, :bill_cost,
                :bill_name, :chain
            )";

            $stmt = $pdo->prepare($sql);

            $insertedCount = 0;
            $duplicateCount = 0;
            $skippedCount = 0;

            $pdo->beginTransaction(); // เริ่ม Transaction เพื่อความรวดเร็วในการ Insert

            // --- 3. วนลูปบันทึกข้อมูลเข้าฐานข้อมูล ---
            foreach ($file_lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // แยกข้อมูลด้วยเครื่องหมายจุลภาค (Comma)
                $data = explode(',', $line);

                // ตรวจสอบว่ามีคอลัมน์เพียงพอหรือไม่
                if (count($data) < 15) {
                    $skippedCount++;
                    continue;
                }

                $historyid = $nullify($data[0] ?? '');

                // ตรวจสอบว่า historyid มีอยู่ในฐานข้อมูลแล้วหรือยัง
                if ($historyid !== null && isset($existing_history_ids[$historyid])) {
                    $duplicateCount++; // นับเป็นรายการซ้ำแล้วข้าม
                    continue;
                }

                // แปลงฟิลด์ที่เป็นเวลาให้เป็นเวลาไทย (+07:00) ก่อนบันทึก
                $time_start_th  = convertToThailandTime($nullify($data[3] ?? ''));
                $answer_time_th = convertToThailandTime($nullify($data[4] ?? ''));
                $time_end_th    = convertToThailandTime($nullify($data[5] ?? ''));

                $params = [
                    ':historyid'         => $historyid,
                    ':callid'            => $nullify($data[1] ?? ''),
                    ':duration'          => $nullify($data[2] ?? ''),
                    ':time_start'        => $time_start_th,  // เวลาประเทศไทย
                    ':answer_time'       => $answer_time_th, // เวลาประเทศไทย
                    ':time_end'          => $time_end_th,    // เวลาประเทศไทย
                    ':reason_terminated' => $nullify($data[6] ?? ''),
                    ':from_no'           => $nullify($data[7] ?? ''),
                    ':to_no'             => $nullify($data[8] ?? ''),
                    ':from_dn'           => $nullify($data[9] ?? ''),
                    ':to_dn'             => $nullify($data[10] ?? ''),
                    ':dial_no'           => $nullify($data[11] ?? ''),
                    ':reason_changed'    => $nullify($data[12] ?? ''),
                    ':final_number'      => $nullify($data[13] ?? ''),
                    ':final_dn'          => $nullify($data[14] ?? ''),
                    ':bill_code'         => $nullify($data[15] ?? ''),
                    ':bill_rate'         => $nullify($data[16] ?? ''),
                    ':bill_cost'         => $nullify($data[17] ?? ''),
                    ':bill_name'         => $nullify($data[18] ?? ''),
                    ':chain'             => $nullify($data[19] ?? ''),
                ];

                $stmt->execute($params);
                $insertedCount++;

                // เพิ่ม historyid ใหม่ลงใน array เพื่อป้องกันกรณีมี historyid ซ้ำกันเองในไฟล์เดียวกัน
                if ($historyid !== null) {
                    $existing_history_ids[$historyid] = true;
                }
            }

            $pdo->commit();

            $message = "<div style='color: green; font-weight: bold;'>
                นำเข้าข้อมูลเสร็จสิ้น!<br>
                - บันทึกใหม่: {$insertedCount} รายการ<br>
                - ข้ามเนื่องจากซ้ำ (historyid ซ้ำ): {$duplicateCount} รายการ<br>
                - ข้ามบรรทัดที่ไม่ถูกต้อง: {$skippedCount} รายการ
            </div>";

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "<div style='color: red;'>เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Import CDR Log File</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background-color: #f9f9f9; }
        .card { max-width: 500px; margin: auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="file"] { display: block; width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background-color: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; }
        button:hover { background-color: #0056b3; }
        .message { margin-bottom: 15px; padding: 10px; border-radius: 4px; background: #eee; line-height: 1.6; }
    </style>
</head>
<body>

<div class="card">
    <h2>นำเข้าไฟล์ CDR Log (เวลาประเทศไทย)</h2>
    
    <?php if (!empty($message)): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label for="cdr_file">เลือกไฟล์ CDR Log (.log หรือ .txt):</label>
            <input type="file" name="cdr_file" id="cdr_file" accept=".log,.txt,.csv" required>
        </div>
        <button type="submit">เริ่มนำเข้าข้อมูล (Import)</button>
    </form>
</div>

</body>
</html>
