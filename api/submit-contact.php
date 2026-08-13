<?php
/**
 * API-прокси: создание контакта в Concord CRM (lk.r33.ru)
 * Токен хранится в .env на сервере, не в коде.
 *
 * POST /api/submit-contact.php
 * Body JSON: { name, phone, city, biz, note, kind: "seller"|"moderator" }
 *
 * Concord API: POST /api/contacts
 * Docs: https://www.concordcrm.com/docs/api
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://r33.ru');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'method']); exit; }

// ── Конфиг из .env.crm (в корне public_html, защищён htaccess) ──
$envFile = dirname(__DIR__, 2) . '/.env.crm';
if (!file_exists($envFile)) {
    // fallback: рядом с html/
    $envFile = dirname(__DIR__) . '/../.env.crm';
}
if (!file_exists($envFile)) { http_response_code(500); echo json_encode(['error'=>'config']); exit; }
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, '#')) continue;
    $pos = strpos($line, '=');
    if ($pos !== false) $env[substr($line, 0, $pos)] = substr($line, $pos + 1);
}
$token = $env['CRM_TOKEN'] ?? '';
$baseUrl = rtrim($env['CRM_URL'] ?? 'https://lk.r33.ru', '/');
if (!$token) { http_response_code(500); echo json_encode(['error'=>'token']); exit; }

// ── Входные данные ──
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['name']) || empty($input['phone'])) {
    http_response_code(422);
    echo json_encode(['error'=>'name and phone required']);
    exit;
}

$name  = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$city  = trim($input['city'] ?? '');
$biz   = trim($input['biz'] ?? '');
$note  = trim($input['note'] ?? '');
$kind  = ($input['kind'] ?? 'seller') === 'moderator' ? 'Модератор' : 'Продавец';

// ── Формируем фамилию: "Иван Продавец" или "Пётр Модератор" ──
$firstName = $name;
$lastName  = $kind;

// ── Тело запроса к Concord CRM ──
$body = [
    'first_name' => $firstName,
    'last_name'  => $lastName,
    'phones'     => [['number' => $phone, 'type' => 'mobile']],
    'source_id'  => 7, // Direct traffic
    'user_id'    => 2, // Ответственный
];

// Город и вид деятельности — как метки (tags)
$tags = [];
if ($city) $tags[] = $city;
if ($biz)  $tags[] = $biz;
if ($tags) $body['tags'] = $tags;

// Заметка — создаётся отдельным запросом после контакта
if ($note) {
    $body['job_title'] = $note; // дубль в поле "Должность" для видимости
}

// ── Запрос к CRM: создание контакта ──
$ch = curl_init("$baseUrl/api/contacts");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $token",
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo json_encode(['error'=>'crm_unreachable', 'detail'=>$err]);
    exit;
}

if ($httpCode >= 200 && $httpCode < 300) {
    $contactData = json_decode($response, true);
    $contactId = $contactData['id'] ?? null;

    // Создаём заметку (Note) отдельным запросом, привязанную к контакту
    if ($note && $contactId) {
        $noteCh = curl_init("$baseUrl/api/notes");
        curl_setopt_array($noteCh, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'body' => $note,
                'via_resource' => 'contacts',
                'via_resource_id' => $contactId,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($noteCh);
        curl_close($noteCh);
    }

    echo json_encode(['ok'=>true, 'crm_id'=>$contactId]);
} else {
    http_response_code(502);
    echo json_encode(['error'=>'crm_error', 'status'=>$httpCode, 'body'=>$response]);
}
