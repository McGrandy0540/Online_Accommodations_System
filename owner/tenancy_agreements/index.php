<?php
session_start();
require_once '../../config/database.php';

// Redirect to login if not authenticated
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

// Get owner's properties
$properties_stmt = $pdo->prepare("
    SELECT DISTINCT p.id, p.property_name, p.location, p.price
    FROM property p 
    WHERE p.owner_id = ? AND p.deleted = 0
    ORDER BY p.property_name
");
$properties_stmt->execute([$owner_id]);
$properties = $properties_stmt->fetchAll();

// Get all available clauses grouped by category
$clauses_stmt = $pdo->query("
    SELECT * FROM agreement_clauses 
    WHERE status = 'active' 
    ORDER BY clause_category, display_order
");
$all_clauses = $clauses_stmt->fetchAll();

// Group clauses by category
$clauses_by_category = [];
foreach ($all_clauses as $clause) {
    $category = $clause['clause_category'];
    if (!isset($clauses_by_category[$category])) {
        $clauses_by_category[$category] = [];
    }
    $clauses_by_category[$category][] = $clause;
}

// Category labels
$category_labels = [
    'tenancy_rules' => 'Tenancy Rules',
    'property_rules' => 'Property Rules',
    'payment_terms' => 'Payment Terms',
    'maintenance' => 'Maintenance',
    'conduct' => 'Conduct Rules',
    'general' => 'General Terms'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_draft'])) {
        $property_id = $_POST['property_id'];
        $agreement_title = $_POST['agreement_title'];
        $selected_clauses = $_POST['selected_clauses'] ?? [];
        $custom_text = $_POST['custom_text'] ?? '';
        
        // Save as draft
        $insert_stmt = $pdo->prepare("
            INSERT INTO generated_tenancy_agreements 
            (owner_id, property_id, agreement_title, selected_clauses, custom_text, status)
            VALUES (?, ?, ?, ?, ?, 'draft')
        ");
        
        $clauses_json = json_encode(array_map('intval', $selected_clauses));
        
        if ($insert_stmt->execute([$owner_id, $property_id, $agreement_title, $clauses_json, $custom_text])) {
            $_SESSION['success'] = "Agreement saved as draft successfully!";
            header("Location: manage.php");
            exit();
        } else {
            $error = "Failed to save agreement. Please try again.";
        }
    }
    
    if (isset($_POST['generate_document'])) {
        $property_id = $_POST['property_id'];
        $agreement_title = $_POST['agreement_title'];
        $selected_clauses = $_POST['selected_clauses'] ?? [];
        $custom_text = $_POST['custom_text'] ?? '';
        
        // Validate
        if (empty($selected_clauses)) {
            $error = "Please select at least one clause for the agreement.";
        } else {
            // Store data in session for document generation
            $_SESSION['agreement_data'] = [
                'property_id' => $property_id,
                'agreement_title' => $agreement_title,
                'selected_clauses' => $selected_clauses,
                'custom_text' => $custom_text
            ];
            header("Location: generate_document.php");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Tenancy Agreement | Property Owner</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f5f7fa;
            color: #2c3e50;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .back-button {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .page-title {
            font-size: 28px;
            flex-grow: 1;
            text-align: center;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-bottom: 25px;
        }

        .card-title {
            font-size: 22px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #3498db;
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .clauses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .clause-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s;
            cursor: pointer;
        }

        .clause-card:hover {
            border-color: #3498db;
            transform: translateY(-2px);
        }

        .clause-card.selected {
            background: #e3f2fd;
            border-color: #3498db;
        }

        .clause-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .clause-checkbox input[type="checkbox"] {
            margin-top: 4px;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .clause-content {
            flex-grow: 1;
        }

        .clause-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .clause-text {
            font-size: 14px;
            color: #6c757d;
            line-height: 1.5;
        }

        .category-section {
            margin-bottom: 30px;
        }

        .category-header {
            background: #3498db;
            color: white;
            padding: 12px 20px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .category-count {
            background: rgba(255, 255, 255, 0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .helper-text {
            font-size: 14px;
            color: #6c757d;
            margin-top: 5px;
        }

        .select-all-btn {
            background: transparent;
            border: 2px solid white;
            color: white;
            padding: 6px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: 0.3s;
        }

        .select-all-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .selection-counter {
            background: #e3f2fd;
            border: 2px solid #3498db;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .counter-text {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }

        .counter-number {
            background: #3498db;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 20px;
            font-weight: 700;
        }

        .upload-section {
            border: 2px dashed #3498db;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            margin-top: 20px;
        }

        .upload-icon {
            font-size: 48px;
            color: #3498db;
            margin-bottom: 15px;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            color: white;
            font-size: 18px;
        }

        .loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 2s linear infinite;
            margin-bottom: 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                <h1 class="page-title">Generate Tenancy Agreement</h1>
                <a href="manage.php" class="btn btn-warning">
                    <i class="fas fa-list"></i> View Agreements
                </a>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="agreementForm">
            <!-- Basic Information -->
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-info-circle"></i>
                    Basic Information
                </h2>

                <div class="alert alert-info">
                    <i class="fas fa-lightbulb"></i>
                    Your property details will be automatically filled in the agreement. Just select clauses and generate!
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="property_id">Select Property *</label>
                        <select name="property_id" id="property_id" class="form-select" required>
                            <option value="">Choose a property...</option>
                            <?php foreach ($properties as $property): ?>
                                <option value="<?= $property['id'] ?>">
                                    <?= htmlspecialchars($property['property_name']) ?> - <?= htmlspecialchars($property['location']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="helper-text">Property details will auto-fill in the agreement</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="agreement_title">Agreement Title *</label>
                        <input type="text" name="agreement_title" id="agreement_title" class="form-input" 
                               placeholder="E.g., Tenancy Agreement 2025" required>
                        <div class="helper-text">Give this agreement a descriptive title</div>
                    </div>
                </div>
            </div>

            <!-- Clause Selection -->
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-check-square"></i>
                    Select Agreement Clauses
                </h2>

                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i>
                    Select the rules and terms you want to include in your agreement. Default clauses are pre-selected.
                </div>

                <div class="selection-counter">
                    <span class="counter-text">Selected Clauses:</span>
                    <span class="counter-number" id="selectedCount">0</span>
                </div>

                <?php foreach ($clauses_by_category as $category => $clauses): ?>
                    <div class="category-section" data-category="<?= $category ?>">
                        <div class="category-header">
                            <h3>
                                <i class="fas fa-list"></i> 
                                <?= $category_labels[$category] ?? ucfirst(str_replace('_', ' ', $category)) ?>
                            </h3>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <span class="category-count"><?= count($clauses) ?> clauses</span>
                                <button type="button" class="select-all-btn" onclick="toggleCategory('<?= $category ?>')">
                                    Select All
                                </button>
                            </div>
                        </div>

                        <div class="clauses-grid">
                            <?php foreach ($clauses as $clause): ?>
                                <div class="clause-card <?= $clause['is_default'] ? 'selected' : '' ?>" 
                                     onclick="toggleClause(<?= $clause['id'] ?>)">
                                    <div class="clause-checkbox">
                                        <input type="checkbox" 
                                               name="selected_clauses[]" 
                                               value="<?= $clause['id'] ?>" 
                                               id="clause_<?= $clause['id'] ?>"
                                               <?= $clause['is_default'] ? 'checked' : '' ?>
                                               onclick="event.stopPropagation()">
                                        <div class="clause-content">
                                            <div class="clause-title"><?= htmlspecialchars($clause['clause_title']) ?></div>
                                            <div class="clause-text"><?= htmlspecialchars($clause['clause_text']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Custom Text -->
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-edit"></i>
                    Add Custom Text (Optional)
                </h2>

                <div class="form-group">
                    <label class="form-label" for="custom_text">Additional Terms or Notes</label>
                    <textarea name="custom_text" id="custom_text" class="form-textarea" 
                              placeholder="Add any additional terms, conditions, or notes specific to this agreement..."></textarea>
                    <div class="helper-text">This text will be added at the end of the agreement</div>
                </div>
            </div>

            <!-- Upload Option -->
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-upload"></i>
                    Upload Existing Agreement (Alternative)
                </h2>

                <div class="upload-section">
                    <div class="upload-icon">
                        <i class="fas fa-file-upload"></i>
                    </div>
                    <h3>Already have an agreement document?</h3>
                    <p>You can upload your existing tenancy agreement document instead of generating one.</p>
                    <a href="upload_agreement.php" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Document
                    </a>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button type="submit" name="save_draft" class="btn btn-secondary">
                    <i class="fas fa-save"></i> Save as Draft
                </button>
                <button type="button" onclick="previewAgreement()" class="btn btn-warning">
                    <i class="fas fa-eye"></i> Preview Agreement
                </button>
                <button type="submit" name="generate_document" class="btn btn-success">
                    <i class="fas fa-file-word"></i> Generate & Download
                </button>
                <button type="button" onclick="saveAndSend()" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Save & Send to Tenant
                </button>
            </div>
        </form>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div style="text-align: center;">
            <div class="loading-spinner"></div>
            <div>Generating Agreement...</div>
        </div>
    </div>

    <script>
        // Toggle clause selection
        function toggleClause(clauseId) {
            const checkbox = document.getElementById('clause_' + clauseId);
            checkbox.checked = !checkbox.checked;
            updateClauseCard(clauseId);
            updateCounter();
        }

        // Update clause card appearance
        function updateClauseCard(clauseId) {
            const checkbox = document.getElementById('clause_' + clauseId);
            const card = checkbox.closest('.clause-card');
            if (checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        }

        // Toggle all clauses in a category
        function toggleCategory(category) {
            const section = document.querySelector(`[data-category="${category}"]`);
            const checkboxes = section.querySelectorAll('input[type="checkbox"]');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
                updateClauseCard(checkbox.value);
            });
            
            updateCounter();
        }

        // Update selection counter
        function updateCounter() {
            const checkedCount = document.querySelectorAll('input[name="selected_clauses[]"]:checked').length;
            document.getElementById('selectedCount').textContent = checkedCount;
        }

        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Preview agreement
        function previewAgreement() {
            const form = document.getElementById('agreementForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const selectedCount = document.querySelectorAll('input[name="selected_clauses[]"]:checked').length;
            if (selectedCount === 0) {
                alert('Please select at least one clause for the agreement.');
                return;
            }

            // Create preview form
            const previewForm = document.createElement('form');
            previewForm.method = 'POST';
            previewForm.action = 'preview_agreement.php';
            previewForm.target = '_blank';
            
            // Add form data
            addFormField(previewForm, 'property_id', form.property_id.value);
            addFormField(previewForm, 'agreement_title', form.agreement_title.value);
            addFormField(previewForm, 'custom_text', form.custom_text.value);
            
            const selectedClauses = document.querySelectorAll('input[name="selected_clauses[]"]:checked');
            selectedClauses.forEach(checkbox => {
                addFormField(previewForm, 'selected_clauses[]', checkbox.value);
            });
            
            document.body.appendChild(previewForm);
            previewForm.submit();
            document.body.removeChild(previewForm);
        }

        // Save and send to tenant
        function saveAndSend() {
            const form = document.getElementById('agreementForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const selectedCount = document.querySelectorAll('input[name="selected_clauses[]"]:checked').length;
            if (selectedCount === 0) {
                alert('Please select at least one clause for the agreement.');
                return;
            }

            showLoading();

            // Create save form
            const saveForm = document.createElement('form');
            saveForm.method = 'POST';
            saveForm.action = 'save_agreement.php';
            
            addFormField(saveForm, 'property_id', form.property_id.value);
            addFormField(saveForm, 'agreement_title', form.agreement_title.value);
            addFormField(saveForm, 'custom_text', form.custom_text.value);
            addFormField(saveForm, 'action', 'save_and_send');
            
            const selectedClauses = document.querySelectorAll('input[name="selected_clauses[]"]:checked');
            selectedClauses.forEach(checkbox => {
                addFormField(saveForm, 'selected_clauses[]', checkbox.value);
            });
            
            document.body.appendChild(saveForm);
            saveForm.submit();
        }

        // Handle generate document form submission
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('agreementForm');
            form.addEventListener('submit', function(e) {
                if (e.submitter && e.submitter.name === 'generate_document') {
                    e.preventDefault();
                    
                    const selectedCount = document.querySelectorAll('input[name="selected_clauses[]"]:checked').length;
                    if (selectedCount === 0) {
                        alert('Please select at least one clause for the agreement.');
                        return;
                    }

                    showLoading();
                    
                    // Submit the form normally
                    const submitForm = document.createElement('form');
                    submitForm.method = 'POST';
                    submitForm.action = 'generate_document.php';
                    
                    addFormField(submitForm, 'property_id', form.property_id.value);
                    addFormField(submitForm, 'agreement_title', form.agreement_title.value);
                    addFormField(submitForm, 'custom_text', form.custom_text.value);
                    
                    const selectedClauses = document.querySelectorAll('input[name="selected_clauses[]"]:checked');
                    selectedClauses.forEach(checkbox => {
                        addFormField(submitForm, 'selected_clauses[]', checkbox.value);
                    });
                    
                    document.body.appendChild(submitForm);
                    submitForm.submit();
                }
            });
        });

        // Helper function to add form fields
        function addFormField(form, name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        // Initialize counter on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCounter();
            
            // Prevent checkbox label from triggering card click
            document.querySelectorAll('.clause-checkbox input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateClauseCard(this.value);
                    updateCounter();
                });
            });
        });
    </script>
</body>
</html>