<?php
declare(strict_types=1);

/**
 * layout/topnav.php — a barra de navegação do site público (o site não tem menu lateral).
 * Em cima, uma faixa de utilidades: clube em vista, idioma e conta. Por baixo, o logótipo e o menu:
 * as secções "Principal" ficam como ligações diretas e as outras (Liga, Clube) como menus suspensos.
 * Como navbar.php nos painéis, abre a zona de conteúdo (<main> e .content), que footer.php fecha.
 * O menu vem de $layout['sections'] (ver public_top.php).
 */

$topnavSwitch = $layout['teamSwitch'] ?? null;
$topnavConta  = $layout['account'] ?? null;
?>
    <header class="pubnav" id="pubnav">
        <div class="pubnav-util">
            <div class="pubnav-in">
                <?php if ($topnavSwitch && count($topnavSwitch['teams']) > 0): ?>
                <label class="pubnav-club">
                    <i class="fa-solid fa-shield-halved"></i><span><?= e($topnavSwitch['label']) ?></span>
                    <select data-team-switch aria-label="Escolher clube">
                        <option value="0"<?= (int) $topnavSwitch['current'] === 0 ? ' selected' : '' ?>>Toda a liga</option>
                        <?php foreach ($topnavSwitch['teams'] as $topnavTeam): ?>
                            <option value="<?= (int) $topnavTeam['id'] ?>"<?= (int) $topnavTeam['id'] === (int) $topnavSwitch['current'] ? ' selected' : '' ?>><?= e($topnavTeam['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php else: ?><span></span><?php endif; ?>

                <div class="pubnav-tools">
                    <?= lang_switcher() ?>
                    <?php if ($isLoggedIn): ?>
                        <a class="user-info" href="<?= e($topnavConta['href'] ?? ($basePath . 'conta.php')) ?>" title="<?= e($topnavConta['label'] ?? 'A minha conta') ?>">
                            <div class="user-avatar"<?= $layoutAvatar !== '' ? ' style="background:none;overflow:hidden;"' : '' ?>>
                                <?php if ($layoutAvatar !== ''): ?><img src="<?= e($layoutAvatar) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                                <?php else: ?><?= e(mb_strtoupper(mb_substr($userName ?: 'U', 0, 2))) ?><?php endif; ?>
                            </div>
                            <span class="user-name"><?= e($userName) ?></span>
                        </a>
                        <a href="<?= e($logoutPath) ?>" class="logout-btn" title="Sair" aria-label="Sair" data-confirm-leave><i class="fa-solid fa-right-from-bracket"></i></a>
                    <?php else: ?>
                        <a href="<?= e($basePath) ?>login.php" class="login-btn login-btn--ghost" title="Entrar"><i class="fa-solid fa-right-to-bracket"></i><span class="login-btn-text">Entrar</span></a>
                        <a href="<?= e($basePath) ?>registar.php" class="login-btn" title="Criar conta"><i class="fa-solid fa-user-plus"></i><span class="login-btn-text">Criar conta</span></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="pubnav-main">
            <div class="pubnav-in">
                <a class="pubnav-logo logo-mark" href="<?= e($basePath) ?>index.php">
                    <span class="logo-icon"><i class="fa-solid fa-basketball"></i></span>
                    <div class="logo-text">Court<span>IQ</span>MZ</div>
                </a>
                <button type="button" class="pubnav-burger" data-topnav-toggle aria-controls="pubnavMenu" aria-expanded="false" aria-label="Abrir menu"><i class="fa-solid fa-bars"></i></button>

                <nav class="pubnav-menu" id="pubnavMenu" aria-label="Menu principal">
                    <ul>
                        <?php foreach ($layout['sections'] as $topnavTitulo => $topnavItens): ?>
                            <?php if ($topnavTitulo === 'Principal'): ?>
                                <?php foreach ($topnavItens as $topnavChave => $topnavItem): $topnavAtivo = $activeNav === $topnavChave; ?>
                                <li><a class="pubnav-link<?= $topnavAtivo ? ' is-active' : '' ?>" href="<?= e($topnavItem['href']) ?>"<?= $topnavAtivo ? ' aria-current="page"' : '' ?>><?= e($topnavItem['label']) ?></a></li>
                                <?php endforeach; ?>
                            <?php else: $topnavGrupoAtivo = isset($topnavItens[$activeNav]); ?>
                                <li class="pubnav-dd">
                                    <button type="button" class="pubnav-link<?= $topnavGrupoAtivo ? ' is-active' : '' ?>" data-dd aria-expanded="false" aria-haspopup="true">
                                        <?= e($topnavTitulo) ?><i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                    <div class="pubnav-drop">
                                        <?php foreach ($topnavItens as $topnavChave => $topnavItem): $topnavAtivo = $activeNav === $topnavChave; ?>
                                        <a class="<?= $topnavAtivo ? 'is-active' : '' ?>" href="<?= e($topnavItem['href']) ?>"<?= $topnavAtivo ? ' aria-current="page"' : '' ?>><i class="<?= e($topnavItem['icon']) ?>"></i><?= e($topnavItem['label']) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($isLoggedIn && $topnavConta): ?>
                        <li class="pubnav-only-mobile"><a class="pubnav-link<?= $activeNav === 'conta' ? ' is-active' : '' ?>" href="<?= e($topnavConta['href']) ?>"><?= e($topnavConta['label']) ?></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </div>
    </header>

    <main class="main main--top">
        <div class="content">
            <?php flash_render(); ?>
