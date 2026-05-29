<?php
session_start();
require_once 'include/logo-functions.php';
require_once 'include/landing-content.php';
require_once 'include/user-permissions.php';

$isLoggedIn = isset($_SESSION['user_id']);
$canEditLanding = isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'coordinator'], true);
$currentLandingUser = null;
$landingMessage = '';
$landingMessageType = 'success';

$staffMembers = [];
$landingSections = getDefaultLandingSections();

try {
    require_once 'conn/conn.php';

    if (isset($conn)) {
        $currentLandingUser = getCurrentUserRecord($conn);
        $canEditLanding = $currentLandingUser && canAccessAdminManagement($currentLandingUser['role']);
        $landingSections = getLandingSections($conn);

        if ($canEditLanding && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['landing_section_action'])) {
            $sectionKey = $_POST['section_key'] ?? '';
            $existingSection = $landingSections[$sectionKey] ?? [];
            saveLandingSection($conn, [
                'section_key' => $sectionKey,
                'kicker' => $_POST['section_kicker'] ?? '',
                'title' => $_POST['section_title'] ?? '',
                'body' => $_POST['section_body'] ?? '',
                'payload' => json_encode(buildLandingSectionPayload($sectionKey, $_POST, $_FILES, $existingSection, __DIR__), JSON_PRETTY_PRINT),
            ], $currentLandingUser['user_id'] ?? null);
            $landingMessage = 'Landing section updated.';
        } elseif ($canEditLanding && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['landing_staff_action'])) {
            $action = $_POST['landing_staff_action'];

            if ($action === 'delete') {
                deleteLandingStaffEntry($conn, $_POST['landing_staff_id'] ?? 0);
                $landingMessage = 'Landing entry deleted.';
            } elseif (in_array($action, ['add', 'update'], true)) {
                $photoPath = cleanLandingContentText($_POST['photo_path'] ?? '', 255);
                $uploadedPhoto = uploadLandingStaffPhoto('photo_upload', __DIR__);

                if ($uploadedPhoto) {
                    $photoPath = $uploadedPhoto;
                }

                saveLandingStaffEntry($conn, [
                    'landing_staff_id' => $action === 'update' ? ($_POST['landing_staff_id'] ?? 0) : 0,
                    'full_name' => $_POST['full_name'] ?? '',
                    'position_title' => $_POST['position_title'] ?? '',
                    'program' => $_POST['program'] ?? 'NSTP',
                    'group_label' => $_POST['group_label'] ?? 'NSTP Office',
                    'photo_path' => $photoPath,
                    'sort_order' => $_POST['sort_order'] ?? 0,
                    'is_visible' => isset($_POST['is_visible']) ? 1 : 0,
                ], $currentLandingUser['user_id'] ?? null);

                $landingMessage = $action === 'add' ? 'Landing entry added.' : 'Landing entry updated.';
            }
        }

        $landingSections = getLandingSections($conn);
        $staffMembers = getLandingStaff($conn, !$canEditLanding);
    }
} catch (Throwable $error) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEditLanding) {
        $landingMessage = $error->getMessage();
        $landingMessageType = 'error';
        try {
            $landingSections = isset($conn) ? getLandingSections($conn) : getDefaultLandingSections();
            $staffMembers = isset($conn) ? getLandingStaff($conn, false) : [];
        } catch (Throwable $ignored) {
            $staffMembers = [];
        }
    } else {
        $staffMembers = [];
    }
}

