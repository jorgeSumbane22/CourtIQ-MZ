<?php
/**
 * includes/dark_mode_helper.php - Helper para Dark Mode
 * Gerencia o tema em todas as páginas do sistema
 * 
 * @package CourtIQ
 * @version 1.0.4
 */

// ============================================================================
// FUNÇÃO PARA LER O TEMA DO COOKIE
// ============================================================================
function getCurrentTheme(): string {
    $theme = $_COOKIE['courtIQ_dark_mode'] ?? 'auto';
    
    if (!in_array($theme, ['dark', 'light', 'auto'])) {
        $theme = 'auto';
    }
    
    return $theme;
}

// ============================================================================
// FUNÇÃO PARA DEFINIR A CLASSE DO TEMA
// ============================================================================
function getThemeClass(string $theme): string {
    if ($theme === 'dark') {
        return 'dark-mode';
    } elseif ($theme === 'light') {
        return 'light-mode';
    }
    return '';
}
