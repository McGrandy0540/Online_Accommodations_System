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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $property_id = $_POST['property_id'];
    $agreement_title = $_POST['agreement_title'];
    $selected_clauses = $_POST['selected_clauses'] ?? [];
    $custom_text = $_POST['custom_text'] ?? '';
    
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
    
    // Generate preview content
    $current_date = date('F j, Y');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Agreement Preview - <?= htmlspecialchars($agreement_title) ?></title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            line-height: 1.6; 
            margin: 40px; 
            background: #f5f5f5;
        }
        .preview-container {
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            max-width: 800px;
            margin: 0 auto;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #333; 
            padding-bottom: 20px; 
        }
        .header h1 { 
            color: #2c3e50; 
            margin-bottom: 10px; 
        }
        .section { 
            margin-bottom: 25px; 
        }
        .section-title { 
            background: #f8f9fa; 
            padding: 10px 15px; 
            border-left: 4px solid #3498db; 
            margin-bottom: 15px; 
            font-weight: bold; 
        }
        .clause { 
            margin-bottom: 15px; 
            padding-left: 10px; 
        }
        .clause-title { 
            font-weight: bold; 
            margin-bottom: 5px; 
            color: #2c3e50;
        }
        .signature-section { 
            margin-top: 50px; 
            border-top: 1px solid #ccc; 
            padding-top: 20px; 
        }
        .signature-line { 
            margin-top: 60px; 
            border-top: 1px solid #000; 
            width: 300px; 
        }
        .party-info { 
            display: inline-block; 
            width: 45%; 
            vertical-align: top; 
            margin-bottom: 20px;
        }
        .action-buttons {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .btn {
            padding: 12px 24px;
            margin: 0 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <div class="header">
            <h1>TENANCY AGREEMENT</h1>
            <h2><?= htmlspecialchars($agreement_title) ?></h2>
            <p><strong>Date:</strong> <?= $current_date ?></p>
        </div>
        
        <div class="section">
            <div class="section-title">PARTIES TO THIS AGREEMENT</div>
            <div class="party-info">
                <strong>LANDLORD:</strong><br>
                Name: <?= htmlspecialchars($owner['username']) ?><br>
                Email: <?= htmlspecialchars($owner['email']) ?><br>
                Phone: <?= htmlspecialchars($owner['phone_number']) ?><br>
                Address: <?= htmlspecialchars($owner['location']) ?>
            </div>
            <div class="party-info" style="margin-left: 50px;">
                <strong>TENANT:</strong><br>
                [Tenant Name]<br>
                [Tenant Email]<br>
                [Tenant Phone]<br>
                [Tenant Address]
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">PROPERTY DETAILS</div>
            <p><strong>Property Name:</strong> <?= htmlspecialchars($property['property_name']) ?></p>
            <p><strong>Location:</strong> <?= htmlspecialchars($property['location']) ?></p>
            <p><strong>Yearly Rent:</strong> GHS <?= number_format($property['price'], 2) ?></p>
            <p><strong>Description:</strong> <?= htmlspecialchars($property['description']) ?></p>
        </div>
        
        <div class="section">
            <div class="section-title">TERMS AND CONDITIONS</div>
            <?php
            // Group clauses by category
            $clauses_by_category = [];
            foreach ($clauses as $clause) {
                $category = $clause['clause_category'];
                if (!isset($clauses_by_category[$category])) {
                    $clauses_by_category[$category] = [];
                }
                $clauses_by_category[$category][] = $clause;
            }
            
            $category_labels = [
                'tenancy_rules' => 'TENANCY RULES AND REGULATIONS',
                'property_rules' => 'PROPERTY USAGE RULES',
                'payment_terms' => 'PAYMENT TERMS AND CONDITIONS',
                'maintenance' => 'MAINTENANCE AND REPAIRS',
                'conduct' => 'CONDUCT AND BEHAVIOR',
                'general' => 'GENERAL TERMS AND CONDITIONS'
            ];
            
            foreach ($clauses_by_category as $category => $category_clauses) {
                echo "<div class='section-title'>" . ($category_labels[$category] ?? strtoupper(str_replace('_', ' ', $category))) . "</div>";
                foreach ($category_clauses as $clause) {
                    echo "
                    <div class='clause'>
                        <div class='clause-title'>" . htmlspecialchars($clause['clause_title']) . "</div>
                        <div class='clause-text'>" . nl2br(htmlspecialchars($clause['clause_text'])) . "</div>
                    </div>";
                }
            }
            
            if (!empty($custom_text)) {
                echo "
                <div class='section'>
                    <div class='section-title'>ADDITIONAL TERMS AND CONDITIONS</div>
                    <div class='clause'>
                        <div class='clause-text'>" . nl2br(htmlspecialchars($custom_text)) . "</div>
                    </div>
                </div>";
            }
            ?>
        </div>
        
        <div class="signature-section">
            <div class="party-info">
                <strong>LANDLORD'S SIGNATURE</strong>
                <div class="signature-line"></div>
                <p>Name: <?= htmlspecialchars($owner['username']) ?><br>
                Date: ________________</p>
            </div>
            <div class="party-info" style="margin-left: 50px;">
                <strong>TENANT'S SIGNATURE</strong>
                <div class="signature-line"></div>
                <p>Name: ________________<br>
                Date: ________________</p>
            </div>
        </div>
        
        <div class="footer">
            <p>This is a preview of the tenancy agreement. Generate the document to download or send to tenants.</p>
        </div>

        <div class="action-buttons">
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print Preview
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="fas fa-times"></i> Close
            </button>
            <button onclick="generateDocument()" class="btn btn-success">
                <i class="fas fa-download"></i> Generate Document
            </button>
            <button onclick="saveAndSend()" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Save & Send
            </button>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
    function generateDocument() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'generate_document.php';
        
        <?php
        echo "addFormField(form, 'property_id', '$property_id');";
        echo "addFormField(form, 'agreement_title', '" . addslashes($agreement_title) . "');";
        echo "addFormField(form, 'custom_text', '" . addslashes($custom_text) . "');";
        
        foreach ($selected_clauses as $clause_id) {
            echo "addFormField(form, 'selected_clauses[]', '$clause_id');";
        }
        ?>
        
        document.body.appendChild(form);
        form.submit();
    }
    
    function saveAndSend() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'save_agreement.php';
        
        <?php
        echo "addFormField(form, 'property_id', '$property_id');";
        echo "addFormField(form, 'agreement_title', '" . addslashes($agreement_title) . "');";
        echo "addFormField(form, 'custom_text', '" . addslashes($custom_text) . "');";
        echo "addFormField(form, 'action', 'save_and_send');";
        
        foreach ($selected_clauses as $clause_id) {
            echo "addFormField(form, 'selected_clauses[]', '$clause_id');";
        }
        ?>
        
        document.body.appendChild(form);
        form.submit();
    }
    
    function addFormField(form, name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    </script>
</body>
</html>
<?php
}
?>