<?php

function nstpEnrollmentEscape($value) {
    return htmlspecialchars(trim((string) ($value ?? '')), ENT_QUOTES, 'UTF-8');
}

function nstpEnrollmentText($value, $fallback = '') {
    $value = trim((string) ($value ?? ''));
    return nstpEnrollmentEscape($value !== '' && strtoupper($value) !== 'N/A' ? $value : $fallback);
}

function nstpEnrollmentFormatDate($value) {
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('F j, Y', $timestamp);
}

function nstpEnrollmentFullName(array $student) {
    $parts = [
        trim((string) ($student['first_name'] ?? '')),
        trim((string) ($student['middle_name'] ?? '')),
        trim((string) ($student['last_name'] ?? '')),
        trim((string) ($student['extension_name'] ?? '')),
    ];
    $name = trim(implode(' ', array_filter($parts, static fn($part) => $part !== '')));
    return $name !== '' ? $name : trim((string) ($student['student_name'] ?? ''));
}

function nstpEnrollmentCourse(array $student) {
    $parts = [];
    foreach (['course', 'year_section'] as $key) {
        $value = trim((string) ($student[$key] ?? ''));
        if ($value !== '' && strtoupper($value) !== 'N/A') {
            $parts[] = $value;
        }
    }
    return implode(' - ', $parts);
}

function nstpEnrollmentImageDataUri($path, $projectRoot) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    $projectRoot = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');
    $candidate = str_replace('\\', '/', $path);
    if (!preg_match('/^[A-Za-z]:\//', $candidate)) {
        $candidate = $projectRoot . '/' . ltrim($candidate, '/');
    }

    $realPath = realpath($candidate);
    if ($realPath === false) {
        return '';
    }
    $normalized = str_replace('\\', '/', $realPath);
    if ($projectRoot === '' || strpos($normalized, $projectRoot . '/') !== 0 || !is_file($realPath)) {
        return '';
    }

    $mime = function_exists('mime_content_type') ? mime_content_type($realPath) : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][$extension] ?? '';
    }
    if ($mime === '') {
        return '';
    }

    $contents = file_get_contents($realPath);
    return $contents === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($contents);
}

