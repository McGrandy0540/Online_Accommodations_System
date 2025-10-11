<?php
session_start();
require_once '../../config/database.php';

// Redirect if not authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    header("Location: ../../auth/login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get owner details
$owner_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$owner_stmt->execute([$owner_id]);
$owner = $owner_stmt->fetch();

// Get agreement data from session or POST
if (isset($_SESSION['agreement_data'])) {
    $agreement_data = $_SESSION['agreement_data'];
    unset($_SESSION['agreement_data']);
} else {
    $agreement_data = $_POST;
}

$property_id = $agreement_data['property_id'];
$agreement_title = $agreement_data['agreement_title'];
$selected_clauses = $agreement_data['selected_clauses'] ?? [];
$custom_text = $agreement_data['custom_text'] ?? '';

// Get property details
$property_stmt = $pdo->prepare("SELECT * FROM property WHERE id = ? AND owner_id = ?");
$property_stmt->execute([$property_id, $owner_id]);
$property = $property_stmt->fetch();

if (!$property) {
    die("Property not found or access denied.");
}

// Get selected clauses
$clauses = [];
if (!empty($selected_clauses)) {
    $placeholders = str_repeat('?,', count($selected_clauses) - 1) . '?';
    $clause_stmt = $pdo->prepare("SELECT * FROM agreement_clauses WHERE id IN ($placeholders) ORDER BY clause_category, display_order");
    $clause_stmt->execute($selected_clauses);
    $clauses = $clause_stmt->fetchAll();
}

// Save agreement to database first
$clauses_json = json_encode(array_map('intval', $selected_clauses));
$insert_stmt = $pdo->prepare("
    INSERT INTO generated_tenancy_agreements 
    (owner_id, property_id, agreement_title, selected_clauses, custom_text, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'published', NOW())
");
$insert_stmt->execute([$owner_id, $property_id, $agreement_title, $clauses_json, $custom_text]);
$agreement_id = $pdo->lastInsertId();

// Generate filename
$filename = "Tenancy_Agreement_" . preg_replace('/[^a-zA-Z0-9]/', '_', $agreement_title) . "_" . date('Ymd_His');

// Use PHPWord for better Word document generation
require_once '../../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\SimpleType\Jc;

$phpWord = new PhpWord();

// Set document properties
$phpWord->getDocInfo()->setCreator($owner['username'])
    ->setCompany('Landlords&Tenant Platform')
    ->setTitle('Tenancy Agreement - ' . $agreement_title)
    ->setDescription('Automatically generated tenancy agreement')
    ->setLastModifiedBy('System');

// Create section
$section = $phpWord->addSection();

// Add header
$section->addText(
    'TENANCY AGREEMENT',
    ['bold' => true, 'size' => 16],
    ['alignment' => 'center']
);
$section->addText(
    $agreement_title,
    ['bold' => true, 'size' => 14, 'color' => '0066CC'],
    ['alignment' => 'center']
);
$section->addText(
    'Date: ' . date('F j, Y'),
    ['size' => 12],
    ['alignment' => 'center']
);
$section->addTextBreak(2);

// Add parties section
$section->addText('PARTIES TO THIS AGREEMENT', ['bold' => true, 'size' => 12]);
$section->addTextBreak(1);

// Create table for parties
$partiesTable = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 50]);
$partiesTable->addRow();

// Landlord column
$landlordCell = $partiesTable->addCell(4000);
$landlordCell->addText('LANDLORD:', ['bold' => true]);
$landlordCell->addText('Name: ' . $owner['username']);
$landlordCell->addText('Email: ' . $owner['email']);
$landlordCell->addText('Phone: ' . $owner['phone_number']);
$landlordCell->addText('Address: ' . $owner['location']);

// Spacer column
$partiesTable->addCell(1000);

// Tenant column
$tenantCell = $partiesTable->addCell(4000);
$tenantCell->addText('TENANT:', ['bold' => true]);
$tenantCell->addText('Name: [Tenant Name]');
$tenantCell->addText('Email: [Tenant Email]');
$tenantCell->addText('Phone: [Tenant Phone]');
$tenantCell->addText('Address: [Tenant Address]');

