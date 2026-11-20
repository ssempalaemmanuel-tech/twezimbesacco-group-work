<?php
session_start();
require_once('db.php');

$message = "";
$msg_class = "";

if (isset($_GET['clear_session'])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

if (isset($_SESSION['user_type'])) {
    header("Location: dashboard.php");
    exit();
}

// 1. PROCESS STAFF ACTOR LOGIN
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login_staff'])) {
    $staffID = trim($_POST['staff_id']);
    $role = $_POST['system_role'];

    $stmt = $conn->prepare("SELECT * FROM STAFF WHERE STAFF_ID = ? AND ROLE = ?");
    $stmt->bind_param("ss", $staffID, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION['user_type'] = 'Staff';
        $_SESSION['staff_id'] = $row['STAFF_ID'];
        $_SESSION['staff_name'] = $row['NAME'];
        $_SESSION['system_role'] = $row['ROLE'];
        header("Location: dashboard.php");
        exit();
    } else {
        $message = "Authentication Refused: Incorrect Staff Credentials.";
        $msg_class = "error";
    }
}

// 2. PROCESS MEMBER LOGIN (Enforcing Branch Selection)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login_member'])) {
    $accountID = trim($_POST['account_id']);
    $branch = $_POST['login_branch'];

    $stmt = $conn->prepare("SELECT M.*, A.ACCOUNT_ID FROM MEMBERS M JOIN ACCOUNT A ON M.MEMBER_ID = A.MEMBER_ID WHERE A.ACCOUNT_ID = ?");
    $stmt->bind_param("s", $accountID);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION['user_type'] = 'Member';
        $_SESSION['member_id'] = $row['MEMBER_ID'];
        $_SESSION['member_name'] = $row['NAME'];
        $_SESSION['account_id'] = $row['ACCOUNT_ID'];
        $_SESSION['selected_branch'] = $branch; 
        header("Location: dashboard.php");
        exit();
    } else {
        $message = "Authentication Refused: Target Account Reference missing.";
        $msg_class = "error";
    }
}

// 3. PROCESS PUBLIC login (DFD 1.0)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['join_sacco'])) {
    $name = trim($_POST['member_name']);
    $contact = trim($_POST['member_contact']);
    $branch = $_POST['branch_assignment'];
    
    $memberID = "MBR" . rand(105, 999);
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
            $message = " Registration Success! Your Account ID Target is: <strong>" . $accountID . "</strong>. Choose branch '" . $branch . "' to log in.";
            $msg_class = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Registration Fault: " . $e->getMessage();
            $msg_class = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Twezimbe SACCO Portal </title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="welcome-hero">
        <h1>Welcome to Twezimbe SACCO</h1>
    </div>

    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="msg <?php echo $msg_class; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="grid-3">
            <!-- MEMBER SIGN-IN -->
            <div class="card">
                <h2> Member Sign-In</h2>
                <form action="index.php" method="POST">
                    <div class="form-group">
                        <label>Account ID </label>
                        <input type="text" name="account_id" placeholder="e.g., ACC201" required>
                    </div>
                    <div class="form-group">
                        <label>Select Your Branch</label>
                        <select name="login_branch">
                            <option value="Kisaasi Main Office">Kisaasi Main Office</option>
                            <option value="Kalerwe Remote Center">Kalerwe Remote Center</option>
                            <option value="Kawempe Branch">Kawempe Branch</option>
                            <option value="Bwaise Unit">Bwaise Unit</option>
                        </select>
                    </div>
                    <button type="submit" name="login_member" class="btn-blue">Log In to Workspace</button>
                </form>
            </div>

            <!-- PUBLIC REGISTER -->
            <div class="card">
                <h2> Join Us Now</h2>
                <form action="index.php" method="POST">
                    <div class="form-group">
                        <label>Full  Names </label>
                        <input type="text" name="member_name" required>
                    </div>
                    <div class="form-group">
                        <label>Mobile Number</label>
                        <input type="text" name="member_contact" required>
                    </div>
                    <div class="form-group">
                        <label>Select Branch Office</label>
                        <select name="branch_assignment">
                            <option value="Kisaasi Main Office">Kisaasi Main Office</option>
                            <option value="Kalerwe Remote Center">Kalerwe Remote Center</option>
                            <option value="Kawempe Branch">Kawempe Branch</option>
                            <option value="Bwaise Unit">Bwaise Unit</option>
                        </select>
                    </div>
                    <button type="submit" name="join_sacco" class="btn-orange">Onboard Membership</button>
                </form>
            </div>

            <!-- STAFF PORTAL -->
            <div class="card">
                <h2> Staff Sign-In</h2>
                <form action="index.php" method="POST">
                    <div class="form-group">
                        <label>Staff ID Identifier</label>
                        <input type="text" name="staff_id" value="STF001" required>
                    </div>
                    <div class="form-group">
                        <label>Actor Role</label>
                        <select name="system_role">
                            <option value="Teller">Teller Actor</option>
                            <option value="Loan Officer">Loan Officer Actor</option>
                            <option value="Administrator">Administrator Actor</option>
                        </select>
                    </div>
                    <button type="submit" name="login_staff" class="btn-green">Verify Identity</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
