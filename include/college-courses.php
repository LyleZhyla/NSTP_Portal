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
