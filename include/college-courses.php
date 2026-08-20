<?php

function getCollegeCourseData() {
    return [
        [
            'college' => 'College of Agriculture and Forestry',
            'courses' => [
                ['name' => 'Bachelor of Animal Science', 'majors' => []],
                ['name' => 'Bachelor of Science in Food Technology', 'majors' => []],
                ['name' => 'Bachelor of Science in Agriculture', 'majors' => []],
                ['name' => 'Bachelor of Science in Forestry', 'majors' => []],
            ],
        ],
        [
            'college' => 'College of Arts and Sciences',
            'courses' => [
                ['name' => 'Bachelor of Arts in Economics', 'majors' => []],
                ['name' => 'Bachelor of Science in Psychology', 'majors' => []],
                ['name' => 'Bachelor of Science in Development Communication', 'majors' => []],
            ],
        ],
        [
            'college' => 'College of Business and Management',
            'courses' => [
                ['name' => 'Bachelor of Science in Business Administration', 'majors' => ['Financial Management', 'Human Resource Management', 'Marketing Management']],
                ['name' => 'Bachelor of Science in Entrepreneurship', 'majors' => []],
                ['name' => 'Bachelor of Science in Agribusiness', 'majors' => []],
                ['name' => 'Bachelor of Science in Tourism Management', 'majors' => []],
            ],
        ],
        [
            'college' => 'College of Education',
            'courses' => [
                ['name' => 'Bachelor of Elementary Education (BEEd)', 'majors' => []],
                ['name' => 'Bachelor of Elementary Childhood Education (BECEd)', 'majors' => []],
                ['name' => 'Bachelor of Secondary Education (BSEd)', 'majors' => ['Mathematics', 'Science']],
                ['name' => 'Bachelor of Technology and Livelihood Education (BTLEd)', 'majors' => ['Home Economics', 'Information Communication Technology', 'Agri-Fishery Arts']],
                ['name' => 'Bachelor of Science in Exercise and Sports Science', 'majors' => []],
            ],
        ],
        [
            'college' => 'College of Engineering and Technology',
            'courses' => [
                ['name' => 'Bachelor of Science in Agricultural & Biosystems Engineering', 'majors' => []],
                ['name' => 'Bachelor of Science in Geodetic Engineering', 'majors' => []],
                ['name' => 'Bachelor of Science in Information Technology', 'majors' => []],
            ],
        ],
        [
            'college' => 'College of Veterinary Medicine',
            'courses' => [
                ['name' => 'Doctor of Veterinary Medicine', 'majors' => []],
            ],
        ],
    ];
}

function findCollegeCourse($college, $course) {
    foreach (getCollegeCourseData() as $collegeItem) {
        if ($collegeItem['college'] !== $college) {
            continue;
        }

        foreach ($collegeItem['courses'] as $courseItem) {
            if ($courseItem['name'] === $course) {
                return $courseItem;
            }
        }
    }

    return null;
}

function validateCollegeCourseMajor($college, $course, $major) {
    $courseItem = findCollegeCourse($college, $course);
    if (!$courseItem) {
        return false;
    }

    $major = trim((string) $major);
    if (!$courseItem['majors']) {
        return strtoupper($major) === 'N/A';
    }

    return in_array($major, $courseItem['majors'], true) || strtoupper($major) === 'N/A';
}

function academicLookupKey($value) {
    $value = strtolower(trim((string) $value));
    $value = str_replace('&', ' and ', $value);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
}

function academicCollegeAliases() {
    return [
        'caf' => 'College of Agriculture and Forestry',
        'agriculture and forestry' => 'College of Agriculture and Forestry',
        'animal science' => 'College of Agriculture and Forestry',
        'college of animal science' => 'College of Agriculture and Forestry',
        'colleg of animal science' => 'College of Agriculture and Forestry',
        'cas' => 'College of Arts and Sciences',
        'arts and sciences' => 'College of Arts and Sciences',
        'cbm' => 'College of Business and Management',
        'business and management' => 'College of Business and Management',
        'ced' => 'College of Education',
        'coe' => 'College of Education',
        'education' => 'College of Education',
        'cet' => 'College of Engineering and Technology',
        'engineering and technology' => 'College of Engineering and Technology',
        'cvm' => 'College of Veterinary Medicine',
        'veterinary medicine' => 'College of Veterinary Medicine',
    ];
}

