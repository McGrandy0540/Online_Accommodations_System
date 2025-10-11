<?php
session_start();
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is a student
if ($_SESSION['status'] !== 'student') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get student data
$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: ../auth/login.php');
    exit();
}

// Handle download request FIRST - before any output
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $agreement_id = $_GET['download'];
    
    // Clean any existing output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Verify the student has access to this agreement
    $verify_access = $pdo->prepare("
        SELECT gta.*, taa.booking_id
        FROM generated_tenancy_agreements gta
        JOIN tenant_agreement_access taa ON gta.id = taa.agreement_id
        WHERE taa.tenant_id = ? AND gta.id = ? AND gta.status = 'published'
    ");
    $verify_access->execute([$student_id, $agreement_id]);
    $agreement = $verify_access->fetch();
    
    if ($agreement) {
        // Update downloaded_at helveticastamp
        $update_download = $pdo->prepare("
            UPDATE tenant_agreement_access 
            SET downloaded_at = NOW(), status = 'downloaded'
            WHERE tenant_id = ? AND agreement_id = ?
        ");
        $update_download->execute([$student_id, $agreement_id]);
        
        // Get agreement details
        $agreement_details = $pdo->prepare("
            SELECT gta.*, p.*, u.username as owner_name, u.email as owner_email, 
                   u.phone_number as owner_phone, u.location as owner_location
            FROM generated_tenancy_agreements gta
            JOIN property p ON gta.property_id = p.id
            JOIN users u ON gta.owner_id = u.id
            WHERE gta.id = ?
        ");
        $agreement_details->execute([$agreement_id]);
        $agreement_data = $agreement_details->fetch();
        
        // Check if there's an uploaded file
        if (!empty($agreement_data['file_path']) && file_exists($agreement_data['file_path'])) {
            // If there's an uploaded file, serve it as PDF
            $uploaded_file_path = $agreement_data['file_path'];
            $file_extension = strtolower(pathinfo($uploaded_file_path, PATHINFO_EXTENSION));
            
            // Generate PDF using TCPDF for uploaded files
            require_once('../vendor/tecnickcom/tcpdf/tcpdf.php');
            
            // Create new PDF document
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('Landlords&Tenant Platform');
            $pdf->SetAuthor($agreement_data['owner_name']);
            $pdf->SetTitle('Tenancy Agreement - ' . $agreement_data['agreement_title']);
            $pdf->SetSubject('Tenancy Agreement');
            $pdf->SetKeywords('Tenancy, Agreement, Rental, Property');
            
            // Remove header and footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set default monospaced font
            $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
            
            // Set margins
            $pdf->SetMargins(15, 15, 15);
            
            // Set auto page breaks
            $pdf->SetAutoPageBreak(TRUE, 15);
            
            // Set image scale factor
            $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
            
            // Add a page
            $pdf->AddPage();
            
            // Set font to Times New Roman
            $pdf->SetFont('helvetica', 'B', 16);
            
            // Title
            $pdf->Cell(0, 10, 'TENANCY AGREEMENT', 0, 1, 'C');
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetTextColor(0, 102, 204);
            $pdf->Cell(0, 10, $agreement_data['agreement_title'], 0, 1, 'C');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 10, 'Date: ' . date('F j, Y', strtoupper($agreement_data['created_at'])), 0, 1, 'C');
            $pdf->Ln(10);
            
            // Add information about the uploaded file
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'UPLOADED AGREEMENT DOCUMENT', 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->MultiCell(0, 10, 'The property owner has uploaded the original agreement document. Below is a preview of the document content.', 0, 'L');
            $pdf->Ln(10);
            
            // Handle different file types
            if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                // For image files
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 10, 'Uploaded Image Document:', 0, 1);
                $pdf->Ln(5);
                
                // Get image dimensions
                list($width, $height) = getimagesize($uploaded_file_path);
                $max_width = 180; // Max width in mm
                $max_height = 250; // Max height in mm
                
                // Calculate aspect ratio
                $ratio = $width / $height;
                if ($width > $max_width || $height > $max_height) {
                    if ($ratio > 1) {
                        $width = $max_width;
                        $height = $max_width / $ratio;
                    } else {
                        $height = $max_height;
                        $width = $max_height * $ratio;
                    }
                }
                
                // Center the image
                $x = (210 - $width) / 2; // A4 width is 210mm
                $pdf->Image($uploaded_file_path, $x, $pdf->GetY(), $width, $height);
                $pdf->Ln($height + 10);
                
            } elseif (in_array($file_extension, ['pdf'])) {
                // For PDF files - just inform user
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 10, 'Original PDF Document:', 0, 1);
                $pdf->SetFont('helvetica', '', 11);
                $pdf->MultiCell(0, 10, 'The property owner uploaded a PDF document. This converted version contains the same content in a standardized format.', 0, 'L');
                $pdf->Ln(10);
                
            } elseif (in_array($file_extension, ['doc', 'docx'])) {
                // For Word documents - inform user
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 10, 'Original Word Document:', 0, 1);
                $pdf->SetFont('helvetica', '', 11);
                $pdf->MultiCell(0, 10, 'The property owner uploaded a Word document. This PDF version contains the same content for universal compatibility.', 0, 'L');
                $pdf->Ln(10);
                
            } else {
                // For other file types
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 10, 'Uploaded Document:', 0, 1);
                $pdf->SetFont('helvetica', '', 11);
                $pdf->MultiCell(0, 10, 'The property owner uploaded a document file. Please refer to the original file for complete formatting.', 0, 'L');
                $pdf->Ln(10);
            }
            
            // Add file information
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 10, 'File Information:', 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(0, 10, 'Original File: ' . basename($uploaded_file_path), 0, 1);
            $pdf->Cell(0, 10, 'File Type: ' . strtoupper($file_extension), 0, 1);
            $pdf->Cell(0, 10, 'Upload Date: ' . date('F j, Y g:i A', strtoupper($agreement_data['created_at'])), 0, 1);
            $pdf->Ln(10);
            
            // Add note
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->SetTextColor(102, 102, 102);
            $pdf->MultiCell(0, 10, 'Note: This is a converted PDF version of the original document uploaded by the property owner. For the exact original formatting, please request the original file from the property owner.', 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            
        } else {
            // Generate PDF from database content (for agreements without uploaded files)
            require_once('../vendor/tecnickcom/tcpdf/tcpdf.php');
            
            // Create new PDF document
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('Landlords&Tenant Platform');
            $pdf->SetAuthor($agreement_data['owner_name']);
            $pdf->SetTitle('Tenancy Agreement - ' . $agreement_data['agreement_title']);
            $pdf->SetSubject('Tenancy Agreement');
            $pdf->SetKeywords('Tenancy, Agreement, Rental, Property');
            
            // Remove header and footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set default monospaced font
            $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
            
            // Set margins
            $pdf->SetMargins(15, 15, 15);
            
            // Set auto page breaks
            $pdf->SetAutoPageBreak(TRUE, 15);
            
            // Set image scale factor
            $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
            
            // Add a page
            $pdf->AddPage();
            
            // Set font to Times New Roman
            $pdf->SetFont('helvetica', 'B', 16);
            
            // Title
            $pdf->Cell(0, 10, 'TENANCY AGREEMENT', 0, 1, 'C');
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetTextColor(0, 102, 204);
            $pdf->Cell(0, 10, $agreement_data['agreement_title'], 0, 1, 'C');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 10, 'Date: ' . date('F j, Y', strtotime($agreement_data['created_at'])), 0, 1, 'C');
            $pdf->Ln(10);
            
            // Get selected clauses
            $selected_clauses = json_decode($agreement_data['selected_clauses'], true) ?? [];
            $clauses = [];
            if (!empty($selected_clauses)) {
                $placeholders = str_repeat('?,', count($selected_clauses) - 1) . '?';
                $clause_stmt = $pdo->prepare("SELECT * FROM agreement_clauses WHERE id IN ($placeholders) ORDER BY clause_category, display_order");
                $clause_stmt->execute($selected_clauses);
                $clauses = $clause_stmt->fetchAll();
            }
            
            // PARTIES SECTION
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'PARTIES', 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->MultiCell(0, 10, 'THIS AGREEMENT is made on ' . date('jS \d\a\y \o\f F, Y', strtotime($agreement_data['created_at'])), 0, 'L');
            $pdf->Ln(5);
            
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 10, 'BETWEEN:', 0, 1);
            $pdf->Ln(2);
            
            // Landlord details
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 10, 'THE LANDLORD: ' . strtotime($agreement_data['owner_name']), 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            
            if (!empty($agreement_data['owner_location'])) {
                $pdf->Cell(0, 10, 'Address: ' . $agreement_data['owner_location'], 0, 1);
            }
            if (!empty($agreement_data['owner_email'])) {
                $pdf->Cell(0, 10, 'Email: ' . $agreement_data['owner_email'], 0, 1);
            }
            if (!empty($agreement_data['owner_phone'])) {
                $pdf->Cell(0, 10, 'Phone: ' . $agreement_data['owner_phone'], 0, 1);
            }
            
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 10, 'AND:', 0, 1);
            $pdf->Ln(2);
            
            // Tenant details
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 10, 'THE TENANT: ' . strtotime($student['username']), 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            
            if (!empty($student['email'])) {
                $pdf->Cell(0, 10, 'Email: ' . $student['email'], 0, 1);
            }
            if (!empty($student['phone_number'])) {
                $pdf->Cell(0, 10, 'Phone: ' . $student['phone_number'], 0, 1);
            }
            if (!empty($student['location'])) {
                $pdf->Cell(0, 10, 'Address: ' . $student['location'], 0, 1);
            }
            
            $pdf->Ln(10);
            
            // PROPERTY DETAILS SECTION
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'PROPERTY DETAILS', 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            
            $pdf->Cell(0, 10, 'Property Name: ' . $agreement_data['property_name'], 0, 1);
            $pdf->Cell(0, 10, 'Location: ' . $agreement_data['location'], 0, 1);
            $pdf->Cell(0, 10, 'Yearly Rent: GHS ' . number_format($agreement_data['price'], 2), 0, 1);
            $pdf->Cell(0, 10, 'Monthly Rent: GHS ' . number_format($agreement_data['price'] / 12, 2), 0, 1);
            
            if (!empty($agreement_data['description'])) {
                $pdf->MultiCell(0, 10, 'Description: ' . $agreement_data['description'], 0, 'L');
            }
            
            $pdf->Ln(10);
            
            // TERMS AND CONDITIONS SECTION
            if (!empty($clauses)) {
                $pdf->SetFont('helvetica', 'B', 12);
                $pdf->Cell(0, 10, 'TERMS AND CONDITIONS', 0, 1);
                $pdf->SetFont('helvetica', '', 11);
                
                $currentCategory = '';
                $clauseNumber = 1;
                
                foreach ($clauses as $clause) {
                    // Add category heading if it's a new category
                    if ($clause['clause_category'] !== $currentCategory) {
                        $currentCategory = $clause['clause_category'];
                        $pdf->Ln(5);
                        $pdf->SetFont('helvetica', 'B', 11);
                        $pdf->Cell(0, 10, strtoupper(str_replace('_', ' ', $currentCategory)), 0, 1);
                        $pdf->SetFont('helvetica', '', 11);
                    }
                    
                    // Add clause
                    $pdf->SetFont('helvetica', 'B', 11);
                    $pdf->Cell(0, 10, $clauseNumber . '. ' . $clause['clause_title'], 0, 1);
                    $pdf->SetFont('helvetica', '', 11);
                    $pdf->MultiCell(0, 10, $clause['clause_text'], 0, 'J');
                    $pdf->Ln(2);
                    
                    $clauseNumber++;
                }
            }
            
            // CUSTOM TERMS SECTION
            if (!empty($agreement_data['custom_text'])) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 12);
                $pdf->Cell(0, 10, 'ADDITIONAL TERMS', 0, 1);
                $pdf->SetFont('helvetica', '', 11);
                $pdf->MultiCell(0, 10, $agreement_data['custom_text'], 0, 'J');
                $pdf->Ln(10);
            }
            
            // SIGNATURES SECTION
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'SIGNATURES', 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->MultiCell(0, 10, 'IN WITNESS WHEREOF, the parties have executed this Tenancy Agreement as of the date first above written.', 0, 'J');
            $pdf->Ln(15);
            
            // Signature table
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(90, 10, 'LANDLORD:', 0, 0);
            $pdf->Cell(90, 10, 'TENANT:', 0, 1);
            
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(90, 10, $agreement_data['owner_name'], 0, 0);
            $pdf->Cell(90, 10, $student['username'], 0, 1);
            $pdf->Ln(5);
            
            $pdf->Cell(90, 10, '_________________________', 0, 0);
            $pdf->Cell(90, 10, '_________________________', 0, 1);
            
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->Cell(90, 10, 'Signature', 0, 0);
            $pdf->Cell(90, 10, 'Signature', 0, 1);
            
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(90, 10, 'Date: ' . date('F j, Y'), 0, 0);
            $pdf->Cell(90, 10, 'Date: ________________', 0, 1);
            
            $pdf->Ln(15);
        }
        
        // Footer for both types
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->Cell(0, 10, 'This document was generated electronically via the Landlords&Tenant Platform.', 0, 1, 'C');
        $pdf->Cell(0, 10, 'Agreement ID: ' . $agreement_data['id'], 0, 1, 'C');
        
        // Generate filename
        $filename = "Tenancy_Agreement_" . preg_replace('/[^a-zA-Z0-9]/', '_', $agreement_data['agreement_title']) . "_" . date('Ymd_His');
        
        // Output PDF as download
        $pdf->Output($filename . '.pdf', 'D');
        exit();
    } else {
        // Student doesn't have access to this agreement
        header("Location: tenancy_agreements.php?error=access_denied");
        exit();
    }
}

