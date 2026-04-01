<?php
/**
 * API to get available time slots for a specific user in Bitrix24
 * Accepts POST JSON payload in "tool-calls" format
 */

$webhookUrl = 'https://keenenter.bitrix24.com/rest/807/mpb6cgx0wktban69/';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
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

/**
 * Core logic to get free slots
 */
function handle_get_free_slots($args)
{
    // Hardcode ownerId to 1 (nady@keenenter.com)
    $ownerId = 1;
    $targetDate = $args['date'] ?? date('Y-m-d');
    $durationMinutes = intval($args['durationMinutes'] ?? 30);
    $startHour = intval($args['startHour'] ?? 9);
    $startMinute = intval($args['startMinute'] ?? 0);
    $rangeMinutes = intval($args['rangeMinutes'] ?? 480);

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

    // 2️⃣ Calculate available slots
    $startOfSearch = strtotime("$targetDate $startHour:$startMinute:00");
    $endOfSearch = $startOfSearch + ($rangeMinutes * 60);

    $currentTime = $startOfSearch;
    $increment = 15 * 60; 
    $availableSlots = [];

    while ($currentTime + ($durationMinutes * 60) <= $endOfSearch) {
        $slotEnd = $currentTime + ($durationMinutes * 60);
        $isFree = true;

        foreach ($busySlots as $busy) {
            if ($currentTime < $busy['end'] && $slotEnd > $busy['start']) {
                $isFree = false;
                break;
            }
        }

        if ($isFree) {
            $availableSlots[] = [
                'start' => date('Y-m-d\TH:i:s+03:00', $currentTime),
                'end' => date('Y-m-d\TH:i:s+03:00', $slotEnd)
            ];
        }

        $currentTime += $increment;
    }

    return [
        'status' => 'success',
        'available_slots' => $availableSlots,
        'count' => count($availableSlots)
    ];
}

// Read JSON input
$rawInput = json_decode(file_get_contents('php://input'), true);
$results = [];

$toolCallList = $rawInput['message']['toolCallList'] ?? [];

foreach ($toolCallList as $toolCall) {
    if (($toolCall['function']['name'] ?? '') === 'get_free_slots') {
        $result = handle_get_free_slots($toolCall['function']['arguments'] ?? []);
        $results[] = [
            'toolCallId' => $toolCall['id'],
            'result' => $result
        ];
    }
}

echo json_encode(['results' => $results]);