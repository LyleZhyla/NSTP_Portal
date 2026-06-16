<?php

function getDefaultNstpComponentDetails() {
    return [
        'CWTS' => [
            'name' => 'CWTS',
            'title' => 'Civic Welfare Training Service',
            'subtitle' => 'Community-based service, outreach planning, and civic action.',
            'accent' => 'teal',
            'hero_image' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=1600&q=80',
            'summary' => 'CWTS develops students through community service, advocacy work, disaster preparedness, and projects that respond to local needs.',
            'short_details' => 'Outreach, community service, health, environment, and civic welfare projects.',
            'highlights' => [
                'Community immersion and needs assessment',
                'Outreach planning and volunteer leadership',
                'Disaster preparedness, health, and environment campaigns',
                'Service projects with measurable community impact',
            ],
            'activities' => [
                [
                    'title' => 'Community Outreach',
                    'label' => 'Service',
                    'detail' => 'Students organize civic welfare activities with partner communities.',
                    'image' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Relief Operations',
                    'label' => 'Preparedness',
                    'detail' => 'Teams prepare supplies, coordinate volunteers, and support response efforts.',
                    'image' => 'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Clean-up Drive',
                    'label' => 'Environment',
                    'detail' => 'Learners practice civic responsibility through environmental action.',
                    'image' => 'https://images.unsplash.com/photo-1618477462146-050d2767eac4?auto=format&fit=crop&w=900&q=80',
                ],
            ],
        ],
        'LTS' => [
            'name' => 'LTS',
            'title' => 'Literacy Training Service',
            'subtitle' => 'Tutorial support, reading programs, and learning materials.',
            'accent' => 'gold',
            'hero_image' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=1600&q=80',
            'summary' => 'LTS prepares students to help learners improve literacy, numeracy, study confidence, and access to basic educational support.',
            'short_details' => 'Tutorial sessions, reading drives, mentoring, and instructional materials.',
            'highlights' => [
                'Reading, writing, and numeracy support',
                'Tutorial sessions for young learners',
                'Learning modules and instructional material preparation',
                'Patient mentoring and classroom assistance',
            ],
            'activities' => [
                [
                    'title' => 'Reading Session',
                    'label' => 'Literacy',
                    'detail' => 'Students guide learners through reading practice and comprehension tasks.',
                    'image' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Tutorial Support',
                    'label' => 'Mentoring',
                    'detail' => 'Small-group tutorials help learners strengthen basic academic skills.',
                    'image' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Learning Materials',
                    'label' => 'Preparation',
                    'detail' => 'Teams create worksheets, modules, and activities for partner learners.',
                    'image' => 'https://images.unsplash.com/photo-1456513080510-7bf3a84b82f8?auto=format&fit=crop&w=900&q=80',
                ],
            ],
        ],
        'ROTC' => [
            'name' => 'ROTC',
            'title' => 'Reserve Officers Training Corps',
            'subtitle' => 'Leadership, discipline, formations, and preparedness training.',
            'accent' => 'crimson',
            'hero_image' => 'https://images.unsplash.com/photo-1517048676732-d65bc937f952?auto=format&fit=crop&w=1600&q=80',
            'summary' => 'ROTC trains students in leadership, discipline, command responsibility, emergency response, and national defense awareness.',
            'short_details' => 'Formations, drills, leadership exercises, preparedness, and command training.',
            'highlights' => [
                'Leadership and command responsibility',
                'Drills, formations, and discipline-building exercises',
                'Emergency response and readiness activities',
                'Citizenship and national defense awareness',
            ],
            'activities' => [
                [
                    'title' => 'Formation Training',
                    'label' => 'Discipline',
                    'detail' => 'Cadets practice order, timing, and teamwork through structured formations.',
                    'image' => 'https://images.unsplash.com/photo-1517048676732-d65bc937f952?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Leadership Exercise',
                    'label' => 'Command',
                    'detail' => 'Students build confidence through guided command and team responsibility.',
                    'image' => 'https://images.unsplash.com/photo-1517048676732-d65bc937f952?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Preparedness Drill',
                    'label' => 'Readiness',
                    'detail' => 'Training includes emergency response basics and coordinated action.',
                    'image' => 'https://images.unsplash.com/photo-1582213782179-e0d53f98f2ca?auto=format&fit=crop&w=900&q=80',
                ],
            ],
        ],
    ];
}

function ensureNstpComponentDetailsTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_nstp_component_details (
            component_key VARCHAR(10) PRIMARY KEY,
            name VARCHAR(30) NOT NULL,
            title VARCHAR(255) NOT NULL,
            subtitle VARCHAR(255) DEFAULT NULL,
            accent VARCHAR(30) NOT NULL DEFAULT 'teal',
            hero_image VARCHAR(500) DEFAULT NULL,
            summary TEXT DEFAULT NULL,
            short_details VARCHAR(500) DEFAULT NULL,
            highlights LONGTEXT DEFAULT NULL,
            activities LONGTEXT DEFAULT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $defaults = getDefaultNstpComponentDetails();
    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_nstp_component_details
            (component_key, name, title, subtitle, accent, hero_image, summary, short_details, highlights, activities)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($defaults as $key => $component) {
        $stmt->execute([
            $key,
            $component['name'],
            $component['title'],
            $component['subtitle'],
            $component['accent'],
            $component['hero_image'],
            $component['summary'],
            $component['short_details'],
            json_encode($component['highlights'], JSON_UNESCAPED_SLASHES),
            json_encode($component['activities'], JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function getNstpComponentDetails(PDO $conn = null) {
    $components = getDefaultNstpComponentDetails();

    if (!$conn) {
        return $components;
    }

    ensureNstpComponentDetailsTable($conn);

    $stmt = $conn->prepare("
        SELECT component_key, name, title, subtitle, accent, hero_image, summary, short_details, highlights, activities
        FROM tbl_nstp_component_details
    ");
    $stmt->execute();

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = normalizeNstpComponentKey($row['component_key'] ?? '');
        if ($key === '') {
            continue;
        }

        $default = $components[$key] ?? [];
        $highlights = json_decode((string) ($row['highlights'] ?? ''), true);
        $activities = json_decode((string) ($row['activities'] ?? ''), true);

        $components[$key] = array_merge($default, [
            'name' => $row['name'] ?: ($default['name'] ?? $key),
            'title' => $row['title'] ?: ($default['title'] ?? $key),
            'subtitle' => $row['subtitle'] ?? '',
            'accent' => $row['accent'] ?: ($default['accent'] ?? 'teal'),
            'hero_image' => $row['hero_image'] ?: ($default['hero_image'] ?? ''),
            'summary' => $row['summary'] ?? '',
            'short_details' => $row['short_details'] ?? '',
            'highlights' => is_array($highlights) ? array_values(array_filter(array_map('strval', $highlights))) : ($default['highlights'] ?? []),
            'activities' => is_array($activities) ? array_values($activities) : ($default['activities'] ?? []),
        ]);
    }

    return $components;
}

function cleanNstpComponentText($value, $maxLength = null) {
    $value = trim((string) $value);
    return $maxLength ? substr($value, 0, $maxLength) : $value;
}

function nstpComponentImageUrl($path, $baseDir = '') {
    $path = trim((string) $path);
    if ($path === '' || preg_match('/^(https?:)?\/\//i', $path) || strpos($path, 'data:') === 0) {
        return $path;
    }

    $urlParts = explode('?', $path, 2);
    $cleanPath = $urlParts[0];
    $query = $urlParts[1] ?? '';
    $rootDir = $baseDir !== '' ? rtrim($baseDir, '/\\') : dirname(__DIR__);
    $filePath = $rootDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($cleanPath, '/\\'));

    if (is_file($filePath)) {
        $query .= ($query !== '' ? '&' : '') . 'v=' . filemtime($filePath);
    }

    return $cleanPath . ($query !== '' ? '?' . $query : '');
}

function uploadNstpComponentImage(array $file, $baseDir = '') {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $mimeType = mime_content_type($file['tmp_name']);
    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Image must be JPG, PNG, GIF, or WebP.');
    }

    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('Image must be 4MB or smaller.');
    }

    $relativeDir = 'uploads/component_activities/';
    $uploadDir = rtrim($baseDir, '/\\');
    $uploadDir = $uploadDir !== '' ? $uploadDir . DIRECTORY_SEPARATOR . $relativeDir : $relativeDir;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = 'component_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not upload the image.');
    }

    return $relativeDir . $fileName;
}