// Continue with normal page display only if not downloading
// Get tenancy agreements accessible to this student
$agreements_stmt = $pdo->prepare("
    SELECT 
        gta.*,
        p.property_name,
        u.username as owner_name,
        taa.viewed_at,
        taa.downloaded_at,
        taa.status as access_status,
        b.id as booking_id
    FROM generated_tenancy_agreements gta
    JOIN tenant_agreement_access taa ON gta.id = taa.agreement_id
    JOIN property p ON gta.property_id = p.id
    JOIN users u ON gta.owner_id = u.id
    JOIN bookings b ON taa.booking_id = b.id
    WHERE taa.tenant_id = ? 
    AND b.status IN ('paid', 'confirmed', 'cash_approved')
    AND gta.status = 'published'
    ORDER BY gta.created_at DESC
");
$agreements_stmt->execute([$student_id]);
$agreements = $agreements_stmt->fetchAll();

// Update viewed_at helveticastamp when page is loaded
if (!empty($agreements)) {
    foreach ($agreements as $agreement) {
        if (!$agreement['viewed_at']) {
            $update_view = $pdo->prepare("
                UPDATE tenant_agreement_access 
                SET viewed_at = NOW(), status = 'viewed'
                WHERE tenant_id = ? AND agreement_id = ? AND viewed_at IS NULL
            ");
            $update_view->execute([$student_id, $agreement['id']]);
        }
    }
}

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../uploads/profile_pictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenancy Agreements - Tenant Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 80px;
            --header-height: 60px;
            --transition-speed: 0.3s;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
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
            transition: background-color var(--transition-speed);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .page-title {
            font-size: 28px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
        }

        .card-title {
            font-size: 22px;
            color: var(--secondary-color);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--accent-color);
        }

        .agreements-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .agreement-card {
            border: 1px solid #e9ecef;
            border-radius: var(--border-radius);
            padding: 20px;
            background-color: white;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
        }

        .agreement-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
        }

        .agreement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .agreement-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .agreement-details {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        @media (min-width: 768px) {
            .agreement-details {
                grid-template-columns: 1fr 1fr;
            }
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }

        .detail-value {
            font-size: 14px;
            color: var(--secondary-color);
        }

        .agreement-description {
            margin-bottom: 15px;
            color: #495057;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
        }

        .agreement-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all var(--transition-speed);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        .access-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            font-size: 14px;
            color: #6c757d;
        }

        .no-agreements {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-agreements-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-sent { background-color: #fff3cd; color: #856404; }
        .status-viewed { background-color: #d1ecf1; color: #0c5460; }
        .status-downloaded { background-color: #d4edda; color: #155724; }
        .status-signed { background-color: #d4edda; color: #155724; }

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
            
            .card {
                padding: 20px;
            }
            
            .card-title {
                font-size: 20px;
            }

            .agreement-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .agreement-actions {
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
            
            .btn {
                padding: 10px 20px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Tenancy Agreements</h1>
                <div></div> <!-- Empty div for spacing -->
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-file-contract"></i>
                Your Tenancy Agreements
            </h2>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                This section displays tenancy agreements from property owners whose properties you have booked and paid for.
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php 
                    if ($_GET['error'] == 'access_denied') {
                        echo "You don't have access to download this agreement.";
                    } else {
                        echo "An error occurred. Please try again.";
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (empty($agreements)): ?>
                <div class="no-agreements">
                    <div class="no-agreements-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h3>No Tenancy Agreements Available</h3>
                    <p>You will see tenancy agreements here once you have booked and paid for a property and the owner has shared an agreement with you.</p>
                </div>
            <?php else: ?>
                <div class="agreements-list">
                    <?php foreach ($agreements as $agreement): ?>
                        <div class="agreement-card">
                            <div class="agreement-header">
                                <h3 class="agreement-title"><?php echo htmlspecialchars($agreement['agreement_title']); ?></h3>
                                <span class="status-badge status-<?php echo $agreement['access_status']; ?>">
                                    <?php echo ucfirst($agreement['access_status']); ?>
                                </span>
                            </div>
                            
                            <div class="agreement-details">
                                <div class="detail-item">
                                    <span class="detail-label">Property</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($agreement['property_name']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Property Owner</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($agreement['owner_name']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Generated On</span>
                                    <span class="detail-value"><?php echo date('M j, Y', strtotime($agreement['created_at'])); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">You Viewed</span>
                                    <span class="detail-value">
                                        <?php echo $agreement['viewed_at'] ? date('M j, Y g:i A', strtotime($agreement['viewed_at'])) : 'Just now'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if (!empty($agreement['custom_text'])): ?>
                                <div class="agreement-description">
                                    <strong>Additional Terms:</strong><br>
                                    <?php echo htmlspecialchars($agreement['custom_text']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="agreement-actions">
                                <a href="?download=<?php echo $agreement['id']; ?>" class="btn btn-success">
                                    <i class="fas fa-file-pdf"></i> Download PDF
                                </a>
                            </div>
                            
                            <div class="access-info">
                                <?php if ($agreement['downloaded_at']): ?>
                                    <span><i class="fas fa-check-circle"></i> Downloaded on <?php echo date('M j, Y', strtotime($agreement['downloaded_at'])); ?></span>
                                <?php else: ?>
                                    <span><i class="fas fa-info-circle"></i> Not downloaded yet</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>