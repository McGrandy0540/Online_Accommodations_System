<?php
session_start();
require_once __DIR__ . '../../../config/database.php';

// Check if user is logged in and is a property owner
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if ($_SESSION['status'] !== 'property_owner') {
    header('Location: ../dashboard.php');
    exit();
}

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

// Get all room levy payments for this owner
$stmt = $pdo->prepare("
    SELECT 
        rlp.*,
        COUNT(pr.id) AS rooms_count,
        GROUP_CONCAT(pr.room_number SEPARATOR ', ') AS room_numbers,
        GROUP_CONCAT(p.property_name SEPARATOR ', ') AS property_names
    FROM room_levy_payments rlp
    LEFT JOIN property_rooms pr ON rlp.id = pr.levy_payment_id
    LEFT JOIN property p ON pr.property_id = p.id
    WHERE rlp.owner_id = ?
    GROUP BY rlp.id
    ORDER BY rlp.payment_date DESC
");
$stmt->execute([$owner_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$total_paid = array_sum(array_column($payments, 'amount'));
$completed_payments = array_filter($payments, fn($p) => $p['status'] === 'completed');
$pending_payments = array_filter($payments, fn($p) => $p['status'] === 'pending');
$failed_payments = array_filter($payments, fn($p) => $p['status'] === 'failed');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Levy Payments | UniHomes</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stats-card {
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .payment-card {
            transition: all 0.3s ease;
            border-radius: 0.5rem;
        }
        .payment-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
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
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-money-bill-wave mr-2"></i>Room Levy Payments
            </h1>
            <a href="make_payment.php" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg">
                <i class="fas fa-plus mr-2"></i>New Payment
            </a>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="stats-card bg-gradient-to-r from-blue-500 to-blue-600">
                <h3 class="text-2xl font-bold">GHS <?= number_format($total_paid, 2) ?></h3>
                <p class="opacity-90">Total Paid</p>
            </div>
            <div class="stats-card bg-gradient-to-r from-green-500 to-green-600">
                <h3 class="text-2xl font-bold"><?= count($completed_payments) ?></h3>
                <p class="opacity-90">Completed</p>
            </div>
            <div class="stats-card bg-gradient-to-r from-yellow-500 to-yellow-600">
                <h3 class="text-2xl font-bold"><?= count($pending_payments) ?></h3>
                <p class="opacity-90">Pending</p>
            </div>
            <div class="stats-card bg-gradient-to-r from-red-500 to-red-600">
                <h3 class="text-2xl font-bold"><?= count($failed_payments) ?></h3>
                <p class="opacity-90">Failed</p>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="bg-white rounded-lg shadow-md p-4 mb-8">
            <h2 class="text-xl font-semibold mb-4"><i class="fas fa-filter mr-2"></i>Filters</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="statusFilter" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select id="statusFilter" class="w-full p-2 border border-gray-300 rounded-md">
                        <option value="">All Statuses</option>
                        <option value="completed">Completed</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div>
                    <label for="dateFrom" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                    <input type="date" id="dateFrom" class="w-full p-2 border border-gray-300 rounded-md">
                </div>
                <div>
                    <label for="dateTo" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                    <input type="date" id="dateTo" class="w-full p-2 border border-gray-300 rounded-md">
                </div>
            </div>
        </div>

        <!-- Payments List -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rooms</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Properties</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($payments as $payment): ?>
                        <tr class="payment-item hover:bg-gray-50" 
                            data-status="<?= $payment['status'] ?>"
                            data-date="<?= date('Y-m-d', strtotime($payment['payment_date'])) ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($payment['payment_reference']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-500"><?= date('M j, Y', strtotime($payment['payment_date'])) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-green-600">GHS <?= number_format($payment['amount'], 2) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-500"><?= $payment['rooms_count'] ?> rooms</div>
                                <div class="text-xs text-gray-400"><?= htmlspecialchars($payment['room_numbers']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-500"><?= htmlspecialchars($payment['property_names']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="status-badge <?= 'status-' . $payment['status'] ?>">
                                    <?= ucfirst($payment['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="payment_details.php?id=<?= $payment['id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-eye mr-1"></i> View
                                </a>
                                <?php if ($payment['status'] === 'pending'): ?>
                                    <a href="#" class="text-green-600 hover:text-green-900 mr-3">
                                        <i class="fas fa-check mr-1"></i> Confirm
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (empty($payments)): ?>
            <div class="bg-white rounded-lg shadow-md p-8 text-center">
                <i class="fas fa-money-bill-wave text-4xl text-gray-400 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Room Levy Payments Found</h3>
                <p class="text-gray-500 mb-4">You haven't made any room levy payments yet.</p>
                <a href="make_payment.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">
                    <i class="fas fa-plus mr-2"></i> Make Your First Payment
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
    // Filter payments based on selected filters
    document.addEventListener('DOMContentLoaded', function() {
        const statusFilter = document.getElementById('statusFilter');
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const paymentItems = document.querySelectorAll('.payment-item');

        function applyFilters() {
            const statusValue = statusFilter.value;
            const dateFromValue = dateFrom.value;
            const dateToValue = dateTo.value;

            paymentItems.forEach(item => {
                const itemStatus = item.dataset.status;
                const itemDate = item.dataset.date;
                
                let statusMatch = statusValue === '' || itemStatus === statusValue;
                let dateMatch = true;
                
                if (dateFromValue) {
                    dateMatch = dateMatch && itemDate >= dateFromValue;
                }
                
                if (dateToValue) {
                    dateMatch = dateMatch && itemDate <= dateToValue;
                }
                
                if (statusMatch && dateMatch) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        statusFilter.addEventListener('change', applyFilters);
        dateFrom.addEventListener('change', applyFilters);
        dateTo.addEventListener('change', applyFilters);
    });
    </script>
</body>
</html>