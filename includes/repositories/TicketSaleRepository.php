<?php
declare(strict_types=1);

/**
 * CourtIQMZ - Ticket Sale Repository
 * 
 * @package CourtIQ
 * @version 2.0.0
 * @author CourtIQMZ
 */

require_once __DIR__ . '/../Database.php';

/** TicketSaleRepository — venda de bilhetes (site público). */
final class TicketSaleRepository
{
    private PDO $pdo;
    private const UNIT_PRICE = 250.00; // MZN, valor fixo simples para a fase 4

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Cria uma nova venda de bilhete
     */
    public function create(int $gameId, string $name, string $email, int $quantity, string $ticketType = 'normal', float $unitPrice = null): array
    {
        $unitPrice = $unitPrice ?? self::UNIT_PRICE;
        $total = $unitPrice * $quantity;
        $code = 'TICKET-' . strtoupper(substr(md5(uniqid() . $email . $gameId), 0, 10));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_sales (game_id, buyer_name, buyer_email, quantity, ticket_type, unit_price, total, code, status)
             VALUES (:game_id, :name, :email, :qty, :ticket_type, :unit, :total, :code, :status)'
        );
        
        $stmt->execute([
            'game_id' => $gameId,
            'name' => $name,
            'email' => $email,
            'qty' => $quantity,
            'ticket_type' => $ticketType,
            'unit' => $unitPrice,
            'total' => $total,
            'code' => $code,
            'status' => 'confirmed'
        ]);

        $id = (int) $this->pdo->lastInsertId();
        
        return [
            'id' => $id,
            'code' => $code,
            'total' => $total,
            'quantity' => $quantity,
            'ticket_type' => $ticketType,
            'unit_price' => $unitPrice,
            'buyer_name' => $name,
            'buyer_email' => $email,
            'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Lista bilhetes por email do comprador
     */
    public function listByEmail(string $email, int $limit = 20): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ts.*,
                    g.opponent as event_name,
                    g.game_date,
                    g.game_time,
                    g.location
                FROM ticket_sales ts
                LEFT JOIN games g ON ts.game_id = g.id
                WHERE ts.buyer_email = :email
                ORDER BY ts.created_at DESC
                LIMIT :limit
            ");
            
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao listar bilhetes por email: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Lista bilhetes por ID do usuário
     */
    public function listByUser(int $userId, int $limit = 20): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ts.*,
                    g.opponent as event_name,
                    g.game_date,
                    g.game_time,
                    g.location
                FROM ticket_sales ts
                LEFT JOIN games g ON ts.game_id = g.id
                WHERE ts.user_id = :user_id
                ORDER BY ts.created_at DESC
                LIMIT :limit
            ");
            
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao listar bilhetes por usuário: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca um bilhete pelo ID
     */
    public function find(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ts.*,
                    g.opponent as event_name,
                    g.game_date,
                    g.game_time,
                    g.location
                FROM ticket_sales ts
                LEFT JOIN games g ON ts.game_id = g.id
                WHERE ts.id = :id
            ");
            
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ?: null;
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao buscar bilhete: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca um bilhete pelo código
     */
    public function findByCode(string $code): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ts.*,
                    g.opponent as event_name,
                    g.game_date,
                    g.game_time,
                    g.location
                FROM ticket_sales ts
                LEFT JOIN games g ON ts.game_id = g.id
                WHERE ts.code = :code
            ");
            
            $stmt->execute([':code' => $code]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ?: null;
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao buscar bilhete por código: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Atualiza o status de um bilhete
     */
    public function updateStatus(int $id, string $status): bool
    {
        $allowedStatuses = ['pending', 'confirmed', 'canceled', 'used', 'refunded'];
        
        if (!in_array($status, $allowedStatuses)) {
            return false;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE ticket_sales 
                SET status = :status 
                WHERE id = :id
            ");
            
            return $stmt->execute([
                ':id' => $id,
                ':status' => $status
            ]);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao atualizar status do bilhete: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica disponibilidade de bilhetes para um jogo
     */
    public function checkAvailability(int $gameId): int
    {
        try {
            // Simulação - na vida real, teria uma tabela de lugares
            // Por enquanto, retorna um número aleatório entre 10 e 50
            return rand(10, 50);
        } catch (Exception $e) {
            error_log('[CourtIQ] Erro ao verificar disponibilidade: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retorna o preço unitário do bilhete
     */
    public function unitPrice(): float
    {
        return self::UNIT_PRICE;
    }

    /**
     * Lista todos os bilhetes de um jogo
     */
    public function listByGame(int $gameId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM ticket_sales
                WHERE game_id = :game_id
                ORDER BY created_at DESC
            ");
            
            $stmt->execute([':game_id' => $gameId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao listar bilhetes por jogo: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Conta quantos bilhetes foram vendidos para um jogo
     */
    public function countSold(int $gameId): int
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT SUM(quantity) as total
                FROM ticket_sales
                WHERE game_id = :game_id AND status IN ('confirmed', 'pending')
            ");
            
            $stmt->execute([':game_id' => $gameId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int) ($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log('[CourtIQ] Erro ao contar bilhetes vendidos: ' . $e->getMessage());
            return 0;
        }
    }
}