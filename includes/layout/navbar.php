<?php
declare(strict_types=1);

/**
 * layout/navbar.php — a barra de cima, igual em todo o sistema.
 * Abre também a zona de conteúdo (<main> e .content) e mostra as mensagens flash.
 * O que muda vem em $layout (ver head.php):
 *     notifHref / chatHref   ligações dos ícones de avisos e de chat (vazio = sem ícone)
 *     accountHref            para onde vai o clique no nome do utilizador
 */

$navbarNotif = (string) ($layout['notifHref'] ?? '');
$navbarChat  = (string) ($layout['chatHref'] ?? '');
$navbarConta = (string) ($layout['accountHref'] ?? '');
?>
    <main class="main">
        <div class="topbar">
            <div class="topbar-left">
                <button type="button" class="topbar-icon-btn menu-toggle" id="menuToggle" aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <?php if (!empty($pageIcon)): ?><i class="<?= e($pageIcon) ?>" style="color:var(--accent);margin-right:8px;"></i><?php endif; ?>
                    <?= e($pageTitle) ?>
                </div>
            </div>

            <div class="topbar-right">
                <?= lang_switcher() ?>
                <?php if ($isLoggedIn): ?>
                    <?php if ($navbarNotif !== ''): ?>
                    <a href="<?= e($navbarNotif) ?>" class="topbar-icon-btn" title="Notificações" aria-label="Notificações<?= $totalNotificacoesNaoLidas ? ' (' . $totalNotificacoesNaoLidas . ' por ler)' : '' ?>">
                        <i class="fa-solid fa-bell"></i>
                        <span class="badge<?= $totalNotificacoesNaoLidas > 0 ? '' : ' zero' ?>"><?= $totalNotificacoesNaoLidas > 0 ? $totalNotificacoesNaoLidas : '' ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($navbarChat !== ''): ?>
                    <a href="<?= e($navbarChat) ?>" class="topbar-icon-btn" title="Chat" aria-label="Chat<?= $mensagensNaoLidas ? ' (' . $mensagensNaoLidas . ' por ler)' : '' ?>">
                        <i class="fa-solid fa-comment-dots"></i>
                        <span class="badge<?= $mensagensNaoLidas > 0 ? '' : ' zero' ?>"><?= $mensagensNaoLidas > 0 ? $mensagensNaoLidas : '' ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($navbarNotif !== '' || $navbarChat !== ''): ?><div class="topbar-divider"></div><?php endif; ?>

                    <<?= $navbarConta !== '' ? 'a href="' . e($navbarConta) . '" style="text-decoration:none;"' : 'div' ?> class="user-info">
                        <div class="user-avatar"<?= $layoutAvatar !== '' ? ' style="background:none;overflow:hidden;"' : '' ?>>
                            <?php if ($layoutAvatar !== ''): ?><img src="<?= e($layoutAvatar) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                            <?php else: ?><?= e(mb_strtoupper(mb_substr($userName ?: 'U', 0, 2))) ?><?php endif; ?>
                        </div>
                        <span class="user-name"><?= e($userName) ?></span>
                    </<?= $navbarConta !== '' ? 'a' : 'div' ?>>
                    <a href="<?= e($logoutPath) ?>" class="logout-btn" title="Sair" aria-label="Sair" data-confirm-leave>
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </a>
                <?php else: ?>
                    <a href="<?= e($basePath) ?>login.php" class="login-btn login-btn--ghost" title="Entrar">
                        <i class="fa-solid fa-right-to-bracket"></i><span class="login-btn-text">Entrar</span>
                    </a>
                    <a href="<?= e($basePath) ?>registar.php" class="login-btn" title="Criar conta">
                        <i class="fa-solid fa-user-plus"></i><span class="login-btn-text">Criar conta</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="content">
            <?php flash_render(); ?>