if (!$staffMembers) {
    $staffMembers = getDefaultLandingStaff();
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function initials($name) {
    $words = preg_split('/\s+/', trim((string) $name));
    $letters = '';

    foreach ($words as $word) {
        if ($word !== '') {
            $letters .= strtoupper(substr($word, 0, 1));
        }
        if (strlen($letters) >= 2) {
            break;
        }
    }

    return $letters ?: 'NS';
}

function staffProgram($staff) {
    if (!empty($staff['program'])) {
        return normalizeLandingProgram($staff['program']);
    }

    $text = strtoupper(($staff['full_name'] ?? '') . ' ' . ($staff['position_title'] ?? '') . ' ' . ($staff['group_label'] ?? ''));

    if (strpos($text, 'CWTS') !== false) {
        return 'CWTS';
    }

    if (strpos($text, 'LTS') !== false) {
        return 'LTS';
    }

    if (strpos($text, 'ROTC') !== false || strpos($text, 'ALPHA') !== false) {
        return 'ROTC';
    }

    if (strpos($text, 'DRRM') !== false) {
        return 'DRRM';
    }

    return 'NSTP';
}

function findOrgEntryByOrder($entries, $sortOrder) {
    foreach ($entries as $entry) {
        if ((int) ($entry['sort_order'] ?? 0) === (int) $sortOrder) {
            return $entry;
        }
    }

    return null;
}

function renderOrgNode($entry, $canEditLanding, $extraClass = '') {
    if (!$entry) {
        return;
    }

    $photo = $entry['photo_path'] ?? '';
    $hasPhoto = $photo && file_exists($photo);
    $program = staffProgram($entry);
    $groupLabel = $entry['group_label'] ?: 'NSTP Office';
    $positionTitle = $entry['position_title'] ?: 'NSTP Staff';
    $isVisible = !isset($entry['is_visible']) || (int) $entry['is_visible'] === 1;
    ?>
    <article class="org-node <?php echo e(trim($extraClass . ' ' . ($isVisible ? '' : 'is-hidden'))); ?>">
        <?php if ($canEditLanding): ?>
            <button
                type="button"
                class="inline-edit-btn"
                title="Edit entry"
                data-entry-id="<?php echo e($entry['landing_staff_id'] ?? ''); ?>"
                data-full-name="<?php echo e($entry['full_name']); ?>"
                data-position-title="<?php echo e($positionTitle); ?>"
                data-program="<?php echo e($entry['program'] ?? $program); ?>"
                data-group-label="<?php echo e($groupLabel); ?>"
                data-photo-path="<?php echo e($photo); ?>"
                data-sort-order="<?php echo e($entry['sort_order'] ?? 0); ?>"
                data-is-visible="<?php echo $isVisible ? '1' : '0'; ?>"
            >
                <i class="fas fa-pen"></i>
            </button>
        <?php endif; ?>
        <?php if ($hasPhoto): ?>
            <img class="org-photo" src="<?php echo e($photo); ?>" alt="<?php echo e($entry['full_name']); ?>">
        <?php else: ?>
            <div class="org-initials" aria-hidden="true"><?php echo e(initials($entry['full_name'])); ?></div>
        <?php endif; ?>
        <div class="org-text">
            <h3><?php echo e($entry['full_name']); ?></h3>
            <p><?php echo e($positionTitle); ?></p>
        </div>
        <?php if ($canEditLanding && !$isVisible): ?>
            <span class="pill"><i class="fas fa-eye-slash"></i> Hidden</span>
        <?php endif; ?>
    </article>
    <?php
}

function sectionPayloadForEdit($section) {
    return isset($section['payload']) ? json_encode($section['payload'], JSON_PRETTY_PRINT) : '';
}

function uploadLandingSectionImage($file, $index, $baseDir = '') {
    if (empty($file['name'][$index]) || ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'][$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mimeType = mime_content_type($file['tmp_name'][$index]);
    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Image must be JPG, PNG, or WebP.');
    }

    if (($file['size'][$index] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('Image must be 4MB or smaller.');
    }

    $relativeDir = 'uploads/landing_sections/';
    $uploadDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $relativeDir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = uniqid('section_', true) . '.' . $allowedTypes[$mimeType];
    $targetPath = $uploadDir . $fileName;
    if (!move_uploaded_file($file['tmp_name'][$index], $targetPath)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return $relativeDir . $fileName;
}

function arrayTextValue($array, $index, $fallback = '', $maxLength = 255) {
    return cleanLandingContentText($array[$index] ?? $fallback, $maxLength);
}

function buildLandingSectionPayload($sectionKey, array $post, array $files, array $existingSection, $baseDir) {
    $payload = is_array($existingSection['payload'] ?? null) ? $existingSection['payload'] : [];

    if ($sectionKey === 'hero') {
        return [
            'primary_label' => cleanLandingContentText($post['hero_primary_label'] ?? 'View Programs', 80),
            'secondary_label' => cleanLandingContentText($post['hero_secondary_label'] ?? 'Meet Staff', 80),
        ];
    }

    if ($sectionKey === 'quick_guide') {
        $items = [];
        foreach (($post['quick_name'] ?? []) as $index => $name) {
            $items[] = [
                'name' => arrayTextValue($post['quick_name'] ?? [], $index, '', 30),
                'description' => arrayTextValue($post['quick_description'] ?? [], $index, '', 180),
            ];
        }
        return $items;
    }

    if ($sectionKey === 'programs') {
        $items = [];
        foreach (($post['program_name'] ?? []) as $index => $name) {
            $image = arrayTextValue($post['program_image_existing'] ?? [], $index, $payload[$index]['image'] ?? '', 255);
            if (!empty($files['program_image_upload'])) {
                $uploadedImage = uploadLandingSectionImage($files['program_image_upload'], $index, $baseDir);
                if ($uploadedImage) {
                    $image = $uploadedImage;
                }
            }

            $items[] = [
                'name' => arrayTextValue($post['program_name'] ?? [], $index, '', 30),
                'title' => arrayTextValue($post['program_title'] ?? [], $index, '', 150),
                'accent' => arrayTextValue($post['program_accent'] ?? [], $index, 'teal', 30),
                'image' => $image,
                'focus' => trim((string) (($post['program_focus'] ?? [])[$index] ?? '')),
                'best_for' => trim((string) (($post['program_best_for'] ?? [])[$index] ?? '')),
                'output' => trim((string) (($post['program_output'] ?? [])[$index] ?? '')),
            ];
        }
        return $items;
    }

    if ($sectionKey === 'difference') {
        $items = [];
        foreach (($post['difference_component'] ?? []) as $index => $component) {
            $items[] = [
                'component' => arrayTextValue($post['difference_component'] ?? [], $index, '', 30),
                'focus' => trim((string) (($post['difference_focus'] ?? [])[$index] ?? '')),
                'style' => trim((string) (($post['difference_style'] ?? [])[$index] ?? '')),
                'experience' => trim((string) (($post['difference_experience'] ?? [])[$index] ?? '')),
            ];
        }
        return $items;
    }

    if ($sectionKey === 'activities') {
        $items = [];
        foreach (($post['activity_title'] ?? []) as $index => $title) {
            $image = arrayTextValue($post['activity_image_existing'] ?? [], $index, $payload[$index]['image'] ?? '', 255);
            if (!empty($files['activity_image_upload'])) {
                $uploadedImage = uploadLandingSectionImage($files['activity_image_upload'], $index, $baseDir);
                if ($uploadedImage) {
                    $image = $uploadedImage;
                }
            }

            $items[] = [
                'title' => arrayTextValue($post['activity_title'] ?? [], $index, '', 120),
                'label' => arrayTextValue($post['activity_label'] ?? [], $index, '', 80),
                'image' => $image,
            ];
        }
        return $items;
    }

    if ($sectionKey === 'cta') {
        return [
            'guest_label' => cleanLandingContentText($post['cta_guest_label'] ?? 'Open QR Attendance System', 100),
            'logged_in_label' => cleanLandingContentText($post['cta_logged_in_label'] ?? 'Open Dashboard', 100),
        ];
    }

    return $payload;
}

function renderSectionEditButton($sectionKey, $section, $canEditLanding) {
    if (!$canEditLanding) {
        return;
    }
    ?>
    <button
        type="button"
        class="section-edit-btn"
        title="Edit section"
        data-section-key="<?php echo e($sectionKey); ?>"
        data-section-kicker="<?php echo e($section['kicker'] ?? ''); ?>"
        data-section-title="<?php echo e($section['title'] ?? ''); ?>"
        data-section-body="<?php echo e($section['body'] ?? ''); ?>"
        data-section-payload="<?php echo e(sectionPayloadForEdit($section)); ?>"
    >
        <i class="fas fa-pen"></i>
    </button>
    <?php
}

$componentLogos = [
    'NSTP' => 'include/logos/nstp.png',
    'CWTS' => 'include/logos/cwts.png',
    'LTS' => 'include/logos/lts.png',
    'ROTC' => 'include/logos/rotc.png',
];

$programDefaultImages = [
    'CWTS' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=900&q=80',
    'LTS' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=900&q=80',
    'ROTC' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&q=80',
    'NSTP' => 'https://images.unsplash.com/photo-1523580846011-d3a5bc25702b?auto=format&fit=crop&w=900&q=80',
];

$programs = [
    [
        'name' => 'CWTS',
        'title' => 'Civic Welfare Training Service',
        'image' => $programDefaultImages['CWTS'],
        'accent' => 'teal',
        'focus' => 'Community service, social responsibility, disaster preparedness, health, environment, and local development projects.',
        'best_for' => 'Students who want hands-on outreach and civic action.',
        'output' => 'Service projects, community immersion, advocacy work, and volunteer leadership.',
    ],
    [
        'name' => 'LTS',
        'title' => 'Literacy Training Service',
        'image' => $programDefaultImages['LTS'],
        'accent' => 'gold',
        'focus' => 'Teaching support, reading programs, numeracy, tutoring, learning materials, and youth education.',
        'best_for' => 'Students who enjoy mentoring learners and helping improve basic education.',
        'output' => 'Tutorial sessions, learning modules, reading drives, and classroom support activities.',
    ],
    [
        'name' => 'ROTC',
        'title' => 'Reserve Officers Training Corps',
        'image' => $programDefaultImages['ROTC'],
        'accent' => 'crimson',
        'focus' => 'Leadership, discipline, citizenship, emergency response, basic military orientation, and national defense awareness.',
        'best_for' => 'Students who prefer structured training, command responsibility, and physical formation.',
        'output' => 'Drills, leadership exercises, preparedness training, and civic defense participation.',
    ],
];

$gallery = [
    [
        'title' => 'Community Work',
        'label' => 'Service',
        'image' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=800&q=80',
    ],
    [
        'title' => 'Learning Support',
        'label' => 'Education',
        'image' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=800&q=80',
    ],
    [
        'title' => 'Formation',
        'label' => 'Leadership',
        'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=800&q=80',
    ],
];

if (!empty($landingSections['programs']['payload']) && is_array($landingSections['programs']['payload'])) {
    $programs = array_map(function ($program) use ($programDefaultImages, $componentLogos) {
        $name = strtoupper((string) ($program['name'] ?? 'NSTP'));
        if (empty($program['image']) || in_array($program['image'], $componentLogos, true)) {
            $program['image'] = $programDefaultImages[$name] ?? $programDefaultImages['NSTP'];
        }
        $program['accent'] = $program['accent'] ?? 'teal';
        return $program;
    }, $landingSections['programs']['payload']);
    $landingSections['programs']['payload'] = $programs;
}

if (!empty($landingSections['activities']['payload']) && is_array($landingSections['activities']['payload'])) {
    $gallery = $landingSections['activities']['payload'];
}

$heroPayload = $landingSections['hero']['payload'] ?? [];
$quickGuideItems = $landingSections['quick_guide']['payload'] ?? [];
$differenceRows = $landingSections['difference']['payload'] ?? [];
$ctaPayload = $landingSections['cta']['payload'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TAU NSTP Programs</title>
    <?php echo getFaviconTags(); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ink: #14213d;
            --muted: #637083;
            --line: #d8e0ea;
            --paper: #ffffff;
            --wash: #f4f8fb;
            --teal: #167a7f;
            --green: #2f855a;
            --gold: #b7791f;
            --crimson: #b8323b;
            --blue: #2b6cb0;
            --page-max: 1180px;
            --page-gutter: clamp(20px, 4vw, 48px);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

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
            top: 0;
            left: 0;
            right: 0;
            z-index: 20;
            background: rgba(255, 255, 255, 0.93);
            border-bottom: 1px solid rgba(216, 224, 234, 0.85);
            backdrop-filter: blur(14px);
        }

        .nav-shell {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand img {
            width: 44px;
            height: 44px;
            object-fit: contain;
        }

        .brand strong {
            display: block;
            font-size: 0.98rem;
            letter-spacing: 0;
        }

        .brand span {
            display: block;
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 600;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 20px;
            color: #334155;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .nav-links a {
            white-space: nowrap;
        }

        .login-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 16px;
            border-radius: 6px;
            background: var(--ink);
            color: #fff;
        }

        .hero {
            min-height: 92vh;
            display: flex;
            align-items: flex-end;
            color: #fff;
            background:
                linear-gradient(90deg, rgba(10, 21, 38, 0.9), rgba(10, 21, 38, 0.58) 52%, rgba(10, 21, 38, 0.25)),
                url('https://images.unsplash.com/photo-1523580846011-d3a5bc25702b?auto=format&fit=crop&w=1800&q=80') center/cover;
            padding: 132px 0 72px;
        }

        .hero-inner {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(320px, 0.55fr);
            align-items: end;
            gap: 42px;
        }

        .hero-inner > div,
        .cta-inner > div {
            position: relative;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border: 1px solid rgba(255, 255, 255, 0.34);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 22px 0 18px;
            max-width: 860px;
            font-size: clamp(2.45rem, 6vw, 5.75rem);
            line-height: 0.98;
            letter-spacing: 0;
        }

        .hero-copy {
            max-width: 740px;
            margin: 0 0 30px;
            color: rgba(255, 255, 255, 0.88);
            font-size: 1.08rem;
            font-weight: 500;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 18px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 6px;
            font-weight: 800;
        }

        .btn-primary {
            background: #fff;
            color: var(--ink);
        }

        .btn-ghost {
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
        }

        .quick-panel {
            position: relative;
            background: rgba(255, 255, 255, 0.94);
            color: var(--ink);
            border-radius: 8px;
            padding: 22px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.28);
        }

        .quick-panel h2 {
            margin: 0 0 12px;
            font-size: 1.1rem;
        }

        .quick-item {
            display: grid;
            grid-template-columns: 46px 1fr;
            gap: 12px;
            padding: 14px 0;
            border-top: 1px solid var(--line);
        }

        .quick-item:first-of-type {
            border-top: 0;
        }

        .quick-icon {
            width: 46px;
            height: 46px;
            display: block;
            border-radius: 6px;
            object-fit: contain;
            padding: 5px;
            background: #fff;
            border: 1px solid var(--line);
        }

        .quick-item strong {
            display: block;
            line-height: 1.25;
        }

        .quick-item span {
            display: block;
            color: var(--muted);
            font-size: 0.86rem;
            font-weight: 600;
        }

        section {
            padding: 72px 0;
        }

        .section-inner {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
        }

        .section-head {
            position: relative;
            max-width: 780px;
            margin-bottom: 30px;
        }

        .section-kicker {
            color: var(--blue);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .section-head h2 {
            margin: 8px 0 10px;
            font-size: clamp(1.9rem, 4vw, 3.2rem);
            line-height: 1.05;
            letter-spacing: 0;
        }

        .section-head p {
            margin: 0;
            color: var(--muted);
            font-weight: 600;
        }

        .program-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .program-card {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--paper);
            box-shadow: 0 12px 32px rgba(20, 33, 61, 0.08);
        }

        .program-media {
            height: 170px;
            position: relative;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at 50% 45%, rgba(255, 255, 255, 0.95), rgba(232, 244, 242, 0.78) 52%, rgba(20, 33, 61, 0.08));
            padding: 22px;
        }

        .program-media img {
            width: min(72%, 155px);
            height: 130px;
            object-fit: contain;
            filter: drop-shadow(0 12px 18px rgba(20, 33, 61, 0.16));
        }

        .program-badge {
            position: absolute;
            left: 16px;
            bottom: 16px;
            padding: 8px 12px;
            border-radius: 6px;
            color: #fff;
            font-size: 0.86rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        .program-badge.teal { background: var(--teal); }
        .program-badge.gold { background: var(--gold); }
        .program-badge.crimson { background: var(--crimson); }

        .program-body {
            padding: 22px;
        }

        .program-body h3 {
            margin: 0 0 10px;
            font-size: 1.18rem;
        }

        .program-body p {
            margin: 0 0 16px;
            color: #415269;
            font-size: 0.94rem;
        }

        .fact-row {
            padding-top: 13px;
            margin-top: 13px;
            border-top: 1px solid var(--line);
        }

        .fact-row span {
            display: block;
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 900;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .fact-row strong {
            display: block;
            color: var(--ink);
            font-size: 0.9rem;
            line-height: 1.45;
        }

        .compare-band {
            background: #0f1f35;
            color: #fff;
        }

        .compare-band .section-kicker,
        .compare-band .section-head p {
            color: rgba(255, 255, 255, 0.72);
        }

        .compare-table {
            overflow-x: auto;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 8px;
        }

        table {
            width: 100%;
            min-width: 780px;
            border-collapse: collapse;
            background: rgba(255, 255, 255, 0.04);
        }

        th,
        td {
            padding: 18px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        th {
            color: #fff;
            font-size: 0.84rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.07);
        }

        td {
            color: rgba(255, 255, 255, 0.82);
            font-size: 0.94rem;
        }

        td strong {
            color: #fff;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr 1fr;
            gap: 18px;
        }

        .gallery-tile {
            min-height: 310px;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            background: #1f2937;
        }

        .gallery-tile:nth-child(2) {
            min-height: 380px;
        }

        .gallery-tile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            inset: 0;
        }

        .gallery-caption {
            position: absolute;
            inset: auto 18px 18px 18px;
            color: #fff;
            z-index: 1;
        }

        .gallery-tile::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(10, 21, 38, 0.05), rgba(10, 21, 38, 0.8));
        }

        .gallery-caption span {
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

        .gallery-caption strong {
            display: block;
            font-size: 1.3rem;
        }

        .staff-section {
            background: #fff;
        }

        .org-chart {
            --org-card-width: 280px;
            --org-gap: 16px;
            --org-level-width: 1168px;
            --line-color: #1d2f3f;
            --line-size: 2px;
            position: relative;
            overflow-x: hidden;
            margin: 0;
            padding: 10px 0 20px;
        }

        .org-level {
            position: relative;
            display: flex;
            justify-content: center;
            gap: var(--org-gap);
            min-width: var(--org-level-width);
        }

        .org-level + .org-level {
            margin-top: 34px;
        }

        .director-row + .org-level {
            margin-top: 104px;
        }

        .org-node {
            position: relative;
            z-index: 2;
            width: var(--org-card-width);
            min-height: 96px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 38px 14px 14px;
            border: 2px solid var(--line-color);
            border-radius: 4px;
            background: #fff;
            text-align: left;
            box-shadow: 0 10px 26px rgba(20, 33, 61, 0.08);
        }

        .org-node.is-hidden {
            opacity: 0.68;
            border-style: dashed;
        }

        .org-node.compact {
            width: var(--org-card-width);
            min-height: 96px;
        }

        .org-text {
            flex: 1;
            min-width: 0;
        }

        .org-node h3 {
            margin: 0 0 5px;
            max-width: 100%;
            font-size: 0.82rem;
            line-height: 1.18;
            white-space: nowrap;
        }

        .org-node p {
            margin: 0;
            max-width: 100%;
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 800;
            line-height: 1.2;
            display: -webkit-box;
            overflow: hidden;
            white-space: normal;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .org-photo,
        .org-initials {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            flex: 0 0 auto;
            margin-bottom: 0;
        }

        .org-photo {
            object-fit: cover;
        }

        .org-initials {
            display: grid;
            place-items: center;
            color: #fff;
            background: linear-gradient(135deg, var(--blue), var(--teal));
            font-weight: 900;
            font-size: 0.8rem;
        }

        .org-vertical::before {
            content: "";
            position: absolute;
            left: 50%;
            top: -34px;
            width: var(--line-size);
            height: 34px;
            background: var(--line-color);
            transform: translateX(-50%);
        }

        .director-row {
            align-items: center;
            min-height: 96px;
        }

        .director-row::after {
            content: "";
            position: absolute;
            top: calc(100% + 52px);
            left: 50%;
            width: 220px;
            height: var(--line-size);
            background: var(--line-color);
            transform: translateY(-50%);
        }

        .director-row .side-node {
            position: absolute;
            left: calc(50% + 336px);
            top: calc(100% + 52px);
            margin-left: 0;
            transform: translate(-50%, -50%);
        }

        .director-row + .org-level .org-vertical::before {
            top: -104px;
            height: 104px;
        }

        .bottom-row {
            justify-content: center;
            gap: var(--org-gap);
            padding: 28px 0 0;
        }

        .bottom-row::before {
            content: "";
            position: absolute;
            top: 0;
            left: calc((100% - ((var(--org-card-width) * 4) + (var(--org-gap) * 3))) / 2 + (var(--org-card-width) / 2));
            width: calc((var(--org-card-width) * 3) + (var(--org-gap) * 3));
            right: auto;
            height: var(--line-size);
            background: var(--line-color);
        }

        .bottom-row::after {
            content: "";
            position: absolute;
            left: 50%;
            top: -34px;
            width: var(--line-size);
            height: 34px;
            background: var(--line-color);
            transform: translateX(-50%);
        }

        .bottom-row .org-node::before {
            content: "";
            position: absolute;
            left: 50%;
            top: -30px;
            width: var(--line-size);
            height: 30px;
            background: var(--line-color);
            transform: translateX(-50%);
        }

        .section-tools {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
        }

        .edit-chip,
        .inline-edit-btn,
        .section-edit-btn,
        .modal-action {
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            font: inherit;
            font-weight: 800;
        }

        .edit-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 14px;
            background: var(--ink);
            color: #fff;
        }

        .inline-edit-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            background: #eef5f8;
            color: var(--ink);
        }

        .section-edit-btn {
            position: absolute;
            top: 0;
            right: -52px;
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            background: var(--ink);
            color: #fff;
        }

        .landing-alert {
            margin: 0 0 22px;
            padding: 12px 14px;
            border-radius: 8px;
            font-weight: 800;
        }

        .landing-alert.success {
            background: #e7f7ef;
            color: #146c43;
        }

        .landing-alert.error {
            background: #fde8e8;
            color: #a12626;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 31, 53, 0.64);
        }

        .modal-backdrop.is-open {
            display: flex;
        }

        .landing-modal {
            width: min(720px, 100%);
            max-height: min(760px, calc(100vh - 40px));
            overflow: auto;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 28px 90px rgba(0, 0, 0, 0.35);
        }

        .modal-head,
        .modal-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
        }

        .modal-foot {
            border-top: 1px solid var(--line);
            border-bottom: 0;
            justify-content: flex-end;
        }

        .modal-head h3 {
            margin: 0;
            font-size: 1.2rem;
        }

        .modal-close {
            width: 38px;
            height: 38px;
            border: 0;
            border-radius: 6px;
            background: #eef2f7;
            color: var(--ink);
            cursor: pointer;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .payload-panel {
            display: none;
            grid-column: 1 / -1;
        }

        .payload-panel.is-active {
            display: block;
        }

        .payload-list {
            display: grid;
            gap: 16px;
        }

        .payload-item {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
        }

        .payload-item h4 {
            grid-column: 1 / -1;
            margin: 0;
            font-size: 0.9rem;
        }

        .payload-preview {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: #fff;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            color: #314155;
            font-size: 0.82rem;
            font-weight: 900;
        }

        .field input {
            width: 100%;
            min-height: 42px;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 0 11px;
            font: inherit;
        }

        .field textarea {
            width: 100%;
            min-height: 110px;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px 11px;
            font: inherit;
            resize: vertical;
        }

        .check-field {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            font-weight: 800;
        }

        .check-field input {
            width: 18px;
            height: 18px;
            min-height: 18px;
        }

        .modal-action {
            min-height: 42px;
            padding: 0 14px;
        }

        .modal-save {
            background: var(--ink);
            color: #fff;
        }

        .modal-delete {
            margin-right: auto;
            background: #dc3545;
            color: #fff;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 5px;
            background: #eef5f8;
            color: #264653;
            font-size: 0.75rem;
            font-weight: 900;
        }

        .cta {
            padding: 58px 0;
            background: #e8f4f2;
        }

        .cta-inner {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 26px;
        }

        .cta h2 {
            margin: 0 0 8px;
            font-size: clamp(1.7rem, 3vw, 2.6rem);
            line-height: 1.1;
        }

        .cta p {
            margin: 0;
            color: #496272;
            font-weight: 600;
        }

        .cta .btn-primary {
            background: var(--ink);
            color: #fff;
            border-color: var(--ink);
            flex: 0 0 auto;
        }

        .site-footer {
            padding: 24px 0;
            background: #0f1f35;
            color: rgba(255, 255, 255, 0.72);
        }

        .footer-inner {
            width: min(var(--page-max), calc(100% - (var(--page-gutter) * 2)));
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            font-size: 0.88rem;
            font-weight: 700;
        }

        @media (max-width: 980px) {
            .org-chart {
                overflow-x: auto;
                padding-bottom: 22px;
            }

            .hero-inner,
            .program-grid,
            .gallery-grid {
                grid-template-columns: 1fr 1fr;
            }

            .hero-inner {
                align-items: start;
            }

            .quick-panel {
                grid-column: 1 / -1;
            }

            .gallery-tile,
            .gallery-tile:nth-child(2) {
                min-height: 280px;
            }

            .nav-links {
                gap: 12px;
            }
        }

        @media (max-width: 720px) {
            .nav-shell {
                min-height: 66px;
            }

            .brand span,
            .nav-links a:not(.login-link) {
                display: none;
            }

            .hero {
                min-height: auto;
                padding: 112px 0 48px;
            }

            .hero-inner,
            .program-grid,
            .modal-grid,
            .gallery-grid,
            .cta-inner {
                grid-template-columns: 1fr;
            }

            .section-edit-btn {
                position: static;
                margin-top: 12px;
            }

            .cta-inner {
                display: block;
            }

            .cta .btn {
                width: 100%;
                margin-top: 22px;
            }

            section {
                padding: 54px 0;
            }

            .footer-inner {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <nav class="nav-shell" aria-label="Primary navigation">
            <a class="brand" href="landing_page.php">
                <img src="include/logo.png" alt="TAU NSTP logo">
                <span>
                    <strong>TAU NSTP</strong>
                    <span>CWTS · LTS · ROTC</span>
                </span>
            </a>
            <div class="nav-links">
                <a href="#programs">Programs</a>
                <a href="#difference">Difference</a>
                <a href="#staff">Staff</a>
                <a class="login-link" href="<?php echo $isLoggedIn ? 'index.php' : 'login.php'; ?>">
                    <i class="fas fa-arrow-right-to-bracket"></i> <?php echo $isLoggedIn ? 'Dashboard' : 'Login'; ?>
                </a>
            </div>
        </nav>
    </header>

    <main>
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-inner">
                <div>
                    <?php renderSectionEditButton('hero', $landingSections['hero'], $canEditLanding); ?>
                    <span class="eyebrow"><i class="fas fa-seedling"></i> <?php echo e($landingSections['hero']['kicker']); ?></span>
                    <h1 id="hero-title"><?php echo e($landingSections['hero']['title']); ?></h1>
                    <p class="hero-copy">
                        <?php echo e($landingSections['hero']['body']); ?>
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="#programs"><i class="fas fa-table-columns"></i> <?php echo e($heroPayload['primary_label'] ?? 'View Programs'); ?></a>
                        <a class="btn btn-ghost" href="#staff"><i class="fas fa-users"></i> <?php echo e($heroPayload['secondary_label'] ?? 'Meet Staff'); ?></a>
                    </div>
                </div>

                <aside class="quick-panel" aria-label="Program quick guide">
                    <?php renderSectionEditButton('quick_guide', $landingSections['quick_guide'], $canEditLanding); ?>
                    <h2><?php echo e($landingSections['quick_guide']['title']); ?></h2>
                    <?php foreach ($quickGuideItems as $index => $item): ?>
                        <?php $quickName = strtoupper((string) ($item['name'] ?? 'NSTP')); ?>
                        <div class="quick-item">
                            <img class="quick-icon" src="<?php echo e($componentLogos[$quickName] ?? $componentLogos['NSTP']); ?>" alt="<?php echo e($quickName); ?> logo">
                            <span><strong><?php echo e($item['name'] ?? 'NSTP'); ?></strong><span><?php echo e($item['description'] ?? ''); ?></span></span>
                        </div>
                    <?php endforeach; ?>
                </aside>
            </div>
        </section>

        <section id="programs">
            <div class="section-inner">
                <div class="section-head">
                    <?php renderSectionEditButton('programs', $landingSections['programs'], $canEditLanding); ?>
                    <span class="section-kicker"><?php echo e($landingSections['programs']['kicker']); ?></span>
                    <h2><?php echo e($landingSections['programs']['title']); ?></h2>
                    <p><?php echo e($landingSections['programs']['body']); ?></p>
                </div>

                <div class="program-grid">
                    <?php foreach ($programs as $program): ?>
                        <article class="program-card">
                            <div class="program-media">
                                <img src="<?php echo e($program['image']); ?>" alt="<?php echo e($program['title']); ?>">
                                <span class="program-badge <?php echo e($program['accent']); ?>"><?php echo e($program['name']); ?></span>
                            </div>
                            <div class="program-body">
                                <h3><?php echo e($program['title']); ?></h3>
                                <p><?php echo e($program['focus']); ?></p>
                                <div class="fact-row">
                                    <span>Best for</span>
                                    <strong><?php echo e($program['best_for']); ?></strong>
                                </div>
                                <div class="fact-row">
                                    <span>Common outputs</span>
                                    <strong><?php echo e($program['output']); ?></strong>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="compare-band" id="difference">
            <div class="section-inner">
                <div class="section-head">
                    <?php renderSectionEditButton('difference', $landingSections['difference'], $canEditLanding); ?>
                    <span class="section-kicker"><?php echo e($landingSections['difference']['kicker']); ?></span>
                    <h2><?php echo e($landingSections['difference']['title']); ?></h2>
                    <p><?php echo e($landingSections['difference']['body']); ?></p>
                </div>

                <div class="compare-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Component</th>
                                <th>Main Focus</th>
                                <th>Training Style</th>
                                <th>Student Experience</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($differenceRows as $row): ?>
                                <tr>
                                    <td><strong><?php echo e($row['component'] ?? 'NSTP'); ?></strong></td>
                                    <td><?php echo e($row['focus'] ?? ''); ?></td>
                                    <td><?php echo e($row['style'] ?? ''); ?></td>
                                    <td><?php echo e($row['experience'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section aria-labelledby="gallery-title">
            <div class="section-inner">
                <div class="section-head">
                    <?php renderSectionEditButton('activities', $landingSections['activities'], $canEditLanding); ?>
                    <span class="section-kicker"><?php echo e($landingSections['activities']['kicker']); ?></span>
                    <h2 id="gallery-title"><?php echo e($landingSections['activities']['title']); ?></h2>
                    <p><?php echo e($landingSections['activities']['body']); ?></p>
                </div>

                <div class="gallery-grid">
                    <?php foreach ($gallery as $item): ?>
                        <article class="gallery-tile">
                            <img src="<?php echo e($item['image']); ?>" alt="<?php echo e($item['title']); ?>">
                            <div class="gallery-caption">
                                <span><?php echo e($item['label']); ?></span>
                                <strong><?php echo e($item['title']); ?></strong>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="staff-section" id="staff">
            <div class="section-inner">
                <?php if ($landingMessage): ?>
                    <div class="landing-alert <?php echo e($landingMessageType); ?>">
                        <?php echo e($landingMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="section-head">
                    <?php renderSectionEditButton('org_chart', $landingSections['org_chart'], $canEditLanding); ?>
                    <span class="section-kicker"><?php echo e($landingSections['org_chart']['kicker']); ?></span>
                    <h2><?php echo e($landingSections['org_chart']['title']); ?></h2>
                    <p><?php echo e($landingSections['org_chart']['body']); ?></p>
                    <?php if ($canEditLanding): ?>
                        <div class="section-tools">
                            <button type="button" class="edit-chip" id="addLandingEntryBtn">
                                <i class="fas fa-plus"></i> Add entry
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php $chartOrders = [10, 20, 30, 45, 40, 50, 60, 70, 80]; ?>
                <div class="org-chart" aria-label="NSTP organizational chart">
                    <div class="org-level">
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 10), $canEditLanding); ?>
                    </div>
                    <div class="org-level">
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 20), $canEditLanding, 'org-vertical compact'); ?>
                    </div>
                    <div class="org-level director-row">
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 30), $canEditLanding, 'org-vertical compact'); ?>
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 45), $canEditLanding, 'side-node compact'); ?>
                    </div>
                    <div class="org-level">
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 40), $canEditLanding, 'org-vertical compact'); ?>
                    </div>
                    <div class="org-level bottom-row">
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 50), $canEditLanding, 'compact'); ?>
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 60), $canEditLanding, 'compact'); ?>
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 70), $canEditLanding, 'compact'); ?>
                        <?php renderOrgNode(findOrgEntryByOrder($staffMembers, 80), $canEditLanding, 'compact'); ?>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($canEditLanding): ?>
            <div class="modal-backdrop" id="sectionEditorModal" aria-hidden="true">
                <div class="landing-modal" role="dialog" aria-modal="true" aria-labelledby="sectionEditorTitle">
                    <form method="POST" enctype="multipart/form-data" id="sectionEditorForm">
                        <input type="hidden" name="landing_section_action" value="update">
                        <input type="hidden" name="section_key" id="sectionKey" value="">

                        <div class="modal-head">
                            <h3 id="sectionEditorTitle">Edit section</h3>
                            <button type="button" class="modal-close" id="closeSectionEditor" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <div class="modal-body">
                            <div class="modal-grid">
                                <div class="field">
                                    <label for="sectionKicker">Kicker / Small Label</label>
                                    <input type="text" name="section_kicker" id="sectionKicker">
                                </div>
                                <div class="field">
                                    <label for="sectionTitle">Title</label>
                                    <input type="text" name="section_title" id="sectionTitle" required>
                                </div>
                                <div class="field full">
                                    <label for="sectionBody">Body Text</label>
                                    <textarea name="section_body" id="sectionBody"></textarea>
                                </div>
                                <div class="payload-panel" data-payload-panel="hero">
                                    <div class="modal-grid">
                                        <div class="field">
                                            <label for="heroPrimaryLabel">Primary Button Label</label>
                                            <input type="text" name="hero_primary_label" id="heroPrimaryLabel">
                                        </div>
                                        <div class="field">
                                            <label for="heroSecondaryLabel">Secondary Button Label</label>
                                            <input type="text" name="hero_secondary_label" id="heroSecondaryLabel">
                                        </div>
                                    </div>
                                </div>

                                <div class="payload-panel" data-payload-panel="quick_guide">
                                    <div class="payload-list">
                                        <?php foreach (['CWTS', 'LTS', 'ROTC'] as $index => $component): ?>
                                            <div class="payload-item">
                                                <h4><?php echo e($component); ?> Quick Guide</h4>
                                                <div class="field">
                                                    <label>Name</label>
                                                    <input type="text" name="quick_name[]" data-payload-field="quick_name" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field">
                                                    <label>Description</label>
                                                    <input type="text" name="quick_description[]" data-payload-field="quick_description" data-index="<?php echo $index; ?>">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="payload-panel" data-payload-panel="programs">
                                    <div class="payload-list">
                                        <?php foreach (['CWTS', 'LTS', 'ROTC'] as $index => $component): ?>
                                            <div class="payload-item">
                                                <h4><?php echo e($component); ?> Program Card</h4>
                                                <div class="field">
                                                    <label>Program</label>
                                                    <input type="text" name="program_name[]" data-payload-field="program_name" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field">
                                                    <label>Title</label>
                                                    <input type="text" name="program_title[]" data-payload-field="program_title" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field">
                                                    <label>Accent</label>
                                                    <input type="text" name="program_accent[]" data-payload-field="program_accent" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field">
                                                    <label>Upload Picture</label>
                                                    <input type="file" name="program_image_upload[]" accept="image/*">
                                                    <input type="hidden" name="program_image_existing[]" data-payload-field="program_image" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field full">
                                                    <img class="payload-preview" data-preview="program_image" data-index="<?php echo $index; ?>" alt="">
                                                </div>
                                                <div class="field full">
                                                    <label>Focus</label>
                                                    <textarea name="program_focus[]" data-payload-field="program_focus" data-index="<?php echo $index; ?>"></textarea>
                                                </div>
                                                <div class="field full">
                                                    <label>Best For</label>
                                                    <textarea name="program_best_for[]" data-payload-field="program_best_for" data-index="<?php echo $index; ?>"></textarea>
                                                </div>
                                                <div class="field full">
                                                    <label>Output</label>
                                                    <textarea name="program_output[]" data-payload-field="program_output" data-index="<?php echo $index; ?>"></textarea>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="payload-panel" data-payload-panel="difference">
                                    <div class="payload-list">
                                        <?php foreach (['CWTS', 'LTS', 'ROTC'] as $index => $component): ?>
                                            <div class="payload-item">
                                                <h4><?php echo e($component); ?> Comparison</h4>
                                                <div class="field">
                                                    <label>Component</label>
                                                    <input type="text" name="difference_component[]" data-payload-field="difference_component" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field full">
                                                    <label>Focus</label>
                                                    <textarea name="difference_focus[]" data-payload-field="difference_focus" data-index="<?php echo $index; ?>"></textarea>
                                                </div>
                                                <div class="field full">
                                                    <label>Style</label>
                                                    <textarea name="difference_style[]" data-payload-field="difference_style" data-index="<?php echo $index; ?>"></textarea>
                                                </div>
                                                <div class="field full">
                                                    <label>Experience</label>
                                                    <textarea name="difference_experience[]" data-payload-field="difference_experience" data-index="<?php echo $index; ?>"></textarea>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="payload-panel" data-payload-panel="activities">
                                    <div class="payload-list">
                                        <?php foreach ([0, 1, 2] as $index): ?>
                                            <div class="payload-item">
                                                <h4>Activity Image <?php echo $index + 1; ?></h4>
                                                <div class="field">
                                                    <label>Title</label>
                                                    <input type="text" name="activity_title[]" data-payload-field="activity_title" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field">
                                                    <label>Label</label>
                                                    <input type="text" name="activity_label[]" data-payload-field="activity_label" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field">
                                                    <label>Upload Picture</label>
                                                    <input type="file" name="activity_image_upload[]" accept="image/*">
                                                    <input type="hidden" name="activity_image_existing[]" data-payload-field="activity_image" data-index="<?php echo $index; ?>">
                                                </div>
                                                <div class="field">
                                                    <img class="payload-preview" data-preview="activity_image" data-index="<?php echo $index; ?>" alt="">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="payload-panel" data-payload-panel="cta">
                                    <div class="modal-grid">
                                        <div class="field">
                                            <label for="ctaGuestLabel">Guest Button Label</label>
                                            <input type="text" name="cta_guest_label" id="ctaGuestLabel">
                                        </div>
                                        <div class="field">
                                            <label for="ctaLoggedInLabel">Logged-in Button Label</label>
                                            <input type="text" name="cta_logged_in_label" id="ctaLoggedInLabel">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal-foot">
                            <button type="button" class="modal-action" id="cancelSectionEditor">Cancel</button>
                            <button type="submit" class="modal-action modal-save">
                                <i class="fas fa-save"></i> Save Section
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="modal-backdrop" id="landingEditorModal" aria-hidden="true">
                <div class="landing-modal" role="dialog" aria-modal="true" aria-labelledby="landingEditorTitle">
                    <form method="POST" enctype="multipart/form-data" id="landingEditorForm">
                        <input type="hidden" name="landing_staff_action" id="landingStaffAction" value="add">
                        <input type="hidden" name="landing_staff_id" id="landingStaffId" value="">

                        <div class="modal-head">
                            <h3 id="landingEditorTitle">Edit landing entry</h3>
                            <button type="button" class="modal-close" id="closeLandingEditor" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <div class="modal-body">
                            <div class="modal-grid">
                                <div class="field">
                                    <label for="landingFullName">Name / Label</label>
                                    <input type="text" name="full_name" id="landingFullName" required>
                                </div>
                                <div class="field">
                                    <label for="landingPositionTitle">Position / Title</label>
                                    <input type="text" name="position_title" id="landingPositionTitle" required>
                                </div>
                                <div class="field">
                                    <label for="landingProgram">Program</label>
                                    <input type="text" name="program" id="landingProgram" list="landingProgramOptions">
                                </div>
                                <div class="field">
                                    <label for="landingGroupLabel">Group</label>
                                    <input type="text" name="group_label" id="landingGroupLabel">
                                </div>
                                <div class="field">
                                    <label for="landingSortOrder">Order</label>
                                    <input type="number" name="sort_order" id="landingSortOrder">
                                </div>
                                <div class="field">
                                    <label for="landingPhotoUpload">Upload Photo</label>
                                    <input type="file" name="photo_upload" id="landingPhotoUpload" accept="image/*">
                                </div>
                                <div class="field full">
                                    <label for="landingPhotoPath">Photo Path</label>
                                    <input type="text" name="photo_path" id="landingPhotoPath" placeholder="uploads/landing_staff/photo.png">
                                </div>
                                <div class="field full">
                                    <label class="check-field" for="landingIsVisible">
                                        <input type="checkbox" name="is_visible" id="landingIsVisible" checked>
                                        Show this entry on public landing page
                                    </label>
                                </div>
                            </div>
                            <datalist id="landingProgramOptions">
                                <option value="NSTP">
                                <option value="CWTS">
                                <option value="LTS">
                                <option value="ROTC">
                                <option value="DRRM">
                            </datalist>
                        </div>

                        <div class="modal-foot">
                            <button type="submit" class="modal-action modal-delete" id="deleteLandingEntryBtn">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                            <button type="button" class="modal-action" id="cancelLandingEditor">Cancel</button>
                            <button type="submit" class="modal-action modal-save">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <section class="cta">
            <div class="cta-inner">
                <div>
                    <?php renderSectionEditButton('cta', $landingSections['cta'], $canEditLanding); ?>
                    <h2><?php echo e($landingSections['cta']['title']); ?></h2>
                    <p><?php echo e($landingSections['cta']['body']); ?></p>
                </div>
                <a class="btn btn-primary" href="<?php echo $isLoggedIn ? 'index.php' : 'login.php'; ?>">
                    <i class="fas fa-qrcode"></i> <?php echo e($isLoggedIn ? ($ctaPayload['logged_in_label'] ?? 'Open Dashboard') : ($ctaPayload['guest_label'] ?? 'Open QR Attendance System')); ?>
                </a>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <span>TAU NSTP QR Attendance System</span>
            <span>CWTS · LTS · ROTC</span>
        </div>
    </footer>
    <?php if ($canEditLanding): ?>
        <script>
            (function () {
                const sectionModal = document.getElementById('sectionEditorModal');
                const sectionFields = {
                    key: document.getElementById('sectionKey'),
                    kicker: document.getElementById('sectionKicker'),
                    title: document.getElementById('sectionTitle'),
                    body: document.getElementById('sectionBody')
                };
                const modal = document.getElementById('landingEditorModal');
                const form = document.getElementById('landingEditorForm');
                const actionInput = document.getElementById('landingStaffAction');
                const entryIdInput = document.getElementById('landingStaffId');
                const title = document.getElementById('landingEditorTitle');
                const deleteButton = document.getElementById('deleteLandingEntryBtn');
                const fields = {
                    fullName: document.getElementById('landingFullName'),
                    positionTitle: document.getElementById('landingPositionTitle'),
                    program: document.getElementById('landingProgram'),
                    groupLabel: document.getElementById('landingGroupLabel'),
                    photoPath: document.getElementById('landingPhotoPath'),
                    sortOrder: document.getElementById('landingSortOrder'),
                    isVisible: document.getElementById('landingIsVisible'),
                    photoUpload: document.getElementById('landingPhotoUpload')
                };

                function parsePayload(rawPayload) {
                    try {
                        return rawPayload ? JSON.parse(rawPayload) : null;
                    } catch (error) {
                        return null;
                    }
                }

                function setPayloadInput(fieldName, index, value) {
                    const input = document.querySelector(`[data-payload-field="${fieldName}"][data-index="${index}"]`);
                    if (input) {
                        input.value = value || '';
                    }
                }

                function setPayloadPreview(fieldName, index, value) {
                    const image = document.querySelector(`[data-preview="${fieldName}"][data-index="${index}"]`);
                    if (image) {
                        image.src = value || '';
                        image.style.display = value ? 'block' : 'none';
                    }
                }

                function showPayloadPanel(sectionKey, payload) {
                    document.querySelectorAll('.payload-panel').forEach(function (panel) {
                        panel.classList.toggle('is-active', panel.dataset.payloadPanel === sectionKey);
                    });

                    document.querySelectorAll('.payload-panel input:not([type="file"]), .payload-panel textarea').forEach(function (input) {
                        input.value = '';
                    });
                    document.querySelectorAll('.payload-panel input[type="file"]').forEach(function (input) {
                        input.value = '';
                    });
                    document.querySelectorAll('.payload-preview').forEach(function (image) {
                        image.removeAttribute('src');
                        image.style.display = 'none';
                    });

                    if (sectionKey === 'hero' && payload) {
                        document.getElementById('heroPrimaryLabel').value = payload.primary_label || '';
                        document.getElementById('heroSecondaryLabel').value = payload.secondary_label || '';
                    }

                    if (sectionKey === 'quick_guide' && Array.isArray(payload)) {
                        payload.forEach(function (item, index) {
                            setPayloadInput('quick_name', index, item.name);
                            setPayloadInput('quick_description', index, item.description);
                        });
                    }

                    if (sectionKey === 'programs' && Array.isArray(payload)) {
                        payload.forEach(function (item, index) {
                            setPayloadInput('program_name', index, item.name);
                            setPayloadInput('program_title', index, item.title);
                            setPayloadInput('program_accent', index, item.accent);
                            setPayloadInput('program_image', index, item.image);
                            setPayloadInput('program_focus', index, item.focus);
                            setPayloadInput('program_best_for', index, item.best_for);
                            setPayloadInput('program_output', index, item.output);
                            setPayloadPreview('program_image', index, item.image);
                        });
                    }

                    if (sectionKey === 'difference' && Array.isArray(payload)) {
                        payload.forEach(function (item, index) {
                            setPayloadInput('difference_component', index, item.component);
                            setPayloadInput('difference_focus', index, item.focus);
                            setPayloadInput('difference_style', index, item.style);
                            setPayloadInput('difference_experience', index, item.experience);
                        });
                    }

                    if (sectionKey === 'activities' && Array.isArray(payload)) {
                        payload.forEach(function (item, index) {
                            setPayloadInput('activity_title', index, item.title);
                            setPayloadInput('activity_label', index, item.label);
                            setPayloadInput('activity_image', index, item.image);
                            setPayloadPreview('activity_image', index, item.image);
                        });
                    }

                    if (sectionKey === 'cta' && payload) {
                        document.getElementById('ctaGuestLabel').value = payload.guest_label || '';
                        document.getElementById('ctaLoggedInLabel').value = payload.logged_in_label || '';
                    }
                }

                function openSectionModal(data) {
                    sectionFields.key.value = data.sectionKey || '';
                    sectionFields.kicker.value = data.sectionKicker || '';
                    sectionFields.title.value = data.sectionTitle || '';
                    sectionFields.body.value = data.sectionBody || '';
                    showPayloadPanel(data.sectionKey || '', parsePayload(data.sectionPayload || ''));
                    sectionModal.classList.add('is-open');
                    sectionModal.setAttribute('aria-hidden', 'false');
                    sectionFields.title.focus();
                }

                function closeSectionModal() {
                    sectionModal.classList.remove('is-open');
                    sectionModal.setAttribute('aria-hidden', 'true');
                }

                function openModal(mode, data) {
                    actionInput.value = mode;
                    entryIdInput.value = data.entryId || '';
                    title.textContent = mode === 'add' ? 'Add landing entry' : 'Edit landing entry';
                    fields.fullName.value = data.fullName || '';
                    fields.positionTitle.value = data.positionTitle || '';
                    fields.program.value = data.program || 'NSTP';
                    fields.groupLabel.value = data.groupLabel || 'NSTP Office';
                    fields.photoPath.value = data.photoPath || '';
                    fields.sortOrder.value = data.sortOrder || '0';
                    fields.isVisible.checked = data.isVisible !== '0';
                    fields.photoUpload.value = '';
                    deleteButton.style.display = mode === 'add' ? 'none' : 'inline-flex';
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                    fields.fullName.focus();
                }

                function closeModal() {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                }

                document.getElementById('addLandingEntryBtn')?.addEventListener('click', function () {
                    openModal('add', {});
                });

                document.querySelectorAll('.section-edit-btn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        openSectionModal(button.dataset);
                    });
                });

                document.getElementById('closeSectionEditor')?.addEventListener('click', closeSectionModal);
                document.getElementById('cancelSectionEditor')?.addEventListener('click', closeSectionModal);

                sectionModal.addEventListener('click', function (event) {
                    if (event.target === sectionModal) {
                        closeSectionModal();
                    }
                });

                document.querySelectorAll('.inline-edit-btn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        openModal('update', button.dataset);
                    });
                });

                document.getElementById('closeLandingEditor')?.addEventListener('click', closeModal);
                document.getElementById('cancelLandingEditor')?.addEventListener('click', closeModal);

                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        closeModal();
                    }
                });

                deleteButton.addEventListener('click', function (event) {
                    if (!confirm('Delete this landing entry?')) {
                        event.preventDefault();
                        return;
                    }

                    actionInput.value = 'delete';
                    form.noValidate = true;
                });
            })();
        </script>
    <?php endif; ?>
</body>
</html>
