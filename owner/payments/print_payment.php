<?php
session_start();
require_once __DIR__ . '../../../config/database.php';

// Check if user is logged in and is a property owner
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

if ($_SESSION['status'] !== 'property_owner') {
    header('Location: ../dashboard.php');
    exit();
}

// Check if payment ID is provided
if (!isset($_GET['id'])) {
    header('Location: payments.php');
    exit();
}

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];
$payment_id = $_GET['id'];

// Get payment details
$stmt = $pdo->prepare("
    SELECT 
        rlp.*,
        u.username AS owner_name,
        u.email AS owner_email,
        u.phone_number AS owner_phone,
        admin.username AS approver_name
    FROM room_levy_payments rlp
    LEFT JOIN users u ON rlp.owner_id = u.id
    LEFT JOIN users admin ON rlp.admin_approver_id = admin.id
    WHERE rlp.id = ? AND rlp.owner_id = ?
");
$stmt->execute([$payment_id, $owner_id]);
$payment = $stmt->fetch();

if (!$payment) {
    $_SESSION['error'] = "Payment not found or you don't have permission to view it";
    header('Location: payments.php');
    exit();
}

// Get rooms associated with this payment
$stmt = $pdo->prepare("
    SELECT 
        pr.*,
        p.property_name,
        p.location AS property_location
    FROM property_rooms pr
    JOIN property p ON pr.property_id = p.id
    WHERE pr.levy_payment_id = ?
    ORDER BY p.property_name, pr.room_number
");
$stmt->execute([$payment_id]);
$rooms = $stmt->fetchAll();

// Calculate total amount
$total_amount = $payment['amount'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt #<?= htmlspecialchars($payment['payment_reference']) ?> | UniHomes</title>
    <style>
        @page {
            size: A4;
            margin: 1cm;
        }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #2c5282;
            margin-bottom: 10px;
        }
        .receipt-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .receipt-subtitle {
            font-size: 16px;
            color: #666;
        }
        .section {
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        .flex-container {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .flex-item {
            width: 48%;
            margin-bottom: 15px;
        }
        .label {
            font-weight: bold;
            color: #555;
            margin-bottom: 3px;
        }
        .value {
            font-size: 15px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .table th {
            background-color: #f8f9fa;
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        .table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .total-row {
            font-weight: bold;
            background-color: #f8f9fa;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-completed {
            background-color: #28a745;
            color: white;
        }
        .status-pending {
            background-color: #ffc107;
            color: #212529;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #777;
            text-align: center;
        }
        .signature-line {
            margin-top: 50px;
            border-top: 1px dashed #ccc;
            width: 200px;
            display: inline-block;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                padding: 0;
                background: white;
            }
            .receipt-container {
                border: none;
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header Section -->
        <div class="header">
            <div class="logo">Landlords&Tenant</div>
            <div class="receipt-title">Room Levy Payment Receipt</div>
            <div class="receipt-subtitle">Official Receipt for Payment #<?= htmlspecialchars($payment['payment_reference']) ?></div>
        </div>

        <!-- Payment Details Section -->
        <div class="section">
            <div class="section-title">Payment Information</div>
            <div class="flex-container">
                <div class="flex-item">
                    <div class="label">Payment Reference</div>
                    <div class="value"><?= htmlspecialchars($payment['payment_reference']) ?></div>
                </div>
                <div class="flex-item">
                    <div class="label">Payment Date</div>
                    <div class="value"><?= date('F j, Y \a\t H:i', strtotime($payment['payment_date'])) ?></div>
                </div>
                <div class="flex-item">
                    <div class="label">Payment Method</div>
                    <div class="value"><?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?></div>
                </div>
                <div class="flex-item">
                    <div class="label">Payment Status</div>
                    <div class="value">
                        <span class="status-badge status-<?= $payment['status'] ?>">
                            <?= ucfirst($payment['status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Property Owner Information -->
        <div class="section">
            <div class="section-title">Property Owner Details</div>
            <div class="flex-container">
                <div class="flex-item">
                    <div class="label">Name</div>
                    <div class="value"><?= htmlspecialchars($payment['owner_name']) ?></div>
                </div>
                <div class="flex-item">
                    <div class="label">Email</div>
                    <div class="value"><?= htmlspecialchars($payment['owner_email']) ?></div>
                </div>
                <div class="flex-item">
                    <div class="label">Phone Number</div>
                    <div class="value"><?= htmlspecialchars($payment['owner_phone']) ?></div>
                </div>
            </div>
        </div>

        <!-- Rooms Covered Section -->
        <div class="section">
            <div class="section-title">Rooms Covered</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Property</th>
                        <th>Room Number</th>
                        <th>Location</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td><?= htmlspecialchars($room['property_name']) ?></td>
                        <td><?= htmlspecialchars($room['room_number']) ?></td>
                        <td><?= htmlspecialchars($room['property_location']) ?></td>
                        <td class="text-right">GHS <?= number_format($room['payment_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3" class="text-right">Total Amount:</td>
                        <td class="text-right">GHS <?= number_format($total_amount, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Payment Notes -->
        <?php if ($payment['notes']): ?>
        <div class="section">
            <div class="section-title">Notes</div>
            <p><?= htmlspecialchars($payment['notes']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Approval Section -->
        <?php if ($payment['status'] === 'completed'): ?>
        <div class="section">
            <div class="section-title">Approval Details</div>
            <div class="flex-container">
                <div class="flex-item">
                    <div class="label">Approved By</div>
                    <div class="value"><?= $payment['approver_name'] ? htmlspecialchars($payment['approver_name']) : 'System' ?></div>
                </div>
                <div class="flex-item">
                    <div class="label">Approval Date</div>
                    <div class="value"><?= $payment['approval_date'] ? date('F j, Y \a\t H:i', strtotime($payment['approval_date'])) : 'N/A' ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <div class="text-center">
                <p>Thank you for your payment!</p>
                <p>This is an official receipt from UniHomes. Please keep it for your records.</p>
                <p>For any inquiries, please contact support@unihomes.com</p>
                <div class="signature-line"></div>
                <p>Authorized Signature</p>
            </div>
            <div class="text-center" style="margin-top: 20px;">
                <p>Generated on: <?= date('F j, Y \a\t H:i') ?></p>
            </div>
        </div>
    </div>

    <!-- Print Button (hidden when printing) -->
    <div class="no-print" style="text-align: center; margin: 20px;">
        <button onclick="window.print()" style="
            background-color: #2c5282;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            border-radius: 4px;
        ">
            Print Receipt
        </button>
        <button onclick="window.close()" style="
            background-color: #718096;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            border-radius: 4px;
            margin-left: 10px;
        ">
            Close Window
        </button>
    </div>

    <script>
    // Automatically trigger print dialog when page loads
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
    
    // After printing, close the window (if supported)
    window.onafterprint = function() {
        window.close();
    };
    </script>
</body>
</html>