$section->addTextBreak(2);

// Add property details section
$section->addText('PROPERTY DETAILS', ['bold' => true, 'size' => 12]);
$section->addTextBreak(1);
$section->addText('Property Name: ' . $property['property_name']);
$section->addText('Location: ' . $property['location']);
$section->addText('Yearly Rent: GHS ' . number_format($property['price'], 2));
$section->addText('Description: ' . $property['description']);
$section->addTextBreak(2);

// Add terms and conditions section
$section->addText('TERMS AND CONDITIONS', ['bold' => true, 'size' => 12]);
$section->addTextBreak(1);

// Group clauses by category
$clauses_by_category = [];
foreach ($clauses as $clause) {
    $category = $clause['clause_category'];
    if (!isset($clauses_by_category[$category])) {
        $clauses_by_category[$category] = [];
    }
    $clauses_by_category[$category][] = $clause;
}

// Category labels
$category_labels = [
    'tenancy_rules' => 'TENANCY RULES AND REGULATIONS',
    'property_rules' => 'PROPERTY USAGE RULES',
    'payment_terms' => 'PAYMENT TERMS AND CONDITIONS',
    'maintenance' => 'MAINTENANCE AND REPAIRS',
    'conduct' => 'CONDUCT AND BEHAVIOR',
    'general' => 'GENERAL TERMS AND CONDITIONS'
];

// Add clauses by category
foreach ($clauses_by_category as $category => $category_clauses) {
    $section->addText($category_labels[$category] ?? strtoupper(str_replace('_', ' ', $category)), 
        ['bold' => true, 'size' => 11]);
    
    foreach ($category_clauses as $clause) {
        $section->addText($clause['clause_title'], ['bold' => true, 'size' => 10]);
        $section->addText($clause['clause_text'], ['size' => 10]);
        $section->addTextBreak(1);
    }
    $section->addTextBreak(1);
}

// Add custom text if provided
if (!empty($custom_text)) {
    $section->addText('ADDITIONAL TERMS AND CONDITIONS', ['bold' => true, 'size' => 12]);
    $section->addTextBreak(1);
    $section->addText($custom_text, ['size' => 10]);
    $section->addTextBreak(2);
}

// Add signature section
$section->addText('SIGNATURES', ['bold' => true, 'size' => 12]);
$section->addTextBreak(1);

// Create table for signatures
$signatureTable = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 50]);
$signatureTable->addRow();

// Landlord signature
$landlordSigCell = $signatureTable->addCell(4000);
$landlordSigCell->addText('LANDLORD\'S SIGNATURE', ['bold' => true]);
$landlordSigCell->addText('');
$landlordSigCell->addText('_________________________'); // Signature line
$landlordSigCell->addText('Name: ' . $owner['username']);
$landlordSigCell->addText('Date: ________________');

// Spacer column
$signatureTable->addCell(1000);

// Tenant signature
$tenantSigCell = $signatureTable->addCell(4000);
$tenantSigCell->addText('TENANT\'S SIGNATURE', ['bold' => true]);
$tenantSigCell->addText('');
$tenantSigCell->addText('_________________________'); // Signature line
$tenantSigCell->addText('Name: ________________');
$tenantSigCell->addText('Date: ________________');

$section->addTextBreak(3);

// Add footer
$footer = $section->addFooter();
$footer->addText(
    'This agreement was generated automatically on ' . date('F j, Y') . ' through the Landlords&Tenant Platform',
    ['size' => 9, 'color' => '666666'],
    ['alignment' => 'center']
);
$footer->addText(
    'Agreement ID: ' . $agreement_id . ' | Property: ' . $property['property_name'],
    ['size' => 9, 'color' => '666666'],
    ['alignment' => 'center']
);

// Save the document
$objWriter = IOFactory::createWriter($phpWord, 'Word2007');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '.docx"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
$objWriter->save('php://output');
exit;
?>