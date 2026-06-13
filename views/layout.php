<!DOCTYPE html>
<html lang="<?= \App\Translator::getLang() ?>" dir="<?= \App\Translator::isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
    <title><?= htmlspecialchars($title ?? 'Chat') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script>
        (function () {
            var saved = localStorage.getItem('theme')
            var modes = ['auto', 'light', 'dark']

            function getEffective(mode) {
                if (mode === 'light' || mode === 'dark') return mode
                return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            }

            function apply(mode) {
                var eff = getEffective(mode)
                if (mode === 'auto') {
                    delete document.documentElement.dataset.theme
                } else {
                    document.documentElement.dataset.theme = mode
                }
                var metas = document.querySelectorAll('meta[name="theme-color"]')
                var color = eff === 'dark' ? '#000000' : '#ffffff'
                metas.forEach(function (m) {
                    m.setAttribute('content', color)
                })
                var icons = {auto: 'monitor', light: 'sun', dark: 'moon'}
                document.querySelectorAll('.theme-toggle use').forEach(function (u) {
                    u.setAttribute('href', '#icon-' + icons[mode])
                })
            }

            window.cycleTheme = function () {
                var cur = localStorage.getItem('theme') || 'auto'
                var next = modes[(modes.indexOf(cur) + 1) % modes.length]
                if (next === 'auto') {
                    localStorage.removeItem('theme')
                } else {
                    localStorage.setItem('theme', next)
                }
                apply(next)
            }

            matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                apply(localStorage.getItem('theme') || 'auto')
            })

            apply(saved || 'auto')
        })()
    </script>
</head>
<body>
    <?php require __DIR__ . "/$view.php"; ?>
    <?php require __DIR__ . "/icons.svg.php"; ?>
</body>
</html>
