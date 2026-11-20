<?php
session_start();
require_once('db.php');

if (!isset($_SESSION['user_type']) || ($_SESSION['system_role'] !== 'Teller' && $_SESSION['system_role'] !== 'Administrator')) {
    header("Location: index.php");
    exit();
}

$message = "";
$msg_class = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register_member'])) {
    $memberID = "MBR" . rand(105, 999);
    $name = trim($_POST['name']);
    $contact = trim($_POST['contact']);
    $accountID = "ACC" . rand(205, 999);
    $currentDate = date('Y-m-d');

    if (!empty($name) && !empty($contact)) {
        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("INSERT INTO MEMBERS (MEMBER_ID, NAME, CONTACT) VALUES (?, ?, ?)");
            $stmt1->bind_param("sss", $memberID, $name, $contact);
            $stmt1->execute();

            $stmt2 = $conn->prepare("INSERT INTO ACCOUNT (ACCOUNT_ID, MEMBER_ID, ACCOUNT_STATUS, CREATION_DATE, CURRENT_BALANCE) VALUES (?, ?, 'Active', ?, 0.00)");
            $stmt2->bind_param("sss", $accountID, $memberID, $currentDate);
            $stmt2->execute();

            $conn->commit();
            $message = "Success: Onboarded Profile! Account Target: $accountID";
            $msg_class = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Fault: " . $e->getMessage();
            $msg_class = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Twezimbe MIS - Process 1.0 Terminal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div>
            <h1 style="margin:0; font-size: 18px;">Process 1.0: Members Management</h1>
            <small style="color: #a7f3d0;">Operator: <?php echo htmlspecialchars($_SESSION['staff_name']); ?></small>
        </div>
        <div><a href="dashboard.php" class="nav-link">← Return to Dashboard</a></div>
    </header>

    <div class="container" style="margin-top: 30px;">
        <div class="card" style="max-width: 500px; margin: 0 auto;">
            <h2>Teller register new member</h2>
            <?php if (!empty($message)): ?>
                <div class="msg <?php echo $msg_class; ?>"><?php echo $message; ?></div>
            <?php endif; ?>
            <form action="register.php" method="POST">
                <div class="form-group">
                    <label>Member Name</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Contact Info (Phone)</label>
                    <input type="text" name="contact" required>
                </div>
                <button type="submit" name="register_member" class="btn-green">Commit to D1 Datastore</button>
            </form>
        </div>
    </div>
</body>
</html>
