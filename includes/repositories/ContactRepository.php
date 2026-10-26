<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

/** ContactRepository — mensagens do formulário de contacto público. */
final class ContactRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** $teamId = equipa a quem o visitante está a escrever. */
    public function create(string $name, string $email, string $subject, string $message, ?int $teamId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO contact_messages (team_id, name, email, subject, message)
             VALUES (:team_id, :name, :email, :subject, :message)'
        );
        $stmt->execute([
            'team_id' => $teamId, 'name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Mensagens recebidas por uma equipa. */
    public function listByTeam(int $teamId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contact_messages WHERE team_id = :team_id ORDER BY created_at DESC');
        $stmt->execute(['team_id' => $teamId]);
        return $stmt->fetchAll();
    }
}
