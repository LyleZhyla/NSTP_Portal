<?php
session_start();

require_once 'include/logo-functions.php';
require_once 'include/nstp-component-content.php';
require_once 'include/user-permissions.php';

$conn = null;
$currentUser = null;
$canEditComponent = false;
$componentMessage = '';
$componentMessageType = 'success';

try {
    require_once 'conn/conn.php';
    if (isset($conn)) {
        $currentUser = getCurrentUserRecord($conn);
        $canEditComponent = $currentUser && ($currentUser['role'] ?? '') === 'super_admin';

        if ($canEditComponent && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['component_content_action'])) {
            $componentData = applyNstpComponentImageUploads($_POST, $_FILES, __DIR__);
            saveNstpComponentDetails($conn, $componentData, $currentUser['user_id'] ?? null);
            $componentMessage = 'Component content saved.';
        }
    }
} catch (Throwable $error) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $componentMessage = $error->getMessage();
        $componentMessageType = 'error';
    }
}

$componentKey = normalizeNstpComponentKey($_GET['component'] ?? 'CWTS') ?: 'CWTS';
$components = getNstpComponentDetails($conn);
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
                url('<?php echo e(nstpComponentImageUrl($component['hero_image'], __DIR__)); ?>') center/cover;
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

        .admin-editor {
            background: #fff;
            border-top: 1px solid var(--line);
        }

        .admin-editor .section-inner {
            display: grid;
            gap: 18px;
        }

        .editor-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .editor-head h2 {
            margin: 0;
            line-height: 1.15;
        }

        .editor-form {
            display: grid;
            gap: 18px;
        }

        .editor-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .field {
            display: grid;
            gap: 7px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            color: #20324a;
            font-size: 0.84rem;
            font-weight: 850;
        }

        .field input,
        .field textarea,
        .field select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 11px 12px;
            color: var(--ink);
            font: inherit;
            font-weight: 650;
            background: #fff;
        }

        .field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .repeat-list {
            display: grid;
            gap: 12px;
        }

        .repeat-row,
        .activity-editor-row {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
            background: #f8fafc;
        }

        .activity-editor-row {
            display: grid;
            gap: 12px;
        }

        .activity-editor-fields {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 150px;
            gap: 12px;
        }

        .remove-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #7f1d1d;
            font-weight: 800;
        }

        .editor-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .save-button,
        .add-button {
            min-height: 42px;
            border: 0;
            border-radius: 6px;
            padding: 0 16px;
            color: #fff;
            background: var(--blue);
            font: inherit;
            font-weight: 850;
            cursor: pointer;
        }

        .add-button {
            background: #14213d;
        }

        .admin-alert {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            border-radius: 8px;
            padding: 12px 14px;
            color: #14532d;
            background: #dcfce7;
            font-weight: 800;
        }

        .admin-alert.error {
            color: #7f1d1d;
            background: #fee2e2;
        }

        @media (max-width: 860px) {
            .detail-grid,
            .activity-grid,
            .editor-grid,
            .activity-editor-fields {
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
                            <img src="<?php echo e(nstpComponentImageUrl($activity['image'], __DIR__)); ?>" alt="<?php echo e($activity['title']); ?>">
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

        <?php if ($canEditComponent): ?>
            <section class="admin-editor" id="component-editor">
                <div class="section-inner">
                    <div class="editor-head">
                        <div>
                            <span class="component-pill" style="background:#0f5132;color:#fff;">Super Admin</span>
                            <h2>Edit <?php echo e($component['name']); ?> Content</h2>
                        </div>
                        <?php if ($componentMessage): ?>
                            <div class="admin-alert <?php echo e($componentMessageType); ?>">
                                <i class="fas <?php echo $componentMessageType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                                <span><?php echo e($componentMessage); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form class="editor-form" method="post" enctype="multipart/form-data" action="component-detail.php?component=<?php echo e($componentKey); ?>#component-editor">
                        <input type="hidden" name="component_content_action" value="save">
                        <input type="hidden" name="component_key" value="<?php echo e($componentKey); ?>">

                        <div class="panel editor-grid">
                            <div class="field">
                                <label for="name">Component Label</label>
                                <input id="name" name="name" value="<?php echo e($component['name']); ?>" required>
                            </div>
                            <div class="field">
                                <label for="accent">Accent Color</label>
                                <select id="accent" name="accent">
                                    <?php foreach (['teal', 'gold', 'crimson', 'blue'] as $accent): ?>
                                        <option value="<?php echo e($accent); ?>" <?php echo ($component['accent'] ?? '') === $accent ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($accent)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field full">
                                <label for="title">Title</label>
                                <input id="title" name="title" value="<?php echo e($component['title']); ?>" required>
                            </div>
                            <div class="field full">
                                <label for="subtitle">Subtitle</label>
                                <input id="subtitle" name="subtitle" value="<?php echo e($component['subtitle']); ?>">
                            </div>
                            <div class="field full">
                                <label for="hero_image">Hero Image URL or Path</label>
                                <input id="hero_image" name="hero_image" value="<?php echo e($component['hero_image']); ?>">
                            </div>
                            <div class="field full">
                                <label for="hero_image_upload">Upload Hero Image</label>
                                <input id="hero_image_upload" name="hero_image_upload" type="file" accept="image/*">
                            </div>
                            <div class="field full">
                                <label for="summary">About / Summary</label>
                                <textarea id="summary" name="summary"><?php echo e($component['summary']); ?></textarea>
                            </div>
                            <div class="field full">
                                <label for="short_details">Short Details</label>
                                <textarea id="short_details" name="short_details"><?php echo e($component['short_details']); ?></textarea>
                            </div>
                        </div>

                        <div class="panel">
                            <h3>Highlights</h3>
                            <div class="repeat-list" id="highlightList">
                                <?php foreach (array_values($component['highlights']) as $index => $highlight): ?>
                                    <div class="repeat-row field">
                                        <label for="highlight_<?php echo $index; ?>">Highlight <?php echo $index + 1; ?></label>
                                        <input id="highlight_<?php echo $index; ?>" name="highlights[]" value="<?php echo e($highlight); ?>">
                                    </div>
                                <?php endforeach; ?>
                                <div class="repeat-row field">
                                    <label for="highlight_new">Add Highlight</label>
                                    <input id="highlight_new" name="highlights[]" value="" placeholder="New highlight">
                                </div>
                            </div>
                        </div>

                        <div class="panel">
                            <h3>Activity Images</h3>
                            <div class="repeat-list" id="activityList">
                                <?php foreach (array_values($component['activities']) as $index => $activity): ?>
                                    <div class="activity-editor-row">
                                        <div class="activity-editor-fields">
                                            <div class="field">
                                                <label for="activity_title_<?php echo $index; ?>">Activity Title</label>
                                                <input id="activity_title_<?php echo $index; ?>" name="activity_title[]" value="<?php echo e($activity['title'] ?? ''); ?>">
                                            </div>
                                            <div class="field">
                                                <label for="activity_label_<?php echo $index; ?>">Label</label>
                                                <input id="activity_label_<?php echo $index; ?>" name="activity_label[]" value="<?php echo e($activity['label'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label for="activity_detail_<?php echo $index; ?>">Details</label>
                                            <textarea id="activity_detail_<?php echo $index; ?>" name="activity_detail[]"><?php echo e($activity['detail'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="field">
                                            <label for="activity_image_<?php echo $index; ?>">Image URL or Path</label>
                                            <input id="activity_image_<?php echo $index; ?>" name="activity_image[]" value="<?php echo e($activity['image'] ?? ''); ?>">
                                        </div>
                                        <div class="field">
                                            <label for="activity_image_upload_<?php echo $index; ?>">Upload Activity Image</label>
                                            <input id="activity_image_upload_<?php echo $index; ?>" name="activity_image_upload[]" type="file" accept="image/*">
                                        </div>
                                        <label class="remove-check">
                                            <input type="checkbox" name="activity_remove[<?php echo $index; ?>]" value="1">
                                            Remove this activity
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <?php $newIndex = count($component['activities']); ?>
                                <div class="activity-editor-row">
                                    <div class="activity-editor-fields">
                                        <div class="field">
                                            <label for="activity_title_<?php echo $newIndex; ?>">Add Activity Title</label>
                                            <input id="activity_title_<?php echo $newIndex; ?>" name="activity_title[]" value="" placeholder="New activity">
                                        </div>
                                        <div class="field">
                                            <label for="activity_label_<?php echo $newIndex; ?>">Label</label>
                                            <input id="activity_label_<?php echo $newIndex; ?>" name="activity_label[]" value="">
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label for="activity_detail_<?php echo $newIndex; ?>">Details</label>
                                        <textarea id="activity_detail_<?php echo $newIndex; ?>" name="activity_detail[]"></textarea>
                                    </div>
                                    <div class="field">
                                        <label for="activity_image_<?php echo $newIndex; ?>">Image URL or Path</label>
                                        <input id="activity_image_<?php echo $newIndex; ?>" name="activity_image[]" value="">
                                    </div>
                                    <div class="field">
                                        <label for="activity_image_upload_<?php echo $newIndex; ?>">Upload Activity Image</label>
                                        <input id="activity_image_upload_<?php echo $newIndex; ?>" name="activity_image_upload[]" type="file" accept="image/*">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="editor-actions">
                            <button type="button" class="add-button" id="addActivityRow">
                                <i class="fas fa-plus"></i> Add another activity
                            </button>
                            <button type="submit" class="save-button">
                                <i class="fas fa-save"></i> Save Content
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="section-inner">National Service Training Program</div>
    </footer>
    <?php if ($canEditComponent): ?>
        <script>
            (function () {
                const activityList = document.getElementById('activityList');
                const addActivityRow = document.getElementById('addActivityRow');
                let nextActivityIndex = <?php echo (int) count($component['activities']) + 1; ?>;

                if (!activityList || !addActivityRow) {
                    return;
                }

                addActivityRow.addEventListener('click', function () {
                    const row = document.createElement('div');
                    row.className = 'activity-editor-row';
                    row.innerHTML = `
                        <div class="activity-editor-fields">
                            <div class="field">
                                <label for="activity_title_dynamic_${nextActivityIndex}">Activity Title</label>
                                <input id="activity_title_dynamic_${nextActivityIndex}" name="activity_title[]" value="" placeholder="New activity">
                            </div>
                            <div class="field">
                                <label for="activity_label_dynamic_${nextActivityIndex}">Label</label>
                                <input id="activity_label_dynamic_${nextActivityIndex}" name="activity_label[]" value="">
                            </div>
                        </div>
                        <div class="field">
                            <label for="activity_detail_dynamic_${nextActivityIndex}">Details</label>
                            <textarea id="activity_detail_dynamic_${nextActivityIndex}" name="activity_detail[]"></textarea>
                        </div>
                        <div class="field">
                            <label for="activity_image_dynamic_${nextActivityIndex}">Image URL or Path</label>
                            <input id="activity_image_dynamic_${nextActivityIndex}" name="activity_image[]" value="">
                        </div>
                        <div class="field">
                            <label for="activity_image_upload_dynamic_${nextActivityIndex}">Upload Activity Image</label>
                            <input id="activity_image_upload_dynamic_${nextActivityIndex}" name="activity_image_upload[]" type="file" accept="image/*">
                        </div>
                    `;
                    activityList.appendChild(row);
                    nextActivityIndex += 1;
                });
            })();
        </script>
    <?php endif; ?>
</body>
</html>
