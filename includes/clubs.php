<?php
declare(strict_types=1);

/**
 * clubs.php — entrada de clubes novos na plataforma.
 * Usado por registar-clube.php (inscrição feita pelo próprio clube) e por
 * superadmin/pedidos-clubes.php (aprovação manual).
 */

/** Vagas que restam para clubes novos (null = sem limite). */
function club_slots(PDO $pdo): ?int
{
    $max = (int) ($pdo->query("SELECT config_value FROM system_config WHERE config_key = 'max_teams'")->fetchColumn() ?: 0);
    if ($max <= 0) {
        return null;
    }
    return max(0, $max - (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn());
}

/**
 * Os clubes ficam ativos logo ao inscrever-se? (system_config.club_auto_approve)
 * Desligado, cada inscrição espera pela aprovação do superadministrador.
 */
function club_auto_approve(PDO $pdo): bool
{
    $v = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'club_auto_approve'")->fetchColumn();
    return $v === false ? true : (string) $v === '1';
}

/**
 * Cria o clube e a conta do seu administrador. $d traz os campos de club_requests
 * (club_name, city, stadium, founded_year, escaloes, admin_name, admin_username, admin_email,
 * admin_phone, password_hash). Volta a verificar tudo, porque entre o pedido e a criação
 * podem ter aparecido outro clube ou outra conta com os mesmos dados.
 * Devolve [team_id, admin_user_id]; lança RuntimeException com a razão se não puder.
 */
function club_create(PDO $pdo, array $d): array
{
    if (club_slots($pdo) === 0) {
        throw new RuntimeException('O limite de clubes da plataforma está atingido.');
    }
    team_name_check((string) $d['club_name']);
    $stmt = $pdo->prepare('SELECT id FROM teams WHERE name = :n');
    $stmt->execute(['n' => $d['club_name']]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Já existe um clube com esse nome.');
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
    $stmt->execute(['u' => $d['admin_username'], 'e' => $d['admin_email']]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Esse utilizador ou email já pertence a outra conta.');
    }
    if ((string) $d['password_hash'] === '') {
        throw new RuntimeException('O pedido já não tem palavra-passe guardada.');
    }

    $propria = !$pdo->inTransaction();
    if ($propria) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare(
            "INSERT INTO teams (name, city, stadium, founded_year, escaloes, logo_initials, status)
             VALUES (:name, :city, :stadium, :founded_year, :escaloes, :initials, 'active')"
        )->execute([
            'name' => $d['club_name'], 'city' => $d['city'] ?: null, 'stadium' => $d['stadium'] ?: null, 'founded_year' => $d['founded_year'] ?: null,
            'escaloes' => escaloes_from_post(explode(',', (string) $d['escaloes'])), 'initials' => mb_strtoupper(mb_substr((string) $d['club_name'], 0, 2)),
        ]);
        $teamId = (int) $pdo->lastInsertId();
        role_limit_check($pdo, 'admin', $teamId);
        $pdo->prepare(
            "INSERT INTO users (name, username, email, phone, password_hash, role, team_id, status, avatar_initials)
             VALUES (:name, :username, :email, :phone, :hash, 'admin', :team_id, 'active', :initials)"
        )->execute([
            'name' => $d['admin_name'], 'username' => $d['admin_username'], 'email' => $d['admin_email'], 'phone' => $d['admin_phone'] ?: null,
            'hash' => $d['password_hash'], 'team_id' => $teamId, 'initials' => mb_strtoupper(mb_substr((string) $d['admin_name'], 0, 2)),
        ]);
        $adminId = (int) $pdo->lastInsertId();
        if ($propria) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($propria && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    notify_user($pdo, $adminId, 'Bem-vindo ao CourtIQMZ',
        'O clube ' . $d['club_name'] . ' está ativo. Comece por completar os dados do clube em "Equipa" e por adicionar jogadores e treinadores.', 'success');
    return [$teamId, $adminId];
}
