<?php
declare(strict_types=1);

/**
 * layout/sidebar.php — o menu lateral, igual em todo o sistema.
 * O que muda de painel para painel vem em $layout (ver head.php):
 * as secções do menu, o texto por baixo do logótipo e, se houver, o seletor de clube.
 *     $layout['teamSwitch'] = ['label' => …, 'teams' => [[id, name], …], 'current' => id]
 */

$sidebarSwitch = $layout['teamSwitch'] ?? null;
$sidebarConta  = $layout['account'] ?? null;      // ligação "A minha conta" / "O meu painel" (site público)
?>
    <aside class="sidebar" id="sidebar" aria-label="Menu">
        <div class="logo">
            <a class="logo-mark" href="<?= e($basePath) ?>index.php" style="text-decoration:none;">
                <div class="logo-icon"><i class="fa-solid fa-basketball"></i></div>
                <div>
                    <div class="logo-text">Court<span>IQ</span>MZ</div>
                    <?php if ($layout['brandSub'] !== ''): ?><div class="logo-sub"><?= e($layout['brandSub']) ?></div><?php endif; ?>
                </div>
            </a>
        </div>

        <?php if ($sidebarSwitch && count($sidebarSwitch['teams']) > 1): ?>
        <div class="team-switch">
            <label for="teamSwitch"><?= e($sidebarSwitch['label']) ?></label>
            <select id="teamSwitch" data-team-switch>
                <?php foreach ($sidebarSwitch['teams'] as $sidebarTeam): ?>
                    <option value="<?= (int) $sidebarTeam['id'] ?>"<?= (int) $sidebarTeam['id'] === (int) $sidebarSwitch['current'] ? ' selected' : '' ?>><?= e($sidebarTeam['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <nav class="nav">
            <?php foreach ($layout['sections'] as $sidebarTitulo => $sidebarItens): ?>
                <div class="nav-section"><?= e($sidebarTitulo) ?></div>
                <?php foreach ($sidebarItens as $sidebarChave => $sidebarItem): ?>
                <a class="nav-item<?= $activeNav === $sidebarChave ? ' active' : '' ?>" href="<?= e($sidebarItem['href']) ?>"<?= $activeNav === $sidebarChave ? ' aria-current="page"' : '' ?>>
                    <i class="<?= e($sidebarItem['icon']) ?> nav-icon"></i><?= e($sidebarItem['label']) ?>
                </a>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php if ($isLoggedIn): ?>
                <div class="nav-section" style="padding-top:16px;">Conta</div>
                <?php if ($sidebarConta): ?>
                <a class="nav-item<?= $activeNav === 'conta' ? ' active' : '' ?>" href="<?= e($sidebarConta['href']) ?>">
                    <i class="fa-solid fa-user nav-icon"></i><?= e($sidebarConta['label']) ?>
                </a>
                <?php endif; ?>
                <a class="logout-item" href="<?= e($logoutPath) ?>" data-confirm-leave>
                    <i class="fa-solid fa-right-from-bracket nav-icon"></i>Terminar Sessão
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="team-card">
                <div class="team-av"<?= $layoutAvatar !== '' ? ' style="background:none;overflow:hidden;padding:0;"' : '' ?>>
                    <?php if ($layoutAvatar !== ''): ?><img src="<?= e($layoutAvatar) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">
                    <?php elseif ($isLoggedIn): ?><?= e(mb_strtoupper(mb_substr($userName ?: 'U', 0, 2))) ?>
                    <?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
                </div>
                <div>
                    <div class="team-name"><?= e($isLoggedIn ? $userName : 'Visitante') ?></div>
                    <div class="team-role"><?= e($isLoggedIn ? ($layout['roleLabel'] ?: role_label($userRole)) : 'Não autenticado') ?></div>
                </div>
            </div>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
