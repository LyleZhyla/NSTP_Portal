<?php
session_start();
require_once __DIR__ . '/conn/conn.php';
require_once __DIR__ . '/include/student-cor.php';

$pendingUserId = (int) ($_SESSION['pending_cor_user_id'] ?? 0);
$loggedUserId = (int) ($_SESSION['user_id'] ?? 0);
$userId = $pendingUserId ?: $loggedUserId;
if ($userId < 1) {
    header('Location: login.php');
    exit();
}

$stmt = $conn->prepare("SELECT user_id, full_name, role FROM tbl_users WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || ($user['role'] ?? '') !== 'student') {
    header('Location: login.php');
    exit();
}

$requirement = currentStudentCorRequirement($conn);
if (empty($requirement['enabled']) || studentHasCurrentCor($conn, $userId, $requirement)) {
    header('Location: ' . ($loggedUserId ? 'student-dashboard.php' : 'login.php'));
    exit();
}

$error = $_SESSION['cor_upload_error'] ?? '';
unset($_SESSION['cor_upload_error']);
if (empty($_SESSION['cor_upload_csrf'])) {
    $_SESSION['cor_upload_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload COR - TAU NSTP</title>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;font-family:Arial,sans-serif;background:linear-gradient(135deg,#0f5132,#198754);color:#1f2937}.card{width:min(520px,100%);background:#fff;border-radius:18px;padding:32px;box-shadow:0 22px 60px rgba(0,0,0,.25)}.brand{text-align:center}.brand img{width:78px;height:78px;object-fit:contain}.brand h1{font-size:24px;margin:12px 0 4px}.semester{color:#198754;font-weight:700}.notice{background:#fff3cd;border:1px solid #ffecb5;border-radius:10px;padding:14px;margin:22px 0}.error{background:#f8d7da;border:1px solid #f5c2c7;color:#842029;border-radius:10px;padding:12px;margin:16px 0}.field{margin:18px 0}.field label{display:block;font-weight:700;margin-bottom:8px}.field input{width:100%;border:1px solid #ced4da;border-radius:9px;padding:12px;background:#fff}.hint{font-size:13px;color:#6b7280;margin-top:7px}.btn{width:100%;border:0;border-radius:9px;padding:13px;background:#198754;color:#fff;font-weight:700;font-size:16px;cursor:pointer}.btn:hover{background:#146c43}.logout{display:block;text-align:center;margin-top:18px;color:#6b7280;text-decoration:none}
    </style>
</head>
<body>
<main class="card">
    <div class="brand">
        <img src="include/logo.png" alt="TAU NSTP logo">
        <h1>New Semester COR<br><span style="font-size:18px;font-weight:700">COR para sa Bagong Semester</span></h1>
        <div class="semester"><?php echo htmlspecialchars($requirement['semester_label']); ?></div>
    </div>
    <div class="notice">
        <i class="fa-solid fa-circle-info"></i>
        Hi <?php echo htmlspecialchars($user['full_name']); ?>. Please upload your current COR before using the other features of your student account.<br>
        <strong>Filipino:</strong> I-upload muna ang kasalukuyan mong COR bago magamit ang ibang feature ng iyong student account.
    </div>
    <?php if ($error !== ''): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form action="endpoint/upload-student-cor.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['cor_upload_csrf']); ?>">
        <div class="field">
            <label for="cor_file">Certificate of Registration (COR) / Katibayan ng Pagpaparehistro</label>
            <input id="cor_file" name="cor_file" type="file" accept="application/pdf,image/jpeg,image/png" required>
            <div class="hint">PDF, JPG, or PNG; maximum of 10 MB. The file will be saved directly to the database.<br>PDF, JPG, o PNG; maximum na 10 MB. Direktang ise-save ang file sa database.</div>
        </div>
        <button class="btn" type="submit"><i class="fa-solid fa-cloud-arrow-up"></i> Upload COR and Continue / Magpatuloy</button>
    </form>
    <a class="logout" href="endpoint/logout.php">Sign Out / Mag-sign out</a>
</main>
</body>
</html>

