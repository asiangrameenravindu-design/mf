<?php
require_once '../../config/config.php';
checkAccess();

if (!isset($_SESSION['user_type']) || ($_SESSION['user_type'] !== 'admin' && $_SESSION['user_type'] !== 'manager')) {
    header("Location: ../unauthorized.php");
    exit;
}

$page_title = "Create Insurance Claim";
include '../includes/header.php';

// Initialize variables to preserve form data
$voucher_no = "";
$center_id = "";
$customer_data = null;
$customer_loans = [];
$search_nic = '';
$form_data = [];

// Generate voucher number (only if not already set)
if (empty($_POST['voucher_no'])) {
    $voucher_query = "SELECT voucher_no FROM insurance_claims ORDER BY id DESC LIMIT 1";
    $voucher_result = $conn->query($voucher_query);
    if ($voucher_result && $voucher_result->num_rows > 0) {
        $last_voucher = $voucher_result->fetch_assoc()['voucher_no'];
        if (preg_match('/ICL(\d+)/', $last_voucher, $matches)) {
            $next_number = intval($matches[1]) + 1;
            $voucher_no = "ICL" . str_pad($next_number, 6, '0', STR_PAD_LEFT);
        } else {
            $voucher_no = "ICL000001";
        }
    } else {
        $voucher_no = "ICL000001";
    }
} else {
    $voucher_no = $_POST['voucher_no'];
}

