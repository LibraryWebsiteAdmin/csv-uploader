<?php
header('Access-Control-Allow-Origin: https://tech-dragoness.github.io');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Start output buffering to prevent stray output
ob_start();

// URL of the GoDaddy proxy
$proxy_url = 'https://sophiacollegelibrary.in/api/db_proxy.php';
$api_key = 'zX9mPqW3vL8nR5tY2uJ6hF4dS7gB!kM2wQ5eT8yI1oP4aL7xC0vN3b3I7'; // Same API key as in db_proxy.php

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

// Handle POST request for combined CSV processing and backup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['table']) || !isset($_FILES['csvFile'])) {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid request!']);
        exit;
    }

    // Validate file
    $file = $_FILES['csvFile'];
    $tmpFilePath = $file['tmp_name'];
    if ($file['error'] == UPLOAD_ERR_NO_FILE) {
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'No file uploaded!']);
        exit;
    }
    if ($file['type'] !== 'text/csv' && !str_ends_with(strtolower($file['name']), '.csv')) {
        unlink($tmpFilePath);
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed!']);
        exit;
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        unlink($tmpFilePath);
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'File size exceeds 3MB limit!']);
        exit;
    }

    // Forward the combined request to db_proxy.php
    $url = $proxy_url . '?action=process_and_backup&api_key=' . urlencode($api_key);
    $post_data = [
        'table' => $_POST['table'],
        'csvFile' => new CURLFile($tmpFilePath, 'text/csv', $file['name'])
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    if ($response === false) {
        unlink($tmpFilePath);
        header('Content-Type: application/json; charset=UTF-8');
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to process CSV via proxy: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    // Delete the temporary file
    unlink($tmpFilePath);

    // Send the response (JSON containing CSV result and backup)
    header('Content-Type: application/json; charset=UTF-8');
    header('Connection: close'); // Help with HTTP/2 issues
    ob_clean();
    echo $response;
    exit;
}
?>
