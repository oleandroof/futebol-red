<?php require __DIR__ . '/../partials/header.php'; ?>
<?php
$editCategory = $editCategory ?? null;
$editLeague = $editLeague ?? null;
$editGame = $editGame ?? null;
$editTeam = $editTeam ?? null;
$editManager = $editManager ?? null;
$editBookmaker = $editBookmaker ?? null;
$agentSummary = $agentSummary ?? [
    'managers_total' => 0,
    'bookmakers_total' => 0,
    'active_agents_total' => 0,
    'inactive_agents_total' => 0,
    'agent_balance_total' => 0,
    'commissions_total' => 0,
];
$managers = $managers ?? [];
$bookmakers = $bookmakers ?? [];
$managerOptions = $managerOptions ?? [];
$agentCashEntries = $agentCashEntries ?? [];
$agentCashFilters = $agentCashFilters ?? [
    'agent_id' => 0,
    'role' => 'all',
    'status' => 'all',
    'entry_type' => 'all',
    'date_from' => '',
    'date_to' => '',
];
$gatewaySummary = $gatewaySummary ?? [
    'default_provider' => 'ecompag',
    'active_providers' => [],
    'providers' => [],
];
$cleanupSummary = $cleanupSummary ?? [
    'total_games' => 0,
    'deletable_games' => 0,
    'linked_games' => 0,
    'locked_games' => 0,
    'orphan_leagues' => 0,
    'orphan_teams' => 0,
    'games_without_odds' => 0,
];
$lockSummary = $lockSummary ?? [
    'global_lock_all' => false,
    'manual_locked_games' => 0,
    'prestart_games' => 0,
    'timed_games' => 0,
    'currently_locked_games' => 0,
];
$lockSettings = $lockSettings ?? [
    'global_lock_all' => false,
];
$lockGames = $lockGames ?? [];
$overviewSummary = $overviewSummary ?? [
    'tickets_total' => 0,
    'tickets_open' => 0,
    'tickets_won' => 0,
    'tickets_lost' => 0,
    'tickets_cancelled' => 0,
    'tickets_today' => 0,
    'stakes_total' => 0,
    'stakes_open' => 0,
    'avg_stake' => 0,
    'avg_odd' => 0,
    'games_total' => 0,
    'games_visible' => 0,
    'games_hidden' => 0,
    'games_live' => 0,
    'games_finished' => 0,
    'games_manual_locked' => 0,
];
$overviewDaily = $overviewDaily ?? [];
$formatMoney = static function (float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
};
$netRevenue = (float) ($stats['revenue'] ?? 0) - (float) ($stats['payouts'] ?? 0);
$netRevenueTone = $netRevenue >= 0 ? 'positive' : 'negative';
$netRevenueLabel = $netRevenue >= 0 ? 'saldo positivo' : 'saldo pressionado';
$ticketBreakdown = [
    ['label' => 'Abertos', 'value' => (int) ($overviewSummary['tickets_open'] ?? 0), 'color' => '#3b82f6'],
    ['label' => 'Ganhos', 'value' => (int) ($overviewSummary['tickets_won'] ?? 0), 'color' => '#18a957'],
    ['label' => 'Perdidos', 'value' => (int) ($overviewSummary['tickets_lost'] ?? 0), 'color' => '#d33d3d'],
    ['label' => 'Cancelados', 'value' => (int) ($overviewSummary['tickets_cancelled'] ?? 0), 'color' => '#7b8794'],
];
$ticketBreakdownTotal = 0;
foreach ($ticketBreakdown as $ticketBreakdownItem) {
    $ticketBreakdownTotal += (int) ($ticketBreakdownItem['value'] ?? 0);
}
$ticketBreakdownGradientParts = [];
$ticketBreakdownOffset = 0.0;
foreach ($ticketBreakdown as $ticketBreakdownItem) {
    $value = (int) ($ticketBreakdownItem['value'] ?? 0);
    if ($value <= 0 || $ticketBreakdownTotal <= 0) {
        continue;
    }

    $start = round($ticketBreakdownOffset * 360, 2);
    $ticketBreakdownOffset += $value / $ticketBreakdownTotal;
    $end = round($ticketBreakdownOffset * 360, 2);
    $ticketBreakdownGradientParts[] = $ticketBreakdownItem['color'] . ' ' . $start . 'deg ' . $end . 'deg';
}
$ticketBreakdownGradient = $ticketBreakdownGradientParts !== []
    ? implode(', ', $ticketBreakdownGradientParts)
    : '#d8e2ec 0deg 360deg';
$overviewChartPeak = 0.0;
foreach ($overviewDaily as $overviewDayItem) {
    $overviewChartPeak = max(
        $overviewChartPeak,
        (float) ($overviewDayItem['inflow'] ?? 0),
        (float) ($overviewDayItem['outflow'] ?? 0),
        (float) ($overviewDayItem['stakes'] ?? 0)
    );
}
$overviewChartPeak = $overviewChartPeak > 0 ? $overviewChartPeak : 1;
$todayOverview = $overviewDaily !== [] ? $overviewDaily[array_key_last($overviewDaily)] : [
    'label' => date('d/m'),
    'tickets' => 0,
    'stakes' => 0,
    'inflow' => 0,
    'outflow' => 0,
    'balance' => 0,
];
$todayBalanceTone = ((float) ($todayOverview['balance'] ?? 0)) >= 0 ? 'positive' : 'negative';
$ticketResolutionRate = (int) ($overviewSummary['tickets_total'] ?? 0) > 0
    ? (int) round((((int) ($overviewSummary['tickets_won'] ?? 0) + (int) ($overviewSummary['tickets_lost'] ?? 0) + (int) ($overviewSummary['tickets_cancelled'] ?? 0)) / max(1, (int) ($overviewSummary['tickets_total'] ?? 0))) * 100)
    : 0;
$visibilityRate = (int) ($overviewSummary['games_total'] ?? 0) > 0
    ? (int) round(((int) ($overviewSummary['games_visible'] ?? 0) / max(1, (int) ($overviewSummary['games_total'] ?? 0))) * 100)
    : 0;
