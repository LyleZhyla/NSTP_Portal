<?php
session_start();

require_once 'include/logo-functions.php';
require_once 'include/nstp-component-content.php';

$componentKey = normalizeNstpComponentKey($_GET['component'] ?? 'CWTS') ?: 'CWTS';
$components = getNstpComponentDetails();
$component = $components[$componentKey];

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($component['name']); ?> Details - TAU NSTP</title>
    <?php echo getFaviconTags(); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ink: #14213d;
            --muted: #637083;
            --line: #d8e0ea;
            --wash: #f4f8fb;
            --blue: #198754;
            --teal: #16845f;
            --gold: #8a9a22;
            --crimson: #2f6f4e;
            --page-max: 1120px;
            --page-gutter: clamp(20px, 4vw, 48px);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: var(--ink);
            background: var(--wash);
            font-family: 'Plus Jakarta Sans', Arial, sans-serif;
            line-height: 1.6;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        img {
            display: block;
            max-width: 100%;
        }

        .site-header {
            position: fixed;
            inset: 0 0 auto;
            z-index: 20;
            background: rgba(255, 255, 255, 0.93);
            border-bottom: 1px solid rgba(216, 224, 234, 0.85);
            backdrop-filter: blur(14px);
        }

        .nav-shell {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            min-height: 72px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .brand,
        .nav-link,
        .component-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
        }

        .brand img {
            width: 44px;
            height: 44px;
            object-fit: contain;
        }

        .nav-link {
            min-height: 40px;
            padding: 0 14px;
            border-radius: 6px;
            background: var(--blue);
            color: #fff;
            font-size: 0.9rem;
        }

        .hero {
            min-height: 72vh;
            display: flex;
            align-items: flex-end;
            padding: 132px 0 64px;
            color: #fff;
            background:
                linear-gradient(90deg, rgba(5, 46, 22, 0.9), rgba(15, 81, 50, 0.62) 58%, rgba(21, 128, 61, 0.22)),
                url('<?php echo e($component['hero_image']); ?>') center/cover;
        }

        .hero-inner,
        .section-inner {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
        }

        .component-pill {
            min-height: 36px;
            padding: 0 12px;
            border: 1px solid rgba(255, 255, 255, 0.34);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 0.78rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            max-width: 860px;
            margin: 18px 0 16px;
            font-size: clamp(2.4rem, 6vw, 5.2rem);
            line-height: 1;
            letter-spacing: 0;
        }

        .hero p {
            max-width: 760px;
            margin: 0;
            color: rgba(255, 255, 255, 0.88);
            font-size: 1.08rem;
            font-weight: 600;
        }

        section {
            padding: 64px 0;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.95fr) minmax(300px, 0.55fr);
            gap: 24px;
            align-items: start;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 24px;
        }

        .panel h2,
        .panel h3 {
            margin: 0 0 12px;
            line-height: 1.15;
        }

        .panel p {
            margin: 0;
            color: #415269;
            font-weight: 600;
        }

        .highlight-list {
            display: grid;
            gap: 12px;
            margin: 18px 0 0;
            padding: 0;
            list-style: none;
        }

        .highlight-list li {
            display: flex;
            gap: 10px;
            color: #334155;
            font-weight: 700;
        }

        .highlight-list i {
            margin-top: 5px;
            color: var(--<?php echo e($component['accent']); ?>);
        }

        .component-switcher {
            display: grid;
            gap: 10px;
        }

        .switch-link {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--ink);
            font-weight: 850;
        }

        .switch-link.is-active {
            color: #fff;
            background: var(--<?php echo e($component['accent']); ?>);
            border-color: transparent;
        }

        .activity-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-top: 22px;
        }

        .activity-card {
            min-height: 330px;
            position: relative;
            overflow: hidden;
            border-radius: 8px;
            background: #1f2937;
            color: #fff;
        }

        .activity-card img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.28s ease;
        }

        .activity-card::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(10, 21, 38, 0.08), rgba(10, 21, 38, 0.84));
        }

        .activity-card:hover img,
        .activity-card:focus-within img {
            transform: scale(1.05);
        }

        .activity-copy {
            position: absolute;
            inset: auto 18px 18px 18px;
            z-index: 1;
        }

        .activity-copy span {
            display: inline-flex;
            margin-bottom: 8px;
            padding: 5px 9px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.18);
            font-size: 0.74rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .activity-copy strong {
            display: block;
            font-size: 1.24rem;
            line-height: 1.2;
        }

        .activity-copy p {
            max-height: 0;
            margin: 0;
            overflow: hidden;
            color: rgba(255, 255, 255, 0.86);
            font-size: 0.9rem;
            font-weight: 650;
            opacity: 0;
            transition: max-height 0.25s ease, margin-top 0.25s ease, opacity 0.25s ease;
        }

        .activity-card:hover .activity-copy p,
        .activity-card:focus-within .activity-copy p {
            max-height: 120px;
            margin-top: 9px;
            opacity: 1;
        }

        .footer {
            padding: 28px 0;
            background: #0f5132;
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.9rem;
            font-weight: 700;
        }

        @media (max-width: 860px) {
            .detail-grid,
            .activity-grid {
                grid-template-columns: 1fr;
            }

            .nav-shell {
                align-items: flex-start;
                flex-direction: column;
                padding: 14px 0;
            }

            .hero {
                min-height: 76vh;
                padding-top: 164px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <nav class="nav-shell" aria-label="Primary navigation">
            <a class="brand" href="landing_page.php">
                <img src="include/logo.png" alt="TAU NSTP logo">
                <span>TAU NSTP</span>
            </a>
            <a class="nav-link" href="landing_page.php#gallery-title">
                <i class="fas fa-arrow-left"></i> Back to activities
            </a>
        </nav>
    </header>

    <main>
        <section class="hero">
            <div class="hero-inner">
                <span class="component-pill"><?php echo e($component['name']); ?> Component</span>
                <h1><?php echo e($component['title']); ?></h1>
                <p><?php echo e($component['subtitle']); ?></p>
            </div>
        </section>

        <section>
            <div class="section-inner detail-grid">
                <article class="panel">
                    <h2>About <?php echo e($component['name']); ?></h2>
                    <p><?php echo e($component['summary']); ?></p>
                    <ul class="highlight-list">
                        <?php foreach ($component['highlights'] as $highlight): ?>
                            <li><i class="fas fa-check-circle"></i><span><?php echo e($highlight); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </article>

                <aside class="component-switcher" aria-label="Other NSTP components">
                    <?php foreach ($components as $key => $item): ?>
                        <a class="switch-link <?php echo $key === $componentKey ? 'is-active' : ''; ?>" href="component-detail.php?component=<?php echo e($key); ?>">
                            <span><?php echo e($item['name']); ?></span>
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    <?php endforeach; ?>
                </aside>
            </div>
        </section>

        <section>
            <div class="section-inner">
                <div class="panel">
                    <h3><?php echo e($component['name']); ?> Activity Images</h3>
                    <p>Hover or press an activity image to reveal the short details.</p>
                </div>
                <div class="activity-grid">
                    <?php foreach ($component['activities'] as $activity): ?>
                        <article class="activity-card" tabindex="0">
                            <img src="<?php echo e($activity['image']); ?>" alt="<?php echo e($activity['title']); ?>">
                            <div class="activity-copy">
                                <span><?php echo e($activity['label']); ?></span>
                                <strong><?php echo e($activity['title']); ?></strong>
                                <p><?php echo e($activity['detail']); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="section-inner">National Service Training Program</div>
    </footer>
</body>
</html>
