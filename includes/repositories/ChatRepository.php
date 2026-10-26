<?php
declare(strict_types=1);

/**
 * ChatRepository - Gerencia o chat em tempo real
 *
 * @package CourtIQ
 * @version 1.0.2
 */

class ChatRepository
{
    private PDO $pdo;

    /** Equipa e papel do utilizador autenticado: o chat é isolado por equipa. */
    private ?int $teamId = null;
    private bool $isSuperadmin = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        if ($userId > 0) {
            $stmt = $this->pdo->prepare('SELECT role, team_id FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            if ($me = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->isSuperadmin = strtolower(trim((string) $me['role'])) === 'superadmin';
                $this->teamId       = $me['team_id'] !== null ? (int) $me['team_id'] : null;
            }
        }
    }

    /**
     * Um utilizador só fala com colegas da mesma equipa.
     * O superadmin (suporte) pode falar com todos e todos podem falar com ele.
     */
    private function canTalkTo(int $otherUserId): bool
    {
        $stmt = $this->pdo->prepare('SELECT role, team_id FROM users WHERE id = ?');
        $stmt->execute([$otherUserId]);
        $other = $stmt->fetch(PDO::FETCH_ASSOC);
        // os adeptos seguem o clube mas não fazem parte do chat interno
        if (!$other || strtolower(trim((string) $other['role'])) === 'adepto') {
            return false;
        }
        if ($this->isSuperadmin) {
            return true;
        }
        return strtolower(trim((string) $other['role'])) === 'superadmin'
            || ($this->teamId !== null && (int) $other['team_id'] === $this->teamId);
    }

    /**
     * Enviar uma mensagem
     */
    public function sendMessage(int $userId, string $content, int $recipientId = 0, string $type = 'private'): int
    {
        if ($recipientId > 0 && !$this->canTalkTo($recipientId)) {
            throw new RuntimeException('Só pode enviar mensagens a membros da sua equipa.');
        }

        // recipient_id = 0 -> chat geral da equipa (team_id identifica a sala)
        $sql = "INSERT INTO chat_messages (team_id, user_id, recipient_id, content, message_type, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->teamId, $userId, $recipientId, $content, $type]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Buscar mensagens do chat geral
     */
    public function getMessages(int $limit = 50, int $lastId = 0): array
    {
        $sql = "SELECT m.*, u.name as user_name, u.role as user_role
                FROM chat_messages m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE m.recipient_id = 0
                  AND m.team_id <=> ?
                  AND m.id > ?
                ORDER BY m.created_at ASC
                LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $this->teamId, $this->teamId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(2, $lastId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Buscar mensagens privadas entre dois utilizadores
     */
    public function getPrivateMessages(int $userId, int $otherUserId, int $limit = 50, int $lastId = 0): array
    {
        $sql = "SELECT m.*, u.name as user_name, u.role as user_role
                FROM chat_messages m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE ((m.user_id = ? AND m.recipient_id = ?)
                   OR (m.user_id = ? AND m.recipient_id = ?))
                  AND m.id > ?
                ORDER BY m.created_at ASC
                LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $otherUserId, $otherUserId, $userId, $lastId, $limit]);

        return $stmt->fetchAll();
    }

    /**
     * Buscar últimas conversas privadas do utilizador
     */
    public function getConversations(int $userId): array
    {
        $sql = "SELECT
                    u.id as user_id,
                    u.name as user_name,
                    u.role as user_role,
                    u.last_activity,
                    (SELECT content FROM chat_messages
                     WHERE (user_id = u.id AND recipient_id = ?)
                        OR (user_id = ? AND recipient_id = u.id)
                     ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT created_at FROM chat_messages
                     WHERE (user_id = u.id AND recipient_id = ?)
                        OR (user_id = ? AND recipient_id = u.id)
                     ORDER BY created_at DESC LIMIT 1) as last_message_time,
                    (SELECT COUNT(*) FROM chat_messages
                     WHERE user_id = u.id AND recipient_id = ? AND is_read = 0) as unread_count
                FROM users u
                WHERE u.id != ?
                  AND u.status = 'active'
                  AND EXISTS (
                      SELECT 1 FROM chat_messages
                      WHERE (user_id = u.id AND recipient_id = ?)
                         OR (user_id = ? AND recipient_id = u.id)
                  )
                ORDER BY last_message_time DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Buscar última mensagem
     */
    public function getLastMessage(int $userId): ?array
    {
        $sql = "SELECT m.*, u.name as user_name, u.role as user_role
                FROM chat_messages m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE (m.user_id = ? OR m.recipient_id = ?)
                ORDER BY m.created_at DESC
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $userId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Buscar última mensagem privada entre dois utilizadores
     */
    public function getLastPrivateMessage(int $userId, int $otherUserId): ?array
    {
        $sql = "SELECT m.*, u.name as user_name, u.role as user_role
                FROM chat_messages m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE (m.user_id = ? AND m.recipient_id = ?)
                   OR (m.user_id = ? AND m.recipient_id = ?)
                ORDER BY m.created_at DESC
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $otherUserId, $otherUserId, $userId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Marcar mensagens privadas como lidas
     */
    public function markPrivateMessagesAsRead(int $userId, int $otherUserId): bool
    {
        $sql = "UPDATE chat_messages
                SET is_read = 1
                WHERE user_id = ? AND recipient_id = ? AND is_read = 0";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$otherUserId, $userId]);
    }

    /**
     * Marcar todas as mensagens como lidas
     */
    public function markAllAsRead(int $userId): bool
    {
        $sql = "UPDATE chat_messages
                SET is_read = 1
                WHERE recipient_id = ? AND is_read = 0";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$userId]);
    }

    /**
     * Contar mensagens não lidas
     */
    public function getUnreadCount(int $userId): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM chat_messages
                WHERE recipient_id = ? AND is_read = 0";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch();

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Contar mensagens não lidas de um utilizador específico
     */
    public function getUnreadCountFromUser(int $userId, int $otherUserId): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM chat_messages
                WHERE user_id = ? AND recipient_id = ? AND is_read = 0";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$otherUserId, $userId]);
        $result = $stmt->fetch();

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Atualizar atividade do utilizador
     */
    public function updateUserActivity(int $userId): bool
    {
        $sql = "UPDATE users SET last_activity = NOW() WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$userId]);
    }

    /**
     * Buscar utilizadores para o chat (apenas com quem já conversou)
     */
    public function getUsersWithConversations(int $userId, string $search = ''): array
    {
        $searchTerm = '%' . $search . '%';

        $sql = "SELECT DISTINCT
                    u.id, u.name, u.role,
                    u.last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) as is_online,
                    (SELECT COUNT(*) FROM chat_messages
                     WHERE user_id = u.id AND recipient_id = ? AND is_read = 0) as unread_count,
                    (SELECT content FROM chat_messages
                     WHERE (user_id = u.id AND recipient_id = ?)
                        OR (user_id = ? AND recipient_id = u.id)
                     ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT created_at FROM chat_messages
                     WHERE (user_id = u.id AND recipient_id = ?)
                        OR (user_id = ? AND recipient_id = u.id)
                     ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM users u
                WHERE u.id != ?
                  AND u.status = 'active'
                  AND (u.name LIKE ? OR u.role LIKE ?)
                  AND EXISTS (
                      SELECT 1 FROM chat_messages
                      WHERE (user_id = u.id AND recipient_id = ?)
                         OR (user_id = ? AND recipient_id = u.id)
                  )
                ORDER BY last_message_time DESC, is_online DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $userId, $userId, $userId,
            $userId, $userId,
            $userId,
            $searchTerm, $searchTerm,
            $userId, $userId
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Buscar todos os utilizadores para iniciar nova conversa
     */
    public function getAllUsers(int $userId, string $search = ''): array
    {
        $searchTerm = '%' . $search . '%';

        $sql = "SELECT u.id, u.name, u.role,
                       u.last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) as is_online
                FROM users u
                WHERE u.id != ?
                  AND u.status = 'active'
                  AND (u.name LIKE ? OR u.role LIKE ?)
                  AND (? = 1 OR u.team_id = ? OR u.role = 'superadmin')
                ORDER BY is_online DESC, u.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $searchTerm, $searchTerm, (int) $this->isSuperadmin, (int) $this->teamId]);

        return $stmt->fetchAll();
    }

    /**
     * Eliminar mensagem
     */
    public function deleteMessage(int $messageId, int $userId): bool
    {
        $sql = "DELETE FROM chat_messages
                WHERE id = ? AND (user_id = ? OR recipient_id = ?)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$messageId, $userId, $userId]);
    }

    /**
     * Eliminar conversa privada entre dois utilizadores
     */
    public function deletePrivateConversation(int $userId, int $otherUserId): bool
    {
        $sql = "DELETE FROM chat_messages
                WHERE (user_id = ? AND recipient_id = ?)
                   OR (user_id = ? AND recipient_id = ?)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$userId, $otherUserId, $otherUserId, $userId]);
    }

    /**
     * Eliminar todas as mensagens do chat geral
     */
    public function clearGeneralChat(): bool
    {
        // Só limpa a sala da equipa de quem pede
        $sql = "DELETE FROM chat_messages WHERE recipient_id = 0 AND team_id <=> ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $this->teamId, $this->teamId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        return $stmt->execute();
    }

    // ========================================================================
    // Métodos usados pela página de chat partilhada (includes/panel_pages/chat.php)
    // ========================================================================

    /** Quem não tem equipa (superadmin) não tem sala de equipa: só conversas privadas. */
    public function hasRoom(): bool
    {
        return $this->teamId !== null;
    }

    public function canMessage(int $otherUserId): bool
    {
        return $otherUserId > 0 && $this->canTalkTo($otherUserId);
    }

    /**
     * Mensagens de uma conversa por ordem cronológica. $other = 0 é a sala da equipa.
     * Com $afterId devolve só as mais recentes do que essa; sem ele, as últimas $limit.
     */
    public function thread(int $userId, int $other, int $afterId = 0, int $limit = 80): array
    {
        if ($other === 0) {
            if (!$this->hasRoom()) return [];
            $where  = 'm.recipient_id = 0 AND m.team_id = ?';
            $params = [$this->teamId];
        } else {
            $where  = '((m.user_id = ? AND m.recipient_id = ?) OR (m.user_id = ? AND m.recipient_id = ?))';
            $params = [$userId, $other, $other, $userId];
        }
        $limit = max(1, min(200, $limit));
        $sql = "SELECT m.id, m.user_id, m.content, m.created_at, u.name AS user_name, u.role AS user_role
                FROM chat_messages m LEFT JOIN users u ON u.id = m.user_id
                WHERE $where AND m.id > ? ORDER BY m.id " . ($afterId > 0 ? 'ASC' : 'DESC') . " LIMIT $limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$afterId]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $afterId > 0 ? $rows : array_reverse($rows);
    }

    /** Quantas mensagens a conversa tem a partir de $fromId (serve para detetar mensagens apagadas). */
    public function threadCount(int $userId, int $other, int $fromId): int
    {
        if ($other === 0) {
            if (!$this->hasRoom()) return 0;
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE recipient_id = 0 AND team_id = ? AND id >= ?');
            $stmt->execute([$this->teamId, $fromId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE ((user_id = ? AND recipient_id = ?) OR (user_id = ? AND recipient_id = ?)) AND id >= ?');
            $stmt->execute([$userId, $other, $other, $userId, $fromId]);
        }
        return (int) $stmt->fetchColumn();
    }

    /** Conversas privadas do utilizador, a mais recente primeiro, com a última mensagem e as não lidas. */
    public function threads(int $userId): array
    {
        $sql = "SELECT u.id, u.name, u.role, t.name AS team_name,
                       (u.last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS online,
                       m.content AS last_message, m.created_at AS last_time, m.user_id AS last_from,
                       (SELECT COUNT(*) FROM chat_messages c WHERE c.user_id = u.id AND c.recipient_id = ? AND c.is_read = 0) AS unread
                FROM (SELECT IF(user_id = ?, recipient_id, user_id) AS other, MAX(id) AS last_id
                      FROM chat_messages WHERE recipient_id <> 0 AND (user_id = ? OR recipient_id = ?) GROUP BY other) x
                JOIN users u ON u.id = x.other
                JOIN chat_messages m ON m.id = x.last_id
                LEFT JOIN teams t ON t.id = u.team_id
                ORDER BY x.last_id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pessoas com quem o utilizador pode falar: colegas de equipa e superadmin (o superadmin fala com todos). */
    public function people(int $userId): array
    {
        $sql = "SELECT u.id, u.name, u.role, t.name AS team_name,
                       (u.last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS online
                FROM users u LEFT JOIN teams t ON t.id = u.team_id
                WHERE u.id <> ? AND u.status = 'active' AND u.role <> 'adepto' AND (? = 1 OR u.team_id = ? OR u.role = 'superadmin')
                ORDER BY t.name IS NULL DESC, t.name, FIELD(u.role, 'superadmin', 'admin', 'treinador', 'jogador'), u.name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, (int) $this->isSuperadmin, (int) $this->teamId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function roomLastId(): int
    {
        if (!$this->hasRoom()) return 0;
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE recipient_id = 0 AND team_id = ?');
        $stmt->execute([$this->teamId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Apaga uma mensagem: cada um apaga as suas; um moderador (admin da equipa)
     * apaga também qualquer mensagem da sala da sua equipa.
     */
    public function deleteOwn(int $messageId, int $userId, bool $moderator = false): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM chat_messages WHERE id = ? AND (user_id = ? OR (? = 1 AND recipient_id = 0 AND team_id = ?))'
        );
        $stmt->execute([$messageId, $userId, (int) ($moderator && $this->hasRoom()), (int) $this->teamId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Buscar conversas recentes (para exibir na lista)
     */
    public function getRecentConversations(int $userId, int $limit = 10): array
    {
        $sql = "SELECT
                    u.id as user_id,
                    u.name as user_name,
                    u.role as user_role,
                    u.last_activity,
                    (SELECT content FROM chat_messages
                     WHERE (user_id = u.id AND recipient_id = ?)
                        OR (user_id = ? AND recipient_id = u.id)
                     ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT created_at FROM chat_messages
                     WHERE (user_id = u.id AND recipient_id = ?)
                        OR (user_id = ? AND recipient_id = u.id)
                     ORDER BY created_at DESC LIMIT 1) as last_message_time,
                    (SELECT COUNT(*) FROM chat_messages
                     WHERE user_id = u.id AND recipient_id = ? AND is_read = 0) as unread_count
                FROM users u
                WHERE u.id != ?
                  AND u.status = 'active'
                  AND EXISTS (
                      SELECT 1 FROM chat_messages
                      WHERE (user_id = u.id AND recipient_id = ?)
                         OR (user_id = ? AND recipient_id = u.id)
                  )
                ORDER BY last_message_time DESC
                LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $limit]);

        return $stmt->fetchAll();
    }
}