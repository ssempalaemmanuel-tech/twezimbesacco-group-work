<?php
session_start();
require_once('db.php');

// Explicit Logout Redirect Interceptor Core Routing
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    $_SESSION = array();
    session_destroy();
    header("Location: index.php?clear_session=true");
    exit();
}

if (!isset($_SESSION['user_type'])) {
    header("Location: index.php");
    exit();
}

$userType = $_SESSION['user_type'];
$message = "";
$msg_class = "";
$currentRole = isset($_SESSION['system_role']) ? $_SESSION['system_role'] : 'Member';

// Helper to fetch a valid existing STAFF_ID for automated member self-service logs
function getSystemStaffId($conn) {
    $res = $conn->query("SELECT STAFF_ID FROM STAFF LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return $row['STAFF_ID'];
    }
    return null;
}

// ==========================================
// ACTION INTERCEPTOR 1: MEMBER SELF-SERVICE REQUESTS
// ==========================================
if ($userType === 'Member') {
    $accountID = $_SESSION['account_id'];
    
    // 1A. Member Deposit / Withdrawal
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['member_tx_action'])) {
        $type = $_POST['action_type'];
        $amount = floatval($_POST['amount']);
        $sysStaff = getSystemStaffId($conn);
        $txID = "TX" . time() . rand(10, 99);
        
        if ($amount > 0) {
            $conn->begin_transaction();
            try {
                if ($type === 'DR') {
                    $chk = $conn->prepare("SELECT CURRENT_BALANCE FROM ACCOUNT WHERE ACCOUNT_ID = ?");
                    $chk->bind_param("s", $accountID);
                    $chk->execute();
                    $res = $chk->get_result();
                    $r = $res->fetch_assoc();
                    
                    if ($r['CURRENT_BALANCE'] < $amount) {
                        throw new Exception("Withdrawal Refused: Insufficient funds.");
                    }
                    $sql = "UPDATE ACCOUNT SET CURRENT_BALANCE = CURRENT_BALANCE - ? WHERE ACCOUNT_ID = ?";
                } else {
                    $sql = "UPDATE ACCOUNT SET CURRENT_BALANCE = CURRENT_BALANCE + ? WHERE ACCOUNT_ID = ?";
                }
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ds", $amount, $accountID);
                $stmt->execute();
                
                $log = $conn->prepare("INSERT INTO TRANSACTION (TRANSACTION_ID, ACCOUNT_ID, STAFF_ID, TRANSACTION_TYPE, AMOUNT) VALUES (?, ?, ?, ?, ?)");
                $log->bind_param("ssssd", $txID, $accountID, $sysStaff, $type, $amount);
                $log->execute();
                
                $conn->commit();
                $message = "Request Completed: Balance updated inside your ledger view.";
                $msg_class = "success";
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $msg_class = "error";
            }
        }
    }
    
    // 1B. Member Loan Application
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['member_loan_action'])) {
        $lAmount = floatval($_POST['loan_amount']);
        $loanID = "LN" . rand(500, 999);
        $ldate = date('Y-m-d');
        $ddate = date('Y-m-d', strtotime('+6 months'));
        
        if ($lAmount > 0) {
            $stmt = $conn->prepare("INSERT INTO LOAN (LOAN_ID, ACCOUNT_ID, LOAN_DATE, DUE_DATE, STATUS, PRINCIPAL_AMOUNT, REMAINING_BALANCE) VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
            $stmt->bind_param("ssssdd", $loanID, $accountID, $ldate, $ddate, $lAmount, $lAmount);
            $stmt->execute();
            
            $message = "Success: Application submitted to Loan Officer queue.";
            $msg_class = "success";
        }
    }

    // 1C. Member Self-Service Loan Repayment
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['member_repay_loan'])) {
        $targetLoan = trim($_POST['member_repay_loan_id']);
        $payAmount = floatval($_POST['member_repay_amount']);
        $paySource = $_POST['repay_source'];
        $sysStaff = getSystemStaffId($conn);
        $payID = "PMT" . time() . rand(10, 99);
        $txID = "TX" . time() . rand(10, 99);

        if ($payAmount > 0 && !empty($targetLoan)) {
            $conn->begin_transaction();
            try {
                // Verify loan belongs to this member and is Approved
                $chk = $conn->prepare("SELECT REMAINING_BALANCE FROM LOAN WHERE LOAN_ID = ? AND ACCOUNT_ID = ? AND STATUS = 'Approved'");
                $chk->bind_param("ss", $targetLoan, $accountID);
                $chk->execute();
                $result = $chk->get_result();

                if ($result->num_rows == 0) {
                    throw new Exception("Error: Active approved loan not found for your account.");
                }
                $lRow = $result->fetch_assoc();

                if ($payAmount > $lRow['REMAINING_BALANCE']) {
                    throw new Exception("Denied: Payment exceeds your remaining loan balance of " . number_format($lRow['REMAINING_BALANCE'], 2) . " UGX.");
                }

                // If paying from SACCO savings balance, check and deduct funds
                if ($paySource === 'savings') {
                    $balChk = $conn->prepare("SELECT CURRENT_BALANCE FROM ACCOUNT WHERE ACCOUNT_ID = ?");
                    $balChk->bind_param("s", $accountID);
                    $balChk->execute();
                    $bRow = $balChk->get_result()->fetch_assoc();

                    if ($bRow['CURRENT_BALANCE'] < $payAmount) {
                        throw new Exception("Payment Failed: Insufficient SACCO savings balance.");
                    }

                    $deduct = $conn->prepare("UPDATE ACCOUNT SET CURRENT_BALANCE = CURRENT_BALANCE - ? WHERE ACCOUNT_ID = ?");
                    $deduct->bind_param("ds", $payAmount, $accountID);
                    $deduct->execute();

                    $drType = "DR";
                    $logTx = $conn->prepare("INSERT INTO TRANSACTION (TRANSACTION_ID, ACCOUNT_ID, STAFF_ID, TRANSACTION_TYPE, AMOUNT) VALUES (?, ?, ?, ?, ?)");
                    $logTx->bind_param("ssssd", $txID, $accountID, $sysStaff, $drType, $payAmount);
                    $logTx->execute();
                }

                // Reduce remaining loan balance and close loan if fully paid
                $newBalance = $lRow['REMAINING_BALANCE'] - $payAmount;
                $newStatus = ($newBalance <= 0) ? 'Paid' : 'Approved';

                $upd = $conn->prepare("UPDATE LOAN SET REMAINING_BALANCE = ?, STATUS = ? WHERE LOAN_ID = ?");
                $upd->bind_param("dss", $newBalance, $newStatus, $targetLoan);
                $upd->execute();

                // Record payment in LOAN_PAYMENT table using valid STAFF_ID
                $ins = $conn->prepare("INSERT INTO LOAN_PAYMENT (PAYMENT_ID, LOAN_ID, STAFF_ID, AMOUNT) VALUES (?, ?, ?, ?)");
                $ins->bind_param("sssd", $payID, $targetLoan, $sysStaff, $payAmount);
                $ins->execute();

                $conn->commit();
                if ($newStatus === 'Paid') {
                    $message = "Congratulations! Loan " . $targetLoan . " is now fully paid off.";
                } else {
                    $message = "Success: Loan repayment of " . number_format($payAmount, 2) . " UGX recorded. Remaining balance: " . number_format($newBalance, 2) . " UGX.";
                }
                $msg_class = "success";
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $msg_class = "error";
            }
        }
    }
}

