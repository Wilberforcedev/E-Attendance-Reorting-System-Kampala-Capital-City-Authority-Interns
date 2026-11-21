<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$name = trim((string) getenv('BOOTSTRAP_ADMIN_NAME'));
$email = strtolower(trim((string) getenv('BOOTSTRAP_ADMIN_EMAIL')));
$password = getenv('BOOTSTRAP_ADMIN_PASSWORD');

if ($name === '' || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) < 12) {
    fwrite(STDERR, "Set BOOTSTRAP_ADMIN_NAME, BOOTSTRAP_ADMIN_EMAIL, and BOOTSTRAP_ADMIN_PASSWORD (12+ characters).\n");
    exit(2);
}

try {
    $db = (new Database())->getConnection();
    $lock = $db->query("SELECT GET_LOCK('ears_initial_admin_bootstrap', 10)");
    if ((int) $lock->fetchColumn() !== 1) {
        fwrite(STDERR, "Could not acquire the administrator bootstrap lock. Try again later.\n");
        exit(4);
    }

    $db->beginTransaction();
    $lock = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
    if ($lock->fetchColumn()) {
        $db->rollBack();
        fwrite(STDERR, "An active administrator already exists; no account was created.\n");
        exit(3);
    }

    $insert = $db->prepare(
        'INSERT INTO users (email, password, full_name, role, is_active)
         VALUES (:email, :password, :full_name, \'admin\', 1)'
    );
    $insert->execute([
        ':email' => $email,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':full_name' => $name,
    ]);
    $db->commit();
    fwrite(STDOUT, "Initial administrator created. Clear BOOTSTRAP_ADMIN_PASSWORD from the environment.\n");
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Initial administrator bootstrap failed: ' . $error->getMessage());
    fwrite(STDERR, "Administrator creation failed. Check server logs.\n");
    exit(1);
} finally {
    if (isset($db) && $db instanceof PDO) {
        try {
            $db->query("SELECT RELEASE_LOCK('ears_initial_admin_bootstrap')");
        } catch (Throwable) {
            // A connection closing also releases the advisory lock.
        }
    }
}
