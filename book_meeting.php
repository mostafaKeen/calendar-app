<?php
/**
 * Script to automatically find a free slot and book a meeting in Bitrix24
 * Supports start time and search range in minutes
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
 * Core logic to book a meeting
 */
function handle_book_meeting($args)
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

    // 2️⃣ Calculate free slots
    $startOfSearch = strtotime("$targetDate $startHour:$startMinute:00");
    $endOfSearch = $startOfSearch + ($rangeMinutes * 60);

    $currentTime = $startOfSearch;
    $foundSlot = null;
    $increment = 15 * 60;

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
            $foundSlot = ['start' => $currentTime, 'end' => $slotEnd];
            break;
        }
        $currentTime += $increment;
    }

    if (!$foundSlot) {
        return [
            'status' => 'error',
            'message' => 'Not Available',
            'details' => 'No free slots found within the specified range.'
        ];
    }

    // 3️⃣ Book the slot
    $bookDateFrom = date('Y-m-d\TH:i:s+03:00', $foundSlot['start']);
    $bookDateTo = date('Y-m-d\TH:i:s+03:00', $foundSlot['end']);

    $bookData = [
        'type' => 'user',
        'ownerId' => $ownerId,
        'name' => $args['name'] ?? 'Meeting Booking',
        'from' => $bookDateFrom,
        'to' => $bookDateTo,
        'skip_time' => 'N',
        'accessibility' => $args['accessibility'] ?? 'busy',
        'importance' => $args['importance'] ?? 'normal',
        'private_event' => $args['private_event'] ?? 'N',
    ];

    // Optional fields
    foreach (['description', 'color', 'text_color', 'location'] as $field) {
        if (!empty($args[$field])) $bookData[$field] = $args[$field];
    }

    // Meetings and attendees
    if (!empty($args['is_meeting']) && $args['is_meeting'] === 'Y') {
        $bookData['is_meeting'] = 'Y';
        $bookData['host'] = $ownerId;
        if (!empty($args['attendees'])) $bookData['attendees'] = $args['attendees'];
        $bookData['meeting'] = [
            'notify' => true,
            'reinvite' => false,
            'allow_invite' => false,
            'hide_guests' => false
        ];
    }

    $bookData['remind'] = $args['remind'] ?? [['type' => 'min', 'count' => 15]];

    $bookResponse = callBitrix24('calendar.event.add', $bookData);

    if (isset($bookResponse['result']) && !empty($bookResponse['result'])) {
        return [
            'status' => 'success',
            'event_id' => $bookResponse['result'],
            'slot' => ['start' => $bookDateFrom, 'end' => $bookDateTo],
            'message' => 'Meeting booked successfully'
        ];
    } else {
        return [
            'status' => 'error',
            'message' => 'Failed to book slot',
            'details' => $bookResponse['error_description'] ?? 'Bitrix24 API Error'
        ];
    }
}

// Read JSON input
$rawInput = json_decode(file_get_contents('php://input'), true);
$results = [];

$toolCallList = $rawInput['message']['toolCallList'] ?? [];

// If no input or not in tool-calls format, use default for testing if needed
// However, the request specifies tool-calls only.
foreach ($toolCallList as $toolCall) {
    if (($toolCall['function']['name'] ?? '') === 'book_bitrix_meeting') {
        $result = handle_book_meeting($toolCall['function']['arguments'] ?? []);
        $results[] = [
            'toolCallId' => $toolCall['id'],
            'result' => $result
        ];
    }
}

echo json_encode(['results' => $results]);