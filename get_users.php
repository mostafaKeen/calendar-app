<?php

/**
 * Script to fetch users from Bitrix24.
 * Useful for frontend to populate user selection dropdown.
 */

$webhookUrl = 'https://keenenter.bitrix24.com/rest/807/mpb6cgx0wktban69/';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function callBitrix24($method, $data = []) {
    global $webhookUrl;
    $url = $webhookUrl . $method . '.json';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return null;
    return json_decode($response, true);
}

$response = callBitrix24('user.get', ['ACTIVE' => true]);

if (isset($response['result'])) {
    $filteredUsers = [];
    foreach ($response['result'] as $user) {
        $filteredUsers[] = [
            'id' => $user['ID'],
            'name' => trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? '')),
            'email' => $user['EMAIL'] ?? '',
            'work_position' => $user['WORK_POSITION'] ?? ''
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'users' => $filteredUsers
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch users',
        'details' => $response
    ]);
}

?>