function academicCourseAliases() {
    return [
        'animal science' => 'Bachelor of Animal Science',
        'bas' => 'Bachelor of Animal Science',
        'bs animal science' => 'Bachelor of Animal Science',
        'food technology' => 'Bachelor of Science in Food Technology',
        'bsft' => 'Bachelor of Science in Food Technology',
        'agriculture' => 'Bachelor of Science in Agriculture',
        'bsa' => 'Bachelor of Science in Agriculture',
        'bs agriculture' => 'Bachelor of Science in Agriculture',
        'bsag' => 'Bachelor of Science in Agriculture',
        'forestry' => 'Bachelor of Science in Forestry',
        'bsf' => 'Bachelor of Science in Forestry',
        'bs forestry' => 'Bachelor of Science in Forestry',
        'economics' => 'Bachelor of Arts in Economics',
        'bae' => 'Bachelor of Arts in Economics',
        'ba economics' => 'Bachelor of Arts in Economics',
        'psychology' => 'Bachelor of Science in Psychology',
        'bspsych' => 'Bachelor of Science in Psychology',
        'bs psychology' => 'Bachelor of Science in Psychology',
        'development communication' => 'Bachelor of Science in Development Communication',
        'bsdc' => 'Bachelor of Science in Development Communication',
        'devcom' => 'Bachelor of Science in Development Communication',
        'bs devcom' => 'Bachelor of Science in Development Communication',
        'business administration' => 'Bachelor of Science in Business Administration',
        'bsba' => 'Bachelor of Science in Business Administration',
        'entrepreneurship' => 'Bachelor of Science in Entrepreneurship',
        'bsentrep' => 'Bachelor of Science in Entrepreneurship',
        'bs entrepreneurship' => 'Bachelor of Science in Entrepreneurship',
        'agribusiness' => 'Bachelor of Science in Agribusiness',
        'bsab' => 'Bachelor of Science in Agribusiness',
        'bs agribusiness' => 'Bachelor of Science in Agribusiness',
        'tourism management' => 'Bachelor of Science in Tourism Management',
        'bs tourism' => 'Bachelor of Science in Tourism Management',
        'bstm' => 'Bachelor of Science in Tourism Management',
        'beed' => 'Bachelor of Elementary Education (BEEd)',
        'elementary education' => 'Bachelor of Elementary Education (BEEd)',
        'beced' => 'Bachelor of Elementary Childhood Education (BECEd)',
        'elementary childhood education' => 'Bachelor of Elementary Childhood Education (BECEd)',
        'bsed' => 'Bachelor of Secondary Education (BSEd)',
        'secondary education' => 'Bachelor of Secondary Education (BSEd)',
        'btled' => 'Bachelor of Technology and Livelihood Education (BTLEd)',
        'technology and livelihood education' => 'Bachelor of Technology and Livelihood Education (BTLEd)',
        'exercise and sports science' => 'Bachelor of Science in Exercise and Sports Science',
        'sports science' => 'Bachelor of Science in Exercise and Sports Science',
        'bsess' => 'Bachelor of Science in Exercise and Sports Science',
        'agricultural and biosystems engineering' => 'Bachelor of Science in Agricultural & Biosystems Engineering',
        'agricultural biosystems engineering' => 'Bachelor of Science in Agricultural & Biosystems Engineering',
        'bsae' => 'Bachelor of Science in Agricultural & Biosystems Engineering',
        'bsabe' => 'Bachelor of Science in Agricultural & Biosystems Engineering',
        'geodetic engineering' => 'Bachelor of Science in Geodetic Engineering',
        'bsge' => 'Bachelor of Science in Geodetic Engineering',
        'information technology' => 'Bachelor of Science in Information Technology',
        'bsit' => 'Bachelor of Science in Information Technology',
        'dvm' => 'Doctor of Veterinary Medicine',
        'doctor veterinary medicine' => 'Doctor of Veterinary Medicine',
    ];
}

function academicMajorAliases() {
    return [
        'fm' => 'Financial Management',
        'financial management' => 'Financial Management',
        'hrm' => 'Human Resource Management',
        'human resources management' => 'Human Resource Management',
        'marketing' => 'Marketing Management',
        'marketing management' => 'Marketing Management',
        'math' => 'Mathematics',
        'mathematics' => 'Mathematics',
        'science' => 'Science',
        'home economics' => 'Home Economics',
        'he' => 'Home Economics',
        'ict' => 'Information Communication Technology',
        'information and communication technology' => 'Information Communication Technology',
        'information communication technology' => 'Information Communication Technology',
        'agri fishery arts' => 'Agri-Fishery Arts',
        'afa' => 'Agri-Fishery Arts',
        'na' => 'N/A',
        'n a' => 'N/A',
        'none' => 'N/A',
        'no major' => 'N/A',
    ];
}

