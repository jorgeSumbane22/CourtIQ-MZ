<?php
declare(strict_types=1);

/**
 * password_reset.php — recuperação de palavra-passe por ligação de uso único.
 *
 * Na base de dados fica só o SHA-256 do código: quem ler a tabela não consegue
 * usar as ligações. Cada conta tem no máximo uma ligação válida de cada vez.
 */

const PR_TTL_SELF  = 3600;        // pedida pelo próprio em recuperar.php: 1 hora
const PR_TTL_ADMIN = 86400;       // gerada por um administrador: 24 horas
const PR_MIN_LENGTH = 8;

/** Cria uma ligação nova para a conta (anula as anteriores) e devolve o código em claro. */
function pr_create(PDO $pdo, int $userId, int $ttl = PR_TTL_SELF, ?int $createdBy = null): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id AND used_at IS NULL')->execute(['id' => $userId]);
    $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at, created_by, ip_address, created_at)
         VALUES (:user_id, :hash, :expires, :created_by, :ip, :now)'
    )->execute([
        'user_id'    => $userId,
        'hash'       => hash('sha256', $token),
        'expires'    => date('Y-m-d H:i:s', time() + $ttl),
        'created_by' => $createdBy,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
        'now'        => date('Y-m-d H:i:s'),
    ]);
    return $token;
}

/** A conta a que pertence um código ainda válido, ou null. */
function pr_find(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT pr.id AS reset_id, u.id, u.name, u.username, u.email, u.role, u.team_id
         FROM password_resets pr JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > :now AND u.status = 'active'"
    );
    $stmt->execute(['hash' => hash('sha256', $token), 'now' => date('Y-m-d H:i:s')]);
    return $stmt->fetch() ?: null;
}

/** Grava a palavra-passe nova e gasta a ligação. */
function pr_consume(PDO $pdo, array $conta, string $novaPassword): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($novaPassword, PASSWORD_DEFAULT), 'id' => (int) $conta['id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int) $conta['reset_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id AND used_at IS NULL')->execute(['id' => (int) $conta['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Endereço completo da página onde se escolhe a palavra-passe nova. */
function pr_link(string $token): string
{
    return Auth::absoluteUrl('redefinir.php') . '?token=' . $token;
}

/** Quantas ligações esta conta pediu nos últimos $segundos (trava pedidos em série). */
function pr_recent(PDO $pdo, int $userId, int $segundos = 900): int
{
    // conta-se pela auditoria: cada pedido novo apaga a ligação anterior da tabela password_resets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id = :id AND action = 'password_reset_pedido' AND created_at > :desde");
    $stmt->execute(['id' => $userId, 'desde' => date('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

/** Regista o acontecimento na auditoria (quem pede não tem sessão iniciada). */
function pr_log(PDO $pdo, array $conta, string $action, string $details = ''): void
{
    try {
        $pdo->prepare('INSERT INTO audit_logs (user_id, team_id, username, action, details, ip_address) VALUES (:user_id, :team_id, :username, :action, :details, :ip)')
            ->execute([
                'user_id' => (int) $conta['id'], 'team_id' => $conta['team_id'] !== null ? (int) $conta['team_id'] : null,
                'username' => (string) $conta['username'], 'action' => $action, 'details' => $details, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) {
        error_log('[CourtIQ] pr_log: ' . $e->getMessage());
    }
}

/**
 * Avisa quem pode ajudar: o administrador da equipa (para treinadores e jogadores)
 * ou o superadmin (para administradores). Usado quando o email não está configurado.
 */
function pr_notify_admins(PDO $pdo, array $conta): void
{
    try {
        if (in_array($conta['role'], ['treinador', 'jogador'], true) && $conta['team_id'] !== null) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND team_id = :team_id AND status = 'active'");
            $stmt->execute(['team_id' => (int) $conta['team_id']]);
            $onde = 'Equipa → Contas com acesso';
        } else {
            $stmt = $pdo->query("SELECT id FROM users WHERE role = 'superadmin' AND status = 'active'");
            $onde = 'Utilizadores';
        }
        $ins = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, 'warning')");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            if ((int) $adminId === (int) $conta['id']) continue;
            $ins->execute([
                'user_id' => (int) $adminId,
                'title'   => 'Pedido de recuperação de palavra-passe',
                'message' => $conta['name'] . ' (' . $conta['username'] . ') não consegue entrar e pediu uma palavra-passe nova. '
                           . 'Confirme com a pessoa e reponha o acesso em ' . $onde . '.',
            ]);
        }
    } catch (Throwable $e) {
        error_log('[CourtIQ] pr_notify_admins: ' . $e->getMessage());
    }
}
