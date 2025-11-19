<?php
// includes/sms_functions.php

// SMS Functions for TEXT.LK API

function sendSMS($phone, $message) {
    if (!SMS_ENABLED) {
        return ['success' => false, 'message' => 'SMS service is disabled'];
    }
    
    // Clean phone number
    $phone = cleanPhoneNumber($phone);
    
    if (empty($phone) || strlen($phone) != 12) {
        return ['success' => false, 'message' => 'Invalid phone number format: ' . $phone];
    }
    
    // Try multiple API endpoints and methods with different parameters
    $results = [];
    
    // Method 1: OAuth API with 'recipient' parameter
    $results[] = sendViaOAuthWithRecipient($phone, $message);
    
    // Method 2: OAuth API with 'to' parameter  
    $results[] = sendViaOAuthWithTo($phone, $message);
    
    // Method 3: HTTP API
    $results[] = sendViaHTTPAPI($phone, $message);
    
    // Return first successful result
    foreach ($results as $result) {
        if ($result['success']) {
            return $result;
        }
    }
    
    // If all failed, return the last error
    return end($results);
}

function cleanPhoneNumber($phone) {
    // Remove spaces, dashes, and non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Convert to international format (+947XXXXXXXX)
    if (substr($phone, 0, 2) == '07') {
        // 07XXXXXXXX -> +947XXXXXXXX
        $phone = '+94' . substr($phone, 1);
    } elseif (substr($phone, 0, 1) == '7') {
        // 7XXXXXXXX -> +947XXXXXXXX
        $phone = '+94' . $phone;
    } elseif (substr($phone, 0, 2) == '94') {
        // 94XXXXXXXX -> +947XXXXXXXX
        $phone = '+' . $phone;
    } elseif (substr($phone, 0, 3) == '+94') {
        // Already in correct format
        $phone = $phone;
    } else {
        // Unknown format
        return '';
    }
    
    return $phone;
}

