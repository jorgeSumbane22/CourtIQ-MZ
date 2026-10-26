<?php
declare(strict_types=1);

/**
 * CourtIQMZ - Logout
 * 
 * @package CourtIQ
 * @version 2.0.0
 * @author CourtIQMZ
 * 
 * Faz logout do utilizador, destrói a sessão e redireciona para a página inicial.
 */

// ============================================================================
// INICIALIZAÇÃO
// ============================================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Auth.php';

// ============================================================================
// VERIFICAR SE A SESSÃO ESTÁ ATIVA
// ============================================================================

// Verificar se a sessão já está ativa
$sessionActive = session_status() === PHP_SESSION_ACTIVE;

// Se não estiver ativa, iniciar
if (!$sessionActive) {
    session_start();
}

// ============================================================================
// LOGOUT
// ============================================================================

try {
    // Registrar logout no log (se possível)
    $user = Auth::user();
    $userId = $user['id'] ?? null;
    
    // Fazer logout usando o Auth
    Auth::logout();
    
} catch (Throwable $e) {
    error_log('[CourtIQ] Erro no logout: ' . $e->getMessage());
}

// ============================================================================
// DESTRUIR SESSÃO (com verificação)
// ============================================================================

// Limpar variáveis da sessão
$_SESSION = array();

// Destruir cookie da sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Destruir a sessão apenas se estiver ativa
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// ============================================================================
// REDIRECIONAMENTO
// ============================================================================

$redirect = $_GET['redirect'] ?? 'index';

if ($redirect === 'login') {
    header('Location: /CourtIQ/login.php');
} else {
    header('Location: /CourtIQ/index.php');
}
exit;