function uploadedNstpComponentArrayFile(array $files, $index) {
    if (!isset($files['name'][$index])) {
        return [];
    }

    return [
        'name' => $files['name'][$index],
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function applyNstpComponentImageUploads(array $data, array $files, $baseDir = '') {
    if (!empty($files['hero_image_upload'])) {
        $uploadedHero = uploadNstpComponentImage($files['hero_image_upload'], $baseDir);
        if ($uploadedHero) {
            $data['hero_image'] = $uploadedHero;
        }
    }

    if (!empty($files['activity_image_upload']) && is_array($files['activity_image_upload']['name'] ?? null)) {
        $images = $data['activity_image'] ?? [];
        $count = count($files['activity_image_upload']['name']);

        for ($i = 0; $i < $count; $i++) {
            $uploadedActivity = uploadNstpComponentImage(
                uploadedNstpComponentArrayFile($files['activity_image_upload'], $i),
                $baseDir
            );

            if ($uploadedActivity) {
                $images[$i] = $uploadedActivity;
            }
        }

        $data['activity_image'] = $images;
    }

    return $data;
}

function saveNstpComponentDetails(PDO $conn, array $data, $userId = null) {
    ensureNstpComponentDetailsTable($conn);

    $componentKey = normalizeNstpComponentKey($data['component_key'] ?? '');
    if ($componentKey === '') {
        throw new RuntimeException('Invalid NSTP component.');
    }

    $defaults = getDefaultNstpComponentDetails();
    $accent = cleanNstpComponentText($data['accent'] ?? ($defaults[$componentKey]['accent'] ?? 'teal'), 30);
    if (!in_array($accent, ['teal', 'gold', 'crimson', 'blue'], true)) {
        $accent = $defaults[$componentKey]['accent'] ?? 'teal';
    }

    $highlights = [];
    foreach (($data['highlights'] ?? []) as $highlight) {
        $highlight = cleanNstpComponentText($highlight, 255);
        if ($highlight !== '') {
            $highlights[] = $highlight;
        }
    }

    $activities = [];
    $titles = $data['activity_title'] ?? [];
    $labels = $data['activity_label'] ?? [];
    $details = $data['activity_detail'] ?? [];
    $images = $data['activity_image'] ?? [];
    $remove = $data['activity_remove'] ?? [];
    $count = max(count($titles), count($labels), count($details), count($images));

    for ($i = 0; $i < $count; $i++) {
        if (!empty($remove[$i])) {
            continue;
        }

        $title = cleanNstpComponentText($titles[$i] ?? '', 150);
        $image = cleanNstpComponentText($images[$i] ?? '', 500);
        if ($title === '' && $image === '') {
            continue;
        }

        $activities[] = [
            'title' => $title ?: 'NSTP Activity',
            'label' => cleanNstpComponentText($labels[$i] ?? '', 60) ?: 'Activity',
            'detail' => cleanNstpComponentText($details[$i] ?? '', 500),
            'image' => $image,
        ];
    }

    if (!$highlights) {
        $highlights = $defaults[$componentKey]['highlights'];
    }

    if (!$activities) {
        $activities = $defaults[$componentKey]['activities'];
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_nstp_component_details
            (component_key, name, title, subtitle, accent, hero_image, summary, short_details, highlights, activities, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            title = VALUES(title),
            subtitle = VALUES(subtitle),
            accent = VALUES(accent),
            hero_image = VALUES(hero_image),
            summary = VALUES(summary),
            short_details = VALUES(short_details),
            highlights = VALUES(highlights),
            activities = VALUES(activities),
            updated_by = VALUES(updated_by)
    ");

    return $stmt->execute([
        $componentKey,
        cleanNstpComponentText($data['name'] ?? $componentKey, 30) ?: $componentKey,
        cleanNstpComponentText($data['title'] ?? '', 255) ?: ($defaults[$componentKey]['title'] ?? $componentKey),
        cleanNstpComponentText($data['subtitle'] ?? '', 255),
        $accent,
        cleanNstpComponentText($data['hero_image'] ?? '', 500),
        cleanNstpComponentText($data['summary'] ?? ''),
        cleanNstpComponentText($data['short_details'] ?? '', 500),
        json_encode($highlights, JSON_UNESCAPED_SLASHES),
        json_encode($activities, JSON_UNESCAPED_SLASHES),
        $userId,
    ]);
}

function getNstpFeaturedActivities(PDO $conn = null) {
    $featured = [];
    foreach (getNstpComponentDetails($conn) as $component => $details) {
        foreach ($details['activities'] as $activity) {
            $activity['component'] = $component;
            $activity['component_title'] = $details['title'];
            $featured[] = $activity;
        }
    }

    return $featured;
}

function normalizeNstpComponentKey($component) {
    $component = strtoupper(trim((string) $component));
    return in_array($component, ['CWTS', 'LTS', 'ROTC'], true) ? $component : '';
}
