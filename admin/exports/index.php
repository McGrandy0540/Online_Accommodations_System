<?php
// export.php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is admin
if ($_SESSION['status'] !== 'admin') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once __DIR__ . '../../../config/database.php';

// Get the PDO instance from the Database class
$database = new Database();
$pdo = $database->connect();

// Define available export types with detailed queries
$export_types = [
    'students' => [
        'name' => 'Students',
        'query' => "SELECT 
                    u.id, u.username, u.email, u.phone_number, u.student_id, 
                    u.sex, u.location, u.created_at, 
                    CASE WHEN u.phone_verified = 1 THEN 'Yes' ELSE 'No' END as phone_verified,
                    CASE WHEN u.email_verified = 1 THEN 'Yes' ELSE 'No' END as email_verified,
                    COUNT(b.id) as booking_count,
                    CASE WHEN sv.status IS NOT NULL THEN sv.status ELSE 'Not Verified' END as verification_status
                    FROM users u
                    LEFT JOIN bookings b ON u.id = b.user_id
                    LEFT JOIN student_verifications sv ON u.id = sv.student_id
                    WHERE u.status = 'student' AND u.deleted = 0
                    GROUP BY u.id
                    ORDER BY u.created_at DESC"
    ],
    'property_owners' => [
        'name' => 'Property Owners',
        'query' => "SELECT 
                    u.id, u.username, u.email, u.phone_number, u.created_at,
                    COUNT(h.id) as hostel_count,
                    COUNT(r.id) as room_count,
                    SUM(CASE WHEN b.status = 'active' THEN 1 ELSE 0 END) as active_bookings
                    FROM users u
                    LEFT JOIN hostels h ON u.id = h.owner_id
                    LEFT JOIN rooms r ON h.id = r.hostel_id
                    LEFT JOIN bookings b ON r.id = b.room_id
                    WHERE u.status = 'property_owner' AND u.deleted = 0
                    GROUP BY u.id
                    ORDER BY u.created_at DESC"
    ],
    'bookings' => [
        'name' => 'Bookings',
        'query' => "SELECT 
                    b.id, b.booking_code, u.username as student_name, 
                    h.name as hostel_name, r.room_number, r.room_type, r.price,
                    b.check_in_date, b.check_out_date, b.status,
                    b.total_amount, b.payment_status, b.created_at,
                    po.username as property_owner
                    FROM bookings b
                    JOIN users u ON b.user_id = u.id
                    JOIN rooms r ON b.room_id = r.id
                    JOIN hostels h ON r.hostel_id = h.id
                    JOIN users po ON h.owner_id = po.id
                    ORDER BY b.created_at DESC"
    ],
    'verifications' => [
        'name' => 'Document Verifications',
        'query' => "SELECT 
                    sv.id, u.username as student_name, u.email as student_email,
                    admin.username as verified_by, sv.status, sv.notes,
                    sv.verified_at, u.student_id
                    FROM student_verifications sv
                    JOIN users u ON sv.student_id = u.id
                    JOIN users admin ON sv.verified_by = admin.id
                    ORDER BY sv.verified_at DESC"
    ],
    'documents' => [
        'name' => 'Student Documents',
        'query' => "SELECT 
                    sd.id, u.username as student_name, u.email, u.phone_number,
                    u.student_id, sd.ghana_card_path, sd.passport_path,
                    sd.uploaded_at, u.location
                    FROM student_documents sd
                    JOIN users u ON sd.student_id = u.id
                    ORDER BY sd.uploaded_at DESC"
    ],
    'hostels' => [
        'name' => 'Hostels',
        'query' => "SELECT 
                    h.id, h.name, h.location, h.description, h.amenities,
                    h.contact_phone, h.contact_email, h.status,
                    u.username as owner_name, u.email as owner_email,
                    COUNT(r.id) as total_rooms,
                    SUM(CASE WHEN r.status = 'available' THEN 1 ELSE 0 END) as available_rooms,
                    h.created_at
                    FROM hostels h
                    JOIN users u ON h.owner_id = u.id
                    LEFT JOIN rooms r ON h.id = r.hostel_id
                    GROUP BY h.id
                    ORDER BY h.created_at DESC"
    ],
    'rooms' => [
        'name' => 'Rooms',
        'query' => "SELECT 
                    r.id, r.room_number, r.room_type, r.price, r.capacity,
                    r.amenities, r.status, r.description,
                    h.name as hostel_name, h.location as hostel_location,
                    u.username as owner_name,
                    COUNT(b.id) as total_bookings,
                    SUM(CASE WHEN b.status = 'active' THEN 1 ELSE 0 END) as active_bookings,
                    r.created_at
                    FROM rooms r
                    JOIN hostels h ON r.hostel_id = h.id
                    JOIN users u ON h.owner_id = u.id
                    LEFT JOIN bookings b ON r.id = b.room_id
                    GROUP BY r.id
                    ORDER BY r.created_at DESC"
    ],
    'payments' => [
        'name' => 'Payments',
        'query' => "SELECT 
                    p.id, p.booking_id, b.booking_code,
                    u.username as student_name, p.amount, p.payment_method,
                    p.transaction_id, p.status, p.created_at,
                    h.name as hostel_name, r.room_number
                    FROM payments p
                    JOIN bookings b ON p.booking_id = b.id
                    JOIN users u ON b.user_id = u.id
                    JOIN rooms r ON b.room_id = r.id
                    JOIN hostels h ON r.hostel_id = h.id
                    ORDER BY p.created_at DESC"
    ],
    'reviews' => [
        'name' => 'Reviews & Ratings',
        'query' => "SELECT 
                    r.id, u.username as student_name, h.name as hostel_name,
                    r.rating, r.comment, r.created_at, r.status,
                    po.username as property_owner
                    FROM reviews r
                    JOIN users u ON r.user_id = u.id
                    JOIN hostels h ON r.hostel_id = h.id
                    JOIN users po ON h.owner_id = po.id
                    ORDER BY r.created_at DESC"
    ]
];

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_type'], $_POST['format'])) {
    $export_type = $_POST['export_type'];
    $format = $_POST['format'];
    
    if (array_key_exists($export_type, $export_types)) {
        try {
            $stmt = $pdo->prepare($export_types[$export_type]['query']);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($data)) {
                $_SESSION['export_error'] = "No data available for export.";
                header("Location: export.php");
                exit();
            }
            
            // Generate filename with timestamp
            $filename = $export_types[$export_type]['name'] . '_Export_' . date('Y-m-d_H-i-s');
            
            // Export based on format
            switch ($format) {
                case 'csv':
                    exportCSV($data, $filename);
                    break;
                case 'excel':
                    exportExcel($data, $filename, $export_types[$export_type]['name']);
                    break;
                case 'json':
                    exportJSON($data, $filename);
                    break;
                case 'pdf':
                    exportPDFWithoutTCPDF($data, $filename, $export_types[$export_type]['name']);
                    break;
                default:
                    $_SESSION['export_error'] = "Invalid export format.";
                    header("Location: export.php");
                    exit();
            }
            
        } catch (Exception $e) {
            $_SESSION['export_error'] = "Error generating export: " . $e->getMessage();
            header("Location: export.php");
            exit();
        }
    } else {
        $_SESSION['export_error'] = "Invalid export type.";
        header("Location: export.php");
        exit();
    }
}