function sendViaOAuthWithRecipient($phone, $message) {
    // OAuth API with 'recipient' parameter
    $url = 'https://app.text.lk/api/v3/sms/send';
    
    $data = [
        'recipient' => $phone,  // Use 'recipient' as per error message
        'message' => $message,
        'sender_id' => SMS_SENDER_ID
    ];
    
    $headers = [
        'Authorization: Bearer ' . SMS_API_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    return makeAPIRequest($url, $data, $headers, 'POST', $phone, $message, 'OAuth-recipient');
}

function sendViaOAuthWithTo($phone, $message) {
    // OAuth API with 'to' parameter
    $url = 'https://app.text.lk/api/v3/sms/send';
    
    $data = [
        'to' => $phone,  // Try 'to' parameter
        'message' => $message,
        'sender_id' => SMS_SENDER_ID
    ];
    
    $headers = [
        'Authorization: Bearer ' . SMS_API_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    return makeAPIRequest($url, $data, $headers, 'POST', $phone, $message, 'OAuth-to');
}

function sendViaHTTPAPI($phone, $message) {
    // Traditional HTTP API
    $url = 'https://app.text.lk/api/http/sms/send';
    
    $data = [
        'token' => SMS_API_TOKEN,
        'to' => $phone,
        'message' => $message,
        'from' => SMS_SENDER_ID
    ];
    
    return makeAPIRequest($url, $data, [], 'POST', $phone, $message, 'HTTP');
}

function makeAPIRequest($url, $data, $headers = [], $method = 'POST', $phone = null, $message = null, $method_name = 'Unknown') {
    if (SMS_TEST_MODE) {
        // Test mode - log without sending actual SMS
        $log_id = logSMSMessage($phone, $message, 'TEST MODE - ' . $method_name);
        return [
            'success' => true, 
            'message' => 'SMS sent (TEST MODE)', 
            'test_data' => $data,
            'log_id' => $log_id,
            'method' => $method_name
        ];
    }
    
    $ch = curl_init();
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        
        if (in_array('Content-Type: application/json', $headers)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    } else {
        // GET request
        $url_with_params = $url . '?' . http_build_query($data);
        curl_setopt($ch, CURLOPT_URL, $url_with_params);
        curl_setopt($ch, CURLOPT_POST, false);
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MicroFinance System/1.0');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log the API response
    $log_id = null;
    if ($phone !== null && $message !== null) {
        $log_id = logSMSMessage($phone, $message, 'API_CALL: ' . $http_code . ' - ' . $method_name);
    }
    
    // Enhanced debug logging
    error_log("=== SMS API DEBUG [" . $method_name . "] ===");
    error_log("URL: " . $url);
    error_log("Method: " . $method);
    error_log("Headers: " . json_encode($headers));
    error_log("Data: " . json_encode($data));
    error_log("HTTP Code: " . $http_code);
    error_log("Response: " . $response);
    error_log("cURL Error: " . $curl_error);
    error_log("====================");
    
    // Response Handling
    if ($http_code == 200) {
        $response_data = json_decode($response, true);
        
        // Check for TEXT.LK success format
        if (isset($response_data['status']) && $response_data['status'] === 'success') {
            if ($log_id) updateSMSLog($log_id, 'SUCCESS - ' . $method_name, $response);
            return [
                'success' => true, 
                'message' => 'SMS sent successfully via ' . $method_name,
                'response' => $response_data,
                'log_id' => $log_id,
                'method' => $method_name
            ];
        } elseif (isset($response_data['success']) && $response_data['success'] === true) {
            if ($log_id) updateSMSLog($log_id, 'SUCCESS - ' . $method_name, $response);
            return [
                'success' => true, 
                'message' => 'SMS sent successfully via ' . $method_name,
                'response' => $response_data,
                'log_id' => $log_id,
                'method' => $method_name
            ];
        } else {
            $error_message = 'Unknown success response';
            if (isset($response_data['message'])) {
                $error_message = $response_data['message'];
            }
            
            if ($log_id) updateSMSLog($log_id, 'FAILED: ' . $error_message . ' - ' . $method_name, $response);
            return [
                'success' => false, 
                'message' => 'SMS sending failed via ' . $method_name . ': ' . $error_message,
                'response' => $response_data,
                'log_id' => $log_id,
                'method' => $method_name
            ];
        }
    } elseif ($http_code == 404) {
        if ($log_id) updateSMSLog($log_id, 'FAILED: Endpoint not found (404) - ' . $method_name, $response);
        return [
            'success' => false, 
            'message' => 'API endpoint not found (404) via ' . $method_name,
            'response' => $response,
            'log_id' => $log_id,
            'method' => $method_name
        ];
    } else {
        if ($log_id) updateSMSLog($log_id, 'FAILED: HTTP ' . $http_code . ' - ' . $method_name, $curl_error . ' | Response: ' . $response);
        return [
            'success' => false, 
            'message' => 'HTTP ' . $http_code . ' via ' . $method_name . ' - ' . $curl_error,
            'response' => $response,
            'log_id' => $log_id,
            'method' => $method_name
        ];
    }
}

function logSMSMessage($phone, $message, $status = 'SENT') {
    global $conn;
    
    if ($phone === null) $phone = 'N/A';
    if ($message === null) $message = 'Balance Check or Status Check';
    
    // Ensure database connection exists
    if (!$conn || $conn->connect_error) {
        error_log("Database connection error - cannot log SMS");
        return null;
    }
    
    $sql = "INSERT INTO sms_logs (phone_number, message, status, sent_at) 
            VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Failed to prepare SMS log statement: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("sss", $phone, $message, $status);
    
    if ($stmt->execute()) {
        return $stmt->insert_id;
    } else {
        error_log("Failed to log SMS: " . $stmt->error);
        return null;
    }
}

function updateSMSLog($log_id, $status, $response_text = null) {
    global $conn;
    
    if ($log_id === null) return;
    
    // Ensure database connection exists
    if (!$conn || $conn->connect_error) {
        error_log("Database connection error - cannot update SMS log");
        return;
    }
    
    $sql = "UPDATE sms_logs SET status = ?, response_text = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Failed to prepare SMS update statement: " . $conn->error);
        return;
    }
    
    // Truncate response text if too long for database
    if ($response_text && strlen($response_text) > 1000) {
        $response_text = substr($response_text, 0, 1000) . '... [truncated]';
    }
    
    $stmt->bind_param("ssi", $status, $response_text, $log_id);
    $stmt->execute();
}

function checkSMSBalance() {
    if (SMS_TEST_MODE) {
        return ['success' => true, 'balance' => 'TEST MODE - Balance check disabled'];
    }
    
    // Try profile endpoint for balance info
    $url = 'https://app.text.lk/api/v3/profile';
    $headers = [
        'Authorization: Bearer ' . SMS_API_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $response_data = json_decode($response, true);
        
        if (isset($response_data['data']['balance'])) {
            return ['success' => true, 'balance' => $response_data['data']['balance']];
        } else {
            return ['success' => true, 'balance' => 'Check TEXT.LK dashboard for balance'];
        }
    }
    
    return [
        'success' => false, 
        'message' => 'Unable to check balance',
        'balance' => 'Unknown'
    ];
}

// Bulk SMS function
function sendBulkSMS($phones, $message) {
    $results = [];
    
    foreach ($phones as $phone) {
        $result = sendSMS($phone, $message);
        $results[] = [
            'phone' => $phone,
            'success' => $result['success'],
            'message' => $result['message']
        ];
        usleep(500000); // 0.5 second delay between messages
    }
    
    return $results;
}

// Message validation
function validateMessageLength($message) {
    $length = strlen($message);
    $max_length = 612; // TEXT.LK might have different limits
    
    if ($length > $max_length) {
        return [
            'valid' => false,
            'message' => "Message too long: {$length}/{$max_length} characters",
            'length' => $length,
            'max_length' => $max_length
        ];
    }
    
    return [
        'valid' => true,
        'length' => $length,
        'max_length' => $max_length
    ];
}

// SMS statistics
function getSMSStatistics($period = '30 days') {
    global $conn;
    
    // Ensure database connection exists
    if (!$conn || $conn->connect_error) {
        return [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'test' => 0
        ];
    }
    
    $date_condition = "";
    if ($period == 'today') {
        $date_condition = " AND DATE(sent_at) = CURDATE()";
    } elseif ($period == '7 days') {
        $date_condition = " AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($period == '30 days') {
        $date_condition = " AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    $sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'SUCCESS' THEN 1 ELSE 0 END) as successful,
        SUM(CASE WHEN status LIKE 'FAILED%' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'TEST MODE' THEN 1 ELSE 0 END) as test
        FROM sms_logs 
        WHERE 1=1 {$date_condition}";
    
    $result = $conn->query($sql);
    if ($result) {
        return $result->fetch_assoc();
    } else {
        return [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'test' => 0
        ];
    }
}

// Resend failed SMS
function resendFailedSMS($log_id) {
    global $conn;
    
    // Ensure database connection exists
    if (!$conn || $conn->connect_error) {
        return ['success' => false, 'message' => 'Database connection error'];
    }
    
    $sql = "SELECT phone_number, message FROM sms_logs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return ['success' => false, 'message' => 'Failed to prepare statement'];
    }
    
    $stmt->bind_param("i", $log_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $sms = $result->fetch_assoc();
    
    if (!$sms) {
        return ['success' => false, 'message' => 'SMS log not found'];
    }
    
    $result = sendSMS($sms['phone_number'], $sms['message']);
    
    if ($result['success']) {
        $update_sql = "UPDATE sms_logs SET status = 'RESENT_SUCCESS' WHERE id = ?";
    } else {
        $update_sql = "UPDATE sms_logs SET status = 'RESENT_FAILED' WHERE id = ?";
    }
    
    $update_stmt = $conn->prepare($update_sql);
    if ($update_stmt) {
        $update_stmt->bind_param("i", $log_id);
        $update_stmt->execute();
    }
    
    return $result;
}

// අවසානයේ ? > tag එක තබන්න එපා!