// ==========================================
// ACTION INTERCEPTOR 2: TELLER TRANSACTION OPERATIONS (Process 2.0)
// ==========================================
if ($userType === 'Staff' && ($currentRole === 'Teller' || $currentRole === 'Administrator')) {
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['teller_submit_tx'])) {
        $tgtAccount = trim($_POST['tgt_account_id']);
        $type = $_POST['teller_tx_type'];
        $amount = floatval($_POST['teller_amount']);
        $staffID = $_SESSION['staff_id'];
        $txID = "TX" . time() . rand(10, 99);
        
        if ($amount > 0 && !empty($tgtAccount)) {
            $conn->begin_transaction();
            try {
                $chk = $conn->prepare("SELECT CURRENT_BALANCE FROM ACCOUNT WHERE ACCOUNT_ID = ?");
                $chk->bind_param("s", $tgtAccount);
                $chk->execute();
                $res = $chk->get_result();
                
                if ($res->num_rows == 0) {
                    throw new Exception("Error: Target ledger missing.");
                }
                $r = $res->fetch_assoc();
                
                if ($type === 'DR' && $r['CURRENT_BALANCE'] < $amount) {
                    throw new Exception("Denied: Low account balance.");
                }
                
                if ($type === 'DR') {
                    $sql = "UPDATE ACCOUNT SET CURRENT_BALANCE = CURRENT_BALANCE - ? WHERE ACCOUNT_ID = ?";
                } else {
                    $sql = "UPDATE ACCOUNT SET CURRENT_BALANCE = CURRENT_BALANCE + ? WHERE ACCOUNT_ID = ?";
                }
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ds", $amount, $tgtAccount);
                $stmt->execute();
                
                $log = $conn->prepare("INSERT INTO TRANSACTION (TRANSACTION_ID, ACCOUNT_ID, STAFF_ID, TRANSACTION_TYPE, AMOUNT) VALUES (?, ?, ?, ?, ?)");
                $log->bind_param("ssssd", $txID, $tgtAccount, $staffID, $type, $amount);
                $log->execute();
                
                $conn->commit();
                $message = "Success: Transaction ledger synchronized successfully.";
                $msg_class = "success";
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $msg_class = "error";
            }
        }
    }
}

