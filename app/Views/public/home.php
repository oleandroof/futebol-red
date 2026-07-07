<?php require __DIR__ . '/../partials/header.php'; ?>
<?php
$groupedGames = [];
foreach ($games as $game) {
    $leagueId = (int) ($game['league_id'] ?? 0);
    if (!isset($groupedGames[$leagueId])) {
        $groupedGames[$leagueId] = [
            'league_id' => $leagueId,
            'league_name' => (string) ($game['league_name'] ?? 'Liga'),
            'games' => [],
        ];
    }
    $groupedGames[$leagueId]['games'][] = $game;
}
$stakeDefault = 10.00;
$potentialDefault = $betSlipTotalOdd > 0 ? ($betSlipTotalOdd * $stakeDefault) : 0;
$activePeriod = in_array(($activePeriod ?? 'today'), ['all', 'today', 'tomorrow', 'week', 'live'], true) ? $activePeriod : 'today';
$activeLeagueId = max(0, (int) ($activeLeagueId ?? 0));
$searchQuery = trim((string) ($searchQuery ?? ''));
$globalBettingLock = !empty($globalBettingLock);
$betSlipHasLockedItems = !empty($betSlipHasLockedItems);
$isAgentUser = !empty($isAgentUser);
$selectedBetSlipOddIds = array_flip(array_map(static fn (array $item): int => (int) ($item['odd_id'] ?? 0), $betSlipItems ?? []));
$buildUrl = static function (string $period, string $category, int $leagueId) use ($searchQuery): string {
    $params = ['period' => $period];
    if ($category !== '') {
        $params['cat'] = $category;
    }
    if ($leagueId > 0) {
        $params['league'] = $leagueId;
    }
    if ($searchQuery !== '') {
        $params['q'] = $searchQuery;
    }

    return app_url('/?' . http_build_query($params));
};
$buildCategoryUrl = static function (string $category) use ($searchQuery): string {
    $params = ['period' => 'all'];
    if ($category !== '') {
        $params['cat'] = $category;
    }
    if ($searchQuery !== '') {
        $params['q'] = $searchQuery;
    }

    return app_url('/?' . http_build_query($params));
};
$buildLeagueUrl = static function (int $leagueId) use ($searchQuery, $activeCategory, $activePeriod): string {
    $params = ['period' => $activePeriod];
    if ($activeCategory !== '') {
        $params['cat'] = $activeCategory;
    }
    if ($leagueId > 0) {
        $params['league'] = $leagueId;
    }
    if ($searchQuery !== '') {
        $params['q'] = $searchQuery;
    }

    return app_url('/?' . http_build_query($params));
};
?>
<div class="page" data-page>
    <header class="top-header">
        <div class="top-left">
            <button type="button" class="mobile-menu-btn" data-mobile-menu-toggle aria-expanded="false" aria-controls="mobile-menu">&#9776;</button>
            <a class="mobile-top-logo" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES) ?>"><img src="<?= htmlspecialchars(app_url('public/assets/img/logo.png'), ENT_QUOTES) ?>" alt="PA DASORTE"></a>
            <a class="brand" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES) ?>">PA DASORTE</a>
            <button type="button" class="menu-btn" data-menu-toggle>&#9776;</button>
        </div>
        <div class="top-right">
            <?php if ($user): ?>
                <?php if ($isAgentUser): ?>
                    <span class="top-balance">Caixa: R$ <?= number_format((float) ($user['agent_balance'] ?? 0), 2, ',', '.') ?></span>
                    <a class="btn-outline" href="<?= htmlspecialchars(app_url('/agent'), ENT_QUOTES) ?>">Painel</a>
                <?php else: ?>
                    <span class="top-balance">Saldo: R$ <?= number_format((float) $user['balance'], 2, ',', '.') ?></span>
                    <button type="button" class="btn-outline" data-modal-open="deposit-modal">Depositar</button>
                    <button type="button" class="btn-outline" data-modal-open="withdraw-modal">Sacar</button>
                    <button type="button" class="btn-outline" data-modal-open="profile-modal">Perfil</button>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin'): ?><a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin'), ENT_QUOTES) ?>">Admin</a><?php endif; ?>
                <a class="btn-dark" href="<?= htmlspecialchars(app_url('/logout'), ENT_QUOTES) ?>">Sair</a>
            <?php else: ?>
                <button type="button" class="btn-outline" data-modal-open="register-modal">Registrar</button>
                <button type="button" class="btn-dark" data-modal-open="login-modal">Entrar</button>
            <?php endif; ?>
        </div>
    </header>

    <div class="mobile-menu-backdrop" data-mobile-menu-close hidden></div>
    <aside class="mobile-menu-panel" id="mobile-menu" data-mobile-menu hidden>
        <div class="mobile-menu-head">
            <a class="mobile-menu-logo" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES) ?>">
                <img src="<?= htmlspecialchars(app_url('public/assets/img/logo.png'), ENT_QUOTES) ?>" alt="PA Dasorte">
            </a>
            <button type="button" class="mobile-menu-close" data-mobile-menu-close aria-label="Fechar menu">&times;</button>
        </div>

        <div class="mobile-menu-body">
            <?php if ($user): ?>
                <div class="mobile-menu-user">
                    <strong><?= htmlspecialchars($user['name'], ENT_QUOTES) ?></strong>
                    <span><?= $isAgentUser ? 'Caixa' : 'Saldo' ?>: R$ <?= number_format((float) ($isAgentUser ? ($user['agent_balance'] ?? 0) : $user['balance']), 2, ',', '.') ?></span>
                </div>
                <div class="mobile-menu-actions">
                    <?php if ($isAgentUser): ?>
                        <a class="btn-outline" href="<?= htmlspecialchars(app_url('/agent'), ENT_QUOTES) ?>" data-mobile-menu-close>Painel</a>
                    <?php else: ?>
                        <button type="button" class="btn-outline" data-modal-open="deposit-modal" data-mobile-menu-close>Depositar</button>
                        <button type="button" class="btn-outline" data-modal-open="withdraw-modal" data-mobile-menu-close>Sacar</button>
                        <button type="button" class="btn-outline" data-modal-open="profile-modal" data-mobile-menu-close>Perfil</button>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'admin'): ?><a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin'), ENT_QUOTES) ?>">Admin</a><?php endif; ?>
                    <a class="btn-dark" href="<?= htmlspecialchars(app_url('/logout'), ENT_QUOTES) ?>">Sair</a>
                </div>
            <?php else: ?>
                <div class="mobile-menu-actions guest">
                    <button type="button" class="btn-outline" data-modal-open="register-modal" data-mobile-menu-close>Registrar</button>
                    <button type="button" class="btn-dark" data-modal-open="login-modal" data-mobile-menu-close>Entrar</button>
                </div>
            <?php endif; ?>

            <div class="menu-section">CATEGORIAS</div>
            <a href="<?= htmlspecialchars($buildCategoryUrl(''), ENT_QUOTES) ?>" class="menu-item <?= $activeCategory === '' ? 'active' : '' ?>">Todas</a>
            <?php foreach ($categories as $category): ?>
                <a href="<?= htmlspecialchars($buildCategoryUrl((string) $category['slug']), ENT_QUOTES) ?>" class="menu-item <?= $activeCategory === $category['slug'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($category['name'], ENT_QUOTES) ?> (<?= (int) $category['total_games'] ?>)
                </a>
            <?php endforeach; ?>

            <div class="menu-section">LIGAS</div>
            <a href="<?= htmlspecialchars($buildLeagueUrl(0), ENT_QUOTES) ?>" class="menu-item <?= $activeLeagueId === 0 ? 'active' : '' ?>" data-mobile-menu-close>Todas</a>
            <?php foreach ($leagues as $league): ?>
                <a href="<?= htmlspecialchars($buildLeagueUrl((int) $league['id']), ENT_QUOTES) ?>" class="menu-item <?= $activeLeagueId === (int) $league['id'] ? 'active' : '' ?>" data-mobile-menu-close>
                    <?= htmlspecialchars($league['name'], ENT_QUOTES) ?> (<?= (int) $league['total_games'] ?>)
                </a>
            <?php endforeach; ?>
        </div>
    </aside>
    <main class="main-grid" data-main-grid>
        <aside class="left-menu" data-menu>
            <div class="logo-wrap">
                <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES) ?>"><img src="<?= htmlspecialchars(app_url('public/assets/img/logo.png'), ENT_QUOTES) ?>" alt="PA Dasorte"></a>
            </div>

            <div class="menu-section">CATEGORIAS</div>
            <a href="<?= htmlspecialchars($buildCategoryUrl(''), ENT_QUOTES) ?>" class="menu-item <?= $activeCategory === '' ? 'active' : '' ?>">Todas</a>
            <?php foreach ($categories as $category): ?>
                <a href="<?= htmlspecialchars($buildCategoryUrl((string) $category['slug']), ENT_QUOTES) ?>" class="menu-item <?= $activeCategory === $category['slug'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($category['name'], ENT_QUOTES) ?> (<?= (int) $category['total_games'] ?>)
                </a>
            <?php endforeach; ?>

            <div class="menu-section">LIGAS</div>
            <a href="<?= htmlspecialchars($buildLeagueUrl(0), ENT_QUOTES) ?>" class="menu-item <?= $activeLeagueId === 0 ? 'active' : '' ?>">Todas</a>
            <?php foreach ($leagues as $league): ?>
                <a href="<?= htmlspecialchars($buildLeagueUrl((int) $league['id']), ENT_QUOTES) ?>" class="menu-item <?= $activeLeagueId === (int) $league['id'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($league['name'], ENT_QUOTES) ?> (<?= (int) $league['total_games'] ?>)
                </a>
            <?php endforeach; ?>
        </aside>

        <section class="center-content">
            <div class="sports-tabs">
                <?php foreach ($categories as $category): ?>
                    <a href="<?= htmlspecialchars($buildCategoryUrl((string) $category['slug']), ENT_QUOTES) ?>" class="tab <?= $activeCategory === $category['slug'] ? 'active' : '' ?>"><?= strtoupper(htmlspecialchars($category['name'], ENT_QUOTES)) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="day-search-row">
                <div class="days">
                    <a href="<?= htmlspecialchars($buildUrl('today', (string) $activeCategory, $activeLeagueId), ENT_QUOTES) ?>" class="day <?= $activePeriod === 'today' ? 'active' : '' ?>">Hoje</a>
                    <a href="<?= htmlspecialchars($buildUrl('tomorrow', (string) $activeCategory, $activeLeagueId), ENT_QUOTES) ?>" class="day <?= $activePeriod === 'tomorrow' ? 'active' : '' ?>">Amanha</a>
                    <a href="<?= htmlspecialchars($buildUrl('week', (string) $activeCategory, $activeLeagueId), ENT_QUOTES) ?>" class="day <?= $activePeriod === 'week' ? 'active' : '' ?>">Semana</a>
                    <a href="<?= htmlspecialchars($buildUrl('live', (string) $activeCategory, $activeLeagueId), ENT_QUOTES) ?>" class="day live <?= $activePeriod === 'live' ? 'active' : '' ?>">Ao vivo</a>
                </div>
                <input type="text" class="search" placeholder="Pesquisar por liga, time, horario" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" data-game-search data-search-url="<?= htmlspecialchars(app_url('/'), ENT_QUOTES) ?>" data-search-category="<?= htmlspecialchars($activeCategory, ENT_QUOTES) ?>" data-search-league="<?= (int) $activeLeagueId ?>">
            </div>

            <?php if (empty($groupedGames)): ?>
                <div class="empty-games">Nenhum jogo cadastrado pelo admin para este filtro.</div>
            <?php endif; ?>

            <div class="empty-games" data-search-empty hidden>Nenhum jogo encontrado para a pesquisa.</div>

            <?php if (!empty($groupedGames)): ?>
                <div class="games-toolbar" data-games-toolbar>
                    <div class="games-toolbar-copy">
                        <strong>Ligas</strong>
                        <span>Toque no nome para recolher.</span>
                    </div>
                    <div class="games-toolbar-actions">
                        <button type="button" class="games-toolbar-btn" data-league-collapse-all>Minimizar</button>
                        <button type="button" class="games-toolbar-btn" data-league-expand-all>Expandir</button>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($groupedGames as $leagueGroup): ?>
                <?php
                $leagueName = (string) ($leagueGroup['league_name'] ?? 'Liga');
                $leagueGames = $leagueGroup['games'] ?? [];
                $leagueKey = ((int) ($leagueGroup['league_id'] ?? 0) > 0)
                    ? (string) ((int) $leagueGroup['league_id'])
                    : substr(md5((string) $activeCategory . '|' . $leagueName), 0, 16);
                $gameLabel = count($leagueGames) === 1 ? 'jogo' : 'jogos';
                ?>
                <div class="league-block" id="league-<?= htmlspecialchars($leagueKey, ENT_QUOTES) ?>" data-league-block data-league-key="<?= htmlspecialchars($leagueKey, ENT_QUOTES) ?>" data-search-text="<?= htmlspecialchars(strtolower((string) $leagueName), ENT_QUOTES) ?>">
                    <button type="button" class="league-title" data-league-toggle aria-expanded="true">
                        <span class="league-title-main">
                            <span class="league-title-label"><?= htmlspecialchars($leagueName, ENT_QUOTES) ?></span>
                            <span class="league-title-count"><?= count($leagueGames) ?> <?= $gameLabel ?></span>
                        </span>
                        <span class="league-title-icon" aria-hidden="true"></span>
                    </button>
                    <div class="league-games" data-league-panel>
                    <?php foreach ($leagueGames as $game): ?>
                        <?php
                        $mainMarket = null;
                        $extraMarkets = [];
                        $gameLocked = !empty($game['betting_is_locked']);
                        $gameLockReason = trim((string) ($game['betting_lock_reason'] ?? ''));
                        foreach (($game['markets'] ?? []) as $market) {
                            if (($market['market_name'] ?? '') === 'Resultado final') {
                                $mainMarket = $market;
                                continue;
                            }
                            $extraMarkets[] = $market;
                        }
                        if ($mainMarket === null && !empty($extraMarkets)) {
                            $mainMarket = array_shift($extraMarkets);
                        }
                        $searchText = strtolower(implode(' ', [
                            (string) $leagueName,
                            (string) ($game['home_name'] ?? ''),
                            (string) ($game['away_name'] ?? ''),
                            date('d/m H:i', strtotime($game['match_date'])),
                            date('H:i', strtotime($game['match_date'])),
                        ]));
                        ?>
                        <div class="match-row match-row-markets <?= $gameLocked ? 'is-betting-locked' : '' ?>" data-game-row data-search-text="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                            <div class="teams" style="width:100%;display:block;text-align:center;"><span class="teams-names" style="display:inline-block;text-align:center;max-width:100%;"><?= htmlspecialchars($game['home_name'], ENT_QUOTES) ?> <strong>X</strong> <?= htmlspecialchars($game['away_name'], ENT_QUOTES) ?></span></div>
                            <div class="match-time">
                                <?= date('d/m H:i', strtotime($game['match_date'])) ?>
                            </div>
                            <div class="match-main-odds">
                                <div class="odds-inline odds-inline-main">
                                    <?php foreach (($mainMarket['options'] ?? []) as $odd): ?>
                                        <?php if ($gameLocked): ?>
                                            <button type="button" class="odd-btn is-disabled" disabled title="<?= htmlspecialchars($gameLockReason !== '' ? $gameLockReason : 'Mercado travado', ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($odd['option_name'], ENT_QUOTES) ?> <?= number_format((float) $odd['odd_value'], 2, ',', '.') ?>
                                            </button>
                                        <?php elseif ($user): ?>
                                            <form method="post" action="<?= htmlspecialchars(app_url('/betslip/add'), ENT_QUOTES) ?>" class="odd-form">
                                                <input type="hidden" name="odd_id" value="<?= (int) $odd['id'] ?>">
                                                <input type="hidden" name="cat" value="<?= htmlspecialchars($activeCategory, ENT_QUOTES) ?>">
                                                <input type="hidden" name="league" value="<?= (int) $activeLeagueId ?>">
                                                <input type="hidden" name="period" value="<?= htmlspecialchars($activePeriod, ENT_QUOTES) ?>">
                                                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>">
                                                <button type="submit" class="odd-btn <?= isset($selectedBetSlipOddIds[(int) $odd['id']]) ? 'selected' : '' ?>">
                                                    <?= htmlspecialchars($odd['option_name'], ENT_QUOTES) ?> <?= number_format((float) $odd['odd_value'], 2, ',', '.') ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="odd-btn" data-modal-open="login-modal">
                                                <?= htmlspecialchars($odd['option_name'], ENT_QUOTES) ?> <?= number_format((float) $odd['odd_value'], 2, ',', '.') ?>
                                            </button>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!empty($extraMarkets)): ?>
                                        <button type="button" class="odd-btn odd-more-btn" data-market-toggle aria-expanded="false">+</button>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($extraMarkets)): ?>
                                    <div class="extra-markets-panel" data-market-panel hidden>
                                        <div class="markets-list">
                                            <?php foreach ($extraMarkets as $market): ?>
                                                <div class="market-group">
                                                    <div class="market-title"><?= htmlspecialchars($market['market_name'], ENT_QUOTES) ?></div>
                                                    <div class="odds-inline">
                                                        <?php foreach (($market['options'] ?? []) as $odd): ?>
                                                            <?php if ($gameLocked): ?>
                                                                <button type="button" class="odd-btn is-disabled" disabled title="<?= htmlspecialchars($gameLockReason !== '' ? $gameLockReason : 'Mercado travado', ENT_QUOTES) ?>">
                                                                    <?= htmlspecialchars($odd['option_name'], ENT_QUOTES) ?> <?= number_format((float) $odd['odd_value'], 2, ',', '.') ?>
                                                                </button>
                                                            <?php elseif ($user): ?>
                                                                <form method="post" action="<?= htmlspecialchars(app_url('/betslip/add'), ENT_QUOTES) ?>" class="odd-form">
                                                <input type="hidden" name="odd_id" value="<?= (int) $odd['id'] ?>">
                                                <input type="hidden" name="cat" value="<?= htmlspecialchars($activeCategory, ENT_QUOTES) ?>">
                                                <input type="hidden" name="league" value="<?= (int) $activeLeagueId ?>">
                                                <input type="hidden" name="period" value="<?= htmlspecialchars($activePeriod, ENT_QUOTES) ?>">
                                                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>">
                                                                    <button type="submit" class="odd-btn <?= isset($selectedBetSlipOddIds[(int) $odd['id']]) ? 'selected' : '' ?>">
                                                                        <?= htmlspecialchars($odd['option_name'], ENT_QUOTES) ?> <?= number_format((float) $odd['odd_value'], 2, ',', '.') ?>
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <button type="button" class="odd-btn" data-modal-open="login-modal">
                                                                    <?= htmlspecialchars($odd['option_name'], ENT_QUOTES) ?> <?= number_format((float) $odd['odd_value'], 2, ',', '.') ?>
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <aside class="right-slip" id="betslip-panel">
            <div class="slip-head">BILHETE E CONFERENCIA</div>
            <div class="slip-body">
                <div class="right-menu-tabs">
                    <strong>Meus bilhetes</strong>
                </div>
                <?php if ($user): ?>
                    <div class="my-tickets-list">
                        <?php foreach (($myOpenTickets ?? []) as $myTicket): ?>
                            <article class="my-ticket-card">
                                <div><strong><?= htmlspecialchars($myTicket['ticket_code'], ENT_QUOTES) ?></strong> - <?= htmlspecialchars($myTicket['status'], ENT_QUOTES) ?></div>
                                <div>Data: <?= date('d/m/Y H:i', strtotime($myTicket['created_at'])) ?></div>
                                <div>Stake: R$ <?= number_format((float) $myTicket['stake'], 2, ',', '.') ?> | Retorno: R$ <?= number_format((float) $myTicket['potential_return'], 2, ',', '.') ?></div>
                                <?php foreach ($myTicket['items'] as $it): ?>
                                    <div class="my-ticket-game"><?= htmlspecialchars($it['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($it['away_name'], ENT_QUOTES) ?> - <?= date('d/m H:i', strtotime($it['match_date'])) ?> - <?= htmlspecialchars($it['market_name'], ENT_QUOTES) ?> / <?= htmlspecialchars($it['option_name'], ENT_QUOTES) ?> @ <?= number_format((float) $it['odd_value'], 2, ',', '.') ?></div>
                                <?php endforeach; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if (empty($myTickets)): ?>
                            <div class="login-required-note">Nenhum bilhete em aberto.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= htmlspecialchars(app_url('/ticket/check'), ENT_QUOTES) ?>" class="ticket-check-form">
                    <input type="text" name="ticket_code" placeholder="Conferir bilhete por codigo" required>
                    <button type="submit" class="btn-outline ticket-btn">Conferir bilhete</button>
                </form>

                <?php if ($ticketCheck): ?>
                    <div class="ticket-check-result">
                        <div><strong>Codigo:</strong> <?= htmlspecialchars($ticketCheck['ticket_code'], ENT_QUOTES) ?></div>
                        <div><strong>Status:</strong> <?= htmlspecialchars($ticketCheck['status'], ENT_QUOTES) ?></div>
                        <div><strong>Apostador:</strong> <?= htmlspecialchars($ticketCheck['user_name'], ENT_QUOTES) ?></div>
                        <div><strong>Possivel ganho:</strong> R$ <?= number_format((float) $ticketCheck['potential_return'], 2, ',', '.') ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!$user): ?>
                    <div class="login-required-note">Faca login para montar e confirmar o bilhete.</div>
                    <button type="button" class="btn-bet" data-modal-open="login-modal">Entrar para apostar</button>
                <?php else: ?>
<div class="bet-items-title">Selecoes no bilhete (<?= count($betSlipItems) ?>)</div>
                    <div class="bet-items-list">
                        <?php foreach ($betSlipItems as $item): ?>
                            <div class="bet-item">
                                <div class="bet-item-main">
                                    <strong><?= htmlspecialchars($item['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($item['away_name'], ENT_QUOTES) ?></strong>
                                    <span><?= htmlspecialchars($item['market_name'], ENT_QUOTES) ?> / <?= htmlspecialchars($item['option_name'], ENT_QUOTES) ?> @ <?= number_format((float) $item['odd_value'], 2, ',', '.') ?></span>
                                    <?php if (!empty($item['betting_is_locked'])): ?>
                                        <small class="bet-item-lock-note"><?= htmlspecialchars((string) ($item['betting_lock_reason'] ?? 'Mercado travado'), ENT_QUOTES) ?></small>
                                    <?php endif; ?>
                                </div>
                                <form method="post" action="<?= htmlspecialchars(app_url('/betslip/remove'), ENT_QUOTES) ?>">
                                    <input type="hidden" name="odd_id" value="<?= (int) $item['odd_id'] ?>">
                                    <input type="hidden" name="cat" value="<?= htmlspecialchars($activeCategory, ENT_QUOTES) ?>">
                                    <input type="hidden" name="league" value="<?= (int) $activeLeagueId ?>">
                                    <input type="hidden" name="period" value="<?= htmlspecialchars($activePeriod, ENT_QUOTES) ?>">
                                    <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>">
                                    <button type="submit" class="remove-item">x</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form method="post" action="<?= htmlspecialchars(app_url('/bets/confirm'), ENT_QUOTES) ?>">
                        <input type="hidden" name="cat" value="<?= htmlspecialchars($activeCategory, ENT_QUOTES) ?>">
                        <input type="hidden" name="league" value="<?= (int) $activeLeagueId ?>">
                        <input type="hidden" name="period" value="<?= htmlspecialchars($activePeriod, ENT_QUOTES) ?>">
                        <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>">
                        <?php if ($globalBettingLock): ?>
                            <div class="login-required-note">As apostas estao travadas globalmente pelo admin no momento.</div>
                        <?php elseif ($betSlipHasLockedItems): ?>
                            <div class="login-required-note">Seu bilhete tem jogos travados. Remova-os ou atualize a lista antes de confirmar.</div>
                        <?php elseif ($isAgentUser): ?>
                            <div class="login-required-note">Gerentes e cambistas emitem bilhete somente apos pagamento Pix confirmado na hora.</div>
                        <?php endif; ?>
                        <input type="number" class="stake-input" step="0.01" min="1" name="stake" value="<?= number_format($stakeDefault, 2, '.', '') ?>" data-stake-input>
                        <div class="quick-values">
                            <button type="button" data-stake-value="5">5,00</button>
                            <button type="button" data-stake-value="10">10,00</button>
                            <button type="button" data-stake-value="20">20,00</button>
                            <button type="button" data-stake-value="30">30,00</button>
                            <button type="button" data-stake-value="50">50,00</button>
                        </div>
                        <div class="slip-resume">
                            <span>Cotacao <strong data-total-odd><?= $betSlipTotalOdd > 0 ? number_format((float) $betSlipTotalOdd, 2, ',', '.') : '0,00' ?></strong></span>
                            <span>Possivel retorno <strong class="green" data-potential-return>R$ <?= number_format((float) $potentialDefault, 2, ',', '.') ?></strong></span>
                        </div>
                        <button type="submit" class="btn-bet" <?= empty($betSlipItems) || $globalBettingLock || $betSlipHasLockedItems ? 'disabled' : '' ?>><?= $isAgentUser ? 'Gerar Pix do bilhete' : 'Confirmar bilhete' ?></button>
                    </form>

                    <?php if (!empty($betSlipItems)): ?>
                        <form method="post" action="<?= htmlspecialchars(app_url('/betslip/clear'), ENT_QUOTES) ?>">
                            <input type="hidden" name="cat" value="<?= htmlspecialchars($activeCategory, ENT_QUOTES) ?>">
                            <input type="hidden" name="league" value="<?= (int) $activeLeagueId ?>">
                            <input type="hidden" name="period" value="<?= htmlspecialchars($activePeriod, ENT_QUOTES) ?>">
                            <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>">
                            <button type="submit" class="btn-outline ticket-btn">Limpar bilhete</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </aside>
    </main>
</div>

<div class="modal" id="login-modal">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card">
        <button class="modal-close" data-modal-close type="button">&times;</button>
        <h2>Entrar</h2>
        <form method="post" action="<?= htmlspecialchars(app_url('/login'), ENT_QUOTES) ?>" class="modal-form">
            <input type="email" name="email" placeholder="E-mail" required>
            <input type="password" name="password" placeholder="Senha" required>
            <button type="submit" class="btn-dark full">Entrar</button>
            <button type="button" class="btn-outline full" data-modal-open="register-modal">Criar conta</button>
        </form>
    </div>
</div>

<div class="modal" id="register-modal">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card">
        <button class="modal-close" data-modal-close type="button">&times;</button>
        <h2>Criar conta</h2>
        <form method="post" action="<?= htmlspecialchars(app_url('/register'), ENT_QUOTES) ?>" class="modal-form">
            <input type="text" name="name" placeholder="Nome" required>
            <input type="email" name="email" placeholder="E-mail" required>
            <input type="text" name="cpf" placeholder="CPF (11 digitos)" maxlength="14" required>
            <input type="password" name="password" placeholder="Senha" required>
            <button type="submit" class="btn-dark full">Cadastrar</button>
        </form>
    </div>
</div>

<?php if ($user): ?>
<div class="modal" id="profile-modal">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card profile-modal-card">
        <button class="modal-close" data-modal-close type="button">&times;</button>
        <h2>Meu perfil</h2>

        <div class="profile-readonly-grid">
            <label>E-mail</label>
            <input type="text" value="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>" readonly>
            <label>CPF</label>
            <input type="text" value="<?= htmlspecialchars($user['cpf'], ENT_QUOTES) ?>" readonly>
        </div>

        <div class="profile-stats-grid">
            <article><span>Total</span><strong><?= (int) (($profileStats['total'] ?? 0)) ?></strong></article>
            <article><span>Vitorias</span><strong><?= (int) (($profileStats['won'] ?? 0)) ?></strong></article>
            <article><span>Derrotas</span><strong><?= (int) (($profileStats['lost'] ?? 0)) ?></strong></article>
            <article><span>Abertos</span><strong><?= (int) (($profileStats['open'] ?? 0)) ?></strong></article>
        </div>

        <h3>Alterar senha</h3>
        <form method="post" action="<?= htmlspecialchars(app_url('/profile/password'), ENT_QUOTES) ?>" class="modal-form">
            <input type="password" name="current_password" placeholder="Senha atual" required>
            <input type="password" name="new_password" placeholder="Nova senha" required>
            <input type="password" name="confirm_password" placeholder="Confirmar nova senha" required>
            <button type="submit" class="btn-dark full">Salvar nova senha</button>
        </form>

        <h3>Historico de bilhetes</h3>
        <div class="profile-history-list">
            <?php foreach ($myTickets as $ticket): ?>
                <article class="profile-history-item">
                    <strong><?= htmlspecialchars($ticket['ticket_code'], ENT_QUOTES) ?></strong>
                    <span><?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?> | <?= htmlspecialchars($ticket['status'], ENT_QUOTES) ?></span>
                    <span>Stake: R$ <?= number_format((float) $ticket['stake'], 2, ',', '.') ?> | Possivel: R$ <?= number_format((float) $ticket['potential_return'], 2, ',', '.') ?></span>
                </article>
            <?php endforeach; ?>
            <?php if (empty($myTickets)): ?>
                <div class="login-required-note">Nenhum bilhete no historico.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$isAgentUser): ?>
<div class="modal" id="deposit-modal">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card">
        <button class="modal-close" data-modal-close type="button">&times;</button>
        <h2>Depositar via Pix</h2>
        <form method="post" action="<?= htmlspecialchars(app_url('/wallet/deposit/create'), ENT_QUOTES) ?>" class="modal-form">
            <input type="number" step="0.01" min="1" name="amount" placeholder="Valor do deposito" required>
            <button type="submit" class="btn-dark full">Gerar Pix</button>
        </form>
    </div>
</div>

<div class="modal" id="withdraw-modal">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card">
        <button class="modal-close" data-modal-close type="button">&times;</button>
        <h2>Sacar</h2>
        <form method="post" action="<?= htmlspecialchars(app_url('/wallet/withdraw'), ENT_QUOTES) ?>" class="modal-form">
            <input type="number" step="0.01" min="1" name="amount" placeholder="Valor do saque" required>
            <button type="submit" class="btn-dark full">Solicitar saque</button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if ($pixCheckout): ?>
<div class="modal open" id="pix-modal">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card pix-modal-card">
        <button class="modal-close" data-modal-close type="button">&times;</button>
        <h2><?= (($pixCheckout['context'] ?? 'deposit') === 'agent_sale') ? 'Pagamento do bilhete' : 'Pix gerado' ?></h2>
        <p class="pix-modal-subtitle">
            <?php if (($pixCheckout['context'] ?? 'deposit') === 'agent_sale' && (($pixCheckout['payment_mode'] ?? 'gateway') !== 'gateway')): ?>
                Confirme o recebimento para emitir o bilhete.
            <?php else: ?>
                Escaneie o QR Code para pagar.
            <?php endif; ?>
        </p>
        <div class="pix-modal-meta">
            <div><strong>Referencia:</strong> <?= htmlspecialchars($pixCheckout['reference'], ENT_QUOTES) ?></div>
            <?php if (isset($pixCheckout['amount'])): ?><div><strong>Valor:</strong> R$ <?= number_format((float) $pixCheckout['amount'], 2, ',', '.') ?></div><?php endif; ?>
            <div><strong>Status:</strong> <?= strtoupper(htmlspecialchars($pixCheckout['status'], ENT_QUOTES)) ?></div>
            <?php if (!empty($pixCheckout['provider'])): ?><div><strong>Gateway:</strong> <?= htmlspecialchars(strtoupper((string) $pixCheckout['provider']), ENT_QUOTES) ?></div><?php endif; ?>
            <?php if (!empty($pixCheckout['ticket_code'])): ?><div><strong>Bilhete:</strong> <?= htmlspecialchars((string) $pixCheckout['ticket_code'], ENT_QUOTES) ?></div><?php endif; ?>
        </div>
        <?php if (!empty($pixCheckout['qr_code'])): ?>
            <img class="pix-modal-qrcode" src="https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=<?= urlencode((string) $pixCheckout['qr_code']) ?>" alt="QR Code Pix" loading="lazy">
            <textarea class="pix-modal-code" readonly rows="4"><?= htmlspecialchars((string) $pixCheckout['qr_code'], ENT_QUOTES) ?></textarea>
        <?php endif; ?>
        <?php if (!empty($pixCheckout['payment_key'])): ?>
            <textarea class="pix-modal-code" readonly rows="3"><?= htmlspecialchars((string) $pixCheckout['payment_key'], ENT_QUOTES) ?></textarea>
        <?php endif; ?>

        <?php if (($pixCheckout['context'] ?? 'deposit') === 'agent_sale'): ?>
            <?php if (($pixCheckout['status'] ?? '') !== 'issued'): ?>
                <?php if (($pixCheckout['payment_mode'] ?? 'gateway') === 'gateway'): ?>
                    <form method="post" action="<?= htmlspecialchars(app_url('/agent/payment/status'), ENT_QUOTES) ?>" class="modal-form">
                        <input type="hidden" name="reference_code" value="<?= htmlspecialchars((string) $pixCheckout['reference'], ENT_QUOTES) ?>">
                        <input type="hidden" name="return_to" value="/#betslip-panel">
                        <button type="submit" class="btn-dark full">Atualizar status Pix</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= htmlspecialchars(app_url('/agent/payment/custom-confirm'), ENT_QUOTES) ?>" class="modal-form" onsubmit="return confirm('Confirmar recebimento e emitir o bilhete?');">
                        <input type="hidden" name="reference_code" value="<?= htmlspecialchars((string) $pixCheckout['reference'], ENT_QUOTES) ?>">
                        <input type="hidden" name="return_to" value="/#betslip-panel">
                        <button type="submit" class="btn-dark full">Confirmar recebimento</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <a class="btn-outline full" href="<?= htmlspecialchars(app_url('/agent#pagamentos'), ENT_QUOTES) ?>">Abrir painel do agente</a>
        <?php else: ?>
            <form method="post" action="<?= htmlspecialchars(app_url('/wallet/deposit/status'), ENT_QUOTES) ?>" class="modal-form">
                <input type="hidden" name="transaction_id" value="<?= htmlspecialchars((string) $pixCheckout['transaction_id'], ENT_QUOTES) ?>">
                <button type="submit" class="btn-dark full">Atualizar status Pix</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="support-float" data-support-float hidden>
    <button type="button" class="support-close" data-support-close>&times;</button>
    <a class="support-link" href="https://wa.me/" target="_blank" rel="noopener noreferrer">
        <span class="wa-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16">
                <path fill="#c11616" d="M20.5 3.5A11.8 11.8 0 0 0 12.1 0C5.6 0 .3 5.3.3 11.8c0 2.1.5 4.1 1.6 5.9L0 24l6.5-1.7a11.7 11.7 0 0 0 5.6 1.4h.1c6.5 0 11.8-5.3 11.8-11.8a11.6 11.6 0 0 0-3.5-8.4Zm-8.4 18.2h-.1a9.7 9.7 0 0 1-5-1.4l-.4-.2-3.9 1 1-3.8-.3-.4a9.7 9.7 0 0 1-1.5-5.1C2 6.5 6.5 2 12 2c2.6 0 5 1 6.9 2.9 1.8 1.8 2.8 4.3 2.8 6.9 0 5.5-4.5 10-9.6 10Zm5.5-7.4c-.3-.1-1.8-.9-2.1-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.1-.2.2-.3.2-.6.1-1.7-.9-2.8-1.6-3.9-3.6-.3-.4.3-.4.9-1.3.1-.2.1-.4 0-.6-.1-.1-.7-1.6-.9-2.2-.2-.5-.4-.4-.7-.4h-.6c-.2 0-.6.1-.9.4-.3.3-1.1 1-1.1 2.5s1.2 2.9 1.4 3.1c.2.2 2.4 3.7 5.9 5.1 2.2.9 3 1 4 .9.6-.1 1.8-.8 2.1-1.5.3-.7.3-1.4.2-1.5-.1-.1-.3-.2-.6-.3Z"/>
            </svg>
        </span>
        <span>Suporte</span>
    </a>
</div>

<div class="pwa-install" data-pwa-install hidden>
    <button type="button" class="pwa-close" data-pwa-close>&times;</button>
    <strong>Instalar aplicativo</strong>
    <p>Adicione o PA DASORTE na tela inicial para acesso rapido.</p>
    <button type="button" class="btn-dark" data-pwa-install-btn>Instalar</button>
</div>
<script>
window.betSlip = {
    totalOdd: <?= json_encode((float) $betSlipTotalOdd) ?>
};
</script>
<?php $appJsVersion = @filemtime(__DIR__ . '/../../../public/assets/js/app.js') ?: time(); ?>
<script src="<?= htmlspecialchars(app_url('public/assets/js/app.js?v=' . $appJsVersion), ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>







