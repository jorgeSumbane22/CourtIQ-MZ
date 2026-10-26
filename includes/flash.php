<?php
declare(strict_types=1);

/**
 * CourtIQMZ - Flash Messages (helper)
 * 
 * Suporta DOIS formatos:
 * 
 * 1) Associativo (usado em configuracao.php):
 *      flash_set('success', 'Guardado!');
 *      // ou
 *      $_SESSION['flash']['success'] = 'Guardado!';
 * 
 * 2) Array de arrays (formato legado):
 *      $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Guardado!'];
 * 
 * @package CourtIQ
 * @version 2.0.0
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// DEFINIR FLASH
// ============================================================================
if (!function_exists('flash_set')) {
    function flash_set(string $type, string $message): void {
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        $_SESSION['flash'][$type] = $message;
    }
}

// ============================================================================
// OBTER TODAS AS FLASH MESSAGES (normaliza os dois formatos)
// ============================================================================
if (!function_exists('flash_get_all')) {
    function flash_get_all(): array {
        $result = [];

        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            return $result;
        }

        foreach ($_SESSION['flash'] as $key => $value) {
            // Formato 1: array associativo  ['success' => 'msg', 'error' => 'msg2']
            if (is_string($value) && is_string($key)) {
                $result[] = [
                    'type'    => $key,
                    'message' => $value,
                ];
                continue;
            }

            // Formato 2: array de arrays  [ ['type' => 'success', 'message' => 'msg'] ]
            if (is_array($value)) {
                $type    = $value['type']    ?? (is_string($key) ? $key : 'info');
                $message = $value['message'] ?? '';

                if ($message !== '') {
                    $result[] = [
                        'type'    => $type,
                        'message' => $message,
                    ];
                }
            }
        }

        return $result;
    }
}

// ============================================================================
// LIMPAR TODAS AS FLASH MESSAGES
// ============================================================================
if (!function_exists('flash_clear')) {
    function flash_clear(): void {
        unset($_SESSION['flash']);
    }
}

// ============================================================================
// RENDER FLASH MESSAGES (HTML)
// ============================================================================
if (!function_exists('flash_render')) {
    function flash_render(): void {
        $messages = flash_get_all();

        if (empty($messages)) {
            return;
        }

        foreach ($messages as $msg) {
            $type = $msg['type'] ?? 'info';
            $text = $msg['message'] ?? '';

            $class = match ($type) {
                'success' => 'alert-success',
                'error'   => 'alert-error',
                'warning' => 'alert-warning',
                default   => 'alert-info',
            };

            $icon = match ($type) {
                'success' => 'fa-circle-check',
                'error'   => 'fa-circle-xmark',
                'warning' => 'fa-triangle-exclamation',
                default   => 'fa-circle-info',
            };

            echo '<div class="flash-message ' . $class . '">';
            echo '<i class="fa-solid ' . $icon . ' flash-icon"></i>';
            echo htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            echo '</div>';
        }

        // Limpar depois de renderizar
        flash_clear();
    }
}

// ============================================================================
// OBTER PRIMEIRA FLASH (opcional)
// ============================================================================
if (!function_exists('flash_get')) {
    function flash_get(?string $type = null): ?string {
        $all = flash_get_all();
        if (empty($all)) return null;

        if ($type === null) {
            return $all[0]['message'] ?? null;
        }

        foreach ($all as $m) {
            if (($m['type'] ?? '') === $type) {
                return $m['message'] ?? null;
            }
        }

        return null;
    }
}