// CSV Export Function
function exportCSV($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Add headers
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    
    // Add data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Excel Export Function
function exportExcel($data, $filename, $title) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; }';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th { background-color: #3498db; color: white; font-weight: bold; padding: 8px; text-align: left; border: 1px solid #ddd; }';
    echo 'td { padding: 8px; text-align: left; border: 1px solid #ddd; }';
    echo 'tr:nth-child(even) { background-color: #f2f2f2; }';
    echo '.title { font-size: 18px; font-weight: bold; margin-bottom: 15px; color: #2c3e50; }';
    echo '.date { margin-bottom: 15px; color: #7f8c8d; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo '<div class="title">' . htmlspecialchars($title) . ' Report</div>';
    echo '<div class="date">Generated on: ' . date('F j, Y, g:i a') . '</div>';
    
    echo '<table>';
    
    // Add headers
    if (!empty($data)) {
        echo '<tr>';
        foreach (array_keys($data[0]) as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
    }
    
    // Add data
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars($cell) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit();
}

// JSON Export Function
function exportJSON($data, $filename) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit();
}

// PDF Export Function without TCPDF
function exportPDFWithoutTCPDF($data, $filename, $title) {
    // Create HTML content for PDF
    $html = '<html>';
    $html .= '<head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<title>' . htmlspecialchars($title) . ' Report</title>';
    $html .= '<style>';
    $html .= 'body { font-family: Arial, sans-serif; margin: 20px; }';
    $html .= 'h1 { color: #2c3e50; text-align: center; margin-bottom: 5px; }';
    $html .= '.subtitle { text-align: center; color: #7f8c8d; margin-bottom: 20px; }';
    $html .= 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    $html .= 'th { background-color: #3498db; color: white; font-weight: bold; padding: 10px; text-align: left; border: 1px solid #ddd; }';
    $html .= 'td { padding: 8px; text-align: left; border: 1px solid #ddd; font-size: 12px; }';
    $html .= 'tr:nth-child(even) { background-color: #f2f2f2; }';
    $html .= '.footer { margin-top: 30px; text-align: center; color: #7f8c8d; font-size: 12px; }';
    $html .= '</style>';
    $html .= '</head>';
    $html .= '<body>';
    
    $html .= '<h1>' . htmlspecialchars($title) . ' Report</h1>';
    $html .= '<div class="subtitle">Generated on: ' . date('F j, Y, g:i a') . '</div>';
    
    if (!empty($data)) {
        $html .= '<table>';
        
        // Add headers
        $html .= '<tr>';
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr>';
        
        // Add data
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</table>';
    } else {
        $html .= '<p>No data available for export.</p>';
    }
    
    $html .= '<div class="footer">Report generated by Accommodation System Admin</div>';
    $html .= '</body>';
    $html .= '</html>';
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    
    // Use external service to convert HTML to PDF
    // Note: This is a simple approach. For production, consider using a proper PDF library
    echo "<script>
        window.onload = function() {
            window.print();
            setTimeout(function() {
                window.close();
            }, 500);
        }
    </script>";
    echo $html;
    exit();
}

