<?php
ob_start();
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require __DIR__ . '../../../config/database.php';
require_once __DIR__ . '../../../config/constants.php';

$reference = $_GET['reference'] ?? null;

if (!$reference) {
    $_SESSION['error'] = "Missing transaction reference";
    header("Location: ../dashboard.php");
    exit();
}

try {
    if ($_SESSION['status'] !== 'student') {
        throw new Exception("Access denied", 403);
    }

    $student_id = $_SESSION['user_id'];
    $pdo = Database::getInstance();

    // Fixed: Added pr.price to the SELECT statement
    $stmt = $pdo->prepare("
        SELECT p.*, b.*, pr.property_name, pr.location, pr.price,
               u.username AS owner_name, u.email AS owner_email, u.phone_number AS owner_phone
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        JOIN property pr ON b.property_id = pr.id
        JOIN users u ON pr.owner_id = u.id
        WHERE p.transaction_id LIKE ? AND b.user_id = ?
    ");
    $stmt->execute([$reference . '%', $student_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$payments) {
        throw new Exception("Transaction not found", 404);
    }

    $booking = $payments[0];
    
    $start = new DateTime($booking['start_date']);
    $end = new DateTime($booking['end_date']);
    $interval = $start->diff($end);
    $months = $interval->y * 12 + $interval->m;

    $property_id = $booking['property_id'];
    $stmt = $pdo->prepare("SELECT image_url FROM property_images WHERE property_id = ?");
    $stmt->execute([$property_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header("Location: ../dashboard.php");
    exit();
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation - Landlords&Tenants</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --danger-color: #dc3545;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 80px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        body {
            background-color: var(--light-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--dark-color);
            line-height: 1.6;
        }
        
        .confirmation-icon {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: var(--box-shadow);
        }
        
        .header-gradient {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }
        
        .property-image {
            height: 300px;
            object-fit: cover;
            width: 100%;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }
        
        .detail-card {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--primary-color);
            transition: all var(--transition-speed) ease;
        }
        
        .receipt-card {
            background: linear-gradient(to right, #dbeafe, #eff6ff);
            border-left: 4px solid var(--primary-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
        }
        
        .steps-card {
            background: linear-gradient(to right, #dcfce7, #f0fdf4);
            border-left: 4px solid var(--success-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
        }
        
        .info-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .btn-primary {
            background: linear-gradient(to right, var(--primary-color), var(--primary-hover));
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--card-shadow);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(59, 130, 246, 0.25);
        }
        
        .btn-success {
            background: linear-gradient(to right, var(--success-color), #1e7e34);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--card-shadow);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.25);
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .badge-room {
            background-color: #dbeafe;
            color: var(--primary-color);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        .amount-display {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--success-color);
        }
        
        .step-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        
        .step-item i {
            color: var(--success-color);
            margin-top: 0.25rem;
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        /* Carousel custom styles */
        .carousel-control-prev, 
        .carousel-control-next {
            width: 40px;
            height: 40px;
            background: rgba(0,0,0,0.2);
            border-radius: 50%;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.7;
        }
        
        .carousel-control-prev:hover, 
        .carousel-control-next:hover {
            opacity: 0.9;
            background: rgba(0,0,0,0.3);
        }
        
        .carousel-indicators {
            bottom: -30px;
        }
        
        .carousel-indicators button {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #ccc;
            border: none;
        }
        
        .carousel-indicators .active {
            background-color: var(--primary-color);
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header-gradient {
                padding: 1.5rem 1rem;
            }
            
            .confirmation-icon {
                width: 70px;
                height: 70px;
            }
            
            .property-image {
                height: 200px;
            }
            
            .detail-card, 
            .receipt-card, 
            .steps-card {
                padding: 1rem;
            }
            
            .section-title {
                font-size: 1.1rem;
            }
            
            .amount-display {
                font-size: 1.5rem;
            }
            
            .btn-primary,
            .btn-success {
                padding: 0.75rem;
                font-size: 0.9rem;
            }
            
            .step-item {
                font-size: 0.9rem;
            }
            
            .info-item {
                padding: 0.5rem 0;
            }
        }
        
        @media (max-width: 480px) {
            .grid-cols-2 {
                grid-template-columns: 1fr;
            }
            
            .header-gradient h1 {
                font-size: 1.5rem;
            }
            
            .property-image {
                height: 180px;
            }
            
            .btn-container {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .step-item i {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <!-- Header Section -->
            <div class="header-gradient p-6 md:p-8 text-center">
                <div class="confirmation-icon w-16 h-16 md:w-20 md:h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check-circle text-white text-3xl md:text-4xl"></i>
                </div>
                <h1 class="text-2xl md:text-3xl font-bold text-white mb-2">Payment Successful!</h1>
                <p class="text-blue-100 text-base md:text-lg">Your accommodation booking has been confirmed</p>
            </div>

            <!-- Main Content -->
            <div class="p-4 md:p-6">
                <!-- Property Details -->
                <div class="detail-card">
                    <h2 class="section-title">
                        <i class="fas fa-home"></i>Accommodation Details
                    </h2>
                    
                    <!-- Image Carousel -->
                    <div class="mb-4 md:mb-6 rounded-lg overflow-hidden">
                        <div id="propertyCarousel" class="carousel slide" data-bs-ride="carousel">
                            <div class="carousel-inner">
                                <?php if (!empty($images)): ?>
                                    <?php foreach ($images as $index => $image): ?>
                                        <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                            <img src="../../uploads/<?= htmlspecialchars($image['image_url']) ?>" 
                                                 class="d-block w-100 property-image" 
                                                 alt="Property image <?= $index + 1 ?>">
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="carousel-item active">
                                        <img src="../../assets/images/default-property.jpg" 
                                             class="d-block w-100 property-image" 
                                             alt="Default property image">
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (count($images) > 1): ?>
                                <button class="carousel-control-prev" type="button" 
                                        data-bs-target="#propertyCarousel" 
                                        data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Previous</span>
                                </button>
                                <button class="carousel-control-next" type="button" 
                                        data-bs-target="#propertyCarousel" 
                                        data-bs-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Next</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                        <div class="info-item">
                            <p class="text-sm text-gray-500">Property Name</p>
                            <p class="font-medium"><?= htmlspecialchars($booking['property_name']) ?></p>
                        </div>
                        <div class="info-item">
                            <p class="text-sm text-gray-500">Location</p>
                            <p class="font-medium"><?= htmlspecialchars($booking['location']) ?></p>
                        </div>
                        <?php if ($booking['room_id']): ?>
                        <div class="info-item">
                            <p class="text-sm text-gray-500">Room Number</p>
                            <p class="font-medium">
                                <span class="badge badge-room">
                                  Room  <?= htmlspecialchars($booking['room_id'] ) ?>
                                </span>
                            </p>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <p class="text-sm text-gray-500">Property Owner</p>
                            <p class="font-medium"><?= htmlspecialchars($booking['owner_name']) ?></p>
                        </div>
                        <div class="info-item">
                            <p class="text-sm text-gray-500">Booking Period</p>
                            <p class="font-medium">
                                <?= date('M j, Y', strtotime($booking['start_date'])) ?> - 
                                <?= date('M j, Y', strtotime($booking['end_date'])) ?>
                                <span class="text-green-600">(<?= $months ?> months)</span>
                            </p>
                        </div>
                        <div class="info-item">
                            <p class="text-sm text-gray-500">Monthly Rate</p>
                            <p class="font-medium">GHS <?= number_format($booking['price'], 2) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Payment Receipt -->
                <div class="receipt-card">
                    <h2 class="section-title">
                        <i class="fas fa-receipt"></i>Payment Receipt
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                        <div>
                            <div class="info-item">
                                <p class="text-sm text-gray-500">Transaction ID</p>
                                <p class="font-mono font-medium text-sm md:text-base"><?= htmlspecialchars($booking['transaction_id']) ?></p>
                            </div>
                            <div class="info-item">
                                <p class="text-sm text-gray-500">Payment Method</p>
                                <p class="font-medium">
                                    <?= ucfirst(str_replace('_', ' ', $booking['payment_method'])) ?>
                                </p>
                            </div>
                        </div>
                        <div>
                            <div class="info-item">
                                <p class="text-sm text-gray-500">Amount Paid</p>
                                <p class="amount-display">
                                    GHS <?= number_format($booking['amount'], 2) ?>
                                </p>
                            </div>
                            <div class="info-item">
                                <p class="text-sm text-gray-500">Payment Date</p>
                                <p class="font-medium">
                                    <?= date('F j, Y \a\t g:i A', strtotime($booking['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Owner Contact & Next Steps -->
                <div class="steps-card">
                    <h3 class="section-title">
                        <i class="fas fa-info-circle"></i>Next Steps
                    </h3>
                    <div class="space-y-3 text-green-700">
                        <div class="step-item">
                            <i class="fas fa-envelope"></i>
                            <span>Your property owner will contact you at <strong><?= htmlspecialchars($booking['owner_email']) ?></strong> 
                            or <strong><?= htmlspecialchars($booking['owner_phone']) ?></strong> to arrange key handover.</span>
                        </div>
                        <div class="step-item">
                            <i class="fas fa-check-circle"></i>
                            <span>Please check your email for the booking confirmation receipt.</span>
                        </div>
                        <div class="step-item">
                            <i class="fas fa-calendar-check"></i>
                            <span>Your booking starts on <strong><?= date('F j, Y', strtotime($booking['start_date'])) ?></strong>.</span>
                        </div>
                        <?php if ($booking['special_requests']): ?>
                        <div class="step-item">
                            <i class="fas fa-comment"></i>
                            <span>Your special requests: <em>"<?= htmlspecialchars($booking['special_requests']) ?>"</em></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row justify-center gap-4 mt-6 md:mt-8 btn-container">
                    <a href="../search/index.php" 
                       class="btn-success">
                        <i class="fas fa-search mr-2"></i> Find More Accommodations
                    </a>
                    <a href="../bookings/index.php" 
                       class="btn-primary">
                        <i class="fas fa-calendar-check mr-2"></i> View My Bookings
                    </a>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-6 md:mt-8 text-gray-500 text-sm">
            <p>Need help? Contact support at support@landlordsandtenants.com</p>
            <p class="mt-1 md:mt-2">&copy; <?= date('Y') ?> Landlords&Tenants. All rights reserved.</p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize carousel
        document.addEventListener('DOMContentLoaded', function() {
            const carousel = new bootstrap.Carousel(document.getElementById('propertyCarousel'), {
                interval: 5000,
                wrap: true
            });
        });
    </script>
</body>
</html>