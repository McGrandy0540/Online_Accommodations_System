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

// Get payment history for these rooms
$stmt = $pdo->prepare("
    SELECT 
        rlph.*,
        pr.room_number,
        p.property_name
    FROM room_levy_payment_history rlph
    JOIN property_rooms pr ON rlph.room_id = pr.id
    JOIN property p ON pr.property_id = p.id
    WHERE rlph.payment_id = ?
    ORDER BY rlph.expiry_date DESC
");
$stmt->execute([$payment_id]);
$history = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Details | Landlords&Tenant</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .payment-card {
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-completed {
            background-color: #28a745;
            color: white;
        }
        .status-pending {
            background-color: #ffc107;
            color: #212529;
        }
        .status-failed {
            background-color: #dc3545;
            color: white;
        }
        .room-card {
            transition: all 0.2s ease;
        }
        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">
                    <i class="fas fa-receipt mr-2"></i>Payment Details
                </h1>
                <nav class="flex" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3">
                        <li class="inline-flex items-center">
                            <a href="index.php" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-blue-600">
                                <i class="fas fa-home mr-2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li>
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400"></i>
                                <a href="room_levy_payment.php" class="ml-1 text-sm font-medium text-gray-700 hover:text-blue-600 md:ml-2">
                                    Room Levy Payments
                                </a>
                            </div>
                        </li>
                        <li aria-current="page">
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400"></i>
                                <span class="ml-1 text-sm font-medium text-gray-500 md:ml-2">
                                    Payment #<?= htmlspecialchars($payment['payment_reference']) ?>
                                </span>
                            </div>
                        </li>
                    </ol>
                </nav>
            </div>
            <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg">
                <i class="fas fa-arrow-left mr-2"></i>Back to Payments
            </a>
        </div>

        <!-- Payment Summary Card -->
        <div class="bg-white payment-card overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-semibold text-gray-800">
                        Payment Summary
                    </h2>
                    <span class="status-badge <?= 'status-' . $payment['status'] ?>">
                        <?= ucfirst($payment['status']) ?>
                    </span>
                </div>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Payment Reference</h3>
                        <p class="mt-1 text-sm text-gray-900 font-semibold"><?= htmlspecialchars($payment['payment_reference']) ?></p>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Payment Date</h3>
                        <p class="mt-1 text-sm text-gray-900">
                            <?= date('M j, Y H:i', strtotime($payment['payment_date'])) ?>
                        </p>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Payment Method</h3>
                        <p class="mt-1 text-sm text-gray-900 capitalize">
                            <?= str_replace('_', ' ', htmlspecialchars($payment['payment_method'])) ?>
                        </p>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Amount Paid</h3>
                        <p class="mt-1 text-lg font-bold text-green-600">
                            GHS <?= number_format($payment['amount'], 2) ?>
                        </p>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Rooms Covered</h3>
                        <p class="mt-1 text-sm text-gray-900">
                            <?= $payment['room_count'] ?> room(s)
                        </p>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Duration</h3>
                        <p class="mt-1 text-sm text-gray-900">
                            1 Year (<?= $payment['duration_days'] ?> days)
                        </p>
                    </div>
                </div>
                
                <?php if ($payment['status'] === 'completed'): ?>
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-medium text-gray-500">Approval Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                            <div>
                                <p class="text-sm text-gray-600">Approved by:</p>
                                <p class="text-sm font-medium text-gray-900">
                                    <?= $payment['approver_name'] ? htmlspecialchars($payment['approver_name']) : 'System' ?>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Approval date:</p>
                                <p class="text-sm font-medium text-gray-900">
                                    <?= $payment['approval_date'] ? date('M j, Y H:i', strtotime($payment['approval_date'])) : 'N/A' ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($payment['notes']): ?>
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-medium text-gray-500">Admin Notes</h3>
                        <p class="mt-1 text-sm text-gray-700"><?= htmlspecialchars($payment['notes']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rooms Covered Section -->
        <div class="bg-white payment-card overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-door-open mr-2"></i>Rooms Covered
                </h2>
            </div>
            <div class="divide-y divide-gray-200">
                <?php if (count($rooms) > 0): ?>
                    <?php foreach ($rooms as $room): ?>
                        <div class="px-6 py-4 room-card">
                            <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                                <div class="mb-4 md:mb-0">
                                    <h3 class="text-lg font-medium text-gray-900">
                                        <?= htmlspecialchars($room['property_name']) ?> - Room <?= htmlspecialchars($room['room_number']) ?>
                                    </h3>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <i class="fas fa-map-marker-alt mr-1"></i>
                                        <?= htmlspecialchars($room['property_location']) ?>
                                    </p>
                                </div>
                                <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                                    <div class="text-center">
                                        <p class="text-sm text-gray-500">Levy Status</p>
                                        <span class="status-badge <?= 'status-' . $room['levy_payment_status'] ?> mt-1 inline-block">
                                            <?= ucfirst($room['levy_payment_status']) ?>
                                        </span>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm text-gray-500">Expiry Date</p>
                                        <p class="text-sm font-medium text-gray-900 mt-1">
                                            <?= $room['levy_expiry_date'] ? date('M j, Y', strtotime($room['levy_expiry_date'])) : 'N/A' ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="px-6 py-8 text-center">
                        <i class="fas fa-door-closed text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Rooms Found</h3>
                        <p class="text-gray-500">This payment doesn't seem to be associated with any rooms.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment History Section -->
        <div class="bg-white payment-card overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-history mr-2"></i>Payment History
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Property</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expiry Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (count($history) > 0): ?>
                            <?php foreach ($history as $record): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($record['room_number']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($record['property_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-500"><?= date('M j, Y', strtotime($record['payment_date'])) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-green-600">GHS <?= number_format($record['amount'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-500"><?= date('M j, Y', strtotime($record['expiry_date'])) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="status-badge <?= 'status-' . $record['status'] ?>">
                                            <?= ucfirst($record['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center">
                                    <i class="fas fa-info-circle text-3xl text-gray-400 mb-2"></i>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Payment History Found</h3>
                                    <p class="text-gray-500">This is the first payment for these rooms.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-8 flex flex-col sm:flex-row justify-end gap-4">
            <?php if ($payment['status'] === 'pending'): ?>
                <a href="#" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg text-center">
                    <i class="fas fa-check mr-2"></i>Confirm Payment
                </a>
            <?php endif; ?>
            
            <a href="print_payment.php?id=<?= $payment['id'] ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg text-center">
                <i class="fas fa-print mr-2"></i>Print Receipt
            </a>
            

        </div>
    </div>

    <script>
    // Simple confirmation for actions
    document.addEventListener('DOMContentLoaded', function() {
        const confirmButtons = document.querySelectorAll('a[href="#"]');
        
        confirmButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to confirm this payment?')) {
                    // In a real implementation, this would submit a form or make an AJAX request
                    alert('Payment confirmation would be processed here. This is just a demo.');
                }
            });
        });
    });
    </script>
</body>
</html>