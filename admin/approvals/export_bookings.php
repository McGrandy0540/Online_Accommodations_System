<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Base query for bookings
$query = "
    SELECT 
        b.id AS booking_id,
        b.booking_date,
        b.start_date,
        b.end_date,
        b.duration_months,
        b.status AS booking_status,
        p.property_name, 
        p.location AS property_location,
        pr.room_number,
        pr.gender AS room_gender,
        u_student.username AS student_name, 
        u_student.email AS student_email,
        u_student.phone_number AS student_phone,
        u_owner.username AS owner_name,
        u_owner.email AS owner_email,
        u_owner.phone_number AS owner_phone,
        py.amount AS payment_amount,
        py.status AS payment_status
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u_student ON b.user_id = u_student.id
    JOIN users u_owner ON p.owner_id = u_owner.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    LEFT JOIN payments py ON b.id = py.booking_id
";

// Add status filter if not 'all'
if ($status_filter !== 'all') {
    $query .= " WHERE b.status = :status";
}

$query .= " ORDER BY b.booking_date DESC";

$stmt = $pdo->prepare($query);

if ($status_filter !== 'all') {
    $stmt->bindValue(':status', $status_filter);
}

$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=bookings_export_' . date('Ymd_His') . '.csv');

// Create output file
$output = fopen('php://output', 'w');

// Header row
fputcsv($output, [
    'Booking ID', 
    'Booking Date',
    'Start Date',
    'End Date',
    'Duration (months)',
    'Status',
    'Property Name',
    'Property Location',
    'Room Number',
    'Room Gender',
    'Student Name',
    'Student Email',
    'Student Phone',
    'Owner Name',
    'Owner Email',
    'Owner Phone',
    'Payment Amount',
    'Payment Status'
]);

// Data rows
foreach ($bookings as $booking) {
    fputcsv($output, [
        $booking['booking_id'],
        $booking['booking_date'],
        $booking['start_date'],
        $booking['end_date'],
        $booking['duration_months'],
        $booking['booking_status'],
        $booking['property_name'],
        $booking['property_location'],
        $booking['room_number'],
        $booking['room_gender'],
        $booking['student_name'],
        $booking['student_email'],
        $booking['student_phone'],
        $booking['owner_name'],
        $booking['owner_email'],
        $booking['owner_phone'],
        $booking['payment_amount'],
        $booking['payment_status']
    ]);
}

fclose($output);
exit;