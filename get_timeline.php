<?php
/**
 * API to get the busy timeline (calendar events) for a specific user in Bitrix24
 * Defaults to nady@keenenter.com (ID 1)
 */

$webhookUrl = 'https://keenenter.bitrix24.com/rest/807/mpb6cgx0wktban69/';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Read input (JSON or Query String)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_GET;
}

// Target user is nady@keenenter.com (ID 1) - can be overridden by input but defaults to 1
$ownerId = intval($input['ownerId'] ?? 1); 
$targetDate = $input['date'] ?? date('Y-m-d');

// Helper to call Bitrix API
function callBitrix24($method, $data)
{
    global $webhookUrl;
    $url = $webhookUrl . $method . '.json';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => $error];
    return json_decode($response, true);
}

// Get events for the given date range
$getEventsData = [
    'type' => 'user',
    'ownerId' => $ownerId,
    'from' => $targetDate . 'T00:00:00+03:00',
    'to' => $targetDate . 'T23:59:59+03:00',
];

$eventsResponse = callBitrix24('calendar.event.get', $getEventsData);

$timeline = [];

if (isset($eventsResponse['result']) && is_array($eventsResponse['result'])) {
    foreach ($eventsResponse['result'] as $event) {
        $timeline[] = [
            'id' => $event['ID'],
            'name' => $event['NAME'],
            'start' => $event['DATE_FROM'],
            'end' => $event['DATE_TO'],
            'accessibility' => $event['ACCESSIBILITY'] ?? 'busy',
            'importance' => $event['IMPORTANCE'] ?? 'normal',
            'location' => $event['LOCATION'] ?? '',
            'description' => strip_tags($event['DESCRIPTION'] ?? '')
        ];
    }
}

// Sort timeline by start time
usort($timeline, function($a, $b) {
    return strtotime($a['start']) <=> strtotime($b['start']);
});

echo json_encode([
    'status' => 'success',
    'user_id' => $ownerId,
    'date' => $targetDate,
    'timeline' => $timeline,
    'count' => count($timeline)
]);

?>