// ==========================================
// ACTION INTERCEPTOR 3: LOAN OFFICER APPROVAL & REJECTION ACTIONS (Process 3.0)
// ==========================================
if ($userType === 'Staff' && ($currentRole === 'Loan Officer' || $currentRole === 'Administrator')) {
    if (isset($_GET['approve_id'])) {
        $appID = $_GET['approve_id'];
        $stmt = $conn->prepare("UPDATE LOAN SET STATUS = 'Approved' WHERE LOAN_ID = ? AND STATUS = 'Pending'");
        $stmt->bind_param("s", $appID);
        $stmt->execute();
        $message = "Loan File " . htmlspecialchars($appID) . " marked approved inside D3 datastore.";
        $msg_class = "success";
    }

    if (isset($_GET['reject_id'])) {
        $rejID = $_GET['reject_id'];
        $stmt = $conn->prepare("UPDATE LOAN SET STATUS = 'Rejected', REMAINING_BALANCE = 0 WHERE LOAN_ID = ? AND STATUS = 'Pending'");
        $stmt->bind_param("s", $rejID);
        $stmt->execute();
        $message = "Loan File " . htmlspecialchars($rejID) . " has been rejected. Account holder notified: 'Your loan request can not be processed, reach out to our officies.'";
        $msg_class = "error";
    }
    
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['officer_post_repayment'])) {
        $targetLoan = trim($_POST['repay_loan_id']);
        $payAmount = floatval($_POST['repay_amount']);
        $staffID = $_SESSION['staff_id'];
        $payID = "PMT" . time() . rand(10, 99);
        
        if ($payAmount > 0 && !empty($targetLoan)) {
            $conn->begin_transaction();
            try {
                $chk = $conn->prepare("SELECT REMAINING_BALANCE FROM LOAN WHERE LOAN_ID = ? AND STATUS = 'Approved'");
                $chk->bind_param("s", $targetLoan);
                $chk->execute();
                $result = $chk->get_result();
                
                if ($result->num_rows == 0) {
                    throw new Exception("Error: Active approved loan not found.");
                }
                $lRow = $result->fetch_assoc();
                
                if ($payAmount > $lRow['REMAINING_BALANCE']) {
                    throw new Exception("Denied: Payout exceeds total obligation.");
                }
                
                $newBal = $lRow['REMAINING_BALANCE'] - $payAmount;
                $newStat = ($newBal <= 0) ? 'Paid' : 'Approved';

                $upd = $conn->prepare("UPDATE LOAN SET REMAINING_BALANCE = ?, STATUS = ? WHERE LOAN_ID = ?");
                $upd->bind_param("dss", $newBal, $newStat, $targetLoan);
                $upd->execute();
                
                $ins = $conn->prepare("INSERT INTO LOAN_PAYMENT (PAYMENT_ID, LOAN_ID, STAFF_ID, AMOUNT) VALUES (?, ?, ?, ?)");
                $ins->bind_param("sssd", $payID, $targetLoan, $staffID, $payAmount);
                $ins->execute();
                
                $conn->commit();
                $message = "Repayment logged live into datastore matrices.";
                $msg_class = "success";
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $msg_class = "error";
            }
        }
    }
}

