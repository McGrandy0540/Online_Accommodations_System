<?php
ob_start();
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);
require '../config/database.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    // Validate session and user role
    if (!isset($_SESSION['user_id'], $_SESSION['status'])) {
        throw new Exception("Access denied - authorization required");
    }
    
    // Validate transaction reference
    $transaction_id = $_GET['reference'] ?? null;
    if (!$transaction_id) {
        throw new Exception("Missing transaction reference");
    }

    $user_id = $_SESSION['user_id'];
    $pdo = Database::getInstance();

    // Verify transaction with Paystack
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/".rawurlencode($transaction_id),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer ".PAYSTACK_SECRET_KEY,
            "Cache-Control: no-cache"
        ],
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        throw new Exception("CURL Error: ".$error);
    }

    if ($http_status !== 200) {
        throw new Exception("Payment verification failed with status: $http_status");
    }

    $result = json_decode($response);
    if (!$result->status || $result->data->status !== 'success') {
        throw new Exception("Payment verification failed: ".($result->message ?? 'Unknown error'));
    }

    // Fetch payment details based on user role
    if ($_SESSION['status'] === 'property_owner') {
        // Get room levy payment details
        $stmt = $pdo->prepare("SELECT 
            rlp.*,
            u.username AS owner_name,
            COUNT(pr.id) AS rooms_paid
        FROM room_levy_payments rlp
        JOIN users u ON rlp.owner_id = u.id
        LEFT JOIN property_rooms pr ON pr.levy_payment_id = rlp.id
        WHERE rlp.transaction_id = ? AND rlp.owner_id = ?
        GROUP BY rlp.id");
        
        $stmt->execute([$transaction_id, $user_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception("Room levy payment details not found");
        }

        // Get rooms associated with this payment
        $rooms_stmt = $pdo->prepare("SELECT 
            pr.id, pr.room_number, pr.capacity, pr.gender,
            p.property_name, p.location,
            pr.levy_payment_status,
            pr.levy_expiry_date,
            DATEDIFF(pr.levy_expiry_date, CURDATE()) AS days_remaining
        FROM property_rooms pr
        JOIN property p ON pr.property_id = p.id
        WHERE pr.levy_payment_id = ?");
        $rooms_stmt->execute([$payment['id']]);
        $rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if payment needs admin approval
        $needs_approval = $payment['status'] === 'completed' && 
                         ($rooms[0]['levy_payment_status'] ?? '') === 'paid';
    } 
    else if ($_SESSION['status'] === 'student') {
        // Get booking payment details
        $stmt = $pdo->prepare("SELECT 
            p.*, 
            u.username AS student_name,
            pr.property_name,
            pr.location,
            pr.price,
            pr.bedrooms,
            pr.bathrooms,
            b.start_date,
            b.end_date,
            b.duration_months,
            b.status AS booking_status,
            rm.room_number,
            rm.capacity,
            rm.gender
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        JOIN property pr ON b.property_id = pr.id
        JOIN users u ON b.user_id = u.id
        LEFT JOIN property_rooms rm ON b.room_id = rm.id
        WHERE p.transaction_id = ? AND b.user_id = ?");
        
        $stmt->execute([$transaction_id, $user_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception("Booking payment details not found");
        }
    } else {
        throw new Exception("Invalid user role");
    }

    // Clear any payment session data
    unset($_SESSION['room_levy_payment']);

} catch (Exception $e) {
    error_log("Payment Error: " . $e->getMessage());
    $_SESSION['error'] = $e->getMessage();
    header("Location: property_dashboard.php");
    exit();
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation - Landlords&Tenant</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .confirmation-icon {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }
        .receipt-card {
            border-left: 4px solid #4299e1;
        }
        .room-card {
            transition: all 0.3s ease;
        }
        .room-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .gender-male {
            background-color: #ebf8ff;
            border-left: 4px solid #3182ce;
        }
        .gender-female {
            background-color: #fff5f7;
            border-left: 4px solid #e53e3e;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-4 py-12 max-w-4xl">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-8 rounded">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <!-- Header Section -->
            <div class="bg-gradient-to-r from-green-500 to-green-600 p-8 text-center">
                <div class="confirmation-icon w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4 shadow-md">
                    <i class="fas fa-check-circle text-white text-4xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-white mb-2">Payment Successful!</h1>
                <p class="text-green-100"><?= $_SESSION['status'] === 'property_owner' ? 'Your room levy payment was processed' : 'Your booking has been confirmed' ?></p>
            </div>

            <!-- Main Content -->
            <div class="p-8">
                <?php if ($_SESSION['status'] === 'property_owner'): ?>
                    <!-- Property Owner Payment Confirmation -->
                    <div class="mb-10">
                        <h2 class="text-xl font-semibold text-gray-800 mb-6 pb-2 border-b border-gray-200">
                            <i class="fas fa-home mr-2 text-blue-500"></i>Room Levy Payment Details
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div class="flex items-start">
                                <i class="fas fa-receipt text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Transaction ID</p>
                                    <p class="font-mono font-medium"><?= htmlspecialchars($payment['transaction_id']) ?></p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-calendar-day text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Payment Date</p>
                                    <p class="font-medium"><?= date('F j, Y \a\t g:i A', strtotime($payment['payment_date'])) ?></p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-money-bill-wave text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Amount Paid</p>
                                    <p class="text-2xl font-bold text-green-600">GHS <?= number_format($payment['amount'], 2) ?></p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-door-open text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Rooms Paid For</p>
                                    <p class="font-medium"><?= $payment['rooms_paid'] ?></p>
                                </div>
                            </div>
                        </div>

                        <h3 class="font-semibold text-lg mb-4">Rooms Included in This Payment</h3>
                        <div class="grid grid-cols-1 gap-4">
                            <?php foreach ($rooms as $room): ?>
                                <div class="room-card bg-white border border-gray-200 rounded-lg p-4 shadow-sm <?= $room['gender'] === 'female' ? 'gender-female' : 'gender-male' ?>">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h4 class="font-medium text-gray-800"><?= htmlspecialchars($room['property_name']) ?></h4>
                                            <p class="text-sm text-gray-600">
                                                Room <?= htmlspecialchars($room['room_number']) ?> 
                                                (<?= ucfirst($room['gender']) ?> only)
                                            </p>
                                        </div>
                                        <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                            Capacity: <?= $room['capacity'] ?> students
                                        </span>
                                    </div>
                                    <div class="mt-3 text-sm text-gray-500">
                                        <?php if ($needs_approval): ?>
                                            <p>Status: <span class="font-medium text-yellow-600">Pending Admin Approval</span></p>
                                            <p class="mt-1">These rooms will be visible to students after admin approval.</p>
                                        <?php elseif ($room['levy_payment_status'] === 'approved'): ?>
                                            <p>Status: <span class="font-medium text-green-600">Approved</span></p>
                                            <p class="mt-1">
                                                Expires in <?= $room['days_remaining'] ?> days 
                                                (<?= date('M j, Y', strtotime($room['levy_expiry_date'])) ?>)
                                            </p>
                                        <?php else: ?>
                                            <p>Status: <span class="font-medium text-blue-600">Processing</span></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Student Booking Confirmation -->
                    <div class="mb-10">
                        <h2 class="text-xl font-semibold text-gray-800 mb-6 pb-2 border-b border-gray-200">
                            <i class="fas fa-home mr-2 text-blue-500"></i>Booking Details
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex items-start">
                                <i class="fas fa-heading text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Property</p>
                                    <p class="font-medium"><?= htmlspecialchars($payment['property_name']) ?></p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-map-marker-alt text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Location</p>
                                    <p class="font-medium"><?= htmlspecialchars($payment['location']) ?></p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-calendar-day text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Move-In Date</p>
                                    <p class="font-medium"><?= date('F j, Y', strtotime($payment['start_date'])) ?></p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-calendar-day text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Move-Out Date</p>
                                    <p class="font-medium"><?= date('F j, Y', strtotime($payment['end_date'])) ?></p>
                                </div>
                            </div>
                            <?php if (!empty($payment['room_number'])): ?>
                            <div class="flex items-start">
                                <i class="fas fa-door-open text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Room Number</p>
                                    <p class="font-medium">
                                        <?= htmlspecialchars($payment['room_number']) ?>
                                        (<?= ucfirst($payment['gender']) ?> only)
                                    </p>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="flex items-start">
                                <i class="fas fa-clock text-gray-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-gray-500">Duration</p>
                                    <p class="font-medium"><?= $payment['duration_months'] ?> months</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Payment Receipt -->
                <div class="receipt-card bg-blue-50 rounded-lg p-6 mb-10">
                    <h2 class="text-xl font-semibold text-gray-800 mb-6 pb-2 border-b border-blue-200">
                        <i class="fas fa-receipt mr-2 text-blue-500"></i>Payment Receipt
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div class="mb-4">
                                <p class="text-sm text-gray-500">Transaction ID</p>
                                <p class="font-mono font-medium"><?= htmlspecialchars($payment['transaction_id']) ?></p>
                            </div>
                            <div class="mb-4">
                                <p class="text-sm text-gray-500">Amount Paid</p>
                                <p class="text-2xl font-bold text-green-600">GHS <?= number_format($payment['amount'], 2) ?></p>
                            </div>
                        </div>
                        <div>
                            <div class="mb-4">
                                <p class="text-sm text-gray-500">Payment Status</p>
                                <p class="font-medium">
                                    <span class="px-3 py-1 rounded-full text-sm bg-green-100 text-green-800">
                                        Completed
                                    </span>
                                </p>
                            </div>
                            <div class="mb-4">
                                <p class="text-sm text-gray-500">Payment Date</p>
                                <p class="font-medium"><?= date('F j, Y \a\t g:i A', strtotime($payment['payment_date'] ?? $payment['created_at'])) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Next Steps -->
                <div class="bg-green-50 rounded-lg p-6 mb-10">
                    <h3 class="font-semibold text-green-800 mb-4 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>Next Steps
                    </h3>
                    <div class="space-y-3 text-green-700">
                        <?php if ($_SESSION['status'] === 'property_owner'): ?>
                            <?php if ($needs_approval): ?>
                                <p class="flex items-start">
                                    <i class="fas fa-clock mt-1 mr-3"></i>
                                    <span>Your rooms will be reviewed by admin and approved within 24 hours.</span>
                                </p>
                                <p class="flex items-start">
                                    <i class="fas fa-bell mt-1 mr-3"></i>
                                    <span>You'll receive a notification when your rooms are approved.</span>
                                </p>
                            <?php else: ?>
                                <p class="flex items-start">
                                    <i class="fas fa-check-circle mt-1 mr-3"></i>
                                    <span>Your rooms are now active and visible to students.</span>
                                </p>
                                <p class="flex items-start">
                                    <i class="fas fa-calendar-alt mt-1 mr-3"></i>
                                    <span>Levy expires on <?= date('F j, Y', strtotime($rooms[0]['levy_expiry_date'])) ?></span>
                                </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="flex items-start">
                                <i class="fas fa-envelope mt-1 mr-3"></i>
                                <span>The property owner will contact you to confirm your booking details.</span>
                            </p>
                            <p class="flex items-start">
                                <i class="fas fa-key mt-1 mr-3"></i>
                                <span>You'll receive digital keys for your room on your move-in date.</span>
                            </p>
                        <?php endif; ?>
                        <p class="flex items-start">
                            <i class="fas fa-check-circle mt-1 mr-3"></i>
                            <span>Please check your email for the payment confirmation receipt.</span>
                        </p>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row justify-center gap-4">
                    <?php if ($_SESSION['status'] === 'property_owner'): ?>
                        <a href="property_dashboard.php" 
                           class="bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors flex items-center justify-center">
                            <i class="fas fa-home mr-2"></i> View Properties
                        </a>
                    <?php else: ?>
                        <a href="property_listing.php" 
                           class="bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors flex items-center justify-center">
                            <i class="fas fa-search mr-2"></i> Browse More Properties
                        </a>
                    <?php endif; ?>
                    <a href="dashboard.php" 
                       class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors flex items-center justify-center">
                        <i class="fas fa-tachometer-alt mr-2"></i> Go to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>