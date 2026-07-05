<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Sanitize::e($pageTitle ?? 'TeamChat') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/public/css/style.css" rel="stylesheet">
    <meta name="csrf-token" content="<?= Csrf::token() ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <meta name="user-id" content="<?= Session::userId() ?>">
    <meta name="poll-interval" content="3000">
</head>
<body class="chat-app">
    <?php require BASE_PATH . '/app/views/' . $chatTemplate . '.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>/public/js/app.js"></script>
    <script src="<?= BASE_URL ?>/public/js/chat.js"></script>
    <?php if (!empty($extraJs)): ?>
        <?php foreach ($extraJs as $js): ?>
            <script src="<?= BASE_URL ?>/public/js/<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
