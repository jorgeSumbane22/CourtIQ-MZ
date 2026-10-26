<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../team_helper.php';

/** TeamRepository — acesso a dados de equipas (usado por Admin e Superadmin). */
final class TeamRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT t.*, COUNT(p.id) AS total_jogadores
             FROM teams t LEFT JOIN players p ON p.team_id = t.id
             GROUP BY t.id ORDER BY t.name'
        );
        return $stmt->fetchAll();
    }

    /**
     * Classificação da liga, calculada a partir dos jogos terminados de cada equipa.
     * Pontos: 2 por vitória, 1 por derrota.
     */
    public function standings(string $escalao = 'seniores'): array
    {
        // cada escalão tem a sua tabela ($escalao só aceita os três valores conhecidos)
        $escalao = in_array($escalao, ['iniciados', 'juniores', 'seniores'], true) ? $escalao : 'seniores';
        $stmt = $this->pdo->query(
            "SELECT t.id, t.name,
                    COUNT(g.id) AS jogos,
                    COALESCE(SUM(g.our_score > g.opponent_score), 0) AS vitorias,
                    COALESCE(SUM(g.our_score < g.opponent_score), 0) AS derrotas,
                    COALESCE(SUM(g.our_score - g.opponent_score), 0) AS saldo,
                    COALESCE(SUM(IF(g.our_score > g.opponent_score, 2, 1)), 0) AS pontos
             FROM teams t
             LEFT JOIN games g ON g.team_id = t.id AND g.status = 'finished' AND g.escalao = '$escalao'
             WHERE t.status = 'active'
             GROUP BY t.id
             ORDER BY pontos DESC, saldo DESC, t.name"
        );
        return $stmt->fetchAll();
    }

    /** Totais públicos da liga (não expõem dados internos de nenhuma equipa). */
    public function leagueTotals(): array
    {
        $row = $this->pdo->query(
            "SELECT (SELECT COUNT(*) FROM teams WHERE status = 'active') AS equipas,
                    (SELECT COUNT(*) FROM players)                       AS jogadores,
                    (SELECT COUNT(DISTINCT jornada, game_date) FROM games WHERE status = 'finished') AS jogos,
                    (SELECT COUNT(DISTINCT competition) FROM games)      AS competicoes"
        )->fetch();

        return array_map('intval', $row ?: ['equipas' => 0, 'jogadores' => 0, 'jogos' => 0, 'competicoes' => 0]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM teams WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        if (!TeamHelper::isSuperadmin()) {
            return 0; // só o superadmin cria equipas
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO teams (name, category, league, founded_year, logo_initials)
             VALUES (:name, :category, :league, :founded, :logo)'
        );
        $stmt->execute([
            'name'     => $data['name'],
            'category' => $data['category'] ?? null,
            'league'   => $data['league'] ?? null,
            'founded'  => $data['founded_year'] ?: null,
            'logo'     => strtoupper(mb_substr($data['name'], 0, 2)),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        if (!team_owns('teams', $id)) {
            return false; // registo de outra equipa
        }
        $stmt = $this->pdo->prepare(
            'UPDATE teams SET name = :name, category = :category, league = :league, founded_year = :founded, logo_url = :logo WHERE id = :id'
        );
        return $stmt->execute([
            'name'     => $data['name'],
            'category' => $data['category'] ?? null,
            'league'   => $data['league'] ?? null,
            'founded'  => $data['founded_year'] ?: null,
            'logo'     => $data['logo_url'] ?: null,
            'id'       => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        if (!TeamHelper::isSuperadmin()) {
            return false; // só o superadmin apaga equipas
        }
        $stmt = $this->pdo->prepare('DELETE FROM teams WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