// ==========================================
// DFD PROCESS 4.0: LIVE AUDIT REPORT ENGINE
// ==========================================
$savings = $conn->query("SELECT SUM(CURRENT_BALANCE) AS total FROM ACCOUNT");
$sRow = $savings->fetch_assoc();
$totalSavings = $sRow['total'] ?? 0;

$loans = $conn->query("SELECT SUM(REMAINING_BALANCE) AS total FROM LOAN WHERE STATUS='Approved'");
$lRow = $loans->fetch_assoc();
$totalLoans = $lRow['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Twezimbe SACCO Workspace</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .member-rejection-alert {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-left: 5px solid #dc2626;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 13px;
        }
    </style>
</head>
<body>
<header>
    <div>
        <h1 style="margin:0; font-size: 19px;">Twezimbe SACCO Workspace</h1>
        <small style="color: #a7f3d0;">
            <?php
            if ($userType === 'Member') {
                echo "Account Target: " . htmlspecialchars($_SESSION['account_id']) . " | Active Session: " . htmlspecialchars($_SESSION['member_name']) . " (" . htmlspecialchars($_SESSION['selected_branch']) . ")";
            } else {
                echo "Active Staff Actor: " . htmlspecialchars($_SESSION['staff_name']) . " [" . htmlspecialchars($currentRole) . "]";
            }
            ?>
        </small>
    </div>
    <div style="display: flex; gap:15px; align-items:center;">
        <?php if ($userType === 'Staff' && ($currentRole === 'Teller' || $currentRole === 'Administrator')): ?>
            <a href="register.php" class="nav-link" style="background:#065f46; padding: 6px 12px; border-radius:4px;">+ Process 1.0 Register Member</a>
        <?php endif; ?>
        <a href="dashboard.php?logout=true" class="nav-link" style="background:#dc2626; padding: 6px 12px; border-radius:4px;">Sign Out Actor</a>
    </div>
</header>