$overviewCards = [
    [
        'label' => 'Bilhetes totais',
        'value' => number_format((int) ($overviewSummary['tickets_total'] ?? 0), 0, ',', '.'),
        'meta' => number_format((int) ($overviewSummary['tickets_today'] ?? 0), 0, ',', '.') . ' criados hoje',
    ],
    [
        'label' => 'Taxa resolvida',
        'value' => $ticketResolutionRate . '%',
        'meta' => number_format((int) ($overviewSummary['tickets_open'] ?? 0), 0, ',', '.') . ' ainda abertos',
    ],
    [
        'label' => 'Jogos visiveis',
        'value' => number_format((int) ($overviewSummary['games_visible'] ?? 0), 0, ',', '.'),
        'meta' => $visibilityRate . '% da grade admin',
    ],
    [
        'label' => 'Jogos ao vivo',
        'value' => number_format((int) ($overviewSummary['games_live'] ?? 0), 0, ',', '.'),
        'meta' => number_format((int) ($lockSummary['currently_locked_games'] ?? 0), 0, ',', '.') . ' travados agora',
    ],
    [
        'label' => 'Stake media',
        'value' => $formatMoney((float) ($overviewSummary['avg_stake'] ?? 0)),
        'meta' => 'odd media ' . number_format((float) ($overviewSummary['avg_odd'] ?? 0), 2, ',', '.'),
    ],
    [
        'label' => 'Caixa agentes',
        'value' => $formatMoney((float) ($agentSummary['agent_balance_total'] ?? 0)),
        'meta' => $formatMoney((float) ($agentSummary['commissions_total'] ?? 0)) . ' em comissoes',
    ],
    [
        'label' => 'Sem odds',
        'value' => number_format((int) ($cleanupSummary['games_without_odds'] ?? 0), 0, ',', '.'),
        'meta' => number_format((int) ($cleanupSummary['linked_games'] ?? 0), 0, ',', '.') . ' jogos protegidos',
    ],
    [
        'label' => 'Agentes ativos',
        'value' => number_format((int) ($agentSummary['active_agents_total'] ?? 0), 0, ',', '.'),
        'meta' => number_format((int) ($agentSummary['inactive_agents_total'] ?? 0), 0, ',', '.') . ' inativos',
    ],
];
$overviewAlerts = [];
if ((int) ($cleanupSummary['games_without_odds'] ?? 0) > 0) {
    $overviewAlerts[] = [
        'tone' => 'warning',
        'title' => 'Jogos sem odds prontas',
        'detail' => number_format((int) ($cleanupSummary['games_without_odds'] ?? 0), 0, ',', '.') . ' jogos precisam de revisao antes de aparecer com forca total.',
    ];
}
if ((int) ($lockSummary['currently_locked_games'] ?? 0) > 0) {
    $overviewAlerts[] = [
        'tone' => 'info',
        'title' => 'Travas ativas agora',
        'detail' => number_format((int) ($lockSummary['currently_locked_games'] ?? 0), 0, ',', '.') . ' jogos estao indisponiveis neste momento.',
    ];
}
if ((int) ($agentSummary['inactive_agents_total'] ?? 0) > 0) {
    $overviewAlerts[] = [
        'tone' => 'warning',
        'title' => 'Agentes inativos',
        'detail' => number_format((int) ($agentSummary['inactive_agents_total'] ?? 0), 0, ',', '.') . ' agentes estao desativados e podem precisar de revisao.',
    ];
}
if ((float) ($stats['risk_exposure'] ?? 0) > 0) {
    $overviewAlerts[] = [
        'tone' => 'danger',
        'title' => 'Risco em aberto',
        'detail' => 'Existem ' . number_format((int) ($stats['open_bets'] ?? 0), 0, ',', '.') . ' apostas abertas com exposicao de ' . $formatMoney((float) ($stats['risk_exposure'] ?? 0)) . '.',
    ];
}
if ($overviewAlerts === []) {
    $overviewAlerts[] = [
        'tone' => 'success',
        'title' => 'Operacao estavel',
        'detail' => 'Sem alertas fortes agora. O painel esta limpo para seguir com cadastro, sync e conferencia.',
    ];
}
$recentMovements = array_slice($settlements ?? [], 0, 5);
$editGameCustomOdds = $editGame['custom_odds'] ?? [];
if ($editGameCustomOdds === []) {
    $editGameCustomOdds = [
        ['market_name' => 'Total de gols', 'option_name' => 'Mais de 2.5', 'odd_value' => ''],
        ['market_name' => 'Total de gols', 'option_name' => 'Menos de 2.5', 'odd_value' => ''],
        ['market_name' => 'Ambos marcam', 'option_name' => 'Sim', 'odd_value' => ''],
        ['market_name' => 'Ambos marcam', 'option_name' => 'Nao', 'odd_value' => ''],
    ];
}
$editGameCustomMarketCount = 0;
$editGameCustomOptionCount = 0;
$editGameCustomMarketKeys = [];
foreach ($editGameCustomOdds as $customOddRow) {
    $marketName = trim((string) ($customOddRow['market_name'] ?? ''));
    $optionName = trim((string) ($customOddRow['option_name'] ?? ''));
    $oddValue = trim((string) ($customOddRow['odd_value'] ?? ''));
    if ($marketName === '' && $optionName === '' && $oddValue === '') {
        continue;
    }

    $editGameCustomOptionCount++;
    if ($marketName !== '') {
        $editGameCustomMarketKeys[mb_strtolower($marketName)] = true;
    }
}
$editGameCustomMarketCount = count($editGameCustomMarketKeys);
$syncStatusOptions = [
    'scheduled' => 'Somente nao iniciados',
    'all' => 'Agendados e ao vivo',
    'live' => 'Somente ao vivo',
];
$syncLeaguePlaceholder = "Ex: Serie A\nCopa do Brasil\nLibertadores";
$marketPresetGroups = [
    [
        'title' => 'Pacotes Principais',
        'description' => 'Base profissional para resultado, gols e mercados classicos de futebol.',
        'presets' => [
            [
                'key' => 'base_profissional',
                'label' => 'Base profissional',
                'rows' => [
                    ['market_name' => 'Total de gols 0.5', 'option_name' => 'Mais de 0.5'],
                    ['market_name' => 'Total de gols 0.5', 'option_name' => 'Menos de 0.5'],
                    ['market_name' => 'Total de gols 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Total de gols 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Total de gols 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Total de gols 2.5', 'option_name' => 'Menos de 2.5'],
                    ['market_name' => 'Total de gols 3.5', 'option_name' => 'Mais de 3.5'],
                    ['market_name' => 'Total de gols 3.5', 'option_name' => 'Menos de 3.5'],
                    ['market_name' => 'Total de gols 4.5', 'option_name' => 'Mais de 4.5'],
                    ['market_name' => 'Total de gols 4.5', 'option_name' => 'Menos de 4.5'],
                    ['market_name' => 'Total de gols 5.5', 'option_name' => 'Mais de 5.5'],
                    ['market_name' => 'Total de gols 5.5', 'option_name' => 'Menos de 5.5'],
                    ['market_name' => 'Ambos marcam', 'option_name' => 'Sim'],
                    ['market_name' => 'Ambos marcam', 'option_name' => 'Nao'],
                    ['market_name' => 'Dupla chance', 'option_name' => 'Casa ou Empate'],
                    ['market_name' => 'Dupla chance', 'option_name' => 'Casa ou Fora'],
                    ['market_name' => 'Dupla chance', 'option_name' => 'Empate ou Fora'],
                    ['market_name' => 'Empate anula aposta', 'option_name' => 'Casa'],
                    ['market_name' => 'Empate anula aposta', 'option_name' => 'Fora'],
                ],
            ],
            [
                'key' => 'resultados_tempo',
                'label' => 'Resultado por tempo',
                'rows' => [
                    ['market_name' => 'Resultado 1o tempo', 'option_name' => 'Casa'],
                    ['market_name' => 'Resultado 1o tempo', 'option_name' => 'Empate'],
                    ['market_name' => 'Resultado 1o tempo', 'option_name' => 'Fora'],
                    ['market_name' => 'Resultado 2o tempo', 'option_name' => 'Casa'],
                    ['market_name' => 'Resultado 2o tempo', 'option_name' => 'Empate'],
                    ['market_name' => 'Resultado 2o tempo', 'option_name' => 'Fora'],
                ],
            ],
            [
                'key' => 'intervalo_final',
                'label' => 'Intervalo/final',
                'rows' => [
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Casa/Casa'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Casa/Empate'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Casa/Fora'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Empate/Casa'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Empate/Empate'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Empate/Fora'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Fora/Casa'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Fora/Empate'],
                    ['market_name' => 'Intervalo/final', 'option_name' => 'Fora/Fora'],
                ],
            ],
        ],
    ],
    [
        'title' => 'Gols e Combos',
        'description' => 'Mercados de gols por tempo, por equipe e combinacoes profissionais.',
        'presets' => [
            [
                'key' => 'gols_tempo',
                'label' => 'Gols por tempo',
                'rows' => [
                    ['market_name' => 'Total de gols 1o tempo 0.5', 'option_name' => 'Mais de 0.5'],
                    ['market_name' => 'Total de gols 1o tempo 0.5', 'option_name' => 'Menos de 0.5'],
                    ['market_name' => 'Total de gols 1o tempo 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Total de gols 1o tempo 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Total de gols 1o tempo 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Total de gols 1o tempo 2.5', 'option_name' => 'Menos de 2.5'],
                    ['market_name' => 'Total de gols 2o tempo 0.5', 'option_name' => 'Mais de 0.5'],
                    ['market_name' => 'Total de gols 2o tempo 0.5', 'option_name' => 'Menos de 0.5'],
                    ['market_name' => 'Total de gols 2o tempo 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Total de gols 2o tempo 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Total de gols 2o tempo 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Total de gols 2o tempo 2.5', 'option_name' => 'Menos de 2.5'],
                ],
            ],
            [
                'key' => 'gols_equipe',
                'label' => 'Gols por equipe',
                'rows' => [
                    ['market_name' => 'Total gols casa 0.5', 'option_name' => 'Mais de 0.5'],
                    ['market_name' => 'Total gols casa 0.5', 'option_name' => 'Menos de 0.5'],
                    ['market_name' => 'Total gols casa 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Total gols casa 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Total gols casa 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Total gols casa 2.5', 'option_name' => 'Menos de 2.5'],
                    ['market_name' => 'Total gols fora 0.5', 'option_name' => 'Mais de 0.5'],
                    ['market_name' => 'Total gols fora 0.5', 'option_name' => 'Menos de 0.5'],
                    ['market_name' => 'Total gols fora 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Total gols fora 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Total gols fora 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Total gols fora 2.5', 'option_name' => 'Menos de 2.5'],
                ],
            ],
            [
                'key' => 'combos_resultado',
                'label' => 'Combos de resultado',
                'rows' => [
                    ['market_name' => 'Resultado + ambos marcam', 'option_name' => 'Casa e Sim'],
                    ['market_name' => 'Resultado + ambos marcam', 'option_name' => 'Casa e Nao'],
                    ['market_name' => 'Resultado + ambos marcam', 'option_name' => 'Empate e Sim'],
                    ['market_name' => 'Resultado + ambos marcam', 'option_name' => 'Empate e Nao'],
                    ['market_name' => 'Resultado + ambos marcam', 'option_name' => 'Fora e Sim'],
                    ['market_name' => 'Resultado + ambos marcam', 'option_name' => 'Fora e Nao'],
                    ['market_name' => 'Resultado + total 2.5', 'option_name' => 'Casa e Mais de 2.5'],
                    ['market_name' => 'Resultado + total 2.5', 'option_name' => 'Casa e Menos de 2.5'],
                    ['market_name' => 'Resultado + total 2.5', 'option_name' => 'Empate e Mais de 2.5'],
                    ['market_name' => 'Resultado + total 2.5', 'option_name' => 'Empate e Menos de 2.5'],
                    ['market_name' => 'Resultado + total 2.5', 'option_name' => 'Fora e Mais de 2.5'],
                    ['market_name' => 'Resultado + total 2.5', 'option_name' => 'Fora e Menos de 2.5'],
                ],
            ],
            [
                'key' => 'marcar_eventos',
                'label' => 'Primeiro e ultimo gol',
                'rows' => [
                    ['market_name' => 'Primeiro time a marcar', 'option_name' => 'Casa'],
                    ['market_name' => 'Primeiro time a marcar', 'option_name' => 'Fora'],
                    ['market_name' => 'Primeiro time a marcar', 'option_name' => 'Nenhum'],
                    ['market_name' => 'Ultimo time a marcar', 'option_name' => 'Casa'],
                    ['market_name' => 'Ultimo time a marcar', 'option_name' => 'Fora'],
                    ['market_name' => 'Ultimo time a marcar', 'option_name' => 'Nenhum'],
                    ['market_name' => 'Gol em ambos os tempos', 'option_name' => 'Sim'],
                    ['market_name' => 'Gol em ambos os tempos', 'option_name' => 'Nao'],
                    ['market_name' => 'Vencer sem sofrer gol', 'option_name' => 'Casa'],
                    ['market_name' => 'Vencer sem sofrer gol', 'option_name' => 'Fora'],
                    ['market_name' => 'Vencer sem sofrer gol', 'option_name' => 'Nenhum'],
                    ['market_name' => 'Ambos marcam 1o tempo', 'option_name' => 'Sim'],
                    ['market_name' => 'Ambos marcam 1o tempo', 'option_name' => 'Nao'],
                ],
            ],
        ],
    ],
    [
        'title' => 'Escanteios e Cartoes',
        'description' => 'Mercados de volume por partida, por tempo e por equipe.',
        'presets' => [
            [
                'key' => 'escanteios',
                'label' => 'Escanteios totais',
                'rows' => [
                    ['market_name' => 'Total escanteios 7.5', 'option_name' => 'Mais de 7.5'],
                    ['market_name' => 'Total escanteios 7.5', 'option_name' => 'Menos de 7.5'],
                    ['market_name' => 'Total escanteios 8.5', 'option_name' => 'Mais de 8.5'],
                    ['market_name' => 'Total escanteios 8.5', 'option_name' => 'Menos de 8.5'],
                    ['market_name' => 'Total escanteios 9.5', 'option_name' => 'Mais de 9.5'],
                    ['market_name' => 'Total escanteios 9.5', 'option_name' => 'Menos de 9.5'],
                    ['market_name' => 'Total escanteios 10.5', 'option_name' => 'Mais de 10.5'],
                    ['market_name' => 'Total escanteios 10.5', 'option_name' => 'Menos de 10.5'],
                    ['market_name' => 'Total escanteios 11.5', 'option_name' => 'Mais de 11.5'],
                    ['market_name' => 'Total escanteios 11.5', 'option_name' => 'Menos de 11.5'],
                    ['market_name' => 'Escanteios 1o tempo 4.5', 'option_name' => 'Mais de 4.5'],
                    ['market_name' => 'Escanteios 1o tempo 4.5', 'option_name' => 'Menos de 4.5'],
                    ['market_name' => 'Escanteios 1o tempo 5.5', 'option_name' => 'Mais de 5.5'],
                    ['market_name' => 'Escanteios 1o tempo 5.5', 'option_name' => 'Menos de 5.5'],
                ],
            ],
            [
                'key' => 'escanteios_equipe',
                'label' => 'Escanteios por equipe',
                'rows' => [
                    ['market_name' => 'Escanteios casa 3.5', 'option_name' => 'Mais de 3.5'],
                    ['market_name' => 'Escanteios casa 3.5', 'option_name' => 'Menos de 3.5'],
                    ['market_name' => 'Escanteios casa 4.5', 'option_name' => 'Mais de 4.5'],
                    ['market_name' => 'Escanteios casa 4.5', 'option_name' => 'Menos de 4.5'],
                    ['market_name' => 'Escanteios casa 5.5', 'option_name' => 'Mais de 5.5'],
                    ['market_name' => 'Escanteios casa 5.5', 'option_name' => 'Menos de 5.5'],
                    ['market_name' => 'Escanteios fora 3.5', 'option_name' => 'Mais de 3.5'],
                    ['market_name' => 'Escanteios fora 3.5', 'option_name' => 'Menos de 3.5'],
                    ['market_name' => 'Escanteios fora 4.5', 'option_name' => 'Mais de 4.5'],
                    ['market_name' => 'Escanteios fora 4.5', 'option_name' => 'Menos de 4.5'],
                    ['market_name' => 'Escanteios fora 5.5', 'option_name' => 'Mais de 5.5'],
                    ['market_name' => 'Escanteios fora 5.5', 'option_name' => 'Menos de 5.5'],
                    ['market_name' => 'Resultado escanteios', 'option_name' => 'Casa'],
                    ['market_name' => 'Resultado escanteios', 'option_name' => 'Empate'],
                    ['market_name' => 'Resultado escanteios', 'option_name' => 'Fora'],
                ],
            ],
            [
                'key' => 'cartoes',
                'label' => 'Cartoes totais',
                'rows' => [
                    ['market_name' => 'Total cartoes 3.5', 'option_name' => 'Mais de 3.5'],
                    ['market_name' => 'Total cartoes 3.5', 'option_name' => 'Menos de 3.5'],
                    ['market_name' => 'Total cartoes 4.5', 'option_name' => 'Mais de 4.5'],
                    ['market_name' => 'Total cartoes 4.5', 'option_name' => 'Menos de 4.5'],
                    ['market_name' => 'Total cartoes 5.5', 'option_name' => 'Mais de 5.5'],
                    ['market_name' => 'Total cartoes 5.5', 'option_name' => 'Menos de 5.5'],
                    ['market_name' => 'Total cartoes 6.5', 'option_name' => 'Mais de 6.5'],
                    ['market_name' => 'Total cartoes 6.5', 'option_name' => 'Menos de 6.5'],
                    ['market_name' => 'Cartoes 1o tempo 0.5', 'option_name' => 'Mais de 0.5'],
                    ['market_name' => 'Cartoes 1o tempo 0.5', 'option_name' => 'Menos de 0.5'],
                    ['market_name' => 'Cartoes 1o tempo 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Cartoes 1o tempo 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Cartoes 1o tempo 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Cartoes 1o tempo 2.5', 'option_name' => 'Menos de 2.5'],
                ],
            ],
            [
                'key' => 'cartoes_equipe',
                'label' => 'Cartoes por equipe',
                'rows' => [
                    ['market_name' => 'Cartoes casa 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Cartoes casa 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Cartoes casa 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Cartoes casa 2.5', 'option_name' => 'Menos de 2.5'],
                    ['market_name' => 'Cartoes casa 3.5', 'option_name' => 'Mais de 3.5'],
                    ['market_name' => 'Cartoes casa 3.5', 'option_name' => 'Menos de 3.5'],
                    ['market_name' => 'Cartoes fora 1.5', 'option_name' => 'Mais de 1.5'],
                    ['market_name' => 'Cartoes fora 1.5', 'option_name' => 'Menos de 1.5'],
                    ['market_name' => 'Cartoes fora 2.5', 'option_name' => 'Mais de 2.5'],
                    ['market_name' => 'Cartoes fora 2.5', 'option_name' => 'Menos de 2.5'],
                    ['market_name' => 'Cartoes fora 3.5', 'option_name' => 'Mais de 3.5'],
                    ['market_name' => 'Cartoes fora 3.5', 'option_name' => 'Menos de 3.5'],
                    ['market_name' => 'Resultado cartoes', 'option_name' => 'Casa'],
                    ['market_name' => 'Resultado cartoes', 'option_name' => 'Empate'],
                    ['market_name' => 'Resultado cartoes', 'option_name' => 'Fora'],
                ],
            ],
        ],
    ],
    [
        'title' => 'Avancados',
        'description' => 'Handicaps, placar exato e mercados mais completos para montar jogo a jogo.',
        'presets' => [
            [
                'key' => 'handicap',
                'label' => 'Handicap asiatico',
                'rows' => [
                    ['market_name' => 'Handicap asiatico 0.25', 'option_name' => 'Casa -0.25'],
                    ['market_name' => 'Handicap asiatico 0.25', 'option_name' => 'Fora +0.25'],
                    ['market_name' => 'Handicap asiatico 0.5', 'option_name' => 'Casa -0.5'],
                    ['market_name' => 'Handicap asiatico 0.5', 'option_name' => 'Fora +0.5'],
                    ['market_name' => 'Handicap asiatico 0.75', 'option_name' => 'Casa -0.75'],
                    ['market_name' => 'Handicap asiatico 0.75', 'option_name' => 'Fora +0.75'],
                    ['market_name' => 'Handicap asiatico 1.0', 'option_name' => 'Casa -1.0'],
                    ['market_name' => 'Handicap asiatico 1.0', 'option_name' => 'Fora +1.0'],
                    ['market_name' => 'Handicap asiatico invertido 0.25', 'option_name' => 'Casa +0.25'],
                    ['market_name' => 'Handicap asiatico invertido 0.25', 'option_name' => 'Fora -0.25'],
                    ['market_name' => 'Handicap asiatico invertido 0.5', 'option_name' => 'Casa +0.5'],
                    ['market_name' => 'Handicap asiatico invertido 0.5', 'option_name' => 'Fora -0.5'],
                ],
            ],
            [
                'key' => 'placar_exato',
                'label' => 'Placar exato',
                'rows' => [
                    ['market_name' => 'Placar exato', 'option_name' => '0-0'],
                    ['market_name' => 'Placar exato', 'option_name' => '1-0'],
                    ['market_name' => 'Placar exato', 'option_name' => '2-0'],
                    ['market_name' => 'Placar exato', 'option_name' => '2-1'],
                    ['market_name' => 'Placar exato', 'option_name' => '3-0'],
                    ['market_name' => 'Placar exato', 'option_name' => '1-1'],
                    ['market_name' => 'Placar exato', 'option_name' => '2-2'],
                    ['market_name' => 'Placar exato', 'option_name' => '3-1'],
                    ['market_name' => 'Placar exato', 'option_name' => '0-1'],
                    ['market_name' => 'Placar exato', 'option_name' => '0-2'],
                    ['market_name' => 'Placar exato', 'option_name' => '1-2'],
                    ['market_name' => 'Placar exato', 'option_name' => '0-3'],
                    ['market_name' => 'Placar exato', 'option_name' => '1-3'],
                    ['market_name' => 'Placar exato', 'option_name' => '2-3'],
                    ['market_name' => 'Placar exato', 'option_name' => '3-2'],
                ],
            ],
        ],
    ],
    [
        'title' => 'Especiais de Bet',
        'description' => 'Mercados adicionais que completam a grade como nas casas profissionais.',
        'presets' => [
            [
                'key' => 'par_impar',
                'label' => 'Par ou impar',
                'rows' => [
                    ['market_name' => 'Total de gols par/impar', 'option_name' => 'Par'],
                    ['market_name' => 'Total de gols par/impar', 'option_name' => 'Impar'],
                    ['market_name' => 'Total gols casa par/impar', 'option_name' => 'Par'],
                    ['market_name' => 'Total gols casa par/impar', 'option_name' => 'Impar'],
                    ['market_name' => 'Total gols fora par/impar', 'option_name' => 'Par'],
                    ['market_name' => 'Total gols fora par/impar', 'option_name' => 'Impar'],
                ],
            ],
            [
                'key' => 'handicap_europeu',
                'label' => 'Handicap europeu',
                'rows' => [
                    ['market_name' => 'Handicap europeu 1:0', 'option_name' => 'Casa'],
                    ['market_name' => 'Handicap europeu 1:0', 'option_name' => 'Empate'],
                    ['market_name' => 'Handicap europeu 1:0', 'option_name' => 'Fora'],
                    ['market_name' => 'Handicap europeu 0:1', 'option_name' => 'Casa'],
                    ['market_name' => 'Handicap europeu 0:1', 'option_name' => 'Empate'],
                    ['market_name' => 'Handicap europeu 0:1', 'option_name' => 'Fora'],
                    ['market_name' => 'Handicap europeu 2:0', 'option_name' => 'Casa'],
                    ['market_name' => 'Handicap europeu 2:0', 'option_name' => 'Empate'],
                    ['market_name' => 'Handicap europeu 2:0', 'option_name' => 'Fora'],
                ],
            ],
            [
                'key' => 'especiais_partida',
                'label' => 'Eventos especiais',
                'rows' => [
                    ['market_name' => 'Havera penalti', 'option_name' => 'Sim'],
                    ['market_name' => 'Havera penalti', 'option_name' => 'Nao'],
                    ['market_name' => 'Havera cartao vermelho', 'option_name' => 'Sim'],
                    ['market_name' => 'Havera cartao vermelho', 'option_name' => 'Nao'],
                    ['market_name' => 'Gol de falta', 'option_name' => 'Sim'],
                    ['market_name' => 'Gol de falta', 'option_name' => 'Nao'],
                    ['market_name' => 'Gol contra', 'option_name' => 'Sim'],
                    ['market_name' => 'Gol contra', 'option_name' => 'Nao'],
                ],
            ],
            [
                'key' => 'equipes_tempos',
                'label' => 'Equipe e tempos',
                'rows' => [
                    ['market_name' => 'Casa marca em ambos os tempos', 'option_name' => 'Sim'],
                    ['market_name' => 'Casa marca em ambos os tempos', 'option_name' => 'Nao'],
                    ['market_name' => 'Fora marca em ambos os tempos', 'option_name' => 'Sim'],
                    ['market_name' => 'Fora marca em ambos os tempos', 'option_name' => 'Nao'],
                    ['market_name' => 'Casa vence algum tempo', 'option_name' => 'Sim'],
                    ['market_name' => 'Casa vence algum tempo', 'option_name' => 'Nao'],
                    ['market_name' => 'Fora vence algum tempo', 'option_name' => 'Sim'],
                    ['market_name' => 'Fora vence algum tempo', 'option_name' => 'Nao'],
                    ['market_name' => 'Cada equipe marca 1+ gol', 'option_name' => 'Sim'],
                    ['market_name' => 'Cada equipe marca 1+ gol', 'option_name' => 'Nao'],
                    ['market_name' => 'Cada equipe marca 2+ gols', 'option_name' => 'Sim'],
                    ['market_name' => 'Cada equipe marca 2+ gols', 'option_name' => 'Nao'],
                ],
            ],
            [
                'key' => 'faixas_gols',
                'label' => 'Faixas e corrida',
                'rows' => [
                    ['market_name' => 'Faixa total de gols', 'option_name' => '0-1'],
                    ['market_name' => 'Faixa total de gols', 'option_name' => '2-3'],
                    ['market_name' => 'Faixa total de gols', 'option_name' => '4-5'],
                    ['market_name' => 'Faixa total de gols', 'option_name' => '6+'],
                    ['market_name' => 'Corrida a 3 gols', 'option_name' => 'Casa'],
                    ['market_name' => 'Corrida a 3 gols', 'option_name' => 'Fora'],
                    ['market_name' => 'Corrida a 3 gols', 'option_name' => 'Nenhum'],
                    ['market_name' => 'Corrida a 5 escanteios', 'option_name' => 'Casa'],
                    ['market_name' => 'Corrida a 5 escanteios', 'option_name' => 'Fora'],
                    ['market_name' => 'Corrida a 5 escanteios', 'option_name' => 'Nenhum'],
                ],
            ],
        ],
    ],
];
$marketPresetMap = [];
$marketPresetCompleteRows = [];
$marketPresetCompleteKeys = [];
foreach ($marketPresetGroups as &$marketPresetGroup) {
    foreach ($marketPresetGroup['presets'] as &$marketPreset) {
        $marketPreset['count'] = count($marketPreset['rows']);
        $marketPresetMap[$marketPreset['key']] = [
            'label' => $marketPreset['label'],
            'rows' => $marketPreset['rows'],
        ];

        foreach ($marketPreset['rows'] as $marketPresetRow) {
            $marketPresetKey = mb_strtolower(trim((string) ($marketPresetRow['market_name'] ?? '')))
                . '|'
                . mb_strtolower(trim((string) ($marketPresetRow['option_name'] ?? '')));

            if ($marketPresetKey === '|' || isset($marketPresetCompleteKeys[$marketPresetKey])) {
                continue;
            }

            $marketPresetCompleteKeys[$marketPresetKey] = true;
            $marketPresetCompleteRows[] = $marketPresetRow;
        }
    }
    unset($marketPreset);
}
unset($marketPresetGroup);
$marketPresetComplete = [
    'key' => 'pack_completo',
    'label' => 'Pacote profissional completo',
    'count' => count($marketPresetCompleteRows),
    'rows' => $marketPresetCompleteRows,
];
array_unshift($marketPresetGroups[0]['presets'], $marketPresetComplete);
$marketPresetMap['pack_completo'] = [
    'label' => $marketPresetComplete['label'],
    'rows' => $marketPresetComplete['rows'],
];
$sortAdminMarkets = static function (array $markets): array {
    usort($markets, static function (array $left, array $right): int {
        $leftSettled = !empty($left['result_option']);
        $rightSettled = !empty($right['result_option']);
        if ($leftSettled !== $rightSettled) {
            return $leftSettled ? 1 : -1;
        }

        $leftPriority = (($left['market_name'] ?? '') === 'Resultado final') ? 0 : 1;
        $rightPriority = (($right['market_name'] ?? '') === 'Resultado final') ? 0 : 1;
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcasecmp((string) ($left['market_name'] ?? ''), (string) ($right['market_name'] ?? ''));
    });

    return $markets;
};
?>
<div class="admin-shell" data-admin-shell>
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <span>Painel Admin</span>
            <strong><?= htmlspecialchars($user['name'], ENT_QUOTES) ?></strong>
        </div>

        <nav class="admin-nav" data-admin-nav>
            <a href="#visao-geral" class="active">Visao geral</a>
            <a href="#categorias">Categorias</a>
            <a href="#ligas">Ligas</a>
            <a href="#times">Times</a>
            <a href="#jogos">Jogos</a>
            <a href="#api">Api</a>
            <a href="#gerentes">Gerentes</a>
            <a href="#cambistas">Cambistas</a>
            <a href="#caixa-agentes">Caixa agentes</a>
            <a href="#trava">Trava</a>
            <a href="#limpeza">Limpeza</a>
            <a href="#financeiro">Contabilidade</a>
            <a href="#configuracoes">Configuracoes</a>
            <a href="#bilhetes">Bilhetes</a>
        </nav>

        <div class="admin-sidebar-actions">
            <a class="btn-outline full" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES) ?>">Ver site</a>
            <a class="btn-dark full" href="<?= htmlspecialchars(app_url('/logout'), ENT_QUOTES) ?>">Sair</a>
        </div>
    </aside>

    <main class="admin-content">
        <section class="admin-header">
            <div>
                <span class="eyebrow">ADMIN - BET - PADASORTE</span>
                <h1>91980118084</h1>
                <p class="admin-current-section" data-admin-current-section>Visao geral</p>
            </div>
            <button class="btn-outline admin-menu-toggle" type="button" data-admin-menu-toggle>Menu</button>
        </section>

        <section class="admin-panel active" id="visao-geral" data-admin-section>
            <div class="admin-overview-layout">
                <div class="admin-overview-hero">
                    <article class="admin-overview-balance admin-overview-balance--<?= $netRevenueTone ?>">
                        <span class="admin-overview-kicker">Painel executivo</span>
                        <div class="admin-overview-balance-head">
                            <div>
                                <h2>Saldo operacional</h2>
                                <p><?= htmlspecialchars($netRevenueLabel, ENT_QUOTES) ?> com leitura rapida da operacao atual.</p>
                            </div>
                            <span class="admin-overview-pill admin-overview-pill--<?= $netRevenueTone ?>"><?= $netRevenue >= 0 ? 'Acima da linha' : 'Abaixo da linha' ?></span>
                        </div>
                        <strong class="admin-overview-balance-value"><?= $formatMoney($netRevenue) ?></strong>
                        <p class="admin-overview-balance-caption">Receita total de <?= $formatMoney((float) ($stats['revenue'] ?? 0)) ?> contra pagamentos de <?= $formatMoney((float) ($stats['payouts'] ?? 0)) ?>.</p>
                        <div class="admin-overview-balance-strip">
                            <div>
                                <span>Receita</span>
                                <strong><?= $formatMoney((float) ($stats['revenue'] ?? 0)) ?></strong>
                            </div>
                            <div>
                                <span>Pagamentos</span>
                                <strong><?= $formatMoney((float) ($stats['payouts'] ?? 0)) ?></strong>
                            </div>
                            <div>
                                <span>Risco aberto</span>
                                <strong><?= $formatMoney((float) ($stats['risk_exposure'] ?? 0)) ?></strong>
                            </div>
                        </div>
                    </article>

                    <article class="admin-card admin-overview-status-card">
                        <div class="admin-card-head">
                            <div>
                                <h2>Distribuicao dos bilhetes</h2>
                                <p class="admin-helper-text">Leitura visual do ciclo das apostas cadastradas no sistema.</p>
                            </div>
                            <span class="admin-compact-badge"><?= number_format($ticketBreakdownTotal, 0, ',', '.') ?> bilhetes</span>
                        </div>
                        <div class="admin-overview-status-body">
                            <div class="admin-overview-donut" style="background: conic-gradient(<?= htmlspecialchars($ticketBreakdownGradient, ENT_QUOTES) ?>);">
                                <div class="admin-overview-donut-hole">
                                    <strong><?= number_format($ticketBreakdownTotal, 0, ',', '.') ?></strong>
                                    <span>total</span>
                                </div>
                            </div>
                            <div class="admin-overview-status-legend">
                                <?php foreach ($ticketBreakdown as $ticketSegment): ?>
                                    <?php $segmentPercent = $ticketBreakdownTotal > 0 ? (int) round(((int) $ticketSegment['value'] / $ticketBreakdownTotal) * 100) : 0; ?>
                                    <div class="admin-overview-status-item">
                                        <div class="admin-overview-status-row">
                                            <span><i style="background: <?= htmlspecialchars((string) $ticketSegment['color'], ENT_QUOTES) ?>"></i><?= htmlspecialchars((string) $ticketSegment['label'], ENT_QUOTES) ?></span>
                                            <strong><?= number_format((int) $ticketSegment['value'], 0, ',', '.') ?></strong>
                                        </div>
                                        <div class="admin-overview-status-track">
                                            <span style="width: <?= $segmentPercent ?>%; background: <?= htmlspecialchars((string) $ticketSegment['color'], ENT_QUOTES) ?>"></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                </div>

                <div class="admin-overview-kpis">
                    <?php foreach ($overviewCards as $overviewCard): ?>
                        <article class="admin-overview-kpi">
                            <span><?= htmlspecialchars((string) $overviewCard['label'], ENT_QUOTES) ?></span>
                            <strong><?= htmlspecialchars((string) $overviewCard['value'], ENT_QUOTES) ?></strong>
                            <small><?= htmlspecialchars((string) $overviewCard['meta'], ENT_QUOTES) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="admin-grid admin-overview-secondary">
                    <article class="admin-card admin-overview-chart-card">
                        <div class="admin-card-head">
                            <div>
                                <h2>Fluxo dos ultimos 7 dias</h2>
                                <p class="admin-helper-text">Entradas, saidas e stakes para acompanhar a movimentacao recente.</p>
                            </div>
                            <span class="admin-compact-badge">7 dias</span>
                        </div>
                        <div class="admin-overview-chart-legend">
                            <span><i class="is-inflow"></i> Entrada</span>
                            <span><i class="is-outflow"></i> Saida</span>
                            <span><i class="is-stakes"></i> Stakes</span>
                        </div>
                        <div class="admin-overview-chart-bars">
                            <?php foreach ($overviewDaily as $overviewDay): ?>
                                <?php
                                $dayInflowHeight = max(10, (int) round((((float) ($overviewDay['inflow'] ?? 0)) / $overviewChartPeak) * 100));
                                $dayOutflowHeight = max(10, (int) round((((float) ($overviewDay['outflow'] ?? 0)) / $overviewChartPeak) * 100));
                                $dayStakesHeight = max(10, (int) round((((float) ($overviewDay['stakes'] ?? 0)) / $overviewChartPeak) * 100));
                                $dayBalanceTone = ((float) ($overviewDay['balance'] ?? 0)) >= 0 ? 'positive' : 'negative';
                                $dayTitle = 'Entrada: ' . $formatMoney((float) ($overviewDay['inflow'] ?? 0))
                                    . ' | Saida: ' . $formatMoney((float) ($overviewDay['outflow'] ?? 0))
                                    . ' | Stakes: ' . $formatMoney((float) ($overviewDay['stakes'] ?? 0));
                                ?>
                                <div class="admin-overview-day" title="<?= htmlspecialchars($dayTitle, ENT_QUOTES) ?>">
                                    <div class="admin-overview-day-bars">
                                        <span class="admin-overview-day-bar is-inflow" style="height: <?= (float) ($overviewDay['inflow'] ?? 0) > 0 ? $dayInflowHeight : 8 ?>%"></span>
                                        <span class="admin-overview-day-bar is-outflow" style="height: <?= (float) ($overviewDay['outflow'] ?? 0) > 0 ? $dayOutflowHeight : 8 ?>%"></span>
                                        <span class="admin-overview-day-bar is-stakes" style="height: <?= (float) ($overviewDay['stakes'] ?? 0) > 0 ? $dayStakesHeight : 8 ?>%"></span>
                                    </div>
                                    <strong><?= htmlspecialchars((string) ($overviewDay['label'] ?? ''), ENT_QUOTES) ?></strong>
                                    <small><?= number_format((int) ($overviewDay['tickets'] ?? 0), 0, ',', '.') ?> bilhetes</small>
                                    <span class="admin-overview-day-balance is-<?= $dayBalanceTone ?>"><?= $formatMoney((float) ($overviewDay['balance'] ?? 0)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>

                    <article class="admin-card admin-overview-radar-card">
                        <div class="admin-card-head">
                            <div>
                                <h2>Radar operacional</h2>
                                <p class="admin-helper-text">Resumo rapido do dia e pontos que merecem olhar agora.</p>
                            </div>
                            <span class="admin-overview-pill admin-overview-pill--<?= $todayBalanceTone ?>"><?= htmlspecialchars((string) ($todayOverview['label'] ?? date('d/m')), ENT_QUOTES) ?></span>
                        </div>

                        <div class="admin-overview-today">
                            <article>
                                <span>Fluxo do dia</span>
                                <strong><?= $formatMoney((float) ($todayOverview['balance'] ?? 0)) ?></strong>
                                <small><?= $formatMoney((float) ($todayOverview['inflow'] ?? 0)) ?> entrou / <?= $formatMoney((float) ($todayOverview['outflow'] ?? 0)) ?> saiu</small>
                            </article>
                            <article>
                                <span>Bilhetes hoje</span>
                                <strong><?= number_format((int) ($todayOverview['tickets'] ?? 0), 0, ',', '.') ?></strong>
                                <small><?= $formatMoney((float) ($todayOverview['stakes'] ?? 0)) ?> em stakes</small>
                            </article>
                            <article>
                                <span>Grade ativa</span>
                                <strong><?= $visibilityRate ?>%</strong>
                                <small><?= number_format((int) ($overviewSummary['games_visible'] ?? 0), 0, ',', '.') ?> jogos visiveis</small>
                            </article>
                        </div>

                        <div class="admin-overview-alert-list">
                            <?php foreach ($overviewAlerts as $overviewAlert): ?>
                                <article class="admin-overview-alert admin-overview-alert--<?= htmlspecialchars((string) ($overviewAlert['tone'] ?? 'info'), ENT_QUOTES) ?>">
                                    <strong><?= htmlspecialchars((string) ($overviewAlert['title'] ?? ''), ENT_QUOTES) ?></strong>
                                    <p><?= htmlspecialchars((string) ($overviewAlert['detail'] ?? ''), ENT_QUOTES) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <div class="admin-overview-movement-list">
                            <div class="admin-overview-subtitle">Ultimas movimentacoes</div>
                            <?php if ($recentMovements === []): ?>
                                <p class="admin-helper-text">Sem movimentacoes recentes para mostrar agora.</p>
                            <?php else: ?>
                                <?php foreach ($recentMovements as $movement): ?>
                                    <div class="admin-overview-movement-item">
                                        <div>
                                            <strong><?= htmlspecialchars((string) ($movement['reference'] ?? 'Sem ref'), ENT_QUOTES) ?></strong>
                                            <span><?= htmlspecialchars((string) ($movement['type'] ?? 'transacao'), ENT_QUOTES) ?> • <?= htmlspecialchars((string) ($movement['status'] ?? 'status'), ENT_QUOTES) ?></span>
                                        </div>
                                        <div>
                                            <strong><?= $formatMoney((float) ($movement['amount'] ?? 0)) ?></strong>
                                            <span><?= !empty($movement['created_at']) ? date('d/m H:i', strtotime((string) $movement['created_at'])) : '--' ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section class="admin-panel" id="categorias" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2><?= $editCategory ? 'Editar categoria' : 'Cadastrar categoria' ?></h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/category/save'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="hidden" name="category_id" value="<?= (int) ($editCategory['id'] ?? 0) ?>">
                        <input type="text" name="name" placeholder="Nome da categoria" value="<?= htmlspecialchars($editCategory['name'] ?? '', ENT_QUOTES) ?>" required>
                        <input type="text" name="slug" placeholder="Slug (ex: futebol)" value="<?= htmlspecialchars($editCategory['slug'] ?? '', ENT_QUOTES) ?>" required>
                        <input type="number" name="sort_order" placeholder="Ordem" value="<?= (int) ($editCategory['sort_order'] ?? 0) ?>">
                        <label><input type="checkbox" name="is_active" <?= (int) ($editCategory['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Ativa</label>
                        <button type="submit" class="btn-dark full"><?= $editCategory ? 'Atualizar categoria' : 'Salvar categoria' ?></button>
                        <?php if ($editCategory): ?><a class="btn-outline full" href="<?= htmlspecialchars(app_url('/admin#categorias'), ENT_QUOTES) ?>">Cancelar</a><?php endif; ?>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Categorias atuais</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nome</th><th>Slug</th><th>Ativa</th><th>Acoes</th></tr></thead>
                            <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($category['slug'], ENT_QUOTES) ?></td>
                                    <td><?= (int) $category['is_active'] === 1 ? 'Sim' : 'Nao' ?></td>
                                    <td>
                                        <a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin?edit_category=' . (int) $category['id'] . '#categorias'), ENT_QUOTES) ?>">Editar</a>
                                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/category/delete'), ENT_QUOTES) ?>" style="display:inline;" onsubmit="return confirm('Deseja deletar esta categoria?');">
                                            <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                            <button type="submit" class="btn-dark">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="ligas" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2><?= $editLeague ? 'Editar liga' : 'Cadastrar liga' ?></h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/league/save'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="hidden" name="league_id" value="<?= (int) ($editLeague['id'] ?? 0) ?>">
                        <select name="category_id" required>
                            <option value="">Categoria</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= (int) ($editLeague['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="name" placeholder="Nome da liga" value="<?= htmlspecialchars($editLeague['name'] ?? '', ENT_QUOTES) ?>" required>
                        <input type="text" name="country_code" placeholder="Pais (ex: br, gb, us)" value="<?= htmlspecialchars($editLeague['country_code'] ?? '', ENT_QUOTES) ?>" required>
                        <button type="submit" class="btn-dark full"><?= $editLeague ? 'Atualizar liga' : 'Salvar liga' ?></button>
                        <?php if ($editLeague): ?><a class="btn-outline full" href="<?= htmlspecialchars(app_url('/admin#ligas'), ENT_QUOTES) ?>">Cancelar</a><?php endif; ?>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Ligas atuais</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Categoria</th><th>Liga</th><th>Pais</th><th>Acoes</th></tr></thead>
                            <tbody>
                            <?php foreach ($leagues as $league): ?>
                                <tr>
                                    <td><?= htmlspecialchars($league['category_name'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($league['name'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($league['country_code'], ENT_QUOTES) ?></td>
                                    <td>
                                        <a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin?edit_league=' . (int) $league['id'] . '#ligas'), ENT_QUOTES) ?>">Editar</a>
                                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/league/delete'), ENT_QUOTES) ?>" style="display:inline;" onsubmit="return confirm('Deseja deletar esta liga?');">
                                            <input type="hidden" name="league_id" value="<?= (int) $league['id'] ?>">
                                            <button type="submit" class="btn-dark">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="times" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2><?= $editTeam ? 'Editar time' : 'Cadastrar time' ?></h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/team/save'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="hidden" name="team_id" value="<?= (int) ($editTeam['id'] ?? 0) ?>">
                        <input type="text" name="name" placeholder="Nome do time" value="<?= htmlspecialchars($editTeam['name'] ?? '', ENT_QUOTES) ?>" required>
                        <input type="text" name="logo" placeholder="URL do escudo (opcional)" value="<?= htmlspecialchars($editTeam['logo'] ?? '', ENT_QUOTES) ?>">
                        <button type="submit" class="btn-dark full"><?= $editTeam ? 'Atualizar time' : 'Salvar time' ?></button>
                        <?php if ($editTeam): ?><a class="btn-outline full" href="<?= htmlspecialchars(app_url('/admin#times'), ENT_QUOTES) ?>">Cancelar</a><?php endif; ?>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Times cadastrados</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Time</th><th>Escudo</th><th>Acoes</th></tr></thead>
                            <tbody>
                            <?php foreach ($teams as $team): ?>
                                <tr>
                                    <td><?= htmlspecialchars($team['name'], ENT_QUOTES) ?></td>
                                    <td>
                                        <?php if (!empty($team['logo'])): ?>
                                            <img src="<?= htmlspecialchars($team['logo'], ENT_QUOTES) ?>" alt="Escudo" style="width:24px;height:24px;object-fit:cover;border-radius:4px;">
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin?edit_team=' . (int) $team['id'] . '#times'), ENT_QUOTES) ?>">Editar</a>
                                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/team/delete'), ENT_QUOTES) ?>" style="display:inline;" onsubmit="return confirm('Deseja deletar este time?');">
                                            <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                                            <button type="submit" class="btn-dark">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="jogos" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <div class="admin-card-head">
                        <div>
                            <h2><?= $editGame ? 'Editar jogo + mercados' : 'Cadastrar jogo + mercados' ?></h2>
                            <p class="admin-helper-text">Area compacta para criar manualmente, revisar jogos sincronizados e manter os mercados organizados.</p>
                        </div>
                        <span class="admin-compact-badge"><?= $editGame ? 'Modo edicao' : 'Novo jogo' ?></span>
                    </div>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/game/save'), ENT_QUOTES) ?>" class="form-grid admin-game-editor" data-admin-odds-builder data-odds-presets="<?= htmlspecialchars((string) json_encode($marketPresetMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
                        <input type="hidden" name="game_id" value="<?= (int) ($editGame['id'] ?? 0) ?>">

                        <div class="admin-game-section full-span">
                            <div class="admin-card-head">
                                <div>
                                    <h3>Dados do jogo</h3>
                                    <p class="admin-helper-text">Preencha os dados base e mantenha a estrutura do evento pronta para receber mercados e resultados.</p>
                                </div>
                                <?php if ($editGame): ?>
                                    <span class="admin-compact-badge">Jogo #<?= (int) ($editGame['id'] ?? 0) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-game-core-grid">
                                <select name="league_id" required>
                                    <option value="">Liga</option>
                                    <?php foreach ($leagues as $league): ?>
                                        <option value="<?= (int) $league['id'] ?>" <?= (int) ($editGame['league_id'] ?? 0) === (int) $league['id'] ? 'selected' : '' ?>><?= htmlspecialchars($league['name'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="sport" placeholder="Esporte" value="<?= htmlspecialchars($editGame['sport'] ?? 'Futebol', ENT_QUOTES) ?>" required>
                                <select name="home_team_id" required>
                                    <option value="">Time casa</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= (int) $team['id'] ?>" <?= (int) ($editGame['home_team_id'] ?? 0) === (int) $team['id'] ? 'selected' : '' ?>><?= htmlspecialchars($team['name'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="away_team_id" required>
                                    <option value="">Time fora</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= (int) $team['id'] ?>" <?= (int) ($editGame['away_team_id'] ?? 0) === (int) $team['id'] ? 'selected' : '' ?>><?= htmlspecialchars($team['name'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="datetime-local" name="match_date" value="<?= !empty($editGame['match_date']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($editGame['match_date'])), ENT_QUOTES) : '' ?>" required>
                                <select name="risk_level">
                                    <option value="low" <?= ($editGame['risk_level'] ?? 'medium') === 'low' ? 'selected' : '' ?>>Baixo</option>
                                    <option value="medium" <?= ($editGame['risk_level'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Medio</option>
                                    <option value="high" <?= ($editGame['risk_level'] ?? 'medium') === 'high' ? 'selected' : '' ?>>Alto</option>
                                </select>
                            </div>
                        </div>

                        <div class="admin-game-editor-top full-span">
                            <div class="admin-market-box">
                                <div class="admin-card-head">
                                    <div>
                                        <h3>Mercado principal</h3>
                                        <p class="admin-helper-text">Resultado final 1x2, usado como base do jogo.</p>
                                    </div>
                                    <span class="admin-compact-badge">3 opcoes</span>
                                </div>
                                <div class="admin-odds-grid admin-odds-grid-base">
                                    <input type="number" step="0.01" name="odd_home" placeholder="Odd casa" value="<?= htmlspecialchars((string) ($editGame['odd_home'] ?? ''), ENT_QUOTES) ?>" required>
                                    <input type="number" step="0.01" name="odd_draw" placeholder="Odd empate" value="<?= htmlspecialchars((string) ($editGame['odd_draw'] ?? ''), ENT_QUOTES) ?>" required>
                                    <input type="number" step="0.01" name="odd_away" placeholder="Odd fora" value="<?= htmlspecialchars((string) ($editGame['odd_away'] ?? ''), ENT_QUOTES) ?>" required>
                                </div>
                            </div>

                            <div class="admin-market-box">
                                <div class="admin-card-head">
                                    <div>
                                        <h3>Mercados configurados</h3>
                                        <p class="admin-helper-text">Busque, edite e remova linhas sem precisar navegar numa tela gigante.</p>
                                    </div>
                                    <div class="admin-market-summary">
                                        <strong data-odds-market-count><?= (int) $editGameCustomMarketCount ?></strong>
                                        <span>mercados</span>
                                        <strong data-odds-row-count><?= (int) $editGameCustomOptionCount ?></strong>
                                        <span>opcoes</span>
                                    </div>
                                </div>
                                <div class="admin-market-toolbar">
                                    <input type="search" class="admin-odds-search" placeholder="Buscar mercado ou opcao..." data-odds-search>
                                    <button type="button" class="btn-outline" data-add-odd-row>Adicionar mercado</button>
                                </div>
                            </div>
                        </div>

                        <div class="admin-market-box full-span">
                            <details class="admin-market-preset-details"<?= $editGame ? '' : ' open' ?>>
                                <summary>
                                    <span>Biblioteca profissional de mercados</span>
                                    <small>Pacotes prontos para preencher rapidamente</small>
                                </summary>
                                <p class="admin-helper-text">Use a biblioteca abaixo para adicionar rapidamente mercados profissionais e depois ajustar odds linha por linha.</p>
                                <div class="admin-market-preset-library">
                                    <?php foreach ($marketPresetGroups as $marketPresetGroup): ?>
                                        <div class="admin-market-preset-group">
                                            <div class="admin-market-preset-group-head">
                                                <h4><?= htmlspecialchars((string) $marketPresetGroup['title'], ENT_QUOTES) ?></h4>
                                                <p><?= htmlspecialchars((string) $marketPresetGroup['description'], ENT_QUOTES) ?></p>
                                            </div>
                                            <div class="admin-market-preset-actions">
                                                <?php foreach (($marketPresetGroup['presets'] ?? []) as $marketPreset): ?>
                                                    <button
                                                        type="button"
                                                        class="btn-outline admin-preset-btn<?= ($marketPreset['key'] ?? '') === 'pack_completo' ? ' is-primary' : '' ?>"
                                                        data-add-odd-preset
                                                        data-preset-key="<?= htmlspecialchars((string) ($marketPreset['key'] ?? ''), ENT_QUOTES) ?>"
                                                    >
                                                        <?= htmlspecialchars((string) ($marketPreset['label'] ?? ''), ENT_QUOTES) ?> (<?= (int) ($marketPreset['count'] ?? 0) ?>)
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <p class="admin-market-preset-feedback" data-odds-preset-feedback></p>
                                </div>
                            </details>
                        </div>

                        <div class="admin-market-box full-span">
                            <div class="admin-market-head">
                                <div>
                                    <h3>Linhas e odds personalizadas</h3>
                                    <p class="admin-helper-text">As linhas ficam compactas, com filtro e contagem em tempo real.</p>
                                </div>
                            </div>
                            <div class="admin-odds-builder-frame">
                                <div class="admin-odds-builder-list" data-odds-rows>
                                    <?php foreach ($editGameCustomOdds as $customOdd): ?>
                                        <div class="admin-odd-row" data-odd-row>
                                            <input type="text" name="extra_market_name[]" placeholder="Mercado (ex: Total de gols)" value="<?= htmlspecialchars((string) ($customOdd['market_name'] ?? ''), ENT_QUOTES) ?>">
                                            <input type="text" name="extra_option_name[]" placeholder="Opcao (ex: Mais de 2.5)" value="<?= htmlspecialchars((string) ($customOdd['option_name'] ?? ''), ENT_QUOTES) ?>">
                                            <input type="number" step="0.01" name="extra_odd_value[]" placeholder="Odd" value="<?= htmlspecialchars((string) ($customOdd['odd_value'] ?? ''), ENT_QUOTES) ?>">
                                            <button type="button" class="btn-dark" data-remove-odd-row>Remover</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="admin-game-editor-actions full-span">
                            <button type="submit" class="btn-dark"><?= $editGame ? 'Atualizar jogo' : 'Salvar jogo' ?></button>
                            <?php if ($editGame): ?><a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin#jogos'), ENT_QUOTES) ?>">Cancelar</a><?php endif; ?>
                        </div>
                    </form>
                </article>

                <article class="admin-card">
                    <div class="admin-card-head">
                        <div>
                            <h2>Resultados dos jogos de hoje</h2>
                            <p class="admin-helper-text">Agora os resultados sao salvos por jogo. Campos vazios limpam somente aquele mercado.</p>
                        </div>
                        <span class="admin-compact-badge"><?= count($resultGamesToday) ?> jogos</span>
                    </div>
                    <?php if (empty($resultGamesToday)): ?>
                        <p class="admin-helper-text">Nenhum jogo cadastrado para hoje.</p>
                    <?php else: ?>
                        <div class="admin-results-day-list">
                            <?php foreach ($resultGamesToday as $game): ?>
                                <?php
                                $sortedMarkets = $sortAdminMarkets($game['markets'] ?? []);
                                $settledMarketCount = 0;
                                foreach ($sortedMarkets as $marketCheck) {
                                    if (!empty($marketCheck['result_option'])) {
                                        $settledMarketCount++;
                                    }
                                }
                                $totalMarketCount = count($sortedMarkets);
                                $pendingMarketCount = max(0, $totalMarketCount - $settledMarketCount);
                                ?>
                                <details class="admin-result-game-card <?= $settledMarketCount > 0 ? 'is-settled' : '' ?>"<?= $pendingMarketCount > 0 ? ' open' : '' ?>>
                                    <summary class="admin-result-summary">
                                        <div class="admin-result-summary-main">
                                            <strong><?= htmlspecialchars($game['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($game['away_name'], ENT_QUOTES) ?></strong>
                                            <span><?= htmlspecialchars($game['league_name'], ENT_QUOTES) ?> - <?= date('d/m/Y H:i', strtotime($game['match_date'])) ?></span>
                                        </div>
                                        <div class="admin-result-summary-meta">
                                            <span class="admin-compact-badge"><?= $settledMarketCount ?>/<?= $totalMarketCount ?> resolvidos</span>
                                            <span class="admin-compact-badge<?= $pendingMarketCount === 0 ? ' is-success' : '' ?>"><?= $pendingMarketCount === 0 ? 'Fechado' : $pendingMarketCount . ' pendente(s)' ?></span>
                                        </div>
                                    </summary>

                                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/game/results'), ENT_QUOTES) ?>" class="admin-game-results-form">
                                        <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
                                        <div class="admin-result-market-grid">
                                            <?php foreach ($sortedMarkets as $market): ?>
                                                <label class="admin-result-market-card <?= !empty($market['result_option']) ? 'is-settled' : '' ?>">
                                                    <input type="hidden" name="result_market_name[]" value="<?= htmlspecialchars((string) ($market['market_name'] ?? ''), ENT_QUOTES) ?>">
                                                    <span class="admin-result-market-title"><?= htmlspecialchars((string) ($market['market_name'] ?? ''), ENT_QUOTES) ?></span>
                                                    <span class="admin-result-market-meta"><?= count($market['options'] ?? []) ?> opcoes</span>
                                                    <select name="result_option_value[]">
                                                        <option value="">Sem resultado</option>
                                                        <?php foreach (($market['options'] ?? []) as $option): ?>
                                                            <option value="<?= htmlspecialchars((string) ($option['option_name'] ?? ''), ENT_QUOTES) ?>" <?= ($market['result_option'] ?? '') === ($option['option_name'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($option['option_name'] ?? ''), ENT_QUOTES) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="admin-result-form-actions">
                                            <p class="admin-helper-text">Selecione os vencedores dos mercados que ja foram definidos e salve tudo de uma vez.</p>
                                            <button type="submit" class="btn-dark">Salvar resultados do jogo</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="admin-card">
                    <div class="admin-card-head">
                        <div>
                            <h2>Jogos cadastrados</h2>
                            <p class="admin-helper-text">Cards compactos para revisar status, editar odds principais e abrir a edicao completa so quando precisar.</p>
                        </div>
                        <span class="admin-compact-badge"><?= count($games) ?> jogos</span>
                    </div>
                    <?php if (empty($games)): ?>
                        <p class="admin-helper-text">Nenhum jogo encontrado.</p>
                    <?php else: ?>
                        <div class="admin-games-grid">
                            <?php foreach ($games as $game): ?>
                                <?php
                                $oddHome = '1.50';
                                $oddDraw = '3.00';
                                $oddAway = '2.50';
                                $gameSettledMarkets = 0;
                                foreach (($game['markets'] ?? []) as $market) {
                                    if (!empty($market['result_option'])) {
                                        $gameSettledMarkets++;
                                    }
                                    if (($market['market_name'] ?? '') !== 'Resultado final') {
                                        continue;
                                    }
                                    foreach (($market['options'] ?? []) as $option) {
                                        $name = (string) ($option['option_name'] ?? '');
                                        if ($name === 'Casa') {
                                            $oddHome = (string) ($option['odd_value'] ?? $oddHome);
                                        } elseif ($name === 'Empate') {
                                            $oddDraw = (string) ($option['odd_value'] ?? $oddDraw);
                                        } elseif ($name === 'Fora') {
                                            $oddAway = (string) ($option['odd_value'] ?? $oddAway);
                                        }
                                    }
                                    break;
                                }
                                $gameMarketCount = count($game['markets'] ?? []);
                                $isEditingThisGame = (int) ($editGame['id'] ?? 0) === (int) ($game['id'] ?? 0);
                                $gameLocked = !empty($game['betting_is_locked']);
                                ?>
                                <div class="admin-game-item <?= (int) ($game['is_visible'] ?? 1) === 1 ? 'is-visible' : 'is-hidden' ?><?= $gameLocked ? ' is-locked' : '' ?>">
                                    <div class="admin-game-item-head">
                                        <strong><?= htmlspecialchars($game['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($game['away_name'], ENT_QUOTES) ?></strong>
                                        <span><?= htmlspecialchars($game['league_name'], ENT_QUOTES) ?> - <?= date('d/m/Y H:i', strtotime($game['match_date'])) ?></span>
                                    </div>

                                    <div class="admin-game-item-meta">
                                        <span>Risco: <?= htmlspecialchars($game['risk_level'], ENT_QUOTES) ?></span>
                                        <span>Status: <?= htmlspecialchars($game['status'], ENT_QUOTES) ?></span>
                                        <span>Visibilidade: <?= (int) ($game['is_visible'] ?? 1) === 1 ? 'Ativo' : 'Desativado' ?></span>
                                        <span class="admin-lock-badge <?= $gameLocked ? 'is-locked' : 'is-open' ?>"><?= $gameLocked ? 'Apostas indisponiveis' : 'Apostas liberadas' ?></span>
                                    </div>

                                    <div class="admin-game-item-summary">
                                        <span>Mercados: <?= $gameMarketCount ?></span>
                                        <span>Resolvidos: <?= $gameSettledMarkets ?></span>
                                        <span>1x2: <?= number_format((float) $oddHome, 2, ',', '.') ?> / <?= number_format((float) $oddDraw, 2, ',', '.') ?> / <?= number_format((float) $oddAway, 2, ',', '.') ?></span>
                                    </div>

                                    <?php if ($gameLocked && !empty($game['betting_lock_reason'])): ?>
                                        <div class="admin-lock-reason"><?= htmlspecialchars((string) $game['betting_lock_reason'], ENT_QUOTES) ?></div>
                                    <?php endif; ?>

                                    <details class="admin-game-item-details"<?= $isEditingThisGame ? ' open' : '' ?>>
                                        <summary><?= $isEditingThisGame ? 'Editando agora' : 'Abrir edicao rapida' ?></summary>
                                        <div class="admin-game-item-details-body">
                                            <form method="post" action="<?= htmlspecialchars(app_url('/admin/game/odds-main'), ENT_QUOTES) ?>" class="admin-game-odds-inline">
                                                <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
                                                <input type="number" step="0.01" min="1.01" name="odd_home" value="<?= htmlspecialchars((string) number_format((float) $oddHome, 2, '.', ''), ENT_QUOTES) ?>" required>
                                                <input type="number" step="0.01" min="1.01" name="odd_draw" value="<?= htmlspecialchars((string) number_format((float) $oddDraw, 2, '.', ''), ENT_QUOTES) ?>" required>
                                                <input type="number" step="0.01" min="1.01" name="odd_away" value="<?= htmlspecialchars((string) number_format((float) $oddAway, 2, '.', ''), ENT_QUOTES) ?>" required>
                                                <button type="submit" class="btn-outline">Salvar odds</button>
                                            </form>

                                            <div class="admin-game-item-actions">
                                                <form method="post" action="<?= htmlspecialchars(app_url('/admin/game/visibility'), ENT_QUOTES) ?>">
                                                    <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
                                                    <input type="hidden" name="is_visible" value="<?= (int) ($game['is_visible'] ?? 1) === 1 ? '0' : '1' ?>">
                                                    <button type="submit" class="<?= (int) ($game['is_visible'] ?? 1) === 1 ? 'btn-dark' : 'btn-outline' ?>">
                                                        <?= (int) ($game['is_visible'] ?? 1) === 1 ? 'Desativar jogo' : 'Ativar jogo' ?>
                                                    </button>
                                                </form>
                                                <a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin?edit_game=' . (int) $game['id'] . '#jogos'), ENT_QUOTES) ?>">Editar completo</a>
                                                <form method="post" action="<?= htmlspecialchars(app_url('/admin/game/delete'), ENT_QUOTES) ?>" onsubmit="return confirm('Deseja deletar este jogo?');">
                                                    <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
                                                    <button type="submit" class="btn-dark">Excluir</button>
                                                </form>
                                            </div>
                                        </div>
                                    </details>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="api" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2>Visibilidade dos syncs</h2>
                    <p class="admin-helper-text">
                        Escolha se os jogos importados pelos syncs entram publicados no sistema ou ocultos para revisao manual antes de aparecer para o apostador.
                    </p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/sync/settings'), ENT_QUOTES) ?>" class="form-grid">
                        <select name="sync_import_visibility">
                            <option value="visible" <?= ($settings['sync_import_visibility'] ?? 'visible') === 'visible' ? 'selected' : '' ?>>Sincronizar e aparecer no sistema</option>
                            <option value="hidden" <?= ($settings['sync_import_visibility'] ?? '') === 'hidden' ? 'selected' : '' ?>>Sincronizar oculto do sistema</option>
                        </select>
                        <p class="admin-helper-text">Essa regra vale para todos os syncs desta aba e tambem atualiza os jogos que forem sincronizados novamente.</p>
                        <button type="submit" class="btn-dark full">salvar visibilidade dos syncs</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Sync Xscore</h2>
                    <p class="admin-helper-text">
                        Importa partidas do dia do <a href="https://www.xscores.com/soccer" target="_blank" rel="noopener noreferrer">Xscore</a>,
                        converte o horario para Brasilia, ignora ao vivo por padrao, aceita filtro por ligas e ja trava os jogos ate o inicio.
                    </p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/games/sync/xscore'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="number" name="limit" min="1" max="500" step="1" value="200">
                        <select name="status_scope">
                            <?php foreach ($syncStatusOptions as $statusValue => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>" <?= $statusValue === 'scheduled' ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="league_filters" rows="4" placeholder="<?= htmlspecialchars($syncLeaguePlaceholder, ENT_QUOTES) ?>"></textarea>
                        <p class="admin-helper-text">Se quiser, informe uma liga por linha ou separada por virgula. Pode usar parte do nome.</p>
                        <button type="submit" class="btn-dark full">sync xscore</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Sync SofaScore</h2>
                    <p class="admin-helper-text">
                        Importa os jogos do dia do <a href="https://www.sofascore.com/" target="_blank" rel="noopener noreferrer">SofaScore</a>
                        com horario de Brasilia, odds 1x2 reais, filtro de status/liga e trava automatica ate o inicio.
                    </p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/games/sync/sofascore'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="number" name="limit" min="1" max="500" step="1" value="200">
                        <select name="status_scope">
                            <?php foreach ($syncStatusOptions as $statusValue => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>" <?= $statusValue === 'scheduled' ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="league_filters" rows="4" placeholder="<?= htmlspecialchars($syncLeaguePlaceholder, ENT_QUOTES) ?>"></textarea>
                        <p class="admin-helper-text">Deixe as ligas em branco para sincronizar tudo o que ainda nao comecou.</p>
                        <button type="submit" class="btn-dark full">sync sofascore</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Sync Flashscore</h2>
                    <p class="admin-helper-text">
                        Importa os jogos do dia do <a href="https://www.flashscore.com.br/" target="_blank" rel="noopener noreferrer">Flashscore</a>
                        com horario de Brasilia, odds 1x2 reais, filtro de status/liga e trava automatica ate o inicio.
                    </p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/games/sync/flashscore'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="number" name="limit" min="1" max="500" step="1" value="200">
                        <select name="status_scope">
                            <?php foreach ($syncStatusOptions as $statusValue => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>" <?= $statusValue === 'scheduled' ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="league_filters" rows="4" placeholder="<?= htmlspecialchars($syncLeaguePlaceholder, ENT_QUOTES) ?>"></textarea>
                        <p class="admin-helper-text">Use o filtro de ligas para puxar apenas campeonatos especificos.</p>
                        <button type="submit" class="btn-dark full">sync flash score</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Sync Betfair</h2>
                    <p class="admin-helper-text">
                        Importa os jogos de futebol exibidos no <a href="https://www.betfair.bet.br/apostas/futebol/s-1" target="_blank" rel="noopener noreferrer">Betfair Sportsbook</a>
                        e puxa as odds 1x2 reais do mercado principal de Resultado final, sem usar API key.
                    </p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/games/sync/betfair'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="number" name="limit" min="1" max="500" step="1" value="200">
                        <select name="status_scope">
                            <?php foreach ($syncStatusOptions as $statusValue => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>" <?= $statusValue === 'scheduled' ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="league_filters" rows="4" placeholder="<?= htmlspecialchars($syncLeaguePlaceholder, ENT_QUOTES) ?>"></textarea>
                        <p class="admin-helper-text">Use os filtros para limitar por liga e processar apenas o recorte desejado do Betfair.</p>
                        <button type="submit" class="btn-dark full">sync betfair</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Sync OddAPI</h2>
                    <p class="admin-helper-text">
                        Usa a <a href="https://the-odds-api.com/liveapi/guides/v4/" target="_blank" rel="noopener noreferrer">The Odds API</a>
                        com a sua API key para puxar jogos de futebol do dia e odds `h2h` em decimal. Consome creditos da sua conta conforme regioes e mercados configurados.
                    </p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/games/sync/oddapi'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="number" name="limit" min="1" max="500" step="1" value="200">
                        <select name="status_scope">
                            <?php foreach ($syncStatusOptions as $statusValue => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>" <?= $statusValue === 'scheduled' ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="league_filters" rows="4" placeholder="<?= htmlspecialchars($syncLeaguePlaceholder, ENT_QUOTES) ?>"></textarea>
                        <p class="admin-helper-text">Cadastre a API key em Configuracoes. Se quiser economizar creditos, use filtros de liga ou limite os sport keys.</p>
                        <button type="submit" class="btn-dark full">sync oddapi</button>
                    </form>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="gerentes" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2><?= $editManager ? 'Editar gerente' : 'Cadastrar gerente' ?></h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/manager/save'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="hidden" name="agent_id" value="<?= (int) ($editManager['id'] ?? 0) ?>">
                        <input type="text" name="name" placeholder="Nome do gerente" value="<?= htmlspecialchars((string) ($editManager['name'] ?? ''), ENT_QUOTES) ?>" required>
                        <input type="email" name="email" placeholder="E-mail" value="<?= htmlspecialchars((string) ($editManager['email'] ?? ''), ENT_QUOTES) ?>" required>
                        <input type="text" name="cpf" placeholder="CPF (11 digitos)" maxlength="14" value="<?= htmlspecialchars((string) ($editManager['cpf'] ?? ''), ENT_QUOTES) ?>" required>
                        <input type="password" name="password" placeholder="<?= $editManager ? 'Nova senha (opcional)' : 'Senha inicial' ?>" <?= $editManager ? '' : 'required' ?>>
                        <input type="number" step="0.01" min="0" max="100" name="commission_rate" placeholder="Comissao %" value="<?= htmlspecialchars((string) ($editManager['commission_rate'] ?? '0'), ENT_QUOTES) ?>">
                        <select name="pix_checkout_mode">
                            <option value="gateway" <?= (($editManager['pix_checkout_mode'] ?? 'gateway') === 'gateway') ? 'selected' : '' ?>>Pix via gateway</option>
                            <option value="custom_key" <?= (($editManager['pix_checkout_mode'] ?? '') === 'custom_key') ? 'selected' : '' ?>>Chave Pix personalizada</option>
                            <option value="custom_qr" <?= (($editManager['pix_checkout_mode'] ?? '') === 'custom_qr') ? 'selected' : '' ?>>QR Code personalizado</option>
                        </select>
                        <input type="text" name="pix_key" placeholder="Chave Pix (se personalizada)" value="<?= htmlspecialchars((string) ($editManager['pix_key'] ?? ''), ENT_QUOTES) ?>">
                        <textarea name="pix_qr_code" rows="4" placeholder="QR Code copia e cola (se personalizado)"><?= htmlspecialchars((string) ($editManager['pix_qr_code'] ?? ''), ENT_QUOTES) ?></textarea>
                        <label><input type="checkbox" name="is_active" <?= (int) ($editManager['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Acesso ativo</label>
                        <button type="submit" class="btn-dark full"><?= $editManager ? 'Atualizar gerente' : 'Salvar gerente' ?></button>
                        <?php if ($editManager): ?><a class="btn-outline full" href="<?= htmlspecialchars(app_url('/admin#gerentes'), ENT_QUOTES) ?>">Cancelar</a><?php endif; ?>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Gerentes cadastrados</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nome</th><th>Status</th><th>Comissao</th><th>Cambistas</th><th>Caixa</th><th>Acoes</th></tr></thead>
                            <tbody>
                            <?php if ($managers === []): ?>
                                <tr><td colspan="6">Nenhum gerente cadastrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($managers as $manager): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $manager['name'], ENT_QUOTES) ?><br><small><?= htmlspecialchars((string) $manager['email'], ENT_QUOTES) ?></small></td>
                                        <td><?= (int) ($manager['is_active'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></td>
                                        <td><?= number_format((float) ($manager['commission_rate'] ?? 0), 2, ',', '.') ?>%</td>
                                        <td><?= (int) ($manager['bookmakers_total'] ?? 0) ?></td>
                                        <td>R$ <?= number_format((float) ($manager['agent_balance'] ?? 0), 2, ',', '.') ?></td>
                                        <td>
                                            <a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin?edit_manager=' . (int) $manager['id'] . '#gerentes'), ENT_QUOTES) ?>">Editar</a>
                                            <form method="post" action="<?= htmlspecialchars(app_url('/admin/agent/delete'), ENT_QUOTES) ?>" style="display:inline;" onsubmit="return confirm('Deseja apagar este gerente?');">
                                                <input type="hidden" name="agent_id" value="<?= (int) $manager['id'] ?>">
                                                <input type="hidden" name="agent_role" value="manager">
                                                <button type="submit" class="btn-dark">Excluir</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="cambistas" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2><?= $editBookmaker ? 'Editar cambista' : 'Cadastrar cambista' ?></h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/bookmaker/save'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="hidden" name="agent_id" value="<?= (int) ($editBookmaker['id'] ?? 0) ?>">
                        <input type="text" name="name" placeholder="Nome do cambista" value="<?= htmlspecialchars((string) ($editBookmaker['name'] ?? ''), ENT_QUOTES) ?>" required>
                        <input type="email" name="email" placeholder="E-mail" value="<?= htmlspecialchars((string) ($editBookmaker['email'] ?? ''), ENT_QUOTES) ?>" required>
                        <input type="text" name="cpf" placeholder="CPF (11 digitos)" maxlength="14" value="<?= htmlspecialchars((string) ($editBookmaker['cpf'] ?? ''), ENT_QUOTES) ?>" required>
                        <input type="password" name="password" placeholder="<?= $editBookmaker ? 'Nova senha (opcional)' : 'Senha inicial' ?>" <?= $editBookmaker ? '' : 'required' ?>>
                        <select name="manager_user_id" required>
                            <option value="">Gerente responsavel</option>
                            <?php foreach ($managerOptions as $managerOption): ?>
                                <option value="<?= (int) $managerOption['id'] ?>" <?= (int) ($editBookmaker['manager_user_id'] ?? 0) === (int) $managerOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $managerOption['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" step="0.01" min="0" max="100" name="commission_rate" placeholder="Comissao %" value="<?= htmlspecialchars((string) ($editBookmaker['commission_rate'] ?? '0'), ENT_QUOTES) ?>">
                        <select name="pix_checkout_mode">
                            <option value="gateway" <?= (($editBookmaker['pix_checkout_mode'] ?? 'gateway') === 'gateway') ? 'selected' : '' ?>>Pix via gateway</option>
                            <option value="custom_key" <?= (($editBookmaker['pix_checkout_mode'] ?? '') === 'custom_key') ? 'selected' : '' ?>>Chave Pix personalizada</option>
                            <option value="custom_qr" <?= (($editBookmaker['pix_checkout_mode'] ?? '') === 'custom_qr') ? 'selected' : '' ?>>QR Code personalizado</option>
                        </select>
                        <input type="text" name="pix_key" placeholder="Chave Pix (se personalizada)" value="<?= htmlspecialchars((string) ($editBookmaker['pix_key'] ?? ''), ENT_QUOTES) ?>">
                        <textarea name="pix_qr_code" rows="4" placeholder="QR Code copia e cola (se personalizado)"><?= htmlspecialchars((string) ($editBookmaker['pix_qr_code'] ?? ''), ENT_QUOTES) ?></textarea>
                        <label><input type="checkbox" name="is_active" <?= (int) ($editBookmaker['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Acesso ativo</label>
                        <button type="submit" class="btn-dark full"><?= $editBookmaker ? 'Atualizar cambista' : 'Salvar cambista' ?></button>
                        <?php if ($editBookmaker): ?><a class="btn-outline full" href="<?= htmlspecialchars(app_url('/admin#cambistas'), ENT_QUOTES) ?>">Cancelar</a><?php endif; ?>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Cambistas cadastrados</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nome</th><th>Gerente</th><th>Status</th><th>Comissao</th><th>Caixa</th><th>Acoes</th></tr></thead>
                            <tbody>
                            <?php if ($bookmakers === []): ?>
                                <tr><td colspan="6">Nenhum cambista cadastrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($bookmakers as $bookmaker): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $bookmaker['name'], ENT_QUOTES) ?><br><small><?= htmlspecialchars((string) $bookmaker['email'], ENT_QUOTES) ?></small></td>
                                        <td><?= htmlspecialchars((string) ($bookmaker['manager_name'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= (int) ($bookmaker['is_active'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></td>
                                        <td><?= number_format((float) ($bookmaker['commission_rate'] ?? 0), 2, ',', '.') ?>%</td>
                                        <td>R$ <?= number_format((float) ($bookmaker['agent_balance'] ?? 0), 2, ',', '.') ?></td>
                                        <td>
                                            <a class="btn-outline" href="<?= htmlspecialchars(app_url('/admin?edit_bookmaker=' . (int) $bookmaker['id'] . '#cambistas'), ENT_QUOTES) ?>">Editar</a>
                                            <form method="post" action="<?= htmlspecialchars(app_url('/admin/agent/delete'), ENT_QUOTES) ?>" style="display:inline;" onsubmit="return confirm('Deseja apagar este cambista?');">
                                                <input type="hidden" name="agent_id" value="<?= (int) $bookmaker['id'] ?>">
                                                <input type="hidden" name="agent_role" value="bookmaker">
                                                <button type="submit" class="btn-dark">Excluir</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="caixa-agentes" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2>Ajustar saldo de gerente/cambista</h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/agent/balance'), ENT_QUOTES) ?>" class="form-grid">
                        <select name="agent_id" required>
                            <option value="">Agente</option>
                            <?php foreach ($managers as $manager): ?>
                                <option value="<?= (int) $manager['id'] ?>">Gerente: <?= htmlspecialchars((string) $manager['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                            <?php foreach ($bookmakers as $bookmaker): ?>
                                <option value="<?= (int) $bookmaker['id'] ?>">Cambista: <?= htmlspecialchars((string) $bookmaker['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" step="0.01" name="amount" placeholder="Valor (+ credito / - debito)" required>
                        <input type="text" name="description" placeholder="Descricao do ajuste">
                        <button type="submit" class="btn-dark full">Adicionar saldo / ajustar caixa</button>
                    </form>
                </article>

                <article class="admin-card">
                    <div class="admin-card-head">
                        <h2>Caixa separado de gerentes e cambistas</h2>
                        <form method="get" action="<?= htmlspecialchars(app_url('/admin'), ENT_QUOTES) ?>" class="admin-ticket-filter-form">
                            <input type="hidden" name="edit_manager" value="<?= (int) ($editManager['id'] ?? 0) ?>">
                            <input type="hidden" name="edit_bookmaker" value="<?= (int) ($editBookmaker['id'] ?? 0) ?>">
                            <select name="agent_cash_agent_id">
                                <option value="0">Todos os agentes</option>
                                <?php foreach ($managers as $manager): ?>
                                    <option value="<?= (int) $manager['id'] ?>" <?= (int) ($agentCashFilters['agent_id'] ?? 0) === (int) $manager['id'] ? 'selected' : '' ?>>Gerente: <?= htmlspecialchars((string) $manager['name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                                <?php foreach ($bookmakers as $bookmaker): ?>
                                    <option value="<?= (int) $bookmaker['id'] ?>" <?= (int) ($agentCashFilters['agent_id'] ?? 0) === (int) $bookmaker['id'] ? 'selected' : '' ?>>Cambista: <?= htmlspecialchars((string) $bookmaker['name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="agent_cash_role">
                                <option value="all">Todos os perfis</option>
                                <option value="manager" <?= ($agentCashFilters['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Gerentes</option>
                                <option value="bookmaker" <?= ($agentCashFilters['role'] ?? '') === 'bookmaker' ? 'selected' : '' ?>>Cambistas</option>
                            </select>
                            <select name="agent_cash_status">
                                <option value="all">Todos os status</option>
                                <option value="paid" <?= ($agentCashFilters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Pago</option>
                                <option value="pending" <?= ($agentCashFilters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pendente</option>
                                <option value="failed" <?= ($agentCashFilters['status'] ?? '') === 'failed' ? 'selected' : '' ?>>Falhou</option>
                            </select>
                            <input type="text" name="agent_cash_entry_type" placeholder="Tipo (ex: manager_commission)" value="<?= htmlspecialchars((string) ($agentCashFilters['entry_type'] ?? ''), ENT_QUOTES) ?>">
                            <input type="date" name="agent_cash_date_from" value="<?= htmlspecialchars((string) ($agentCashFilters['date_from'] ?? ''), ENT_QUOTES) ?>">
                            <input type="date" name="agent_cash_date_to" value="<?= htmlspecialchars((string) ($agentCashFilters['date_to'] ?? ''), ENT_QUOTES) ?>">
                            <button type="submit" class="btn-outline">Filtrar</button>
                            <a class="btn-dark" href="<?= htmlspecialchars(app_url('/admin#caixa-agentes'), ENT_QUOTES) ?>">Limpar</a>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Data</th><th>Agente</th><th>Perfil</th><th>Origem</th><th>Tipo</th><th>Valor</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if ($agentCashEntries === []): ?>
                                <tr><td colspan="7">Nenhum lancamento encontrado no caixa dos agentes.</td></tr>
                            <?php else: ?>
                                <?php foreach ($agentCashEntries as $entry): ?>
                                    <tr>
                                        <td><?= date('d/m/Y H:i', strtotime((string) $entry['created_at'])) ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['agent_name'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['agent_role'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['source_agent_name'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['entry_type'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= (($entry['direction'] ?? 'credit') === 'debit' ? '-' : '+') ?> R$ <?= number_format((float) ($entry['amount'] ?? 0), 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['status'] ?? '-'), ENT_QUOTES) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="trava" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2>Resumo da trava</h2>
                    <div class="admin-metrics">
                        <article><span>Trava global</span><strong><?= !empty($lockSettings['global_lock_all']) ? 'Ligada' : 'Desligada' ?></strong></article>
                        <article><span>Jogos travados agora</span><strong><?= (int) $lockSummary['currently_locked_games'] ?></strong></article>
                        <article><span>Travados manualmente</span><strong><?= (int) $lockSummary['manual_locked_games'] ?></strong></article>
                        <article><span>So ate iniciar</span><strong><?= (int) $lockSummary['prestart_games'] ?></strong></article>
                        <article><span>Com trava por minutos</span><strong><?= (int) $lockSummary['timed_games'] ?></strong></article>
                    </div>
                    <p class="admin-helper-text">A trava atua na home e no fechamento do bilhete. Se o jogo travar depois de entrar no bilhete, ele sai automaticamente na confirmacao.</p>
                </article>

                <article class="admin-card">
                    <h2>Travar todos os jogos</h2>
                    <p class="admin-helper-text">Liga ou desliga uma trava global para todos os jogos do site.</p>
                    <div class="admin-lock-global-state <?= !empty($lockSettings['global_lock_all']) ? 'is-on' : 'is-off' ?>">
                        <?= !empty($lockSettings['global_lock_all']) ? 'Trava global ativa' : 'Trava global desativada' ?>
                    </div>
                    <div class="admin-lock-actions">
                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/lock/global'), ENT_QUOTES) ?>" class="admin-lock-action-form" onsubmit="return confirm('<?= !empty($lockSettings['global_lock_all']) ? 'Deseja destravar novamente todos os jogos?' : 'Deseja travar todos os jogos para apostas?' ?>');">
                            <input type="hidden" name="global_lock" value="<?= !empty($lockSettings['global_lock_all']) ? '0' : '1' ?>">
                            <button type="submit" class="<?= !empty($lockSettings['global_lock_all']) ? 'btn-outline' : 'btn-dark' ?> full">
                                <?= !empty($lockSettings['global_lock_all']) ? 'destravar todos os jogos' : 'travar todos os jogos' ?>
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/lock/clear-all'), ENT_QUOTES) ?>" class="admin-lock-action-form" onsubmit="return confirm('Deseja remover a trava global e limpar todas as travas manuais e por horario dos jogos?');">
                            <button type="submit" class="btn-outline full admin-lock-reset-btn">destravar tudo</button>
                        </form>
                    </div>
                </article>

                <article class="admin-card">
                    <h2>Travar jogo especifico</h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/lock/game'), ENT_QUOTES) ?>" class="form-grid">
                        <select name="game_id" required>
                            <option value="">Selecione um jogo</option>
                            <?php foreach ($lockGames as $game): ?>
                                <option value="<?= (int) $game['id'] ?>">
                                    <?= date('d/m H:i', strtotime($game['match_date'])) ?> - <?= htmlspecialchars($game['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($game['away_name'], ENT_QUOTES) ?> - <?= htmlspecialchars($game['league_name'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="manual_lock">
                            <option value="1">Travar manualmente</option>
                            <option value="0">Remover trava manual</option>
                        </select>
                        <button type="submit" class="btn-dark full">salvar trava manual</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Travar por horario</h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/lock/window'), ENT_QUOTES) ?>" class="form-grid">
                        <select name="game_id" required>
                            <option value="">Selecione um jogo</option>
                            <?php foreach ($lockGames as $game): ?>
                                <option value="<?= (int) $game['id'] ?>">
                                    <?= date('d/m H:i', strtotime($game['match_date'])) ?> - <?= htmlspecialchars($game['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($game['away_name'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="admin-checkbox-line"><input type="checkbox" name="only_before_start" value="1"> so pode apostar ate o inicio da partida</label>
                        <input type="number" name="lock_after_minutes" min="0" max="600" step="1" value="0" placeholder="Travar apos X minutos de jogo (0 desativa)">
                        <button type="submit" class="btn-dark full">salvar trava por horario</button>
                    </form>
                </article>

                <article class="admin-card full-span">
                    <h2>Jogos com regra de trava</h2>
                    <?php
                    $gamesWithRules = array_values(array_filter($lockGames, static function (array $game): bool {
                        return ((int) ($game['betting_locked'] ?? 0) === 1)
                            || ((int) ($game['betting_only_before_start'] ?? 0) === 1)
                            || ((int) ($game['betting_lock_after_minutes'] ?? 0) > 0);
                    }));
                    ?>
                    <?php if ($gamesWithRules === []): ?>
                        <p class="admin-helper-text">Nenhum jogo com trava especial configurada no momento.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Jogo</th><th>Horario</th><th>Regra</th><th>Status atual</th></tr></thead>
                                <tbody>
                                <?php foreach ($gamesWithRules as $game): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($game['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($game['away_name'], ENT_QUOTES) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($game['match_date'])) ?></td>
                                        <td><?= htmlspecialchars((string) ($game['betting_rule_summary'] ?? 'sem trava especial'), ENT_QUOTES) ?></td>
                                        <td>
                                            <span class="admin-lock-badge <?= !empty($game['betting_is_locked']) ? 'is-locked' : 'is-open' ?>">
                                                <?= !empty($game['betting_is_locked']) ? 'Travado' : 'Liberado' ?>
                                            </span>
                                            <?php if (!empty($game['betting_lock_reason'])): ?>
                                                <div class="admin-lock-reason"><?= htmlspecialchars((string) $game['betting_lock_reason'], ENT_QUOTES) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="limpeza" data-admin-section>
            <div class="admin-grid">
                <article class="admin-card">
                    <h2>Resumo da limpeza</h2>
                    <div class="admin-metrics">
                        <article><span>Jogos admin</span><strong><?= (int) $cleanupSummary['total_games'] ?></strong></article>
                        <article><span>Jogos limpaveis</span><strong><?= (int) $cleanupSummary['deletable_games'] ?></strong></article>
                        <article><span>Jogos protegidos</span><strong><?= (int) $cleanupSummary['linked_games'] ?></strong></article>
                        <article><span>Jogos travados</span><strong><?= (int) $cleanupSummary['locked_games'] ?></strong></article>
                        <article><span>Jogos sem odds</span><strong><?= (int) $cleanupSummary['games_without_odds'] ?></strong></article>
                        <article><span>Ligas orfas</span><strong><?= (int) $cleanupSummary['orphan_leagues'] ?></strong></article>
                        <article><span>Times orfaos</span><strong><?= (int) $cleanupSummary['orphan_teams'] ?></strong></article>
                        <article><span>Total de bilhetes</span><strong><?= (int) ($cleanupSummary['total_tickets'] ?? 0) ?></strong></article>
                        <article><span>Bilhetes abertos</span><strong><?= (int) ($cleanupSummary['open_tickets'] ?? 0) ?></strong></article>
                        <article><span>Bilhetes finalizados</span><strong><?= (int) ($cleanupSummary['settled_tickets'] ?? 0) ?></strong></article>
                        <article><span>Logs gateway</span><strong><?= (int) ($cleanupSummary['gateway_logs'] ?? 0) ?></strong></article>
                    </div>
                    <p class="admin-helper-text">A limpeza em massa protege automaticamente jogos que possuem bilhetes vinculados. Nas limpezas profundas de bilhetes e jogos com bilhetes, o sistema remove o historico de apostas, mas nao recalcula os saldos atuais.</p>
                </article>

                <article class="admin-card">
                    <h2>Limpar todos os jogos</h2>
                    <p class="admin-helper-text">Remove em lote todos os jogos do admin sem bilhetes vinculados. Jogos com apostas ficam protegidos.</p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/games'), ENT_QUOTES) ?>" class="form-grid" onsubmit="return confirm('Deseja limpar todos os jogos sem bilhetes vinculados?');">
                        <input type="hidden" name="cleanup_preset" value="all_safe">
                        <input type="number" name="limit" min="1" max="10000" step="1" value="5000">
                        <button type="submit" class="btn-dark full">limpar jogos</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Limpar por categoria</h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/games'), ENT_QUOTES) ?>" class="form-grid" onsubmit="return confirm('Deseja limpar os jogos filtrados por categoria?');">
                        <input type="hidden" name="cleanup_preset" value="category">
                        <select name="category_id" required>
                            <option value="">Categoria</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status">
                            <option value="all">Todos os status</option>
                            <option value="scheduled">Agendados</option>
                            <option value="live">Ao vivo</option>
                            <option value="finished">Finalizados</option>
                        </select>
                        <select name="source">
                            <option value="all">Todas as fontes</option>
                            <option value="manual">Manual</option>
                            <option value="xscore">Xscore</option>
                            <option value="sofascore">Sofascore</option>
                            <option value="flashscore">Flashscore</option>
                            <option value="betfair">Betfair</option>
                            <option value="oddapi">OddAPI</option>
                        </select>
                        <input type="number" name="limit" min="1" max="10000" step="1" value="1000">
                        <button type="submit" class="btn-dark full">limpar por categoria</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Limpar por horario</h2>
                    <p class="admin-helper-text">Informe data inicial, data final ou as duas. O sistema protege jogos ligados a bilhetes.</p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/games'), ENT_QUOTES) ?>" class="form-grid" onsubmit="return confirm('Deseja limpar os jogos dentro do horario informado?');">
                        <input type="hidden" name="cleanup_preset" value="schedule">
                        <input type="datetime-local" name="match_from" value="">
                        <input type="datetime-local" name="match_to" value="">
                        <select name="status">
                            <option value="all">Todos os status</option>
                            <option value="scheduled">Agendados</option>
                            <option value="live">Ao vivo</option>
                            <option value="finished">Finalizados</option>
                        </select>
                        <select name="source">
                            <option value="all">Todas as fontes</option>
                            <option value="manual">Manual</option>
                            <option value="xscore">Xscore</option>
                            <option value="sofascore">Sofascore</option>
                            <option value="flashscore">Flashscore</option>
                            <option value="betfair">Betfair</option>
                            <option value="oddapi">OddAPI</option>
                        </select>
                        <input type="number" name="limit" min="1" max="10000" step="1" value="1000">
                        <button type="submit" class="btn-dark full">limpar por horario</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Limpeza em massa</h2>
                    <p class="admin-helper-text">Combine filtros para uma limpeza maior. Use pelo menos um filtro e os jogos com bilhetes continuam protegidos.</p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/games'), ENT_QUOTES) ?>" class="form-grid" onsubmit="return confirm('Deseja executar a limpeza em massa com os filtros informados?');">
                        <input type="hidden" name="cleanup_preset" value="mass">
                        <select name="category_id">
                            <option value="0">Todas as categorias</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status">
                            <option value="all">Todos os status</option>
                            <option value="scheduled">Agendados</option>
                            <option value="live">Ao vivo</option>
                            <option value="finished">Finalizados</option>
                        </select>
                        <select name="source">
                            <option value="all">Todas as fontes</option>
                            <option value="manual">Manual</option>
                            <option value="xscore">Xscore</option>
                            <option value="sofascore">Sofascore</option>
                            <option value="flashscore">Flashscore</option>
                            <option value="betfair">Betfair</option>
                            <option value="oddapi">OddAPI</option>
                        </select>
                        <input type="datetime-local" name="match_from" value="">
                        <input type="datetime-local" name="match_to" value="">
                        <input type="number" name="limit" min="1" max="10000" step="1" value="2000">
                        <button type="submit" class="btn-dark full">limpar em massa</button>
                    </form>
                </article>

                <article class="admin-card">
                    <h2>Outras opcoes de limpeza</h2>
                    <div class="admin-results-list">
                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar jogos finalizados antigos sem bilhetes vinculados?');">
                            <input type="hidden" name="cleanup_action" value="finished_old">
                            <input type="number" name="days" min="1" max="3650" step="1" value="30">
                            <input type="number" name="limit" min="1" max="10000" step="1" value="1000">
                            <button type="submit" class="btn-dark">limpar finalizados antigos</button>
                        </form>

                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar jogos sem odds e sem bilhetes vinculados?');">
                            <input type="hidden" name="cleanup_action" value="without_odds">
                            <input type="number" name="limit" min="1" max="10000" step="1" value="1000">
                            <button type="submit" class="btn-dark">limpar jogos sem odds</button>
                        </form>

                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar apenas jogos travados e sem bilhetes vinculados?');">
                            <input type="hidden" name="cleanup_action" value="locked_games">
                            <input type="number" name="limit" min="1" max="10000" step="1" value="1000">
                            <button type="submit" class="btn-dark">limpar jogos travados</button>
                        </form>

                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar ligas e times orfaos?');">
                            <input type="hidden" name="cleanup_action" value="orphans">
                            <button type="submit" class="btn-dark">limpar estruturas orfas</button>
                        </form>
                    </div>
                </article>

                <article class="admin-card">
                    <h2>Bilhetes e logs</h2>
                    <p class="admin-helper-text">Essas opcoes sao mais fortes. Elas limpam historico e requests, mas nao mexem no saldo atual de usuarios, gerentes ou cambistas.</p>
                    <div class="admin-results-list">
                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar bilhetes finalizados antigos? Essa acao remove bilhetes e itens, sem recalcular os saldos atuais.');">
                            <input type="hidden" name="cleanup_action" value="tickets_settled_old">
                            <input type="number" name="days" min="1" max="3650" step="1" value="30">
                            <input type="number" name="limit" min="1" max="10000" step="1" value="1000">
                            <button type="submit" class="btn-dark">limpar bilhetes finalizados antigos</button>
                        </form>

                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar todos os bilhetes do sistema? Essa acao remove o historico de apostas e itens, sem recalcular os saldos atuais.');">
                            <input type="hidden" name="cleanup_action" value="tickets_all">
                            <input type="number" name="limit" min="1" max="10000" step="1" value="5000">
                            <button type="submit" class="btn-dark">limpar todos os bilhetes</button>
                        </form>

                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar jogos com bilhetes? Essa acao remove primeiro os bilhetes ligados a esses jogos e depois apaga os jogos, sem recalcular os saldos atuais.');">
                            <input type="hidden" name="cleanup_action" value="games_with_tickets">
                            <input type="number" name="limit" min="1" max="10000" step="1" value="1000">
                            <button type="submit" class="btn-dark">limpar jogos com bilhetes</button>
                        </form>

                        <form method="post" action="<?= htmlspecialchars(app_url('/admin/cleanup/maintenance'), ENT_QUOTES) ?>" class="admin-inline-form" onsubmit="return confirm('Deseja limpar logs antigos de Pix e requests de agentes? Essa acao zera payloads antigos e preserva os registros principais.');">
                            <input type="hidden" name="cleanup_action" value="gateway_logs">
                            <input type="number" name="days" min="1" max="3650" step="1" value="30">
                            <button type="submit" class="btn-dark">limpar logs de gateway</button>
                        </form>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="financeiro" data-admin-section>
            <div class="admin-grid one-column">
                <article class="admin-card">
                    <h2>Contabilidade recente</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Ref</th><th>Tipo</th><th>Valor</th><th>Status</th><th>Data</th></tr></thead>
                            <tbody>
                            <?php foreach ($settlements as $entry): ?>
                                <tr>
                                    <td><?= htmlspecialchars($entry['reference'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($entry['type'], ENT_QUOTES) ?></td>
                                    <td>R$ <?= number_format((float) $entry['amount'], 2, ',', '.') ?></td>
                                    <td><?= htmlspecialchars($entry['status'], ENT_QUOTES) ?></td>
                                    <td><?= date('d/m H:i', strtotime($entry['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="configuracoes" data-admin-section>
            <div class="admin-grid one-column">
                <article class="admin-card">
                    <h2>Configuracoes do site e gateways Pix</h2>
                    <form method="post" action="<?= htmlspecialchars(app_url('/admin/settings/save'), ENT_QUOTES) ?>" class="form-grid">
                        <label>Tema global do site</label>
                        <select name="site_theme">
                            <option value="classic" <?= ($settings['site_theme'] ?? 'classic') === 'classic' ? 'selected' : '' ?>>Tema 1 (classico)</option>
                            <option value="neo" <?= ($settings['site_theme'] ?? 'classic') === 'neo' ? 'selected' : '' ?>>Tema 2 (neo)</option>
                            <option value="sportsbook" <?= ($settings['site_theme'] ?? 'classic') === 'sportsbook' ? 'selected' : '' ?>>Tema 3 (sportsbook verde)</option>
                        </select>
                        <label><input type="checkbox" name="pix_gateway_ecompag_enabled" <?= ($settings['pix_gateway_ecompag_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Ecompag ativo</label>
                        <label><input type="checkbox" name="pix_gateway_ggpix_enabled" <?= ($settings['pix_gateway_ggpix_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> GGPix ativo</label>
                        <select name="pix_gateway_default">
                            <option value="ecompag" <?= ($settings['pix_gateway_default'] ?? 'ecompag') === 'ecompag' ? 'selected' : '' ?>>Gateway padrao: Ecompag</option>
                            <option value="ggpix" <?= ($settings['pix_gateway_default'] ?? 'ecompag') === 'ggpix' ? 'selected' : '' ?>>Gateway padrao: GGPix</option>
                        </select>
                        <input type="text" name="ecompag_client_id" placeholder="Client ID Ecompag" value="<?= htmlspecialchars($settings['ecompag_client_id'] ?? '', ENT_QUOTES) ?>">
                        <input type="text" name="ecompag_client_secret" placeholder="Secret Key Ecompag" value="<?= htmlspecialchars($settings['ecompag_client_secret'] ?? '', ENT_QUOTES) ?>">
                        <input type="text" name="ecompag_webhook_url" placeholder="Webhook URL Ecompag" value="<?= htmlspecialchars($settings['ecompag_webhook_url'] ?? '', ENT_QUOTES) ?>">
                        <input type="text" name="ggpix_api_key" placeholder="API Key GGPix" value="<?= htmlspecialchars($settings['ggpix_api_key'] ?? '', ENT_QUOTES) ?>">
                        <input type="text" name="ggpix_webhook_url" placeholder="Webhook URL GGPix" value="<?= htmlspecialchars($settings['ggpix_webhook_url'] ?? '', ENT_QUOTES) ?>">
                        <input type="text" name="the_odds_api_key" placeholder="API Key The Odds API" value="<?= htmlspecialchars($settings['the_odds_api_key'] ?? '', ENT_QUOTES) ?>">
                        <input type="text" name="the_odds_api_regions" placeholder="Regioes The Odds API (ex: eu)" value="<?= htmlspecialchars($settings['the_odds_api_regions'] ?? 'eu', ENT_QUOTES) ?>">
                        <input type="text" name="the_odds_api_bookmakers" placeholder="Bookmakers The Odds API (opcional, ex: pinnacle,betfair_ex_eu)" value="<?= htmlspecialchars($settings['the_odds_api_bookmakers'] ?? '', ENT_QUOTES) ?>">
                        <textarea name="the_odds_api_sport_keys" rows="4" class="full-span" placeholder="Sport keys The Odds API (opcional, um por linha ou separados por virgula)"><?= htmlspecialchars($settings['the_odds_api_sport_keys'] ?? '', ENT_QUOTES) ?></textarea>
                        <input type="number" step="0.01" name="risk_limit" placeholder="Limite de risco" value="<?= htmlspecialchars($settings['risk_limit'] ?? '', ENT_QUOTES) ?>">
                        <p class="admin-helper-text full-span">Se `bookmakers` estiver preenchido, ele tem prioridade sobre `regioes`. Deixe `sport keys` em branco para buscar todas as ligas de futebol ativas do dia.</p>
                        <div class="admin-results-list full-span">
                            <?php foreach (($gatewaySummary['providers'] ?? []) as $provider): ?>
                                <div class="admin-inline-form">
                                    <strong><?= htmlspecialchars((string) ($provider['label'] ?? ''), ENT_QUOTES) ?></strong>
                                    <span><?= !empty($provider['enabled']) ? 'Ativo' : 'Inativo' ?></span>
                                    <span><?= !empty($provider['is_default']) ? 'Padrao atual' : 'Secundario' ?></span>
                                    <a class="btn-outline" href="<?= htmlspecialchars((string) (($provider['docs']['docs_url'] ?? '#')), ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Documentacao</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn-dark full">Salvar configuracoes</button>
                    </form>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="bilhetes" data-admin-section>
            <div class="admin-grid one-column">
                <article class="admin-card">
                    <div class="admin-card-head">
                        <h2>Bilhetes do dia</h2>
                        <form method="get" action="<?= htmlspecialchars(app_url('/admin'), ENT_QUOTES) ?>" class="admin-ticket-filter-form">
                            <input type="hidden" name="ticket" value="<?= (int) ($selectedTicket['id'] ?? 0) ?>">
                            <input type="hidden" name="edit_category" value="<?= (int) ($editCategory['id'] ?? 0) ?>">
                            <input type="hidden" name="edit_league" value="<?= (int) ($editLeague['id'] ?? 0) ?>">
                            <input type="hidden" name="edit_game" value="<?= (int) ($editGame['id'] ?? 0) ?>">
                            <input type="hidden" name="edit_team" value="<?= (int) ($editTeam['id'] ?? 0) ?>">
                            <input type="date" name="ticket_date_from" value="<?= htmlspecialchars($ticketDateFrom ?? date('Y-m-d'), ENT_QUOTES) ?>">
                            <input type="date" name="ticket_date_to" value="<?= htmlspecialchars($ticketDateTo ?? date('Y-m-d'), ENT_QUOTES) ?>">
                            <button type="submit" class="btn-outline">Filtrar</button>
                            <a class="btn-dark" href="<?= htmlspecialchars(app_url('/admin#bilhetes'), ENT_QUOTES) ?>">Hoje</a>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Codigo</th><th>Usuario</th><th>Stake</th><th>Odd</th><th>Retorno</th><th>Status</th><th>Data</th></tr></thead>
                            <tbody>
                            <?php if (empty($bets)): ?>
                                <tr>
                                    <td colspan="7">Nenhum bilhete encontrado no periodo selecionado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bets as $bet): ?>
                                    <tr>
                                        <td><a class="admin-ticket-link" href="<?= htmlspecialchars(app_url('/admin?ticket=' . (int) $bet['id'] . '&ticket_date_from=' . urlencode($ticketDateFrom ?? date('Y-m-d')) . '&ticket_date_to=' . urlencode($ticketDateTo ?? date('Y-m-d')) . '#bilhetes'), ENT_QUOTES) ?>"><?= htmlspecialchars($bet['ticket_code'], ENT_QUOTES) ?></a></td>
                                        <td><?= htmlspecialchars($bet['user_name'], ENT_QUOTES) ?></td>
                                        <td>R$ <?= number_format((float) $bet['stake'], 2, ',', '.') ?></td>
                                        <td><?= number_format((float) $bet['total_odd'], 2, ',', '.') ?></td>
                                        <td>R$ <?= number_format((float) $bet['potential_return'], 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($bet['status'], ENT_QUOTES) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($bet['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <?php if (!empty($selectedTicket)): ?>
                    <article class="admin-card admin-ticket-detail-card">
                        <h2>Detalhes do bilhete <?= htmlspecialchars($selectedTicket['ticket_code'], ENT_QUOTES) ?></h2>
                        <div class="admin-ticket-summary-grid">
                            <article><span>Usuario</span><strong><?= htmlspecialchars($selectedTicket['user_name'], ENT_QUOTES) ?></strong></article>
                            <article><span>E-mail</span><strong><?= htmlspecialchars($selectedTicket['user_email'], ENT_QUOTES) ?></strong></article>
                            <article><span>CPF</span><strong><?= htmlspecialchars($selectedTicket['user_cpf'], ENT_QUOTES) ?></strong></article>
                            <article><span>Criado em</span><strong><?= date('d/m/Y H:i', strtotime($selectedTicket['created_at'])) ?></strong></article>
                            <article><span>Stake</span><strong>R$ <?= number_format((float) $selectedTicket['stake'], 2, ',', '.') ?></strong></article>
                            <article><span>Odd total</span><strong><?= number_format((float) $selectedTicket['total_odd'], 2, ',', '.') ?></strong></article>
                            <article><span>Possivel ganho</span><strong>R$ <?= number_format((float) $selectedTicket['potential_return'], 2, ',', '.') ?></strong></article>
                            <article><span>Status</span><strong><?= htmlspecialchars($selectedTicket['status'], ENT_QUOTES) ?></strong></article>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Jogo</th><th>Horario</th><th>Mercado</th><th>Escolha</th><th>Odd</th><th>Resultado</th><th>Situacao do jogo</th></tr></thead>
                                <tbody>
                                <?php foreach (($selectedTicket['items'] ?? []) as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['home_name'], ENT_QUOTES) ?> x <?= htmlspecialchars($item['away_name'], ENT_QUOTES) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($item['match_date'])) ?></td>
                                        <td><?= htmlspecialchars($item['market_name'], ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars($item['option_name'], ENT_QUOTES) ?></td>
                                        <td><?= number_format((float) $item['odd_value'], 2, ',', '.') ?></td>
                                        <td><?= !empty($item['result_option']) ? htmlspecialchars($item['result_option'], ENT_QUOTES) : '-' ?></td>
                                        <td><?= htmlspecialchars($item['game_status'], ENT_QUOTES) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script src="<?= htmlspecialchars(app_url('public/assets/js/app.js?v=' . (@filemtime(__DIR__ . '/../../../public/assets/js/app.js') ?: time())), ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
