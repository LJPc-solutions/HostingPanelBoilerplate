<?php

declare(strict_types=1);

namespace Panel;

use PDO;
use PDOException;

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function register(string $email, string $password, string $name): array
    {
        $email = strtolower(trim($email));
        $name = trim($name);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.', 'user_id' => null];
        }
        if (strlen($password) < 10) {
            return ['ok' => false, 'error' => 'Password must be at least 10 characters.', 'user_id' => null];
        }
        if ($name === '') {
            return ['ok' => false, 'error' => 'Please enter your name.', 'user_id' => null];
        }

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch() !== false) {
            return ['ok' => false, 'error' => 'An account with this email already exists.', 'user_id' => null];
        }

        $stmt = $this->pdo->prepare('INSERT INTO users (email, password_hash, name, created_at) VALUES (?, ?, ?, ?)');
        try {
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // unique constraint: lost a create race for this email
                return ['ok' => false, 'error' => 'An account with this email already exists.', 'user_id' => null];
            }

            throw $e;
        }

        return ['ok' => true, 'error' => null, 'user_id' => (int) $this->pdo->lastInsertId()];
    }

    public function attempt(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at > ?'
        );
        $stmt->execute([$email, $ip, date('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60)]);
        if ((int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'error' => 'Too many attempts, try again later.'];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, $user['password_hash'])) {
            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)');
            $stmt->execute([$email, $ip, date('Y-m-d H:i:s')]);

            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
        $stmt->execute([$email]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];

        return ['ok' => true, 'error' => null];
    }

    public function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if ($id === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public function verifyCsrf(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token);
    }
}
