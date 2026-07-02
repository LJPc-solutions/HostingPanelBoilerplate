<?php

declare(strict_types=1);

namespace Panel;

use PDO;

final class PasswordReset
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createToken(string $email): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if ($user === false) {
            return null;
        }

        $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);

        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);

        return $token;
    }

    public function consume(string $token, string $newPassword): bool
    {
        if (strlen($newPassword) < 10) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > ?');
        $stmt->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $row['user_id']]);
        $this->pdo->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$row['id']]);

        return true;
    }
}
