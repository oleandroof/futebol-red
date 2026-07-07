<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminController;
use App\Controllers\AgentController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;

final class App
{
    public function __construct(private readonly array $config)
    {
    }

    public function run(): void
    {
        $route = $this->resolveRoute();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $routes = [
            'GET' => [
                '/' => [HomeController::class, 'index'],
                '/admin' => [AdminController::class, 'index'],
                '/agent' => [AgentController::class, 'index'],
                '/logout' => [AuthController::class, 'logout'],
            ],
            'POST' => [
                '/login' => [AuthController::class, 'login'],
                '/register' => [AuthController::class, 'register'],
                '/wallet/deposit/create' => [AuthController::class, 'createDepositPix'],
                '/wallet/deposit/status' => [AuthController::class, 'syncDepositStatus'],
                '/wallet/withdraw' => [AuthController::class, 'withdraw'],
                '/profile/password' => [AuthController::class, 'changePassword'],
                '/webhook/ecompag' => [AuthController::class, 'ecompagWebhook'],
                '/webhook/ggpix' => [AuthController::class, 'ggpixWebhook'],
                '/betslip/add' => [HomeController::class, 'addToBetSlip'],
                '/betslip/remove' => [HomeController::class, 'removeFromBetSlip'],
                '/betslip/clear' => [HomeController::class, 'clearBetSlip'],
                '/bets/confirm' => [HomeController::class, 'confirmBetSlip'],
                '/ticket/check' => [HomeController::class, 'checkTicket'],
                '/agent/payment/status' => [AgentController::class, 'syncPaymentStatus'],
                '/agent/payment/custom-confirm' => [AgentController::class, 'confirmCustomPayment'],
                '/agent/commission/withdraw' => [AgentController::class, 'withdrawCommission'],
                '/admin/game/save' => [AdminController::class, 'saveGame'],
                '/admin/game/visibility' => [AdminController::class, 'saveGameVisibility'],
                '/admin/game/odds-main' => [AdminController::class, 'saveGameMainOdds'],
                '/admin/game/result' => [AdminController::class, 'saveGameResult'],
                '/admin/game/results' => [AdminController::class, 'saveGameResultsBulk'],
                '/admin/game/delete' => [AdminController::class, 'deleteGame'],
                '/admin/league/save' => [AdminController::class, 'saveLeague'],
                '/admin/league/delete' => [AdminController::class, 'deleteLeague'],
                '/admin/category/save' => [AdminController::class, 'saveCategory'],
                '/admin/category/delete' => [AdminController::class, 'deleteCategory'],
                '/admin/team/save' => [AdminController::class, 'saveTeam'],
                '/admin/team/delete' => [AdminController::class, 'deleteTeam'],
                '/admin/settings/save' => [AdminController::class, 'saveSettings'],
                '/admin/sync/settings' => [AdminController::class, 'saveSyncSettings'],
                '/admin/lock/global' => [AdminController::class, 'saveGlobalLock'],
                '/admin/lock/game' => [AdminController::class, 'saveManualGameLock'],
                '/admin/lock/window' => [AdminController::class, 'saveGameLockWindow'],
                '/admin/lock/clear-all' => [AdminController::class, 'clearAllLocks'],
                '/admin/games/sync' => [AdminController::class, 'syncGamesFromXscores'],
                '/admin/games/sync/xscore' => [AdminController::class, 'syncGamesFromXscores'],
                '/admin/games/sync/sofascore' => [AdminController::class, 'syncGamesFromSofascore'],
                '/admin/games/sync/flashscore' => [AdminController::class, 'syncGamesFromFlashscore'],
                '/admin/games/sync/betfair' => [AdminController::class, 'syncGamesFromBetfair'],
                '/admin/games/sync/oddapi' => [AdminController::class, 'syncGamesFromOddApi'],
                '/admin/cleanup/games' => [AdminController::class, 'cleanupGames'],
                '/admin/cleanup/maintenance' => [AdminController::class, 'cleanupMaintenance'],
                '/admin/manager/save' => [AdminController::class, 'saveManager'],
                '/admin/bookmaker/save' => [AdminController::class, 'saveBookmaker'],
                '/admin/agent/delete' => [AdminController::class, 'deleteAgent'],
                '/admin/agent/balance' => [AdminController::class, 'adjustAgentBalance'],
            ],
        ];

        $handler = $routes[$method][$route] ?? null;
        if ($handler === null) {
            http_response_code(404);
            echo 'Rota nao encontrada.';
            return;
        }

        [$controllerClass, $action] = $handler;
        $controller = new $controllerClass($this->config);
        $controller->{$action}();
    }

    private function resolveRoute(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $baseDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($baseDir !== '/' && $baseDir !== '.') {
            $path = '/' . ltrim((string) preg_replace('#^' . preg_quote($baseDir, '#') . '#', '', $path), '/');
        }

        if ($path === '/index.php') {
            $path = '/';
        } elseif (str_starts_with($path, '/index.php/')) {
            $path = '/' . ltrim(substr($path, strlen('/index.php/')), '/');
        }

        $path = rawurldecode((string) $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }
}