function academicBestCanonicalMatch($value, array $canonicalValues, array $aliases = []) {
    $key = academicLookupKey($value);
    if ($key === '') {
        return null;
    }

    $lookup = [];
    foreach ($canonicalValues as $canonicalValue) {
        $lookup[academicLookupKey($canonicalValue)] = $canonicalValue;
    }
    foreach ($aliases as $alias => $canonicalValue) {
        $lookup[academicLookupKey($alias)] = $canonicalValue;
    }
    if (isset($lookup[$key])) {
        return $lookup[$key];
    }

    if (strlen($key) < 5) {
        return null;
    }

    $scoresByCanonical = [];
    foreach ($lookup as $candidateKey => $canonicalValue) {
        similar_text($key, $candidateKey, $score);
        $scoresByCanonical[$canonicalValue] = max($scoresByCanonical[$canonicalValue] ?? 0, $score);
    }
    arsort($scoresByCanonical, SORT_NUMERIC);
    $rankedValues = array_keys($scoresByCanonical);
    $rankedScores = array_values($scoresByCanonical);
    $bestValue = $rankedValues[0] ?? null;
    $bestScore = (float) ($rankedScores[0] ?? 0);
    $secondScore = (float) ($rankedScores[1] ?? 0);

    return $bestScore >= 82 && ($bestScore - $secondScore) >= 5 ? $bestValue : null;
}

function normalizeAcademicYearSection($value) {
    $value = strtoupper(trim((string) $value));
    if (preg_match('/\b([1-4])(?:ST|ND|RD|TH)?(?:\s*YEAR)?[\s_-]*([A-F])\b/', $value, $matches)) {
        return $matches[1] . $matches[2];
    }
    return null;
}

function canonicalAcademicCollege($college) {
    return academicBestCanonicalMatch(
        $college,
        array_column(getCollegeCourseData(), 'college'),
        academicCollegeAliases()
    );
}

function canonicalAcademicCourse($course) {
    $courseNames = [];
    $courseCollege = [];
    foreach (getCollegeCourseData() as $collegeItem) {
        foreach ($collegeItem['courses'] as $courseItem) {
            $courseNames[] = $courseItem['name'];
            $courseCollege[$courseItem['name']] = $collegeItem['college'];
        }
    }

    $canonicalCourse = academicBestCanonicalMatch($course, $courseNames, academicCourseAliases());
    return $canonicalCourse ? [
        'course' => $canonicalCourse,
        'college' => $courseCollege[$canonicalCourse] ?? null,
    ] : null;
}

function canonicalizeAcademicData($college, $course, $major = 'N/A', $yearSection = '') {
    $courseMatch = canonicalAcademicCourse($course);
    $canonicalCourse = $courseMatch['course'] ?? null;
    $canonicalCollege = $courseMatch['college'] ?? canonicalAcademicCollege($college);
    if (!$canonicalCourse || !$canonicalCollege) {
        return ['resolved' => false, 'college' => null, 'course' => null, 'major' => null, 'year_section' => null];
    }

    $courseItem = findCollegeCourse($canonicalCollege, $canonicalCourse);
    if (!$courseItem) {
        return ['resolved' => false, 'college' => null, 'course' => null, 'major' => null, 'year_section' => null];
    }

    if (empty($courseItem['majors'])) {
        $canonicalMajor = 'N/A';
    } else {
        $canonicalMajor = academicBestCanonicalMatch($major, array_merge($courseItem['majors'], ['N/A']), academicMajorAliases());
        if (!$canonicalMajor || !validateCollegeCourseMajor($canonicalCollege, $canonicalCourse, $canonicalMajor)) {
            return ['resolved' => false, 'college' => null, 'course' => null, 'major' => null, 'year_section' => null];
        }
    }

    $canonicalYearSection = trim((string) $yearSection) === '' ? '' : normalizeAcademicYearSection($yearSection);
    if (trim((string) $yearSection) !== '' && !$canonicalYearSection) {
        return ['resolved' => false, 'college' => null, 'course' => null, 'major' => null, 'year_section' => null];
    }

    return [
        'resolved' => true,
        'college' => $canonicalCollege,
        'course' => $canonicalCourse,
        'major' => $canonicalMajor,
        'year_section' => $canonicalYearSection,
    ];
}
