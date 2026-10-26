<?php
declare(strict_types=1);

/**
 * CourtIQMZ - Sponsor Repository
 * 
 * @package CourtIQ
 * @version 3.0.0
 * @author CourtIQMZ
 */

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../team_helper.php';

/** SponsorRepository — patrocinadores do clube. */
final class SponsorRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Lista todos os patrocinadores
     */
    public function all(?int $teamId = null): array
    {
        try {
            // Multi-equipa: com $teamId só devolve os patrocinadores dessa equipa
            $where = $teamId !== null ? 'WHERE s.team_id = :team_id' : '';
            $stmt = $this->pdo->prepare("
                SELECT s.*, t.name as team_name
                FROM sponsors s
                LEFT JOIN teams t ON s.team_id = t.id
                {$where}
                ORDER BY FIELD(s.tier, 'ouro', 'prata', 'bronze'), s.name
            ");
            $stmt->execute($teamId !== null ? [':team_id' => $teamId] : []);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao listar patrocinadores: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca um patrocinador pelo ID
     */
    public function find(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT s.*, t.name as team_name
                FROM sponsors s
                LEFT JOIN teams t ON s.team_id = t.id
                WHERE s.id = :id
            ");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao buscar patrocinador: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Lista patrocinadores por equipa
     */
    public function listByTeam(int $teamId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM sponsors
                WHERE team_id = :team_id
                ORDER BY FIELD(tier, 'ouro', 'prata', 'bronze'), name
            ");
            $stmt->execute([':team_id' => $teamId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao listar patrocinadores por equipa: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cria um novo patrocinador
     */
    public function create(array $data): int
    {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO sponsors (
                    name, tier, website, logo_url, description, 
                    team_id, start_date, end_date, status
                ) VALUES (
                    :name, :tier, :site, :logo, :description,
                    :team_id, :start_date, :end_date, :status
                )
            ');
            
            $stmt->execute([
                'name' => $data['name'] ?? '',
                'tier' => $data['tier'] ?? 'bronze',
                'site' => $data['website'] ?? null,
                'logo' => $data['logo_url'] ?? null,
                'description' => $data['description'] ?? null,
                'team_id' => $data['team_id'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'status' => $data['status'] ?? 'active'
            ]);
            
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao criar patrocinador: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Atualiza um patrocinador existente (alias para update)
     */
    public function update(int $id, array $data): bool
    {
        return $this->edit($id, $data);
    }

    /**
     * Atualiza um patrocinador existente
     */
    public function edit(int $id, array $data): bool
    {
        if (!team_owns('sponsors', $id)) {
            return false; // registo de outra equipa
        }
        try {
            $stmt = $this->pdo->prepare('
                UPDATE sponsors SET
                    name = :name,
                    tier = :tier,
                    website = :site,
                    logo_url = :logo,
                    description = :description,
                    team_id = :team_id,
                    start_date = :start_date,
                    end_date = :end_date,
                    status = :status
                WHERE id = :id
            ');
            
            return $stmt->execute([
                'id' => $id,
                'name' => $data['name'] ?? '',
                'tier' => $data['tier'] ?? 'bronze',
                'site' => $data['website'] ?? null,
                'logo' => $data['logo_url'] ?? null,
                'description' => $data['description'] ?? null,
                'team_id' => $data['team_id'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'status' => $data['status'] ?? 'active'
            ]);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao atualizar patrocinador: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove um patrocinador (alias para delete)
     */
    public function remove(int $id): bool
    {
        return $this->delete($id);
    }

    /**
     * Remove um patrocinador
     */
    public function delete(int $id): bool
    {
        if (!team_owns('sponsors', $id)) {
            return false; // registo de outra equipa
        }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sponsors WHERE id = :id');
            return $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao remover patrocinador: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualiza o status de um patrocinador
     */
    public function updateStatus(int $id, string $status): bool
    {
        if (!team_owns('sponsors', $id)) {
            return false; // registo de outra equipa
        }
        $allowed = ['active', 'inactive', 'expired'];
        if (!in_array($status, $allowed)) {
            return false;
        }
        
        try {
            $stmt = $this->pdo->prepare('UPDATE sponsors SET status = :status WHERE id = :id');
            return $stmt->execute(['id' => $id, 'status' => $status]);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao atualizar status do patrocinador: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Conta patrocinadores por nível
     */
    public function countByTier(string $tier, ?int $teamId = null): int
    {
        try {
            $sql    = "SELECT COUNT(*) FROM sponsors WHERE tier = :tier AND status = 'active'";
            $params = [':tier' => $tier];
            if ($teamId !== null) {
                $sql .= ' AND team_id = :team_id';
                $params[':team_id'] = $teamId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao contar patrocinadores por nível: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Busca patrocinadores ativos
     */
    public function getActive(?int $teamId = null): array
    {
        try {
            $teamFilter = $teamId !== null ? 'AND team_id = :team_id' : '';
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM sponsors
                WHERE status = 'active' {$teamFilter}
                ORDER BY FIELD(tier, 'ouro', 'prata', 'bronze'), name
            ");
            $stmt->execute($teamId !== null ? [':team_id' => $teamId] : []);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao buscar patrocinadores ativos: ' . $e->getMessage());
            return [];
        }
    }
}