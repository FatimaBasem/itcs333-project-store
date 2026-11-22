<?php
declare(strict_types=1);

$rest   = $GLOBALS['__route']['rest'] ?? '';
$method = http_method();
$pdo    = db();

switch (true) {

    case $method === 'POST' && $rest === '/register':
        $input    = json_body();
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $is_admin = empty($input['is_admin']) ? 0 : 1;

        // validation
        $fields = [];
        if (empty($username)) { $fields['username'] = 'required'; }
        if (empty($password)) { $fields['password'] = 'required'; }
        if (!empty($fields)) {
            send_error(422, 'VALIDATION', ['fields' => $fields]);
        }

        // check duplicate
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            send_error(409, 'USERNAME_TAKEN');
        }

        // insert
        $id = db_insert_and_get_id($pdo, 'users', [
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin'      => $is_admin,
        ]);
        send_json(201, ['id' => $id, 'username' => $username, 'is_admin' => $is_admin]);
        break;

    case $method === 'POST' && $rest === '/login':
        $input    = json_body();
        $username = (string) ($input['username'] ?? '');
        $password = (string) ($input['password'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            send_error(401, 'INVALID_CREDENTIALS');
        }

        session_regenerate_id(true);
        $_SESSION['user_id']  = (int) $user['id'];
        $_SESSION['username'] = $user['username'];

        send_json(200, [
            'id'       => (int) $user['id'],
            'username' => $user['username'],
            'is_admin' => (int) $user['is_admin'],
            'token'    => session_id(),
        ]);
        break;

    case $method === 'POST' && $rest === '/logout':
        $_SESSION = [];
        session_destroy();
        send_json(200, ['ok' => true]);
        break;

    case $method === 'GET' && $rest === '/me':
        if (empty($_SESSION['user_id'])) {
            send_error(401, 'AUTH_REQUIRED');
        }
        $stmt = $pdo->prepare("SELECT id, username, is_admin FROM users WHERE id = ?");
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            send_error(401, 'AUTH_REQUIRED');
        }
        send_json(200, [
            'id'       => (int) $user['id'],
            'username' => $user['username'],
            'is_admin' => (int) $user['is_admin'],
        ]);
        break;

    default:
        send_error(404, 'NOT_FOUND');
}