function nstpEnrollmentRenderHtml(array $students, array $coordinatorNames, $sealDataUri, $projectRoot) {
    $pages = '';
    $componentLabels = [
        'ROTC' => 'Reserved Officer Training Corps (ROTC)',
        'CWTS' => 'Civic Welfare Training Service (CWTS)',
        'LTS' => 'Literacy Training Service (LTS)',
    ];

    foreach ($students as $student) {
        $component = strtoupper(trim((string) ($student['component'] ?? '')));
        $fullName = nstpEnrollmentFullName($student);
        $photo = nstpEnrollmentImageDataUri($student['formal_picture'] ?? '', $projectRoot);
        $coordinatorName = trim((string) ($coordinatorNames[$component] ?? ''));
        $registrationDate = nstpEnrollmentFormatDate($student['registration_date'] ?? '');
        $course = nstpEnrollmentCourse($student);

        $componentOptions = '';
        foreach ($componentLabels as $key => $label) {
            $componentOptions .= '<div class="component-option"><span class="check-box">'
                . ($component === $key ? 'X' : '&nbsp;')
                . '</span><span>' . nstpEnrollmentEscape($label) . '</span></div>';
        }

        $photoHtml = $photo !== ''
            ? '<img src="' . $photo . '" alt="Student formal picture">'
            : '<span class="photo-placeholder">No photo available</span>';

        $pages .= '<section class="student-form">'
            . '<div class="university-header">'
            . '<img class="university-seal" src="' . nstpEnrollmentEscape($sealDataUri) . '" alt="TAU NSTP logo">'
            . '<div class="republic">Republic of the Philippines</div>'
            . '<div class="university-name">TARLAC AGRICULTURAL UNIVERSITY</div>'
            . '<div class="university-location">Camiling, Tarlac</div>'
            . '</div>'
            . '<div class="header-rule"></div>'
            . '<div class="program-banner">NATIONAL SERVICE TRAINING PROGRAM</div>'
            . '<div class="form-title">ENROLMENT FORM</div>'
            . '<table class="component-photo"><tr><td class="component-cell">'
            . '<div class="component-instruction">NSTP Program Components (Choose a program by putting a check mark in the box)</div>'
            . $componentOptions
            . '<div class="date-row"><span>Date:</span><span class="data-line">' . nstpEnrollmentEscape($registrationDate) . '</span></div>'
            . '</td><td class="photo-cell"><div class="photo-box">' . $photoHtml . '</div></td></tr></table>'
            . '<div class="section-title">STUDENT\'S PROFILE</div>'
            . '<table class="profile-grid">'
            . '<tr><td>Last Name: <span class="data-line">' . nstpEnrollmentText($student['last_name'] ?? '') . '</span></td><td>Extension (e.g. Jr./II/III): <span class="data-line short">' . nstpEnrollmentText($student['extension_name'] ?? '') . '</span></td></tr>'
            . '<tr><td>Given Name: <span class="data-line">' . nstpEnrollmentText($student['first_name'] ?? '') . '</span></td><td>Middle Name: <span class="data-line">' . nstpEnrollmentText($student['middle_name'] ?? '') . '</span></td></tr>'
            . '<tr><td>Gender: <span class="data-line medium">' . nstpEnrollmentText($student['gender'] ?? '') . '</span></td><td>Course: <span class="data-line course-data">' . nstpEnrollmentEscape($course) . '</span></td></tr>'
            . '<tr><td>Date of Birth: <span class="data-line">' . nstpEnrollmentEscape(nstpEnrollmentFormatDate($student['date_of_birth'] ?? '')) . '</span></td><td>Place of Birth: <span class="data-line">' . nstpEnrollmentText($student['place_of_birth'] ?? '') . '</span></td></tr>'
            . '<tr><td>Email Address: <span class="data-line">' . nstpEnrollmentText($student['email'] ?? '') . '</span></td><td>Contact No.: <span class="data-line">' . nstpEnrollmentText($student['contact_number'] ?? '') . '</span></td></tr>'
            . '</table>'
            . '<div class="address-title">PERMANENT ADDRESS</div>'
            . '<table class="address-grid"><tr>'
            . '<td>' . nstpEnrollmentText($student['house_no'] ?? '') . '<span>House No.</span></td>'
            . '<td>' . nstpEnrollmentText($student['street'] ?? '') . '<span>Street</span></td>'
            . '<td>' . nstpEnrollmentText($student['barangay'] ?? '') . '<span>Barangay</span></td>'
            . '<td>' . nstpEnrollmentText($student['city_municipality'] ?? '') . '<span>City/Municipality</span></td>'
            . '<td>' . nstpEnrollmentText($student['province'] ?? '') . '<span>Province</span></td>'
            . '</tr></table>'
            . '<div class="address-title temporary-title">TEMPORARY ADDRESS <span>(if boarding/staying at dormitory)</span></div>'
            . '<table class="address-grid"><tr>'
            . '<td>&nbsp;<span>House No.</span></td><td>&nbsp;<span>Street</span></td><td>&nbsp;<span>Barangay</span></td><td>&nbsp;<span>City/Municipality</span></td><td>&nbsp;<span>Province</span></td>'
            . '</tr></table>'
            . '<div class="applicant-signature"><div class="signature-name">' . nstpEnrollmentEscape($fullName) . '</div><div class="signature-caption">(Signature over Printed Name of Applicant)</div></div>'
            . '<div class="approval-grid"><div class="approval-column"><strong>Recommending Approval:</strong><div class="approval-name">' . nstpEnrollmentEscape($coordinatorName) . '</div><div class="approval-role">' . nstpEnrollmentEscape($component ?: 'NSTP') . ' Coordinator</div></div>'
            . '<div class="approval-column approved-column"><strong>Approved:</strong><div class="approval-name director">JOVEN D. VALDEZ, Ph. D</div><div class="approval-role">NSTP, Director</div></div></div>'
            . '<table class="form-footer"><tr><td>Form Code:</td><td>Revision No.:</td><td>Effectivity Date:</td><td>Page:</td></tr><tr><td>TAU-NSTP-QF-01</td><td>00</td><td>September 28, 2023</td><td>1 of 1</td></tr></table>'
            . '</section>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><style>'
        . '@page{size:A4 portrait;margin:0}*{box-sizing:border-box}body{margin:0;font-family:"DejaVu Serif",serif;color:#111;font-size:10.2pt}'
        . '.student-form{position:relative;width:186mm;height:283.5mm;padding:7mm 12mm 6mm;page-break-after:always;overflow:hidden}.student-form:last-child{page-break-after:auto}'
        . '.university-header{position:relative;height:25mm;margin:0 -12mm;background:#087b05;color:#fff;padding:3.2mm 12mm 2mm 45mm}.university-seal{position:absolute;left:12mm;top:-1.5mm;width:31mm;height:31mm;object-fit:contain}.republic{font-size:9.5pt;font-weight:bold}.university-name{font-size:18pt;font-weight:bold;letter-spacing:.2pt;line-height:1.05}.university-location{font-size:9.5pt}.header-rule{height:1.8mm;margin:1.1mm -12mm 1.5mm;border-top:1.1mm solid #087b05;border-bottom:.4mm solid #aaa}.program-banner{width:64%;margin:0 auto 2.2mm;border:1.6pt solid #888;border-radius:5pt;text-align:center;color:#777;font-size:13.5pt;font-weight:bold;padding:1.2mm}.form-title{text-align:center;font-size:13pt;font-weight:bold;margin:0 0 1mm}'
        . '.component-photo{width:100%;table-layout:fixed;border-collapse:collapse}.component-cell{width:77%;vertical-align:top;padding:0 2mm 0 0}.photo-cell{width:23%;vertical-align:top;text-align:right}.component-instruction{font-size:9.5pt;margin-bottom:1.6mm}.component-option{height:4.2mm;margin-left:5mm;white-space:nowrap}.check-box{display:inline-block;width:6mm;height:3.5mm;line-height:3.2mm;border:1pt solid #111;text-align:center;font:700 8pt sans-serif;margin-right:3mm}.photo-box{display:inline-block;width:31mm;height:40mm;border:1pt solid #111;text-align:center;vertical-align:top;overflow:hidden}.photo-box img{width:100%;height:100%}.photo-placeholder{display:block;margin-top:17mm;color:#777;font-size:7.5pt}.date-row{margin-top:2.5mm}.data-line{display:inline-block;min-width:53mm;border-bottom:.7pt solid #111;padding:0 .8mm .3mm;vertical-align:bottom;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.data-line.short{min-width:23mm;max-width:23mm}.data-line.medium{min-width:25mm;max-width:25mm}'
        . '.section-title{text-align:center;font-size:12pt;margin:1.3mm 0 1.2mm}.profile-grid{width:100%;border-collapse:separate;border-spacing:0 1.25mm}.profile-grid td{width:50%;vertical-align:bottom;white-space:nowrap}.profile-grid td:nth-child(2){padding-left:3mm}.profile-grid .data-line{max-width:54mm}.profile-grid .course-data{font-size:8pt}.address-title{font-size:10.5pt;margin-top:1.5mm}.address-title span{font-size:9pt}.address-grid{width:100%;table-layout:fixed;border-collapse:collapse;margin-top:.8mm}.address-grid td{border-bottom:.7pt solid #111;text-align:center;height:6.6mm;padding:0 .8mm;font-size:8.2pt;vertical-align:bottom}.address-grid td span{display:block;border-top:.7pt solid #111;margin:1.1mm -.8mm -5.2mm;padding-top:.4mm;font-size:8pt;white-space:nowrap}.temporary-title{margin-top:6.2mm}'
        . '.applicant-signature{width:43%;margin:10.5mm auto 0;text-align:center}.signature-name{border-bottom:.8pt solid #111;min-height:5.5mm;padding:0 1mm .5mm;font-size:9.5pt}.signature-caption{font-style:italic;margin-top:1.2mm;font-size:8.6pt}.approval-grid{position:absolute;left:12mm;top:235mm;width:186mm;height:27mm}.approval-column{position:absolute;left:0;top:0;width:45%;padding:0 3mm}.approved-column{left:52%;text-align:left}.approval-grid strong{font-size:10pt}.approval-name{width:56mm;border-bottom:.8pt solid #111;margin-top:5mm;min-height:5mm;padding-bottom:.5mm;text-align:center;font-size:9.5pt}.approved-column .approval-name{margin-left:auto;margin-right:auto}.approval-name.director{font-weight:bold}.approval-role{width:56mm;text-align:center;margin-top:1.2mm;font-size:8.5pt;font-style:italic}.approved-column .approval-role{margin-left:auto;margin-right:auto}'
        . '.form-footer{position:absolute;left:12mm;top:270mm;width:186mm;border-collapse:collapse;font-size:7.5pt}.form-footer td{border:.5pt solid #888;padding:.5mm 1.5mm}.form-footer td:nth-child(1){width:34%}.form-footer td:nth-child(2){width:21%}.form-footer td:nth-child(3){width:30%}.form-footer td:nth-child(4){width:15%}.form-footer tr:first-child td{font-style:italic}.form-footer tr:last-child td{text-align:right}'
        . '</style></head><body>' . $pages . '</body></html>';
}
