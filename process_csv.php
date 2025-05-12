<?php
header('Access-Control-Allow-Origin: https://csv-uploader-1t0d.onrender.com'); // Updated to match your site
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Start output buffering to prevent stray output
ob_start();

// URL of the GoDaddy proxy
$proxy_url = 'https://sophiacollegelibrary.in/api/db_proxy.php';
$api_key = 'zX9mPqW3vL8nR5tY2uJ5%RT&l7gB!kM2wQ5eT8yI1oP4aL7xC0vN3b3I7'; // Same API key as in db_proxy.php

// Hashed password for secure login (replace with your generated hash)
$hashedPassword = '$2b$12$n7K3E8I72Drpc7GSV69Sae.Y2DkqU5oDfWC1HQwx.l5MQTzQa5luu';

// Handle POST request for login (new endpoint for password verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'login') {
    // Get the password from the request
    $data = json_decode(file_get_contents('php://input'), true);
    $password = isset($data['password']) ? $data['password'] : '';

    if (empty($password)) {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        exit;
    }

    // Verify the password against the stored hash
    if (password_verify($password, $hashedPassword)) {
        // Generate a simple session token
        $token = bin2hex(random_bytes(16)); // Random 32-character token
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => true, 'token' => $token]);
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Incorrect password']);
    }
    exit;
}

// Handle GET request for table names
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_tables') {
    $url = $proxy_url . '?action=get_tables&api_key=' . urlencode($api_key);
    $response = file_get_contents($url);
    if ($response === false) {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to fetch tables from proxy']);
        exit;
    }
    header('Content-Type: application/json; charset=UTF-8');
    ob_clean();
    echo $response;
    exit;
}

// Handle GET request for table backup (legacy, in case old frontend code still uses it)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'backup_table' && isset($_GET['table'])) {
    $table = $_GET['table'];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid table name!']);
        exit;
    }

    // Forward the backup request to db_proxy.php
    $url = $proxy_url . '?action=backup_table&table=' . urlencode($table) . '&api_key=' . urlencode($api_key);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to fetch backup from proxy']);
        exit;
    }

    // Forward the SQL file to the client
    if (strpos($content_type, 'application/octet-stream') !== false) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $table . '_backup_' . date('YmdHis') . '.sql"');
        header('Content-Length: ' . strlen($response));
        ob_clean();
        echo $response;
        exit;
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo $response; // Likely a JSON error message from db_proxy.php
        exit;
    }
}

// Handle POST request for combined CSV processing and backup with SSE for percentage bar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set SSE headers to enable streaming for the percentage bar
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    ob_clean(); // Clear any stray output

    // Function to send SSE error message and exit
    function sendSSEError($message) {
        echo "event: error\n";
        echo "data: " . json_encode(['success' => false, 'message' => $message]) . "\n\n";
        ob_flush();
        flush();
        exit;
    }

    if (!isset($_POST['table']) || !isset($_FILES['csvFile'])) {
        sendSSEError('Invalid request!');
    }

    // Validate file
    $file = $_FILES['csvFile'];
    $tmpFilePath = $file['tmp_name'];
    if ($file['error'] == UPLOAD_ERR_NO_FILE) {
        sendSSEError('No file uploaded!');
    }
    if ($file['type'] !== 'text/csv' && !str_ends_with(strtolower($file['name']), '.csv')) {
        unlink($tmpFilePath);
        sendSSEError('Only CSV files are allowed!');
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        unlink($tmpFilePath);
        sendSSEError('File size exceeds 3MB limit!');
    }

    // Forward the combined request to db_proxy.php with SSE streaming
    $url = $proxy_url . '?action=process_and_backup&api_key=' . urlencode($api_key);
    $post_data = [
        'table' => $_POST['table'],
        'csvFile' => new CURLFile($tmpFilePath, 'text/csv', $file['name'])
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Stream the response directly
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        // Stream the SSE data directly to the client for percentage updates
        echo $data;
        ob_flush();
        flush();
        return strlen($data);
    });
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 128); // Small buffer to ensure streaming
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Long timeout for large CSVs

    $success = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    // Delete the temporary file
    unlink($tmpFilePath);

    if (!$success) {
        sendSSEError('Failed to process CSV via proxy: ' . $error);
    }

    exit;
}
?>