// Handle customer search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_customer'])) {
    $search_nic = trim($_POST['search_nic']);
    $center_id = $_POST['center_id'] ?? '';
    $voucher_no = $_POST['voucher_no'] ?? $voucher_no;
    
    if (!empty($search_nic)) {
        // Get customer details by NIC
        $customer_query = "SELECT id, full_name, national_id as nic, phone 
                          FROM customers 
                          WHERE national_id = ?";
        $stmt = $conn->prepare($customer_query);
        if ($stmt) {
            $stmt->bind_param("s", $search_nic);
            $stmt->execute();
            $customer_result = $stmt->get_result();
            
            if ($customer_result->num_rows > 0) {
                $customer_data = $customer_result->fetch_assoc();
                
                // Get customer loans
                $loans_query = "SELECT id, loan_number, amount, disbursed_date, status, balance
                               FROM loans 
                               WHERE customer_id = ? AND status IN ('disbursed', 'completed')
                               ORDER BY disbursed_date DESC";
                $loan_stmt = $conn->prepare($loans_query);
                if ($loan_stmt) {
                    $loan_stmt->bind_param("i", $customer_data['id']);
                    $loan_stmt->execute();
                    $loans_result = $loan_stmt->get_result();
                    
                    while ($loan = $loans_result->fetch_assoc()) {
                        $customer_loans[] = $loan;
                    }
                }
            } else {
                $_SESSION['error_message'] = "Customer not found with NIC: " . htmlspecialchars($search_nic);
            }
        } else {
            $_SESSION['error_message'] = "Database error in search";
        }
    } else {
        $_SESSION['error_message'] = "Please enter a NIC number";
    }
    
    // Store form data for repopulation
    $form_data = [
        'voucher_no' => $voucher_no,
        'center_id' => $center_id,
        'search_nic' => $search_nic,
        'info_date' => $_POST['info_date'] ?? '',
        'claim_type' => $_POST['claim_type'] ?? '',
        'claim_person' => $_POST['claim_person'] ?? '',
        'admission_date' => $_POST['admission_date'] ?? '',
        'discharge_date' => $_POST['discharge_date'] ?? '',
        'death_date' => $_POST['death_date'] ?? '',
        'post_mortem_date' => $_POST['post_mortem_date'] ?? '',
        'relationship' => $_POST['relationship'] ?? '',
        'number_of_days' => $_POST['number_of_days'] ?? '0',
        'claim_approval_days' => $_POST['claim_approval_days'] ?? '0',
        'claim_amount' => $_POST['claim_amount'] ?? '500.00',
        'loan_write_off' => $_POST['loan_write_off'] ?? '0'
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_claim'])) {
    $voucher_no = $conn->real_escape_string($_POST['voucher_no']);
    $center_id = (int)$_POST['center_id'];
    $customer_id = (int)$_POST['customer_id'];
    $loan_id = (int)$_POST['loan_id'];
    $nic = $conn->real_escape_string($_POST['nic']);
    $mobile = $conn->real_escape_string($_POST['mobile']);
    $loan_disbursement_date = $conn->real_escape_string($_POST['loan_disbursement_date']);
    $loan_amount = (float)$_POST['loan_amount'];
    $total_outstanding = (float)$_POST['total_outstanding'];
    $arrears_amount = (float)$_POST['arrears_amount'];
    $info_date = $conn->real_escape_string($_POST['info_date']);
    $claim_type = $conn->real_escape_string($_POST['claim_type']);
    $claim_person = $conn->real_escape_string($_POST['claim_person']);
    $admission_date = !empty($_POST['admission_date']) ? $conn->real_escape_string($_POST['admission_date']) : NULL;
    $discharge_date = !empty($_POST['discharge_date']) ? $conn->real_escape_string($_POST['discharge_date']) : NULL;
    $death_date = !empty($_POST['death_date']) ? $conn->real_escape_string($_POST['death_date']) : NULL;
    $post_mortem_date = !empty($_POST['post_mortem_date']) ? $conn->real_escape_string($_POST['post_mortem_date']) : NULL;
    $relationship = !empty($_POST['relationship']) ? $conn->real_escape_string($_POST['relationship']) : NULL;
    $number_of_days = (int)$_POST['number_of_days'];
    $claim_approval_days = (int)$_POST['claim_approval_days'];
    $claim_amount = (float)$_POST['claim_amount'];
    $loan_write_off = isset($_POST['loan_write_off']) ? 1 : 0;
    $created_by = $_SESSION['user_id'];

    // Calculate number of days if not provided
    if ($number_of_days === 0 && !empty($admission_date) && !empty($discharge_date)) {
        $admission = new DateTime($admission_date);
        $discharge = new DateTime($discharge_date);
        $number_of_days = $discharge->diff($admission)->days;
    }

    // Check if voucher number already exists
    $check_query = "SELECT id FROM insurance_claims WHERE voucher_no = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $voucher_no);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $_SESSION['error_message'] = "Voucher number already exists! Please use a different voucher number.";
        
        // Store form data for repopulation
        $form_data = $_POST;
        $search_nic = $nic;
        
        // Get customer data again for repopulation
        $customer_query = "SELECT id, full_name, national_id as nic, phone FROM customers WHERE id = ?";
        $stmt = $conn->prepare($customer_query);
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $customer_result = $stmt->get_result();
        if ($customer_result->num_rows > 0) {
            $customer_data = $customer_result->fetch_assoc();
            
            // Get customer loans again
            $loans_query = "SELECT id, loan_number, amount, disbursed_date, status, balance FROM loans WHERE customer_id = ? AND status IN ('disbursed', 'completed')";
            $loan_stmt = $conn->prepare($loans_query);
            $loan_stmt->bind_param("i", $customer_id);
            $loan_stmt->execute();
            $loans_result = $loan_stmt->get_result();
            while ($loan = $loans_result->fetch_assoc()) {
                $customer_loans[] = $loan;
            }
        }
    } else {
        // Start transaction for data consistency
        $conn->begin_transaction();
        
        try {
            // Insert insurance claim
            $query = "INSERT INTO insurance_claims (
                voucher_no, center_id, customer_id, loan_id, nic, mobile, 
                loan_disbursement_date, loan_amount, total_outstanding, arrears_amount,
                info_date, claim_type, claim_person, admission_date, discharge_date,
                death_date, post_mortem_date, relationship, number_of_days, claim_approval_days, 
                claim_amount, loan_write_off, created_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";

            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param(
                    "siiissssddssssssssiiidi",
                    $voucher_no, $center_id, $customer_id, $loan_id, $nic, $mobile,
                    $loan_disbursement_date, $loan_amount, $total_outstanding, $arrears_amount,
                    $info_date, $claim_type, $claim_person, $admission_date, $discharge_date,
                    $death_date, $post_mortem_date, $relationship, $number_of_days, $claim_approval_days, 
                    $claim_amount, $loan_write_off, $created_by
                );

                if ($stmt->execute()) {
                    $claim_id = $conn->insert_id;
                    
                    // Update loan status if write off is selected
                    if ($loan_write_off && $claim_type == 'death') {
                        $update_loan_query = "UPDATE loans SET status = 'completed', balance = 0, settlement_date = CURDATE() WHERE id = ?";
                        $update_stmt = $conn->prepare($update_loan_query);
                        $update_stmt->bind_param("i", $loan_id);
                        $update_stmt->execute();
                    }
                    
                    // Log the activity
                    $activity_query = "INSERT INTO activity_log (user_id, action, description, module, ip_address, user_agent) VALUES (?, 'create', ?, 'insurance_claims', ?, ?)";
                    $activity_stmt = $conn->prepare($activity_query);
                    $activity_description = "Created insurance claim #{$voucher_no} for customer {$nic} - Amount: Rs. " . number_format($claim_amount, 2);
                    $activity_stmt->bind_param("isss", $created_by, $activity_description, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                    $activity_stmt->execute();
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $_SESSION['success_message'] = "Insurance claim created successfully! Claim Reference: <strong>{$voucher_no}</strong>";
                    header("Location: insurance_claims.php");
                    exit;
                    
                } else {
                    throw new Exception("Error creating insurance claim: " . $conn->error);
                }
            } else {
                throw new Exception("Database error: " . $conn->error);
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $_SESSION['error_message'] = $e->getMessage();
            
            // Store form data for repopulation
            $form_data = $_POST;
            $search_nic = $nic;
            
            // Get customer data again for repopulation
            $customer_query = "SELECT id, full_name, national_id as nic, phone FROM customers WHERE id = ?";
            $stmt = $conn->prepare($customer_query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $customer_result = $stmt->get_result();
            if ($customer_result->num_rows > 0) {
                $customer_data = $customer_result->fetch_assoc();
                
                // Get customer loans again
                $loans_query = "SELECT id, loan_number, amount, disbursed_date, status, balance FROM loans WHERE customer_id = ? AND status IN ('disbursed', 'completed')";
                $loan_stmt = $conn->prepare($loans_query);
                $loan_stmt->bind_param("i", $customer_id);
                $loan_stmt->execute();
                $loans_result = $loan_stmt->get_result();
                while ($loan = $loans_result->fetch_assoc()) {
                    $customer_loans[] = $loan;
                }
            }
        }
    }
}

// Get centers for dropdown
$centers_query = "SELECT id, name FROM cbo ORDER BY name";
$centers_result = $conn->query($centers_query);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Create New Insurance Claim</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $_SESSION['error_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php echo $_SESSION['success_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>

                    <form method="POST" id="insuranceClaimForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="voucher_no" class="form-label">Voucher No *</label>
                                    <input type="text" class="form-control" id="voucher_no" name="voucher_no" 
                                           value="<?php echo htmlspecialchars($voucher_no); ?>" required readonly>
                                    <small class="form-text text-muted">Auto-generated claim reference number</small>
                                </div>

                                <div class="mb-3">
                                    <label for="center_id" class="form-label">Center *</label>
                                    <select class="form-control" id="center_id" name="center_id" required>
                                        <option value="">Select Center</option>
                                        <?php
                                        if ($centers_result && $centers_result->num_rows > 0) {
                                            while ($center = $centers_result->fetch_assoc()) {
                                                $selected = (isset($form_data['center_id']) && $form_data['center_id'] == $center['id']) ? 'selected' : '';
                                                echo "<option value='{$center['id']}' $selected>{$center['name']}</option>";
                                            }
                                        } else {
                                            echo "<option value=''>No centers available</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <!-- Customer Search by NIC -->
                                <div class="mb-3">
                                    <label for="search_nic" class="form-label">Search Customer by NIC *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="search_nic" name="search_nic" 
                                               placeholder="Enter NIC Number" 
                                               value="<?php echo htmlspecialchars($search_nic); ?>" 
                                               required
                                               pattern="[0-9]{9}[xXvV]|[0-9]{12}"
                                               title="Enter valid NIC number (9 digits with letter or 12 digits)">
                                        <button type="submit" name="search_customer" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Search Customer
                                        </button>
                                    </div>
                                    <small class="form-text text-muted">Enter customer NIC number and click Search Customer</small>
                                </div>

                                <?php if ($customer_data): ?>
                                <!-- Customer Details -->
                                <div class="alert alert-success">
                                    <h6><i class="bi bi-person-check-fill"></i> Customer Found:</h6>
                                    <strong>Name:</strong> <?php echo htmlspecialchars($customer_data['full_name']); ?><br>
                                    <strong>NIC:</strong> <?php echo htmlspecialchars($customer_data['nic']); ?><br>
                                    <strong>Phone:</strong> <?php echo htmlspecialchars($customer_data['phone']); ?>
                                </div>

                                <!-- Hidden fields for customer data -->
                                <input type="hidden" name="customer_id" value="<?php echo $customer_data['id']; ?>">
                                <input type="hidden" name="nic" value="<?php echo htmlspecialchars($customer_data['nic']); ?>">
                                <input type="hidden" name="mobile" value="<?php echo htmlspecialchars($customer_data['phone']); ?>">

                                <div class="mb-3">
                                    <label for="loan_id" class="form-label">Select Loan *</label>
                                    <select class="form-control" id="loan_id" name="loan_id" required>
                                        <option value="">Select Loan</option>
                                        <?php foreach ($customer_loans as $loan): 
                                            $selected = (isset($form_data['loan_id']) && $form_data['loan_id'] == $loan['id']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $loan['id']; ?>" 
                                                    data-amount="<?php echo $loan['amount']; ?>"
                                                    data-disbursed="<?php echo $loan['disbursed_date']; ?>"
                                                    data-outstanding="<?php echo $loan['balance'] ? $loan['balance'] : $loan['amount']; ?>"
                                                    <?php echo $selected; ?>>
                                                <?php echo $loan['loan_number'] . ' - Rs. ' . number_format($loan['amount'], 2) . ' (' . $loan['status'] . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($customer_loans)): ?>
                                        <div class="alert alert-warning mt-2">
                                            <i class="bi bi-exclamation-triangle"></i> No active loans found for this customer
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <?php if ($customer_data): ?>
                                <!-- Auto-filled customer details -->
                                <div class="mb-3">
                                    <label class="form-label">Customer Name</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($customer_data['full_name']); ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">NIC *</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($customer_data['nic']); ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Mobile</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($customer_data['phone']); ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label for="loan_disbursement_date" class="form-label">Loan Disbursement Date *</label>
                                    <input type="date" class="form-control" id="loan_disbursement_date" 
                                           name="loan_disbursement_date" required 
                                           value="<?php echo isset($form_data['loan_disbursement_date']) ? $form_data['loan_disbursement_date'] : ''; ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="loan_amount" class="form-label">Loan Amount *</label>
                                    <input type="number" step="0.01" class="form-control" id="loan_amount" 
                                           name="loan_amount" required 
                                           value="<?php echo isset($form_data['loan_amount']) ? $form_data['loan_amount'] : ''; ?>">
                                </div>
                                <?php else: ?>
                                <!-- Placeholder when no customer selected -->
                                <div class="alert alert-info">
                                    <h6><i class="bi bi-info-circle-fill"></i> Instructions:</h6>
                                    <ol>
                                        <li>Select a Center</li>
                                        <li>Enter customer NIC number</li>
                                        <li>Click "Search Customer"</li>
                                        <li>Select a loan from the list</li>
                                        <li>Fill the remaining details</li>
                                    </ol>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($customer_data && !empty($customer_loans)): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="total_outstanding" class="form-label">Total Outstanding *</label>
                                    <input type="number" step="0.01" class="form-control" id="total_outstanding" 
                                           name="total_outstanding" required 
                                           value="<?php echo isset($form_data['total_outstanding']) ? $form_data['total_outstanding'] : ''; ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="arrears_amount" class="form-label">Arrears Amount</label>
                                    <input type="number" step="0.01" class="form-control" id="arrears_amount" 
                                           name="arrears_amount" 
                                           value="<?php echo isset($form_data['arrears_amount']) ? $form_data['arrears_amount'] : '0.00'; ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="info_date" class="form-label">Info Date *</label>
                                    <input type="date" class="form-control" id="info_date" 
                                           name="info_date" required max="<?php echo date('Y-m-d'); ?>"
                                           value="<?php echo isset($form_data['info_date']) ? $form_data['info_date'] : ''; ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="claim_type" class="form-label">Claim Type *</label>
                                    <select class="form-control" id="claim_type" name="claim_type" required>
                                        <option value="">Select Claim Type</option>
                                        <option value="hospitalized" <?php echo (isset($form_data['claim_type']) && $form_data['claim_type'] == 'hospitalized') ? 'selected' : ''; ?>>Hospitalized</option>
                                        <option value="death" <?php echo (isset($form_data['claim_type']) && $form_data['claim_type'] == 'death') ? 'selected' : ''; ?>>Death</option>
                                        <option value="accident" <?php echo (isset($form_data['claim_type']) && $form_data['claim_type'] == 'accident') ? 'selected' : ''; ?>>Accident</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="claim_person" class="form-label">Claim Person</label>
                                    <input type="text" class="form-control" id="claim_person" name="claim_person" 
                                           placeholder="Name of person receiving the claim"
                                           value="<?php echo isset($form_data['claim_person']) ? htmlspecialchars($form_data['claim_person']) : ''; ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="claim_amount" class="form-label">Claim Amount *</label>
                                    <input type="number" step="0.01" class="form-control" id="claim_amount" 
                                           name="claim_amount" required 
                                           value="<?php echo isset($form_data['claim_amount']) ? $form_data['claim_amount'] : '500.00'; ?>">
                                    <small class="form-text text-muted">Enter the claim amount</small>
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic fields based on claim type -->
                        <div id="hospitalized_fields" style="display: <?php echo (isset($form_data['claim_type']) && $form_data['claim_type'] == 'hospitalized') ? 'block' : 'none'; ?>;">
                            <h5><i class="bi bi-hospital"></i> Hospitalization Details</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="admission_date" class="form-label">Admission Date</label>
                                        <input type="date" class="form-control" id="admission_date" name="admission_date" 
                                               max="<?php echo date('Y-m-d'); ?>"
                                               value="<?php echo isset($form_data['admission_date']) ? $form_data['admission_date'] : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="discharge_date" class="form-label">Discharge Date</label>
                                        <input type="date" class="form-control" id="discharge_date" name="discharge_date" 
                                               max="<?php echo date('Y-m-d'); ?>"
                                               value="<?php echo isset($form_data['discharge_date']) ? $form_data['discharge_date'] : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="number_of_days" class="form-label">Number of Days</label>
                                        <input type="number" class="form-control" id="number_of_days" 
                                               name="number_of_days" value="<?php echo isset($form_data['number_of_days']) ? $form_data['number_of_days'] : '0'; ?>" min="0">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="death_fields" style="display: <?php echo (isset($form_data['claim_type']) && $form_data['claim_type'] == 'death') ? 'block' : 'none'; ?>;">
                            <h5><i class="bi bi-flag-fill"></i> Death Claim Details</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="death_date" class="form-label">Death Date *</label>
                                        <input type="date" class="form-control" id="death_date" name="death_date" 
                                               max="<?php echo date('Y-m-d'); ?>"
                                               value="<?php echo isset($form_data['death_date']) ? $form_data['death_date'] : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="post_mortem_date" class="form-label">Post Mortem Date</label>
                                        <input type="date" class="form-control" id="post_mortem_date" name="post_mortem_date" 
                                               max="<?php echo date('Y-m-d'); ?>"
                                               value="<?php echo isset($form_data['post_mortem_date']) ? $form_data['post_mortem_date'] : ''; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label for="relationship" class="form-label">Relationship with Deceased *</label>
                                        <select class="form-control" id="relationship" name="relationship">
                                            <option value="">Select Relationship</option>
                                            <option value="husband" <?php echo (isset($form_data['relationship']) && $form_data['relationship'] == 'husband') ? 'selected' : ''; ?>>Husband</option>
                                            <option value="wife" <?php echo (isset($form_data['relationship']) && $form_data['relationship'] == 'wife') ? 'selected' : ''; ?>>Wife</option>
                                            <option value="son" <?php echo (isset($form_data['relationship']) && $form_data['relationship'] == 'son') ? 'selected' : ''; ?>>Son</option>
                                            <option value="daughter" <?php echo (isset($form_data['relationship']) && $form_data['relationship'] == 'daughter') ? 'selected' : ''; ?>>Daughter</option>
                                            <option value="father" <?php echo (isset($form_data['relationship']) && $form_data['relationship'] == 'father') ? 'selected' : ''; ?>>Father</option>
                                            <option value="mother" <?php echo (isset($form_data['relationship']) && $form_data['relationship'] == 'mother') ? 'selected' : ''; ?>>Mother</option>
                                            <option value="other" <?php echo (isset($form_data['relationship']) && $form_data['relationship'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="claim_approval_days" class="form-label">Claim Approval Days</label>
                                    <input type="number" class="form-control" id="claim_approval_days" 
                                           name="claim_approval_days" value="<?php echo isset($form_data['claim_approval_days']) ? $form_data['claim_approval_days'] : '0'; ?>" min="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="loan_write_off" name="loan_write_off" value="1"
                                           <?php echo (isset($form_data['loan_write_off']) && $form_data['loan_write_off'] == '1') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="loan_write_off">Loan Write Off</label>
                                    <small class="form-text text-muted d-block">Check this if the loan should be written off due to death</small>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" name="create_claim" class="btn btn-success btn-lg">
                                <i class="bi bi-check-circle-fill"></i> Create Insurance Claim
                            </button>
                            <a href="insurance_claims.php" class="btn btn-secondary btn-lg">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                        <?php elseif ($customer_data && empty($customer_loans)): ?>
                        <div class="alert alert-warning">
                            <p><i class="bi bi-exclamation-triangle-fill"></i> This customer has no active loans. Cannot create insurance claim.</p>
                            <a href="insurance_claims.php" class="btn btn-secondary">Back to Claims</a>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <p><i class="bi bi-info-circle-fill"></i> Please search for a customer first to continue with the claim creation.</p>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const loanSelect = document.getElementById('loan_id');
    const loanAmountInput = document.getElementById('loan_amount');
    const loanDisbursementInput = document.getElementById('loan_disbursement_date');
    const totalOutstandingInput = document.getElementById('total_outstanding');
    const claimTypeSelect = document.getElementById('claim_type');
    const hospitalizedFields = document.getElementById('hospitalized_fields');
    const deathFields = document.getElementById('death_fields');
    const admissionDateInput = document.getElementById('admission_date');
    const dischargeDateInput = document.getElementById('discharge_date');
    const numberOfDaysInput = document.getElementById('number_of_days');
    const deathDateInput = document.getElementById('death_date');
    const postMortemDateInput = document.getElementById('post_mortem_date');
    const relationshipSelect = document.getElementById('relationship');
    const loanWriteOffCheckbox = document.getElementById('loan_write_off');

    // Loan change event
    if (loanSelect) {
        loanSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (selectedOption.value) {
                const loanAmount = selectedOption.getAttribute('data-amount');
                const disbursedDate = selectedOption.getAttribute('data-disbursed');
                const outstandingAmount = selectedOption.getAttribute('data-outstanding');
                
                if (loanAmountInput) loanAmountInput.value = loanAmount || '0.00';
                if (loanDisbursementInput) loanDisbursementInput.value = disbursedDate || '';
                if (totalOutstandingInput) totalOutstandingInput.value = outstandingAmount || '0.00';
            }
        });
        
        // Trigger change event on page load if loan is already selected
        if (loanSelect.value) {
            loanSelect.dispatchEvent(new Event('change'));
        }
    }

    // Claim type change event
    if (claimTypeSelect) {
        claimTypeSelect.addEventListener('change', function() {
            // Hide all fields first
            if (hospitalizedFields) hospitalizedFields.style.display = 'none';
            if (deathFields) deathFields.style.display = 'none';
            
            // Show relevant fields
            if (this.value === 'hospitalized') {
                if (hospitalizedFields) hospitalizedFields.style.display = 'block';
            } else if (this.value === 'death') {
                if (deathFields) deathFields.style.display = 'block';
                if (loanWriteOffCheckbox) loanWriteOffCheckbox.checked = true;
            }
        });
        
        // Trigger change event on page load if claim type is already selected
        if (claimTypeSelect.value) {
            claimTypeSelect.dispatchEvent(new Event('change'));
        }
    }

    // Calculate hospitalization days
    if (admissionDateInput && dischargeDateInput) {
        admissionDateInput.addEventListener('change', calculateDays);
        dischargeDateInput.addEventListener('change', calculateDays);
    }

    function calculateDays() {
        if (!admissionDateInput || !dischargeDateInput || !numberOfDaysInput) return;
        
        const admission = new Date(admissionDateInput.value);
        const discharge = new Date(dischargeDateInput.value);
        
        if (admission && discharge && discharge >= admission) {
            const diffTime = Math.abs(discharge - admission);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            numberOfDaysInput.value = diffDays;
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>