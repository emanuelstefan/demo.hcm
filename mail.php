<?php
// Primește formularul de pre-rezervare de pe /contact/ și îl trimite prin SMTP la adresa hotelului.
// Datele SMTP nu sunt în git: stau într-un fișier pe server, cu un nivel deasupra folderului
// site-ului, deci nu pot fi deschise din browser. Vezi docs/formular-pre-rezervare.md.
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false]));
}

// Câmp ascuns: doar roboții îl completează
if (!empty($_POST['botcheck'])) {
    exit(json_encode(['success' => true]));
}

$field = function ($name, $max) {
    $value = trim(strip_tags((string) ($_POST[$name] ?? '')));
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
};

$lastName  = $field('lastName', 100);
$firstName = $field('firstName', 100);
$phone     = $field('phone', 30);
$room      = $field('room', 30);
$startDate = $field('startDate', 20);
$endDate   = $field('endDate', 20);
$notes     = $field('notes', 2000);
$email     = trim((string) ($_POST['email'] ?? ''));

$emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

if (!$lastName || !$firstName || !$phone || empty($_POST['consent']) || ($email !== '' && !$emailValid)) {
    http_response_code(400);
    exit(json_encode(['success' => false]));
}

$configFile = dirname(__DIR__) . '/hotelcodrumoma-mail-config.php';
$config     = is_readable($configFile) ? require $configFile : null;

if (!is_array($config) || empty($config['host']) || empty($config['user']) || empty($config['pass'])) {
    error_log('mail.php: missing or invalid ' . $configFile);
    http_response_code(500);
    exit(json_encode(['success' => false]));
}

$from = $config['user'];
$to   = $config['to'] ?? 'rezervari@hotelcodrumoma.ro';
$port = $config['port'] ?? 465;

$e = function ($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
$name = $firstName . ' ' . $lastName;
$rows = [
    'Nume'     => $e($name),
    'Telefon'  => $e($phone),
    'E-mail'   => $emailValid ? '<a href="mailto:' . $e($email) . '">' . $e($email) . '</a>' : '-',
    'Cameră'   => $e($room),
    'Sosire'   => $e($startDate) ?: '-',
    'Plecare'  => $e($endDate) ?: '-',
    'Acord GDPR' => 'Da',
];
$table = '';
foreach ($rows as $label => $value) {
    $table .= '<tr><td style="padding:6px 16px 6px 0;font-weight:bold">' . $label . ':</td><td>' . $value . '</td></tr>';
}
$safeNotes = nl2br($e($notes));
$html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:20px">
  <h2 style="color:#185253">Cerere de pre-rezervare de pe hotelcodrumoma.ro</h2>
  <table style="border-collapse:collapse">$table</table>
  <h3 style="color:#185253;margin-top:24px">Observații</h3>
  <div style="background:#f2fafa;padding:16px;border-left:4px solid #30a2a7">$safeNotes</div>
  <p style="font-size:12px;color:#777;margin-top:24px">Mesajul a fost generat din datele completate în formular. Nu accesați linkuri neașteptate din textul lui.</p>
</body></html>
HTML;

$ok   = false;
$sock = @stream_socket_client('ssl://' . $config['host'] . ':' . $port, $errno, $errstr, 15);

if ($sock) {
    $read = function () use ($sock) {
        $last = '';
        while (($line = fgets($sock, 512)) !== false) {
            $last = $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $last;
    };
    $write = function ($line) use ($sock) {
        fwrite($sock, $line . "\r\n");
    };
    $encode = function ($text) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    };

    $read();
    $write('EHLO hotelcodrumoma.ro');
    $read();
    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($config['user']));
    $read();
    $write(base64_encode($config['pass']));

    if (strpos($read(), '235') === 0) {
        $write('MAIL FROM:<' . $from . '>');
        $read();
        $write('RCPT TO:<' . $to . '>');
        $read();
        $write('DATA');
        $read();
        $write('From: ' . $encode('Hotel Codru Moma - site') . ' <' . $from . '>');
        $write('To: <' . $to . '>');
        if ($emailValid) {
            $write('Reply-To: ' . $encode($name) . ' <' . $email . '>');
        }
        $write('Subject: ' . $encode('Pre-rezervare site - ' . $name));
        $write('Date: ' . date('r'));
        $write('MIME-Version: 1.0');
        $write('Content-Type: text/html; charset=UTF-8');
        $write('Content-Transfer-Encoding: base64');
        $write('');
        $write(chunk_split(base64_encode($html)));
        $write('.');
        $ok = strpos($read(), '250') === 0;
    }

    $write('QUIT');
    fclose($sock);
}

if (!$ok) {
    error_log('mail.php: SMTP send failed');
    http_response_code(502);
}
echo json_encode(['success' => $ok]);
