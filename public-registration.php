<?php
session_start();
require_once './conn/conn.php';
require_once './include/public-registration-forms.php';
require_once './include/college-courses.php';

if (isset($_SESSION['user_id'])) {
    $home = ($_SESSION['role'] ?? '') === 'student' ? 'student-dashboard.php' : 'index.php';
} else {
    $home = 'login.php';
}

$registrationForm = getPublicRegistrationForm($conn, $_GET['form'] ?? null);
if (!$registrationForm) {
    die('No active public registration form is available.');
}
$fields = $registrationForm['fields'];
$collegeCourseData = getCollegeCourseData();
$registrationRole = normalizePublicRegistrationRole($registrationForm['registration_role'] ?? 'student');
$isFacilitatorForm = $registrationRole === 'facilitator';
$enabledFieldCount = count(array_filter($fields));
$studentNumberBased = !$isFacilitatorForm && !empty($fields['student_number']) && $enabledFieldCount === 1;
$showNameFields = !empty($fields['name']);
$showEmailField = !empty($fields['email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Registration - TAU NSTP</title>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="include/public-registration.css">
</head>
<body>
    <header class="topbar">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="<?php echo htmlspecialchars($home); ?>" class="brand-mark">
                <img src="include/logo.png" alt="TAU NSTP Logo">
                <span>TAU NSTP Registration</span>
            </a>
            <a href="<?php echo htmlspecialchars($home); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-house"></i>
            </a>
        </div>
    </header>

    <main class="page-shell">
        <section class="intro">
            <h1><?php echo htmlspecialchars($registrationForm['form_title']); ?></h1>
            <p><?php echo $isFacilitatorForm ? 'Complete the information below to create your facilitator account.' : ($studentNumberBased ? 'Enter your student number to record attendance. If your student number is not yet registered, please complete the full registration form first.' : 'Complete the required information below. This submission will also count as your attendance for today.'); ?></p>
        </section>

        <div id="alertSlot" class="alert alert-slot" role="alert"></div>

        <form id="publicRegistrationForm" class="form-card" action="endpoint/submit-public-registration.php" method="POST" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="form_id" value="<?php echo (int) $registrationForm['form_id']; ?>">
            <input type="hidden" name="registrant_role" value="<?php echo htmlspecialchars($registrationRole); ?>">
            <?php if ($isFacilitatorForm || $showNameFields || $showEmailField || (!$isFacilitatorForm && ($fields['extension_name'] || $fields['middle_name'] || $fields['birth_info'] || $fields['religion']))): ?>
            <div class="form-block">
                <div class="section-title"><i class="fa-solid fa-user"></i> Personal Information</div>
                <div class="row g-3">
                    <?php if ($isFacilitatorForm || $showNameFields): ?>
                    <?php if ($isFacilitatorForm): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="full_name">Full Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                    </div>
                    <?php else: ?>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="last_name">Last Name <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="first_name">First Name <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$isFacilitatorForm && $fields['extension_name']): ?>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="extension_name">Extension Name</label>
                        <input type="text" class="form-control" id="extension_name" name="extension_name" placeholder="Jr., Sr., III">
                    </div>
                    <div class="col-lg-3 col-md-6 d-flex align-items-end">
                        <div class="form-check option-check">
                            <input class="form-check-input" type="checkbox" id="extension_name_na" name="extension_name_na" value="1">
                            <label class="form-check-label fw-semibold" for="extension_name_na">N/A - No Extension Name</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isFacilitatorForm && $fields['middle_name']): ?>
                    <div class="col-md-6 student-only-field">
                        <label class="form-label" for="middle_name">Middle Name <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="middle_name" name="middle_name" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    <div class="col-md-6 d-flex align-items-end student-only-field">
                        <div class="form-check option-check">
                            <input class="form-check-input" type="checkbox" id="middle_name_na" name="middle_name_na" value="1">
                            <label class="form-check-label fw-semibold" for="middle_name_na">N/A - No Middle Name</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isFacilitatorForm && $fields['birth_info']): ?>
                    <div class="col-md-6 student-only-field">
                        <label class="form-label" for="place_of_birth">Place of Birth <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="place_of_birth" name="place_of_birth" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    <div class="col-md-3 student-only-field">
                        <label class="form-label" for="date_of_birth">Date of Birth <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="date_of_birth" name="date_of_birth" placeholder="mm/dd/yyyy" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isFacilitatorForm && $fields['religion']): ?>
                    <div class="col-md-3 student-only-field">
                        <label class="form-label" for="religion">Religion <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="religion" name="religion" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    <?php endif; ?>
                    <?php if ($isFacilitatorForm || $showEmailField): ?>
                    <div class="col-md-3">
                        <label class="form-label" for="email">Email Address <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="email" class="form-control" id="email" name="email" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isFacilitatorForm && $fields['address']): ?>
            <div class="form-block student-only-block">
                <div class="section-title"><i class="fa-solid fa-location-dot"></i> Address</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="province">Province <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <select class="form-select" id="province" name="province" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                            <option value="">Select Province</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="city_municipality">City/Municipality <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <select class="form-select" id="city_municipality" name="city_municipality" <?php echo $studentNumberBased ? '' : 'required'; ?> disabled>
                            <option value="">Select City/Municipality</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="barangay">Barangay <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <select class="form-select" id="barangay" name="barangay" <?php echo $studentNumberBased ? '' : 'required'; ?> disabled>
                            <option value="">Select Barangay</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="house_no">House No. <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="house_no" name="house_no" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>
                    
                    <div class="col-md-8">
                        <label class="form-label" for="street">Street <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="street" name="street" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                    </div>

                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check option-check">
                            <input class="form-check-input" type="checkbox" id="house_no_na" name="house_no_na" value="1">
                            <label class="form-check-label fw-semibold" for="house_no_na">N/A - No House No.</label>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isFacilitatorForm && ($fields['student_number'] || $fields['course_section'] || $fields['formal_picture'])): ?>
            <div class="form-block">
                <div class="section-title"><i class="fa-solid fa-graduation-cap"></i> Academic Information</div>
                <div class="row g-3">
                    <?php if ($fields['student_number']): ?>
                    <div class="col-md-4 student-only-field">
                        <label class="form-label" for="student_number">Student Number <span class="required">*</span></label>
                        <input type="text" class="form-control" id="student_number" name="student_number" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" required>
                    </div>
                    <?php endif; ?>
                    <?php if ($fields['course_section']): ?>
                    <div class="col-md-4 student-only-field">
                        <label class="form-label" for="college">College <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <select class="form-select" id="college" name="college" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                            <option value="">Select College</option>
                            <?php foreach ($collegeCourseData as $collegeItem): ?>
                                <option value="<?php echo htmlspecialchars($collegeItem['college']); ?>"><?php echo htmlspecialchars($collegeItem['college']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 student-only-field">
                        <label class="form-label" for="course">Course <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <select class="form-select" id="course" name="course" <?php echo $studentNumberBased ? '' : 'required'; ?> disabled>
                            <option value="">Select Course</option>
                        </select>
                    </div>
                    <div class="col-md-4 student-only-field">
                        <label class="form-label" for="major">Major <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <select class="form-select" id="major" name="major" <?php echo $studentNumberBased ? '' : 'required'; ?> disabled>
                            <option value="">Select Major</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end student-only-field">
                        <div class="form-check option-check">
                            <input class="form-check-input" type="checkbox" id="major_na" name="major_na" value="1">
                            <label class="form-check-label fw-semibold" for="major_na">N/A - No Major</label>
                        </div>
                    </div>
                    <div class="col-md-4 student-only-field">
                        <label class="form-label" for="year_section">Year and Section <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <select class="form-select" id="year_section" name="year_section" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                            <option value="">Select Year and Section</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if ($fields['formal_picture']): ?>
                    <div class="col-12 student-only-field">
                        <label class="form-label" for="formal_picture">Formal Picture with White Background <?php if (!$studentNumberBased): ?><span class="required">*</span><?php endif; ?></label>
                        <input type="file" class="form-control" id="formal_picture" name="formal_picture" accept="image/jpeg,image/png,image/webp" <?php echo $studentNumberBased ? '' : 'required'; ?>>
                        <div class="photo-note mt-2">
                            <i class="fa-solid fa-image me-1"></i>
                            Upload a clear formal photo. Accepted files: JPG, PNG, WEBP. Maximum size: 5MB.
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-block">
                <button type="submit" class="btn btn-primary btn-submit w-100" id="submitBtn">
                    <i class="fa-solid fa-paper-plane me-1"></i> <?php echo $studentNumberBased ? 'Record Attendance' : 'Submit Registration'; ?>
                </button>
            </div>
        </form>
    </main>

    <script>
        const activeFields = <?php echo json_encode($fields); ?>;
        const registrationRole = <?php echo json_encode($registrationRole); ?>;
        const studentNumberBased = <?php echo $studentNumberBased ? 'true' : 'false'; ?>;
        const collegeCourseData = <?php echo json_encode($collegeCourseData, JSON_UNESCAPED_UNICODE); ?>;
        const provinceSelect = document.getElementById('province');
        const citySelect = document.getElementById('city_municipality');
        const barangaySelect = document.getElementById('barangay');
        const extensionName = document.getElementById('extension_name');
        const extensionNameNA = document.getElementById('extension_name_na');
        const middleName = document.getElementById('middle_name');
        const middleNameNA = document.getElementById('middle_name_na');
        const houseNo = document.getElementById('house_no');
        const houseNoNA = document.getElementById('house_no_na');
        const studentNumber = document.getElementById('student_number');
        const collegeSelect = document.getElementById('college');
        const courseSelect = document.getElementById('course');
        const majorSelect = document.getElementById('major');
        const majorNA = document.getElementById('major_na');
        const form = document.getElementById('publicRegistrationForm');
        const alertSlot = document.getElementById('alertSlot');
        const submitBtn = document.getElementById('submitBtn');
        const baseRequiredFields = Array.from(form.querySelectorAll('[required]'));
        const studentRoleInputs = Array.from(document.querySelectorAll('.student-only-block input, .student-only-block select, .student-only-block textarea, .student-only-field input, .student-only-field select, .student-only-field textarea'));
        studentRoleInputs.forEach(input => {
            input.dataset.initialDisabled = input.disabled ? '1' : '0';
        });

        const fallbackAddress = {
            'Tarlac': {
                'Tarlac City': ['Aguso', 'Alvindia Segundo', 'Amucao', 'Armenia', 'Balanti', 'Balete', 'Binauganan', 'Bora', 'Buenavista', 'Central', 'Mapalacsiao', 'San Isidro', 'San Jose', 'San Manuel', 'San Miguel', 'San Nicolas', 'San Rafael', 'San Roque', 'San Sebastian', 'Tibag'],
                'Capas': ['Aranguren', 'Cristo Rey', 'Cutcut I', 'Cutcut II', 'Dolores', 'Lawy', 'Manga', 'O Donnell', 'Santa Lucia', 'Santo Domingo I', 'Santo Rosario'],
                'Concepcion': ['Alfonso', 'Balutu', 'Cafe', 'Calius Gueco', 'Corazon de Jesus', 'Lourdes', 'San Agustin', 'San Antonio', 'San Bartolome', 'San Nicolas Balas']
            }
        };

        function setOptions(select, items, placeholder) {
            select.innerHTML = `<option value="">${placeholder}</option>`;
            items.forEach(item => {
                const option = document.createElement('option');
                option.value = item.name || item;
                option.textContent = item.name || item;
                if (item.code) option.dataset.code = item.code;
                select.appendChild(option);
            });
            select.disabled = false;
        }

        async function fetchJson(url) {
            const response = await fetch(url, { cache: 'force-cache' });
            if (!response.ok) throw new Error('Address service unavailable');
            return response.json();
        }

        async function loadProvinces() {
            try {
                const provinces = await fetchJson('https://psgc.gitlab.io/api/provinces/');
                setOptions(provinceSelect, provinces.sort((a, b) => a.name.localeCompare(b.name)), 'Select Province');
            } catch (error) {
                setOptions(provinceSelect, Object.keys(fallbackAddress), 'Select Province');
            }
        }

        if (provinceSelect) provinceSelect.addEventListener('change', async () => {
            const option = provinceSelect.selectedOptions[0];
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            citySelect.disabled = true;
            barangaySelect.disabled = true;

            if (!provinceSelect.value) return;

            try {
                if (option.dataset.code) {
                    const cities = await fetchJson(`https://psgc.gitlab.io/api/provinces/${option.dataset.code}/cities-municipalities/`);
                    setOptions(citySelect, cities.sort((a, b) => a.name.localeCompare(b.name)), 'Select City/Municipality');
                    return;
                }
                setOptions(citySelect, Object.keys(fallbackAddress[provinceSelect.value] || {}), 'Select City/Municipality');
            } catch (error) {
                setOptions(citySelect, Object.keys(fallbackAddress[provinceSelect.value] || {}), 'Select City/Municipality');
            }
        });

        if (citySelect) citySelect.addEventListener('change', async () => {
            const option = citySelect.selectedOptions[0];
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            barangaySelect.disabled = true;

            if (!citySelect.value) return;

            try {
                if (option.dataset.code) {
                    const barangays = await fetchJson(`https://psgc.gitlab.io/api/cities-municipalities/${option.dataset.code}/barangays/`);
                    setOptions(barangaySelect, barangays.sort((a, b) => a.name.localeCompare(b.name)), 'Select Barangay');
                    return;
                }
                setOptions(barangaySelect, fallbackAddress[provinceSelect.value]?.[citySelect.value] || [], 'Select Barangay');
            } catch (error) {
                setOptions(barangaySelect, fallbackAddress[provinceSelect.value]?.[citySelect.value] || [], 'Select Barangay');
            }
        });

        if (extensionNameNA) extensionNameNA.addEventListener('change', () => {
            if (extensionNameNA.checked) {
                extensionName.value = 'N/A';
                extensionName.readOnly = true;
            } else {
                extensionName.value = '';
                extensionName.readOnly = false;
            }
        });

        if (middleNameNA) middleNameNA.addEventListener('change', () => {
            if (middleNameNA.checked) {
                middleName.value = 'N/A';
                middleName.readOnly = true;
            } else {
                middleName.value = '';
                middleName.readOnly = false;
            }
        });

        if (houseNoNA) houseNoNA.addEventListener('change', () => {
            if (houseNoNA.checked) {
                houseNo.value = 'N/A';
                houseNo.readOnly = true;
            } else {
                houseNo.value = '';
                houseNo.readOnly = false;
            }
        });

        if (studentNumber) studentNumber.addEventListener('input', () => {
            studentNumber.value = studentNumber.value.replace(/\D/g, '').slice(0, 10);
        });

        function resetSelect(select, placeholder, disabled = true) {
            if (!select) return;
            select.innerHTML = `<option value="">${placeholder}</option>`;
            select.disabled = disabled;
        }

        function fillCourses() {
            resetSelect(courseSelect, 'Select Course');
            resetSelect(majorSelect, 'Select Major');
            if (majorNA) majorNA.checked = false;

            const college = collegeCourseData.find(item => item.college === collegeSelect.value);
            if (!college) return;

            college.courses.forEach(course => {
                const option = document.createElement('option');
                option.value = course.name;
                option.textContent = course.name;
                courseSelect.appendChild(option);
            });
            courseSelect.disabled = false;
        }

        function fillMajors() {
            resetSelect(majorSelect, 'Select Major');
            if (majorNA) majorNA.checked = false;

            const college = collegeCourseData.find(item => item.college === collegeSelect.value);
            const course = college?.courses.find(item => item.name === courseSelect.value);
            if (!course) return;

            if (!course.majors.length) {
                majorSelect.innerHTML = '<option value="N/A">N/A</option>';
                majorSelect.value = 'N/A';
                majorSelect.disabled = false;
                if (majorNA) majorNA.checked = true;
                return;
            }

            course.majors.forEach(major => {
                const option = document.createElement('option');
                option.value = major;
                option.textContent = major;
                majorSelect.appendChild(option);
            });
            majorSelect.disabled = false;
        }

        if (collegeSelect) collegeSelect.addEventListener('change', fillCourses);
        if (courseSelect) courseSelect.addEventListener('change', fillMajors);
        if (majorNA) majorNA.addEventListener('change', () => {
            if (majorNA.checked) {
                majorSelect.innerHTML = '<option value="N/A">N/A</option>';
                majorSelect.value = 'N/A';
                majorSelect.disabled = false;
            } else {
                fillMajors();
            }
        });

        function showAlert(type, message) {
            alertSlot.className = `alert alert-${type}`;
            alertSlot.textContent = message;
            alertSlot.style.display = 'block';
            alertSlot.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function fillYearSections() {
            const letters = ['A', 'B', 'C', 'D', 'E', 'F'];
            const yearSelect = document.getElementById('year_section');
            for (let year = 1; year <= 4; year++) {
                letters.forEach(letter => {
                    const option = document.createElement('option');
                    option.value = `${year}${letter}`;
                    option.textContent = `${year}${letter}`;
                    yearSelect.appendChild(option);
                });
            }
        }

        function currentRegistrantRole() {
            return registrationRole;
        }

        function applyRegistrantRole() {
            const isFacilitator = currentRegistrantRole() === 'facilitator';
            document.querySelectorAll('.student-only-block, .student-only-field').forEach(element => {
                element.style.display = isFacilitator ? 'none' : '';
                element.querySelectorAll('input, select, textarea').forEach(input => {
                    input.disabled = isFacilitator || (input.dataset.initialDisabled === '1' && !input.value);
                    if (isFacilitator) input.required = false;
                });
            });

            baseRequiredFields.forEach(input => {
                if (!input.closest('.student-only-block') && !input.closest('.student-only-field')) {
                    input.required = true;
                } else if (!isFacilitator) {
                    input.required = true;
                }
            });

            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> ' + (studentNumberBased ? 'Record Attendance' : (isFacilitator ? 'Create Facilitator Account' : 'Submit Registration'));
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            alertSlot.style.display = 'none';

            const middle = middleName ? middleName.value.trim() : '';
            if (currentRegistrantRole() !== 'facilitator' && !studentNumberBased && middleName && !middleNameNA.checked && middle.length <= 1) {
                showAlert('danger', 'Middle Name must be more than one letter, or check N/A if there is no middle name.');
                middleName.focus();
                return;
            }

            if (currentRegistrantRole() !== 'facilitator' && studentNumber && !/^\d{10}$/.test(studentNumber.value.trim())) {
                showAlert('danger', 'Student Number must be exactly 10 digits.');
                studentNumber.focus();
                return;
            }

            if (currentRegistrantRole() !== 'facilitator' && !studentNumberBased && majorSelect && !majorSelect.value) {
                showAlert('danger', 'Please select a major, or check N/A if the course has no major.');
                majorSelect.focus();
                return;
            }

            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                showAlert('danger', 'Please complete all required fields correctly.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form)
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.message || 'Registration failed.');

                form.reset();
                form.classList.remove('was-validated');
                if (citySelect) citySelect.disabled = true;
                if (barangaySelect) barangaySelect.disabled = true;
                if (courseSelect) resetSelect(courseSelect, 'Select Course');
                if (majorSelect) resetSelect(majorSelect, 'Select Major');
                if (extensionName) extensionName.readOnly = false;
                if (middleName) middleName.readOnly = false;
                if (houseNo) houseNo.readOnly = false;
                showAlert('success', data.message || 'Registration submitted successfully.');
            } catch (error) {
                showAlert('danger', error.message || 'Unable to submit registration. Please try again.');
            } finally {
                submitBtn.disabled = false;
                applyRegistrantRole();
            }
        });

        if (document.getElementById('year_section')) fillYearSections();
        if (provinceSelect) loadProvinces();
        applyRegistrantRole();
    </script>
</body>
</html>
