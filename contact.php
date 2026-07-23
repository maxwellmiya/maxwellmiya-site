<?php
/**
 * Self-hosted contact form handler.
 * Requires PHP mail() (standard on cPanel/Exim shared hosting).
 */

declare(strict_types=1);

const EMAIL_TO       = 'maxwell.miya@gmail.com';
const EMAIL_FROM     = 'noreply@maxwellmiya.com'; // must be a maxwellmiya.com address to align with SPF
const MIN_FILL_SECONDS = 3;      // reject submissions faster than this (bots)
const MAX_FILL_SECONDS = 21600;  // reject stale/replayed page loads (6h)
const RATE_LIMIT_MAX    = 5;     // max submissions
const RATE_LIMIT_WINDOW = 3600;  // per this many seconds, per IP
const DATA_DIR = __DIR__ . '/data';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function respond(int $status, bool $ok, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Method not allowed.');
}

// ---------- honeypot ----------
// Hidden field real users never see or fill. Any bot that fills every
// input on the page trips this. Fail silently (fake success) so bots
// don't learn to skip it.
if (!empty($_POST['website'] ?? '')) {
    respond(200, true, 'Message sent.');
}

// ---------- time-trap ----------
// 'loaded_at' is a client-set epoch-ms timestamp from when the form
// rendered. Real humans take seconds to fill a form; most bots submit
// near-instantly or replay an old/no timestamp.
$loadedAt = filter_input(INPUT_POST, 'loaded_at', FILTER_VALIDATE_INT);
if ($loadedAt === null || $loadedAt === false) {
    respond(400, false, 'Invalid submission.');
}
$elapsed = (time() - intdiv((int)$loadedAt, 1000));
if ($elapsed < MIN_FILL_SECONDS || $elapsed > MAX_FILL_SECONDS) {
    respond(400, false, 'Invalid submission.');
}

// ---------- rate limiting (per IP) ----------
function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rateLimited(string $ip): bool {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0700, true);
    }
    $file = DATA_DIR . '/ratelimit.json';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return false; // fail open on storage errors rather than blocking all mail
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        $data = [];
    }
    $now = time();
    $hits = array_filter($data[$ip] ?? [], fn($t) => $now - $t < RATE_LIMIT_WINDOW);
    $limited = count($hits) >= RATE_LIMIT_MAX;
    if (!$limited) {
        $hits[] = $now;
    }
    $data[$ip] = array_values($hits);

    // prune stale IPs so the file doesn't grow unbounded
    foreach ($data as $key => $timestamps) {
        $data[$key] = array_values(array_filter($timestamps, fn($t) => $now - $t < RATE_LIMIT_WINDOW));
        if (empty($data[$key])) {
            unset($data[$key]);
        }
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $limited;
}

if (rateLimited(clientIp())) {
    respond(429, false, 'Too many messages sent. Please try again later.');
}

// ---------- input validation ----------
function cleanText(string $value, int $maxLen): string {
    $value = trim($value);
    $value = str_replace(["\r", "\n"], ' ', $value); // prevent header injection
    return mb_substr($value, 0, $maxLen);
}

$name    = cleanText((string)($_POST['name'] ?? ''), 100);
$email   = cleanText((string)($_POST['email'] ?? ''), 150);
$company = cleanText((string)($_POST['company'] ?? ''), 100);
$message = trim((string)($_POST['message'] ?? ''));
$message = mb_substr($message, 0, 5000);

if ($name === '' || $email === '' || $message === '') {
    respond(400, false, 'Please fill in all required fields.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, false, 'Please provide a valid email address.');
}

// crude link-spam heuristic
if (preg_match_all('#https?://#i', $message) > 2) {
    respond(400, false, 'Message rejected. Please remove links and try again.');
}

// ---------- send ----------
$subject = '=?UTF-8?B?' . base64_encode('Website contact: ' . $name) . '?=';

$bodyLines = [
    'Name: ' . $name,
    'Email: ' . $email,
];
if ($company !== '') {
    $bodyLines[] = 'Company: ' . $company;
}
$bodyLines[] = '';
$bodyLines[] = $message;
$body = implode("\r\n", $bodyLines);

$headers = [
    'From: Maxwell Miya Website <' . EMAIL_FROM . '>',
    'Reply-To: ' . $name . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
];

$sent = @mail(EMAIL_TO, $subject, $body, implode("\r\n", $headers));

if (!$sent) {
    error_log('contact.php: mail() failed sending message from ' . $email);
    respond(500, false, 'Message could not be sent. Please email ' . EMAIL_TO . ' directly.');
}

respond(200, true, 'Message sent. I will get back to you within a couple of days.');
