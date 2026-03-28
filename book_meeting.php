<?php
/**
 * Script to automatically find a free slot and book a meeting in Bitrix24
 * Supports start time and search range in minutes
 * Accepts POST JSON payload
 */

$webhookUrl = 'https://keenenter.bitrix24.com/rest/807/mpb6cgx0wktban69/';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Default test values if no input
if (!$input) {
    $input = [
        'ownerId' => '123',
        'date' => date('Y-m-d'),
        'durationMinutes' => 30,
        'startHour' => 9,
        'startMinute' => 0,
        'rangeMinutes' => 480, // default 8 hours
        'name' => 'Auto-booked Meeting',
        'is_meeting' => 'Y',
        'importance' => 'normal',
    ];
}

// Validate input
$ownerId = isset($input['ownerId']) ? intval($input['ownerId']) : null;
$targetDate = isset($input['date']) ? $input['date'] : date('Y-m-d');
$durationMinutes = isset($input['durationMinutes']) ? intval($input['durationMinutes']) : 30;
$startHour = isset($input['startHour']) ? intval($input['startHour']) : 9;
$startMinute = isset($input['startMinute']) ? intval($input['startMinute']) : 0;
$rangeMinutes = isset($input['rangeMinutes']) ? intval($input['rangeMinutes']) : 480; // default 8 hours

if (!$ownerId || !$targetDate || !$durationMinutes) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid input parameters',
    ]);
    exit;
}

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

// 1️⃣ Get busy slots
$getEventsData = [
    'type' => 'user',
    'ownerId' => $ownerId,
    'from' => $targetDate . 'T00:00:00+03:00',
    'to' => $targetDate . 'T23:59:59+03:00',
];

$eventsResponse = callBitrix24('calendar.event.get', $getEventsData);

$busySlots = [];

if (isset($eventsResponse['result']) && is_array($eventsResponse['result'])) {
    foreach ($eventsResponse['result'] as $event) {
        $busySlots[] = [
            'start' => strtotime($event['DATE_FROM']),
            'end' => strtotime($event['DATE_TO']),
        ];
    }
}

// Sort busy slots
usort($busySlots, fn($a, $b) => $a['start'] <=> $b['start']);

// 2️⃣ Calculate free slots
$startOfSearch = strtotime("$targetDate $startHour:$startMinute:00");
$endOfSearch = $startOfSearch + ($rangeMinutes * 60);

$currentTime = $startOfSearch;
$foundSlot = null;

// Check every 15 min interval
$increment = 15 * 60;

while ($currentTime + ($durationMinutes * 60) <= $endOfSearch) {
    $slotEnd = $currentTime + ($durationMinutes * 60);
    $isFree = true;

    foreach ($busySlots as $busy) {
        // Partial overlap check
        if ($currentTime < $busy['end'] && $slotEnd > $busy['start']) {
            $isFree = false;
            break;
        }
    }

    if ($isFree) {
        $foundSlot = [
            'start' => $currentTime,
            'end' => $slotEnd
        ];
        break;
    }

    $currentTime += $increment;
}

if (!$foundSlot) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Not Available',
        'details' => 'No free slots found within the specified range.'
    ]);
    exit;
}

// 3️⃣ Book the slot
$bookDateFrom = date('Y-m-d\TH:i:s+03:00', $foundSlot['start']);
$bookDateTo = date('Y-m-d\TH:i:s+03:00', $foundSlot['end']);

$bookData = [
    'type' => 'user',
    'ownerId' => $ownerId,
    'name' => $input['name'] ?? 'Meeting Booking',
    'from' => $bookDateFrom,
    'to' => $bookDateTo,
    'skip_time' => 'N',
    'accessibility' => $input['accessibility'] ?? 'busy',
    'importance' => $input['importance'] ?? 'normal',
    'private_event' => $input['private_event'] ?? 'N',
];

// Optional fields
foreach (['description', 'color', 'text_color', 'location'] as $field) {
    if (!empty($input[$field])) $bookData[$field] = $input[$field];
}

// Meetings and attendees
if (!empty($input['is_meeting']) && $input['is_meeting'] === 'Y') {
    $bookData['is_meeting'] = 'Y';
    $bookData['host'] = $input['host'] ?? $ownerId;
    if (!empty($input['attendees'])) $bookData['attendees'] = $input['attendees'];
    $bookData['meeting'] = [
        'notify' => true,
        'reinvite' => false,
        'allow_invite' => false,
        'hide_guests' => false
    ];
}

// Default reminder
$bookData['remind'] = $input['remind'] ?? [['type' => 'min', 'count' => 15]];

// Call API to book
$bookResponse = callBitrix24('calendar.event.add', $bookData);

// Return response
if (isset($bookResponse['result']) && !empty($bookResponse['result'])) {
    echo json_encode([
        'status' => 'success',
        'message' => 'Meeting booked successfully',
        'event_id' => $bookResponse['result'],
        'slot' => ['start' => $bookDateFrom, 'end' => $bookDateTo],
        'api_response' => $bookResponse
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Not Available',
        'details' => $bookResponse['error_description'] ?? 'Failed to book slot',
        'api_response' => $bookResponse
    ]);
}

?>