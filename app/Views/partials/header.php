<?php
/** @var array|null $user */
/** @var string $theme */
/** @var array|null $flash */
$appCssVersion = @filemtime(__DIR__ . '/../../../public/assets/css/app.css') ?: time();
$resolvedTheme = in_array((string) ($theme ?? 'classic'), ['classic', 'neo', 'sportsbook'], true)
    ? (string) ($theme ?? 'classic')
    : 'classic';
$browserThemeColor = match ($resolvedTheme) {
    'neo' => '#0f1624',
    'sportsbook' => '#0f6a43',
    default => '#b11616',
};
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= htmlspecialchars($resolvedTheme, ENT_QUOTES) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['name'], ENT_QUOTES) ?></title>
    <meta name="theme-color" content="<?= htmlspecialchars($browserThemeColor, ENT_QUOTES) ?>">
    <link rel="manifest" href="<?= htmlspecialchars(app_url('manifest.webmanifest'), ENT_QUOTES) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(app_url('public/assets/img/logo.png'), ENT_QUOTES) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('public/assets/css/app.css?v=' . $appCssVersion), ENT_QUOTES) ?>">
    <script>
        window.APP_BASE_URL = <?= json_encode(rtrim(app_url('/'), '/')) ?>;
    </script>
</head>
<body>
<?php if ($flash !== null): ?>
    <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES) ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES) ?></div>
<?php endif; ?>