<div class="container" style="margin-top: 25px;">
    <?php if (!empty($message)): ?>
        <div class="msg <?php echo $msg_class; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($userType === 'Member' || $currentRole === 'Administrator'): ?>
        <?php
        $memberAccount = ($userType === 'Member') ? $_SESSION['account_id'] : 'ACC201';
        
        // Check for rejected loans to notify the account holder directly
        $rejStmt = $conn->prepare("SELECT LOAN_ID, LOAN_DATE, PRINCIPAL_AMOUNT FROM LOAN WHERE ACCOUNT_ID = ? AND STATUS = 'Rejected' ORDER BY LOAN_DATE DESC");
        $rejStmt->bind_param("s", $memberAccount);
        $rejStmt->execute();
        $rejRes = $rejStmt->get_result();
        while ($rejRow = $rejRes->fetch_assoc()):
        ?>
            <div class="member-rejection-alert">
                <strong>Notice (Loan <?php echo htmlspecialchars($rejRow['LOAN_ID']); ?> - <?php echo number_format($rejRow['PRINCIPAL_AMOUNT'], 2); ?> UGX):</strong> Your loan request can not be processed, reach out to our officies.
            </div>
        <?php endwhile; ?>

        <?php
        $stmtBal = $conn->prepare("SELECT CURRENT_BALANCE FROM ACCOUNT WHERE ACCOUNT_ID = ?");
        $stmtBal->bind_param("s", $memberAccount);
        $stmtBal->execute();
        $resBal = $stmtBal->get_result();
        $bQ = $resBal->fetch_assoc();
        $liveBal = $bQ['CURRENT_BALANCE'] ?? 0;
        
        $stmtLoan = $conn->prepare("SELECT SUM(REMAINING_BALANCE) AS total FROM LOAN WHERE ACCOUNT_ID = ? AND STATUS = 'Approved'");
        $stmtLoan->bind_param("s", $memberAccount);
        $stmtLoan->execute();
        $resLoan = $stmtLoan->get_result();
        $lQ = $resLoan->fetch_assoc();
        $liveDebt = $lQ['total'] ?? 0;
        ?>
        <h2 style="margin-top: 20px;">👤 Member Personal Viewport</h2>
        <div class="grid-2">
            <div class="card" style="border-left: 5px solid #2563eb;">
                <h3>Live Balance</h3>
                <p style="font-size: 24px; font-weight: bold; margin: 5px 0 0 0;"><?php echo htmlspecialchars(number_format($liveBal, 2)); ?> UGX</p>
            </div>
            <div class="card" style="border-left: 5px solid #dc2626;">
                <h3>Outstanding Debt</h3>
                <p style="font-size: 24px; font-weight: bold; margin: 5px 0 0 0;"><?php echo htmlspecialchars(number_format($liveDebt, 2)); ?> UGX</p>
            </div>
        </div>

        <div class="grid-3">
            <div class="card">
                <h3>Process Transaction</h3>
                <form action="dashboard.php" method="POST">
                    <div class="form-group">
                        <label>Action Type</label>
                        <select name="action_type">
                            <option value="CR">Deposit (Credit)</option>
                            <option value="DR">Withdrawal (Debit)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (UGX)</label>
                        <input type="number" step="0.01" name="amount" required>
                    </div>
                    <button type="submit" name="member_tx_action" class="btn-blue">Execute Action</button>
                </form>
            </div>

            <div class="card">
                <h3>Apply for a Loan</h3>
                <form action="dashboard.php" method="POST">
                    <div class="form-group">
                        <label>Principal Amount (UGX)</label>
                        <input type="number" step="0.01" name="loan_amount" required>
                    </div>
                    <button type="submit" name="member_loan_action" class="btn-orange">Submit Application</button>
                </form>
            </div>

            <div class="card" style="border-top: 4px solid #047857;">
                <h3>Repay Active Loan</h3>
                <?php
                $activeLoansStmt = $conn->prepare("SELECT LOAN_ID, REMAINING_BALANCE FROM LOAN WHERE ACCOUNT_ID = ? AND STATUS = 'Approved' AND REMAINING_BALANCE > 0");
                $activeLoansStmt->bind_param("s", $memberAccount);
                $activeLoansStmt->execute();
                $activeLoansRes = $activeLoansStmt->get_result();
                ?>
                <?php if ($activeLoansRes->num_rows > 0): ?>
                    <form action="dashboard.php" method="POST">
                        <div class="form-group">
                            <label>Select Active Loan</label>
                            <select name="member_repay_loan_id" required>
                                <?php while ($al = $activeLoansRes->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($al['LOAN_ID']); ?>">
                                        <?php echo htmlspecialchars($al['LOAN_ID']); ?> (Due: <?php echo number_format($al['REMAINING_BALANCE'], 2); ?> UGX)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment Source</label>
                            <select name="repay_source">
                                <option value="savings">Deduct from SACCO Savings</option>
                                <option value="mobile_money">Mobile Money / Direct Cash</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Repayment Amount (UGX)</label>
                            <input type="number" step="0.01" name="member_repay_amount" required>
                        </div>
                        <button type="submit" name="member_repay_loan" class="btn-green">Pay Loan</button>
                    </form>
                <?php else: ?>
                    <p style="color: #64748b; font-size: 13px; margin-top: 10px;">You currently have no active approved loans requiring repayment.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3>My Loan History & Status</h3>
            <table>
                <thead>
                    <tr>
                        <th>Loan ID</th>
                        <th>Date Applied</th>
                        <th>Due Date</th>
                        <th>Principal (UGX)</th>
                        <th>Remaining Balance (UGX)</th>
                        <th>Status & Notice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $myLoans = $conn->prepare("SELECT * FROM LOAN WHERE ACCOUNT_ID = ? ORDER BY LOAN_DATE DESC");
                    $myLoans->bind_param("s", $memberAccount);
                    $myLoans->execute();
                    $myLoansRes = $myLoans->get_result();
                    if ($myLoansRes->num_rows == 0):
                    ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #64748b;">You have not applied for any loans yet.</td>
                        </tr>
                    <?php else: while ($ml = $myLoansRes->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($ml['LOAN_ID']); ?></strong></td>
                            <td><?php echo htmlspecialchars($ml['LOAN_DATE']); ?></td>
                            <td><?php echo htmlspecialchars($ml['DUE_DATE']); ?></td>
                            <td><?php echo htmlspecialchars(number_format($ml['PRINCIPAL_AMOUNT'], 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format($ml['REMAINING_BALANCE'], 2)); ?></td>
                            <td>
                                <?php
                                $badgeClass = 'badge-approved';
                                if ($ml['STATUS'] === 'Pending') $badgeClass = 'badge-pending';
                                elseif ($ml['STATUS'] === 'Rejected') $badgeClass = 'badge-rejected';
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo htmlspecialchars($ml['STATUS']); ?>
                                </span>
                                <?php if ($ml['STATUS'] === 'Rejected'): ?>
                                    <div style="color: #991b1b; font-size: 11.5px; font-weight: 500; margin-top: 4px;">
                                        Your loan request can not be processed, reach out to our officies.
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($userType === 'Staff' && ($currentRole === 'Teller' || $currentRole === 'Loan Officer' || $currentRole === 'Administrator')): ?>
        <h2 style="margin-top: 30px;">💼 Management Console Hub</h2>
        <div class="grid-2">
            <div class="card" style="border-left: 5px solid #047857;">
                <h3>Total Institutional Savings</h3>
                <p style="font-size: 24px; font-weight: bold; margin: 5px 0 0 0; color: #047857;"><?php echo htmlspecialchars(number_format($totalSavings, 2)); ?> UGX</p>
            </div>
            <div class="card" style="border-left: 5px solid #d97706;">
                <h3>Active Loan Exposure</h3>
                <p style="font-size: 24px; font-weight: bold; margin: 5px 0 0 0; color: #d97706;"><?php echo htmlspecialchars(number_format($totalLoans, 2)); ?> UGX</p>
            </div>
        </div>

        <div class="grid-2">
            <?php if ($currentRole === 'Teller' || $currentRole === 'Administrator'): ?>
                <div class="card">
                    <h3>Counter Cash Terminal</h3>
                    <form action="dashboard.php" method="POST">
                        <div class="form-group">
                            <label>Target Account ID</label>
                            <input type="text" name="tgt_account_id" required>
                        </div>
                        <div class="form-group">
                            <label>Transaction Type</label>
                            <select name="teller_tx_type">
                                <option value="CR">Deposit / Inward</option>
                                <option value="DR">Withdrawal / Outward</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Cash Amount (UGX)</label>
                            <input type="number" step="0.01" name="teller_amount" required>
                        </div>
                        <button type="submit" name="teller_submit_tx" class="btn-green">Post Transaction</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($currentRole === 'Loan Officer' || $currentRole === 'Administrator'): ?>
                <div class="card">
                    <h3>Post Loan Repayment (Process 3.0)</h3>
                    <form action="dashboard.php" method="POST">
                        <div class="form-group">
                            <label>Target Loan ID</label>
                            <input type="text" name="repay_loan_id" required>
                        </div>
                        <div class="form-group">
                            <label>Repayment Amount (UGX)</label>
                            <input type="number" step="0.01" name="repay_amount" required>
                        </div>
                        <button type="submit" name="officer_post_repayment" class="btn-green" style="background-color: #334155;">Record Repayment</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($currentRole === 'Loan Officer' || $currentRole === 'Administrator'): ?>
            <div class="card">
                <h3>Member Loan Portfolio & Approval Queue</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Loan ID</th>
                            <th>Member Name</th>
                            <th>Contact</th>
                            <th>Account ID</th>
                            <th>Date Filed</th>
                            <th>Due Date</th>
                            <th>Principal (UGX)</th>
                            <th>Remaining Balance</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $queue = $conn->query("
                            SELECT l.*, m.NAME AS MEMBER_NAME, m.CONTACT 
                            FROM LOAN l
                            JOIN ACCOUNT a ON l.ACCOUNT_ID = a.ACCOUNT_ID
                            JOIN MEMBERS m ON a.MEMBER_ID = m.MEMBER_ID
                            ORDER BY FIELD(l.STATUS, 'Pending', 'Approved', 'Paid', 'Rejected'), l.LOAN_DATE DESC
                        ");
                        if ($queue && $queue->num_rows == 0):
                        ?>
                            <tr>
                                <td colspan="10" style="text-align: center; color: #64748b; padding: 15px;">No loan records found.</td>
                            </tr>
                        <?php elseif ($queue): while($lRow = $queue->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($lRow['LOAN_ID']); ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($lRow['MEMBER_NAME']); ?></strong></td>
                                <td><?php echo htmlspecialchars($lRow['CONTACT']); ?></td>
                                <td><?php echo htmlspecialchars($lRow['ACCOUNT_ID']); ?></td>
                                <td><?php echo htmlspecialchars($lRow['LOAN_DATE']); ?></td>
                                <td><?php echo htmlspecialchars($lRow['DUE_DATE']); ?></td>
                                <td><?php echo htmlspecialchars(number_format($lRow['PRINCIPAL_AMOUNT'], 2)); ?></td>
                                <td><?php echo htmlspecialchars(number_format($lRow['REMAINING_BALANCE'], 2)); ?></td>
                                <td>
                                    <?php
                                    $qBadge = 'badge-approved';
                                    if ($lRow['STATUS'] === 'Pending') $qBadge = 'badge-pending';
                                    elseif ($lRow['STATUS'] === 'Rejected') $qBadge = 'badge-rejected';
                                    ?>
                                    <span class="badge <?php echo $qBadge; ?>">
                                        <?php echo htmlspecialchars($lRow['STATUS']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($lRow['STATUS'] === 'Pending'): ?>
                                        <div style="display: flex; gap: 5px;">
                                            <a href="dashboard.php?approve_id=<?php echo urlencode($lRow['LOAN_ID']); ?>" style="background: #047857; color: white; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 11px;">
                                                Approve
                                            </a>
                                            <a href="dashboard.php?reject_id=<?php echo urlencode($lRow['LOAN_ID']); ?>" onclick="return confirm('Are you sure you want to reject loan <?php echo htmlspecialchars($lRow['LOAN_ID']); ?>?');" style="background: #dc2626; color: white; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 11px;">
                                                Reject
                                            </a>
                                        </div>
                                    <?php elseif ($lRow['STATUS'] === 'Paid'): ?>
                                        <span style="color: #047857; font-size: 11px; font-weight: 600;">Fully Paid</span>
                                    <?php elseif ($lRow['STATUS'] === 'Rejected'): ?>
                                        <span style="color: #dc2626; font-size: 11px; font-weight: 600;">Rejected</span>
                                    <?php else: ?>
                                        <span style="color: #166534; font-size: 11px; font-weight: 600;">Active / Approved</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
