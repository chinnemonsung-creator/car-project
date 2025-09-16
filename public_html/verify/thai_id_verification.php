<?php
session_start();

// ถ้ามี parameter success มา แสดงว่า verification สำเร็จ
if (isset($_GET['success']) && $_GET['success'] === 'true') {
    $_SESSION['thai_id_verified'] = true;
}

$isVerified = isset($_SESSION['thai_id_verified']) && $_SESSION['thai_id_verified'] === true;
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยืนยันตัวตนด้วย Thai ID</title>
    <style>
        body {
            font-family: 'Sarabun', Arial, sans-serif;
            text-align: center;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            max-width: 400px;
            margin: 0 auto;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .btn {
            background-color: #00C851;
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            width: 100%;
            margin: 10px 0;
        }
        .btn:hover {
            background-color: #00A043;
        }
        .success {
            color: #00C851;
            font-size: 18px;
            margin: 20px 0;
        }
        .loading {
            color: #007bff;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>ยืนยันตัวตนด้วย Thai ID</h2>
        
        <?php if ($isVerified): ?>
            <div class="success">
                <h3>✅ ยืนยันตัวตนเสร็จสิ้น</h3>
                <p>คุณได้ทำการยืนยันตัวตนเรียบร้อยแล้ว</p>
                <button class="btn" onclick="resetVerification()">ยืนยันใหม่</button>
            </div>
        <?php else: ?>
            <p>กดปุ่มด้านล่างเพื่อเปิดแอป Thai ID</p>
            <button class="btn" onclick="openThaiID()">ยืนยันตัวตนด้วย Thai ID</button>
            <div id="loading" class="loading" style="display: none;">
                <p>🔄 กำลังรอการยืนยันตัวตน...</p>
                <p>กรุณากลับมาหลังจากใช้แอป Thai ID เสร็จ</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function openThaiID() {
        // แสดง loading
        document.getElementById('loading').style.display = 'block';
        
        // เก็บข้อมูลว่าเปิด Thai ID ไปแล้ว + เวลา
        localStorage.setItem('thai_id_opened', 'true');
        localStorage.setItem('thai_id_time', Date.now());
        
        // เปิด Thai ID app
        window.location.href = 'thaiid://';
        
        // ตั้ง timeout กลับมาเช็ค (กรณีที่ไม่มี app)
        setTimeout(() => {
            if (localStorage.getItem('thai_id_opened') === 'true') {
                alert('กรุณาติดตั้งแอป Thai ID หรือตรวจสอบว่าแอปติดตั้งเรียบร้อยแล้ว');
                document.getElementById('loading').style.display = 'none';
            }
        }, 3000);
    }
    
    function resetVerification() {
        if (confirm('คุณต้องการยืนยันตัวตนใหม่หรือไม่?')) {
            window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>?reset=true';
        }
    }
    
    // เช็คทุกครั้งที่กลับมาหน้านี้
    window.addEventListener('focus', function() {
        const opened = localStorage.getItem('thai_id_opened');
        const openTime = localStorage.getItem('thai_id_time');
        
        if (opened === 'true' && openTime) {
            const timeElapsed = Date.now() - parseInt(openTime);
            
            // ถ้าผ่านไป 5 วินาที แสดงว่าน่าจะไปใช้ app มา
            if (timeElapsed > 5000) {
                localStorage.removeItem('thai_id_opened');
                localStorage.removeItem('thai_id_time');
                
                // redirect ไปหน้าเดิมพร้อม success parameter
                window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>?success=true';
            }
        }
    });
    
    // ตรวจสอบทันทีเมื่อโหลดหน้า (กรณีที่กลับมาจาก app)
    window.addEventListener('load', function() {
        const opened = localStorage.getItem('thai_id_opened');
        const openTime = localStorage.getItem('thai_id_time');
        
        if (opened === 'true' && openTime) {
            const timeElapsed = Date.now() - parseInt(openTime);
            
            if (timeElapsed > 5000) {
                localStorage.removeItem('thai_id_opened');
                localStorage.removeItem('thai_id_time');
                
                // ถ้ายังไม่มี success parameter ให้ redirect
                if (!window.location.search.includes('success=true')) {
                    window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>?success=true';
                }
            }
        }
    });
    </script>
</body>
</html>

<?php
// Reset verification ถ้ามี parameter reset
if (isset($_GET['reset']) && $_GET['reset'] === 'true') {
    unset($_SESSION['thai_id_verified']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>