// Get comprehensive statistics for dashboard
function getStatistics($pdo) {
    $stats = [];
    
    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'student' AND deleted = 0");
    $stats['total_students'] = $stmt->fetch()['count'];
    
    // Verified students
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'student' AND deleted = 0 AND phone_verified = 1 AND email_verified = 1");
    $stats['verified_students'] = $stmt->fetch()['count'];
    
    // Property owners
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'property_owner' AND deleted = 0");
    $stats['property_owners'] = $stmt->fetch()['count'];
    
    // Total bookings
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings");
    $stats['total_bookings'] = $stmt->fetch()['count'];
    
    // Active bookings
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'active'");
    $stats['active_bookings'] = $stmt->fetch()['count'];
    
    
    // Total revenue
    $stmt = $pdo->query("SELECT SUM(amount) as total FROM payments WHERE status = 'completed'");
    $stats['total_revenue'] = $stmt->fetch()['total'] ?: 0;
    
    return $stats;
}

$stats = getStatistics($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Data Export Center</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --secondary-color: #2c3e50;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --danger-color: #dc3545;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7f9;
            color: var(--secondary-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(30deg);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .back-button {
            color: white;
            text-decoration: none;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .page-title {
            font-size: 32px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-card.students::before { background: var(--info-color); }
        .stat-card.owners::before { background: var(--warning-color); }
        .stat-card.bookings::before { background: var(--success-color); }
        .stat-card.revenue::before { background: var(--primary-color); }

        .stat-icon {
            font-size: 24px;
            margin-bottom: 15px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .stat-card.students .stat-icon { background: var(--info-color); }
        .stat-card.owners .stat-icon { background: var(--warning-color); }
        .stat-card.bookings .stat-icon { background: var(--success-color); }
        .stat-card.revenue .stat-icon { background: var(--primary-color); }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            margin: 5px 0;
            color: var(--secondary-color);
        }

        .stat-label {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }

        .export-form {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
        }

        .form-title {
            font-size: 24px;
            color: var(--secondary-color);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .info-box {
            background: #e8f4fc;
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .page-title {
                text-align: left;
                font-size: 24px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="../dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Data Export Center</h1>
            </div>
        </div>

        <?php if (isset($_SESSION['export_error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <?php 
                echo htmlspecialchars($_SESSION['export_error']); 
                unset($_SESSION['export_error']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['export_success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <?php 
                echo htmlspecialchars($_SESSION['export_success']); 
                unset($_SESSION['export_success']);
                ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <i class="fas fa-info-circle"></i> Export system data in various formats for reporting and analysis.
        </div>

        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_students']); ?></div>
                <div class="stat-label">Total Students</div>
            </div>

            <div class="stat-card owners">
                <div class="stat-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['property_owners']); ?></div>
                <div class="stat-label">Property Owners</div>
            </div>

            <div class="stat-card bookings">
                <div class="stat-icon">
                    <i class="fas fa-bed"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_bookings']); ?></div>
                <div class="stat-label">Total Bookings</div>
            </div>

            <div class="stat-card revenue">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-value">GHS <?php echo number_format($stats['total_revenue'], 2); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>

        <div class="export-form">
            <h2 class="form-title">
                <i class="fas fa-download"></i>
                Export Data
            </h2>
            
            <form method="POST" action="export.php">
                <div class="form-group">
                    <label class="form-label" for="export_type">Data Type:</label>
                    <select class="form-control" id="export_type" name="export_type" required>
                        <option value="">-- Select Data Type --</option>
                        <option value="students">Students</option>
                        <option value="property_owners">Property Owners</option>
                        <option value="bookings">Bookings</option>
                        <option value="verifications">Document Verifications</option>
                        <option value="documents">Student Documents</option>
                        <option value="payments">Payments</option>
                        <option value="reviews">Reviews & Ratings</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="format">Export Format:</label>
                    <select class="form-control" id="format" name="format" required>
                        <option value="">-- Select Format --</option>
                        <option value="csv">CSV (Comma Separated Values)</option>
                        <option value="excel">Excel Spreadsheet</option>
                        <option value="json">JSON (JavaScript Object Notation)</option>
                        <option value="pdf">PDF Document</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-download"></i> Generate Export
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Simple form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const exportType = document.getElementById('export_type').value;
            const format = document.getElementById('format').value;
            
            if (!exportType || !format) {
                e.preventDefault();
                alert('Please select both data type and format.');
            }
        });
    </script>
</body>
</html>