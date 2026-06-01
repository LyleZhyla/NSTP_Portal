<?php

function philippinesReligionOptions() {
    return [
        'Roman Catholic',
        'Islam',
        'Iglesia ni Cristo',
        'Evangelical Christian',
        'Born Again Christian',
        'Protestant',
        'Philippine Independent Church (Aglipayan)',
        'Seventh-day Adventist',
        'Jehovah\'s Witnesses',
        'The Church of Jesus Christ of Latter-day Saints',
        'Members Church of God International',
        'Jesus Is Lord Church',
        'United Church of Christ in the Philippines',
        'Baptist',
        'Methodist',
        'Presbyterian',
        'Lutheran',
        'Episcopal Church in the Philippines',
        'Orthodox Christian',
        'Church of Christ',
        'United Pentecostal Church',
        'Buddhism',
        'Hinduism',
        'Sikhism',
        'Judaism',
        'Baha\'i Faith',
        'Indigenous or Ancestral Belief',
        'No Religion',
        'Prefer not to say',
    ];
}

function isReligionAbbreviationOnly($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    $lettersOnly = preg_replace('/[^A-Za-z]/', '', $value);
    if ($lettersOnly === '') {
        return true;
    }

    $words = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    return strlen($lettersOnly) < 4
        || (count($words) === 1 && strtoupper($lettersOnly) === $lettersOnly && strlen($lettersOnly) <= 6);
}

function normalizeSubmittedReligion($selectedReligion, $otherReligion = '') {
    $selectedReligion = trim((string) $selectedReligion);
    $otherReligion = trim((string) $otherReligion);
    $options = philippinesReligionOptions();

    if ($selectedReligion === '' || strtoupper($selectedReligion) === 'N/A') {
        return 'N/A';
    }

    if ($selectedReligion === 'Others') {
        if ($otherReligion === '') {
            throw new InvalidArgumentException('Please type your religion when selecting Others.');
        }

        if (isReligionAbbreviationOnly($otherReligion)) {
            throw new InvalidArgumentException('Please enter the full religion name, not an abbreviation.');
        }

        return $otherReligion;
    }

    if (!in_array($selectedReligion, $options, true)) {
        throw new InvalidArgumentException('Please select a valid religion from the list, or choose Others.');
    }

    return $selectedReligion;
}
