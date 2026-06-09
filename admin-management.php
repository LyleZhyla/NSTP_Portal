<?php
session_start();
include('./include/theme-loader.php');
include('./conn/conn.php');
require_once './include/user-permissions.php';
require_once './include/section-folders.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    header("Location: index.php");
    exit();
}
$isSuperAdmin = $currentUser['role'] === 'super_admin';
$currentProgram = normalizeProgram($currentUser['program'] ?? null);
syncSectionFoldersFromExisting($conn);

if ($isSuperAdmin) {
    $folderStmt = $conn->prepare("
        SELECT f.*,
               assigned.full_name AS facilitator_name,
               assigned.username AS facilitator_username,
               assigned.user_id AS facilitator_id
        FROM tbl_section_folders f
        LEFT JOIN tbl_admin_sections ads ON ads.course_section = f.course_section
        LEFT JOIN tbl_users assigned ON assigned.user_id = ads.user_id AND assigned.role = 'facilitator'
        ORDER BY FIELD(f.program, 'CWTS', 'LTS', 'ROTC'), f.course_section ASC
    ");
    $folderStmt->execute();
} else {
    $folderStmt = $conn->prepare("
        SELECT f.*,
               assigned.full_name AS facilitator_name,
               assigned.username AS facilitator_username,
               assigned.user_id AS facilitator_id
        FROM tbl_section_folders f
        LEFT JOIN tbl_admin_sections ads ON ads.course_section = f.course_section
        LEFT JOIN tbl_users assigned ON assigned.user_id = ads.user_id AND assigned.role = 'facilitator'
        WHERE f.program = ?
        ORDER BY f.course_section ASC
    ");
    $folderStmt->execute([$currentProgram]);
}
$sectionFolders = $folderStmt->fetchAll(PDO::FETCH_ASSOC);
$folderPrograms = [];
foreach ($sectionFolders as $folderRow) {
    $folderPrograms[$folderRow['program']] = true;
}

date_default_timezone_set('Asia/Manila');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management - TAU-NSTP </title>
      <?php include('./include/theme-loader.php'); ?>
    <!-- 🔥 TAB LOGO - NSTP LOGO 🔥 -->
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="shortcut icon" href="include/logo.png">
    <link rel="apple-touch-icon" href="include/logo.png">
    
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <!-- rest of your head content... -->
    <style>
        .admin-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #198754 0%, #0f5132 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            object-fit: cover;
        }
        .admin-avatar-img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .role-badge {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .section-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 10px;
            margin: 2px;
        }
        .section-list {
            max-height: 100px;
            overflow-y: auto;
        }
        .assigned-section-col {
            min-width: 150px;
        }
        .card-header .card-tools {
            position: absolute;
            right: 1rem;
            top: 1rem;
        }
        .modal-header .close {
            padding: 1rem;
            margin: -1rem -1rem -1rem auto;
        }
        .btn-xs {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1.5;
            border-radius: 0.2rem;
        }
        /* Profile picture in modals */
        .profile-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .profile-initials-lg {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #198754 0%, #0f5132 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php include './include/header-notifications.php'; ?>
        </ul>
    </nav>

    <!-- Sidebar -->
    <?php include 'adminlte-sidebar.php'; ?>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">
                            <i class="fas fa-users-cog mr-2"></i>User Management
                        </h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">User Management</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <section class="content">
            <div class="container-fluid">
                <!-- Admin Management Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-folder-tree mr-2"></i>
                                    <?php echo $isSuperAdmin ? 'Section Folders' : htmlspecialchars($currentProgram . ' Section Folders'); ?>
                                </h3>
                                <div class="card-tools">
                                    <a class="btn btn-success" href="masterlist.php">
                                        <i class="fas fa-folder-plus mr-2"></i>Create in Student Management
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($sectionFolders)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Program</th>
                                                    <th>Folder</th>
                                                    <th>Assigned Facilitator</th>
                                                    <th>Created</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($sectionFolders as $folder): ?>
                                                    <tr>
                                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars($folder['program']); ?></span></td>
                                                        <td><i class="fas fa-folder text-warning mr-1"></i><?php echo htmlspecialchars($folder['course_section']); ?></td>
                                                        <td>
                                                            <?php if (!empty($folder['facilitator_id'])): ?>
                                                                <span class="badge badge-success">
                                                                    <?php echo htmlspecialchars($folder['facilitator_name'] ?: $folder['facilitator_username']); ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge badge-secondary">No facilitator assigned</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime($folder['created_at']))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        No folders yet. Create folders in Student Management first, then assign facilitators to them here.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><?php echo $isSuperAdmin ? 'User Accounts' : htmlspecialchars($currentProgram . ' Facilitator Accounts'); ?></h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addAdminModal">
                                        <i class="fas fa-plus mr-2"></i><?php echo $isSuperAdmin ? 'Add User' : 'Add Facilitator'; ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="adminsTable" class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>User</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Program</th>
                                            <th class="assigned-section-col">Assigned Section(s)</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($isSuperAdmin) {
                                            $stmt = $conn->prepare("
                                                SELECT u.*, 
                                                       GROUP_CONCAT(DISTINCT a.course_section ORDER BY a.assigned_at) as assigned_sections_list
                                                FROM tbl_users u
                                                LEFT JOIN tbl_admin_sections a ON u.user_id = a.user_id
                                                GROUP BY u.user_id
                                                ORDER BY FIELD(u.role, 'super_admin', 'coordinator', 'facilitator', 'student'), u.program, u.created_at DESC
                                            ");
                                            $stmt->execute();
                                        } else {
                                            $stmt = $conn->prepare("
                                                SELECT u.*, 
                                                       GROUP_CONCAT(DISTINCT a.course_section ORDER BY a.assigned_at) as assigned_sections_list
                                                FROM tbl_users u
                                                LEFT JOIN tbl_admin_sections a ON u.user_id = a.user_id
                                                WHERE u.role = 'facilitator' AND u.program = ?
                                                GROUP BY u.user_id
                                                ORDER BY u.created_at DESC
                                            ");
                                            $stmt->execute([$currentProgram]);
                                        }
                                        $admins = $stmt->fetchAll();
                                        
                                        foreach ($admins as $admin):
                                            $initials = '';
                                            if (!empty($admin['full_name'])) {
                                                $nameParts = explode(' ', $admin['full_name']);
                                                $initials = strtoupper(substr($nameParts[0], 0, 1));
                                                if (isset($nameParts[1])) {
                                                    $initials .= strtoupper(substr($nameParts[1], 0, 1));
                                                }
                                            }
                                            $roleClass = $admin['role'] === 'super_admin' ? 'danger' : ($admin['role'] === 'coordinator' ? 'warning' : 'primary');
                                            $createdDate = new DateTime($admin['created_at']);
                                            $assignedSections = $admin['assigned_sections_list'] ? explode(',', $admin['assigned_sections_list']) : [];
                                            
                                            // Check if profile picture exists
                                            $hasProfilePic = !empty($admin['profile_picture']) && file_exists($admin['profile_picture']);
                                        ?>
                                        <tr>
                                            <td><?php echo $admin['user_id']; ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($hasProfilePic): ?>
                                                        <img src="<?php echo htmlspecialchars($admin['profile_picture']); ?>?v=<?php echo time(); ?>" 
                                                             alt="<?php echo htmlspecialchars($admin['full_name']); ?>" 
                                                             class="admin-avatar-img mr-3">
                                                    <?php else: ?>
                                                        <div class="admin-avatar mr-3">
                                                            <?php echo $initials; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($admin['full_name']); ?></strong>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $roleClass; ?> role-badge">
                                                    <?php echo ucfirst(str_replace('_', ' ', $admin['role'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($admin['program'])): ?>
                                                    <span class="badge badge-success"><?php echo htmlspecialchars($admin['program']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="assigned-section-col">
                                                <?php if (!empty($assignedSections)): ?>
                                                    <div class="section-list">
                                                        <?php foreach ($assignedSections as $section): ?>
                                                            <span class="badge badge-info section-badge" title="Assigned Section">
                                                                <?php echo htmlspecialchars($section); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php elseif ($admin['role'] === 'facilitator'): ?>
                                                    <span class="badge badge-secondary">Not Assigned</span>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $createdDate->format('M d, Y'); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-info edit-admin" 
                                                            data-id="<?php echo $admin['user_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($admin['full_name']); ?>"
                                                            data-username="<?php echo htmlspecialchars($admin['username']); ?>"
                                                            data-email="<?php echo htmlspecialchars($admin['email']); ?>"
                                                            data-role="<?php echo $admin['role']; ?>"
                                                            data-program="<?php echo htmlspecialchars($admin['program'] ?? ''); ?>"
                                                            data-profile="<?php echo $hasProfilePic ? htmlspecialchars($admin['profile_picture']) : ''; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-warning change-password" 
                                                            data-id="<?php echo $admin['user_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($admin['full_name']); ?>">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <?php if ($admin['role'] === 'facilitator'): ?>
                                                    <?php $hasProgramFolder = !empty($folderPrograms[$admin['program'] ?? '']); ?>
                                                    <button class="btn btn-sm btn-success assign-section" 
                                                            data-id="<?php echo $admin['user_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($admin['full_name']); ?>"
                                                            data-program="<?php echo htmlspecialchars($admin['program'] ?? ''); ?>"
                                                            title="<?php echo $hasProgramFolder ? 'Assign folder' : 'Create a folder for this program first'; ?>"
                                                            <?php echo $hasProgramFolder ? '' : 'disabled'; ?>>
                                                        <i class="fas fa-tasks"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($admin['user_id'] != $_SESSION['user_id'] && $admin['role'] != 'super_admin'): ?>
                                                    <button class="btn btn-sm btn-danger delete-admin" 
                                                            data-id="<?php echo $admin['user_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($admin['full_name']); ?>"
                                                            data-role="<?php echo htmlspecialchars($admin['role']); ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Footer -->
        <!-- Footer -->
    <?php include 'footer.php'; ?>
</div>

<!-- Add Admin Modal -->
<div class="modal fade" id="addAdminModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white">
                    <i class="fas fa-user-plus mr-2"></i>Add User
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="addAdminForm" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div id="addProfilePreviewContainer" class="d-none">
                            <img id="addProfilePreview" src="#" alt="Profile Preview" class="profile-preview">
                        </div>
                        <div id="addProfileInitialsContainer">
                            <div class="profile-initials-lg">AD</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_full_name">Full Name *</label>
                                <input type="text" class="form-control" id="add_full_name" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_username">Username *</label>
                                <input type="text" class="form-control" id="add_username" name="username" required>
                                <small class="form-text text-muted">Must be unique</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_email">Email Address *</label>
                                <input type="email" class="form-control" id="add_email" name="email" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_role">Role *</label>
                                <select class="form-control" id="add_role" name="role" required>
                                    <?php if ($isSuperAdmin): ?>
                                        <option value="facilitator">Facilitator</option>
                                        <option value="student">Student</option>
                                        <option value="coordinator">Coordinator</option>
                                        <option value="super_admin">Super Administrator</option>
                                    <?php else: ?>
                                        <option value="facilitator">Facilitator</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_program">Program</label>
                                <?php if ($isSuperAdmin): ?>
                                    <select class="form-control" id="add_program" name="program">
                                        <option value="">None / All Programs</option>
                                        <option value="CWTS">CWTS</option>
                                        <option value="LTS">LTS</option>
                                        <option value="ROTC">ROTC</option>
                                    </select>
                                <?php else: ?>
                                    <input type="text" class="form-control" id="add_program" name="program" value="<?php echo htmlspecialchars($currentProgram); ?>" readonly>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-key mr-1"></i>
                        A temporary password will be generated automatically and sent to the user's email address.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="add_profile_picture">Profile Picture (Optional)</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="add_profile_picture" name="profile_picture" accept="image/*">
                                    <label class="custom-file-label" for="add_profile_picture">Choose file</label>
                                </div>
                                <small class="form-text text-muted">Allowed: JPG, JPEG, PNG, GIF. Max 2MB.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i>Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Admin Modal -->
<div class="modal fade" id="editAdminModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title text-white">
                    <i class="fas fa-edit mr-2"></i>Edit User
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="editAdminForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="edit_user_id" name="user_id">
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div id="editProfilePreviewContainer">
                            <img id="editProfilePreview" src="#" alt="Profile Preview" class="profile-preview">
                        </div>
                        <div id="editProfileInitialsContainer" class="d-none">
                            <div class="profile-initials-lg"></div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_full_name">Full Name *</label>
                                <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_username">Username *</label>
                                <input type="text" class="form-control" id="edit_username" name="username" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_email">Email Address *</label>
                                <input type="email" class="form-control" id="edit_email" name="email" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_role">Role *</label>
                                <select class="form-control" id="edit_role" name="role" required>
                                    <?php if ($isSuperAdmin): ?>
                                        <option value="facilitator">Facilitator</option>
                                        <option value="student">Student</option>
                                        <option value="coordinator">Coordinator</option>
                                        <option value="super_admin">Super Administrator</option>
                                    <?php else: ?>
                                        <option value="facilitator">Facilitator</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_program">Program</label>
                                <?php if ($isSuperAdmin): ?>
                                    <select class="form-control" id="edit_program" name="program">
                                        <option value="">None / All Programs</option>
                                        <option value="CWTS">CWTS</option>
                                        <option value="LTS">LTS</option>
                                        <option value="ROTC">ROTC</option>
                                    </select>
                                <?php else: ?>
                                    <input type="text" class="form-control" id="edit_program" name="program" value="<?php echo htmlspecialchars($currentProgram); ?>" readonly>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Leave password fields blank to keep current password
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_password">New Password</label>
                                <input type="password" class="form-control" id="edit_password" name="password">
                                <small class="form-text text-muted">Minimum 8 characters</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_confirm_password">Confirm New Password</label>
                                <input type="password" class="form-control" id="edit_confirm_password" name="confirm_password">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="edit_profile_picture">Change Profile Picture</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="edit_profile_picture" name="profile_picture" accept="image/*">
                                    <label class="custom-file-label" for="edit_profile_picture">Choose new file</label>
                                </div>
                                <small class="form-text text-muted">Leave empty to keep current picture. Allowed: JPG, JPEG, PNG, GIF. Max 2MB.</small>
                            </div>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="remove_profile_picture" name="remove_profile_picture">
                                <label class="form-check-label text-danger" for="remove_profile_picture">
                                    <i class="fas fa-trash-alt mr-1"></i> Remove current profile picture
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-save mr-2"></i>Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title text-white">
                    <i class="fas fa-key mr-2"></i>Change Password
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="changePasswordForm" method="POST">
                <input type="hidden" id="password_user_id" name="user_id">
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <h5 id="passwordAdminName"></h5>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input type="password" class="form-control" id="new_password" name="password" required>
                        <small class="form-text text-muted">Minimum 8 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_new_password">Confirm New Password *</label>
                        <input type="password" class="form-control" id="confirm_new_password" name="confirm_password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key mr-2"></i>Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Folder Modal -->
<div class="modal fade" id="assignSectionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title text-white">
                    <i class="fas fa-tasks mr-2"></i>Assign Folder to Facilitator
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="assignSectionForm">
                <input type="hidden" id="assign_user_id" name="user_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="admin_name">Facilitator Name</label>
                                <input type="text" class="form-control" id="admin_name" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="course_section">Folder *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="course_section" name="course_section" 
                                           list="sectionSuggestions" required>
                                    <datalist id="sectionSuggestions"></datalist>
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" type="button" id="refreshSections">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Choose a folder that was created first. If needed, create folders from Student Management.</small>
                                <div id="sectionPresetButtons" class="mt-2"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Currently Assigned Folders:</label>
                                <div id="currentSections" class="mt-2">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        Loading assigned sections...
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check mr-2"></i>Assign Folder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bs-custom-file-input/dist/bs-custom-file-input.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize bs-custom-file-input for file upload styling
    bsCustomFileInput.init();
    
    // Initialize DataTable
    $('#adminsTable').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "order": [[0, 'desc']],
        "columnDefs": [
            { "orderable": false, "targets": [1, 8] }
        ]
    });
    
    // Profile picture preview for add modal
    $('#add_profile_picture').change(function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#addProfilePreview').attr('src', e.target.result);
                $('#addProfilePreviewContainer').removeClass('d-none');
                $('#addProfileInitialsContainer').addClass('d-none');
            }
            reader.readAsDataURL(file);
            
            // Update file input label
            $(this).next('.custom-file-label').html(file.name);
        }
    });
    
    // Profile picture preview for edit modal
    $('#edit_profile_picture').change(function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#editProfilePreview').attr('src', e.target.result);
                $('#editProfilePreviewContainer').removeClass('d-none');
                $('#editProfileInitialsContainer').addClass('d-none');
            }
            reader.readAsDataURL(file);
            
            // Update file input label
            $(this).next('.custom-file-label').html(file.name);
            
            // Uncheck remove picture checkbox
            $('#remove_profile_picture').prop('checked', false);
        }
    });
    
    // Handle remove profile picture checkbox
    $('#remove_profile_picture').change(function() {
        if ($(this).is(':checked')) {
            // Clear file input
            $('#edit_profile_picture').val('');
            $('#edit_profile_picture').next('.custom-file-label').html('Choose new file');
            
            // Show initials instead of image
            const initials = $('#edit_full_name').val().split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
            $('#editProfileInitialsContainer .profile-initials-lg').text(initials);
            $('#editProfilePreviewContainer').addClass('d-none');
            $('#editProfileInitialsContainer').removeClass('d-none');
        }
    });

    // Load available sections for datalist
    function sectionPresetsForProgram(program) {
        const normalized = String(program || '').toUpperCase();
        if (normalized === 'ROTC') {
            const companies = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'];
            const platoons = ['1st', '2nd', '3rd', '4th'];
            const sections = [];
            companies.forEach(company => platoons.forEach(platoon => sections.push(`${company} Company ${platoon} Platoon`)));
            return sections;
        }

        if (normalized === 'CWTS' || normalized === 'LTS') {
            const sections = [];
            ['1', '2'].forEach(year => ['A', 'B', 'C', 'D', 'E', 'F'].forEach(letter => sections.push(`${normalized} ${year}${letter}`)));
            return sections;
        }

        return [];
    }

    function renderSectionPresets(program) {
        const presets = sectionPresetsForProgram(program);
        const container = $('#sectionPresetButtons');
        container.empty();

        if (presets.length === 0) {
            return;
        }

        container.append('<div class="small text-muted mb-1">Quick presets</div>');
        presets.slice(0, 12).forEach(function(section) {
            container.append(`<button type="button" class="btn btn-xs btn-outline-info mr-1 mb-1 section-preset" data-section="${section}">${section}</button>`);
        });
    }

    function loadAvailableSections() {
        $.ajax({
            url: 'endpoint/get-all-sections.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.sections.length > 0) {
                    let datalist = $('#sectionSuggestions');
                    datalist.empty();
                    response.sections.forEach(function(section) {
                        datalist.append(`<option value="${section}">`);
                    });
                }
            },
            error: function() {
                console.log('Failed to load sections list');
            }
        });
    }
    
    // Handle assign section button click
    $(document).on('click', '.assign-section', function() {
        const userId = $(this).data('id');
        const userName = $(this).data('name');
        const program = $(this).data('program');
        
        $('#assign_user_id').val(userId);
        $('#admin_name').val(userName);
        renderSectionPresets(program);
        
        // Load available sections and current assignments
        loadAvailableSections();
        loadAdminSections(userId);
        
        $('#assignSectionModal').modal('show');
    });

    $(document).on('click', '.section-preset', function() {
        $('#course_section').val($(this).data('section')).focus();
    });
    
    // Refresh sections button
    $('#refreshSections').on('click', function() {
        loadAvailableSections();
        Swal.fire({
            icon: 'success',
            title: 'Refreshed!',
            text: 'Section list has been refreshed',
            timer: 1500,
            showConfirmButton: false
        });
    });
    
    // Load admin's assigned sections
    function loadAdminSections(userId) {
        $('#currentSections').html(`
            <div class="text-center py-3">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading assigned sections...</p>
            </div>
        `);
        
        $.ajax({
            url: 'endpoint/get-admin-sections.php',
            method: 'GET',
            data: { user_id: userId },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.sections.length > 0) {
                    let html = '<div class="list-group">';
                    response.sections.forEach(function(section, index) {
                        const assignedDate = new Date(section.assigned_at);
                        const formattedDate = assignedDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        
                        html += `
                            <div class="list-group-item ${index === 0 ? 'list-group-item-primary' : ''}">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center">
                                            <span class="badge badge-info mr-2">${section.course_section}</span>
                                            ${index === 0 ? '<span class="badge badge-success ml-2">Primary</span>' : ''}
                                        </div>
                                        <small class="text-muted d-block mt-1">
                                            <i class="fas fa-user mr-1"></i> Assigned by: ${section.assigned_by_fullname || section.assigned_by_name || 'System'}
                                            <br>
                                            <i class="fas fa-clock mr-1"></i> ${formattedDate}
                                        </small>
                                    </div>
                                    <button class="btn btn-sm btn-danger remove-assignment" 
                                            data-id="${section.admin_section_id}"
                                            data-section="${section.course_section}"
                                            title="Remove this section assignment">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    $('#currentSections').html(html);
                } else {
                    $('#currentSections').html(`
                        <div class="alert alert-warning">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-exclamation-circle fa-2x mr-3"></i>
                                <div>
                                    <h6 class="mb-1">No Sections Assigned</h6>
                                    <p class="mb-0">This admin doesn't have any sections assigned yet.</p>
                                </div>
                            </div>
                        </div>
                    `);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading sections:', error);
                $('#currentSections').html(`
                    <div class="alert alert-danger">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle fa-2x mr-3"></i>
                            <div>
                                <h6 class="mb-1">Failed to Load</h6>
                                <p class="mb-0">Could not load assigned sections. Please try again.</p>
                                <small class="text-muted">Error: ${error}</small>
                            </div>
                        </div>
                    </div>
                `);
            }
        });
    }
    
    // Handle assign folder form submission
    $('#assignSectionForm').on('submit', function(e) {
        e.preventDefault();
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Assigning...').prop('disabled', true);
        
        const formData = $(this).serialize();
        const userId = $('#assign_user_id').val();
        const sectionName = $('#course_section').val();
        
        $.ajax({
            url: 'endpoint/assign-section.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                submitBtn.html(originalText).prop('disabled', false);
                
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Folder Assigned!',
                        html: `
                            <div class="text-center">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5>${response.message}</h5>
                                <p class="text-muted">Folder: <strong>${sectionName}</strong></p>
                                <div class="alert alert-success small mt-3">
                                    <i class="fas fa-info-circle"></i>
                                    ${response.moved_students || 0} pending student(s) moved to this facilitator.
                                </div>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: 'OK',
                        timer: 4000
                    });
                    
                    $('#course_section').val('');
                    loadAdminSections(userId);
                    setTimeout(() => location.reload(), 2000);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        html: `
                            <div class="text-center">
                                <i class="fas fa-times-circle fa-3x text-danger mb-3"></i>
                                <h5>${response.message}</h5>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr, status, error) {
                submitBtn.html(originalText).prop('disabled', false);
                
                Swal.fire({
                    icon: 'error',
                    title: 'Request Failed!',
                    html: `
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                            <h5>Failed to assign section</h5>
                            <p>Please try again.</p>
                            <small class="text-muted">Error: ${error}</small>
                        </div>
                    `,
                    showConfirmButton: true,
                    confirmButtonText: 'OK'
                });
                
                console.error('Assign section error:', error);
            }
        });
    });
    
    // Handle remove assignment
    $(document).on('click', '.remove-assignment', function() {
        const assignmentId = $(this).data('id');
        const sectionName = $(this).data('section');
        const userId = $('#assign_user_id').val();
        
        Swal.fire({
            title: 'Remove Section Assignment?',
            html: `
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5>Are you sure?</h5>
                    <p>This will remove <strong>${sectionName}</strong> from this admin's assigned sections.</p>
                    <div class="alert alert-warning small">
                        <i class="fas fa-info-circle"></i>
                        Students already enrolled in this section will keep their current section.
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<i class="fas fa-trash mr-2"></i> Yes, remove it',
            cancelButtonText: '<i class="fas fa-times mr-2"></i> Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Removing Assignment...',
                    html: `
                        <div class="text-center">
                            <div class="spinner-border text-primary mb-3" role="status">
                                <span class="sr-only">Loading...</span>
                            </div>
                            <p>Please wait while we remove the section assignment...</p>
                        </div>
                    `,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => { Swal.showLoading(); }
                });
                
                $.ajax({
                    url: 'endpoint/remove-assignment.php',
                    method: 'POST',
                    data: { assignment_id: assignmentId },
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Assignment Removed!',
                                html: `
                                    <div class="text-center">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                        <h5>${response.message}</h5>
                                        <p class="text-muted">Section: <strong>${sectionName}</strong></p>
                                    </div>
                                `,
                                showConfirmButton: true,
                                confirmButtonText: 'OK',
                                timer: 3000
                            });
                            loadAdminSections(userId);
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message,
                                showConfirmButton: true,
                                confirmButtonText: 'OK'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Failed to Remove',
                            html: `
                                <div class="text-center">
                                    <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                                    <h5>Could not remove the assignment</h5>
                                    <p>Please try again.</p>
                                    <small class="text-muted">Error: ${error}</small>
                                </div>
                            `,
                            showConfirmButton: true,
                            confirmButtonText: 'OK'
                        });
                    }
                });
            }
        });
    });
    
    // Handle add admin form submission
    $('#addAdminForm').on('submit', function(e) {
        e.preventDefault();
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
        
        const formData = new FormData(this);
        
        $.ajax({
            url: 'endpoint/add-admin.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                submitBtn.html(originalText).prop('disabled', false);
                
                if (response.success) {
                    Swal.fire('Success', response.message, 'success');
                    $('#addAdminModal').modal('hide');
                    $('#addAdminForm')[0].reset();
                    $('#addProfilePreviewContainer').addClass('d-none');
                    $('#addProfileInitialsContainer').removeClass('d-none');
                    $('.custom-file-label').html('Choose file');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                submitBtn.html(originalText).prop('disabled', false);
                Swal.fire('Error', 'Failed to create admin. Please try again.', 'error');
                console.error('Add admin error:', error);
            }
        });
    });
    
    // Handle edit button click
    $('.edit-admin').on('click', function() {
        const userId = $(this).data('id');
        const userName = $(this).data('name');
        const username = $(this).data('username');
        const email = $(this).data('email');
        const role = $(this).data('role');
        const program = $(this).data('program');
        const profilePic = $(this).data('profile');
        
        $('#edit_user_id').val(userId);
        $('#edit_full_name').val(userName);
        $('#edit_username').val(username);
        $('#edit_email').val(email);
        $('#edit_role').val(role);
        $('#edit_program').val(program || '');
        
        // Clear password fields
        $('#edit_password').val('');
        $('#edit_confirm_password').val('');
        
        // Clear file input
        $('#edit_profile_picture').val('');
        $('#edit_profile_picture').next('.custom-file-label').html('Choose new file');
        $('#remove_profile_picture').prop('checked', false);
        
        // Handle profile picture display
        if (profilePic && profilePic.length > 0) {
            // Check if file exists by trying to load it
            const img = new Image();
            img.onload = function() {
                $('#editProfilePreview').attr('src', profilePic + '?v=' + new Date().getTime());
                $('#editProfilePreviewContainer').removeClass('d-none');
                $('#editProfileInitialsContainer').addClass('d-none');
            };
            img.onerror = function() {
                showInitialsInEdit(userName);
            };
            img.src = profilePic + '?v=' + new Date().getTime();
        } else {
            showInitialsInEdit(userName);
        }
        
        $('#editAdminModal').modal('show');
    });
    
    function showInitialsInEdit(fullName) {
        const initials = fullName.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
        $('#editProfileInitialsContainer .profile-initials-lg').text(initials);
        $('#editProfilePreviewContainer').addClass('d-none');
        $('#editProfileInitialsContainer').removeClass('d-none');
    }
    
    // Handle edit form submission
    $('#editAdminForm').on('submit', function(e) {
        e.preventDefault();
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
        
        const formData = new FormData(this);
        
        // Validate passwords if provided
        const password = $('#edit_password').val();
        const confirmPassword = $('#edit_confirm_password').val();
        
        if (password || confirmPassword) {
            if (password !== confirmPassword) {
                Swal.fire('Error', 'Passwords do not match!', 'error');
                submitBtn.html(originalText).prop('disabled', false);
                return;
            }
            
            if (password.length < 8) {
                Swal.fire('Error', 'Password must be at least 8 characters long!', 'error');
                submitBtn.html(originalText).prop('disabled', false);
                return;
            }
        }
        
        $.ajax({
            url: 'endpoint/edit-admin.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                submitBtn.html(originalText).prop('disabled', false);
                
                if (response.success) {
                    Swal.fire('Success', response.message, 'success');
                    $('#editAdminModal').modal('hide');
                    $('#editAdminForm')[0].reset();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                submitBtn.html(originalText).prop('disabled', false);
                Swal.fire('Error', 'Failed to update admin. Please try again.', 'error');
                console.error('Edit admin error:', error);
            }
        });
    });
    
    // Handle change password button click
    $('.change-password').on('click', function() {
        const userId = $(this).data('id');
        const userName = $(this).data('name');
        
        $('#password_user_id').val(userId);
        $('#passwordAdminName').text(userName);
        
        // Clear password fields
        $('#new_password').val('');
        $('#confirm_new_password').val('');
        
        $('#changePasswordModal').modal('show');
    });
    
    // Handle change password form submission
$('#changePasswordForm').on('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = $(this).find('button[type="submit"]');
    const originalText = submitBtn.html();
    submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Changing...').prop('disabled', true);
    
    const userId = $('#password_user_id').val();
    const password = $('#new_password').val();
    const confirmPassword = $('#confirm_new_password').val();
    
    // Validate passwords
    if (password !== confirmPassword) {
        Swal.fire('Error', 'Passwords do not match!', 'error');
        submitBtn.html(originalText).prop('disabled', false);
        return;
    }
    
    if (password.length < 8) {
        Swal.fire('Error', 'Password must be at least 8 characters long!', 'error');
        submitBtn.html(originalText).prop('disabled', false);
        return;
    }
    
    const formData = {
        user_id: userId,
        password: password,
        confirm_password: confirmPassword,
        password_only: 1 // Add this flag to indicate password-only update
    };
    
    $.ajax({
        url: 'endpoint/edit-admin.php', // Use the same edit-admin.php endpoint
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            submitBtn.html(originalText).prop('disabled', false);
            
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Password changed successfully!',
                    timer: 2000,
                    showConfirmButton: false
                });
                $('#changePasswordModal').modal('hide');
                $('#changePasswordForm')[0].reset();
            } else {
                Swal.fire('Error', response.message || 'Failed to change password.', 'error');
            }
        },
        error: function(xhr, status, error) {
            submitBtn.html(originalText).prop('disabled', false);
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            Swal.fire('Error', 'Failed to change password. Please try again.', 'error');
        }
    });
});     
    
    // Handle delete button click
    $('.delete-admin').on('click', function() {
        const userId = $(this).data('id');
        const userName = $(this).data('name');
        const userRole = String($(this).data('role') || 'user').replace('_', ' ');
        
        Swal.fire({
            title: 'Delete User?',
            html: `
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5>Are you sure?</h5>
                    <p>This will permanently delete <strong>${userName}</strong>.</p>
                    <div class="alert alert-danger small">
                        <i class="fas fa-exclamation-circle"></i>
                        This action cannot be undone!
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<i class="fas fa-trash mr-2"></i> Yes, delete it',
            cancelButtonText: '<i class="fas fa-times mr-2"></i> Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Deleting...',
                    html: `
                        <div class="text-center">
                            <div class="spinner-border text-danger mb-3" role="status">
                                <span class="sr-only">Loading...</span>
                            </div>
                            <p>Please wait while we delete the ${userRole} account...</p>
                        </div>
                    `,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => { Swal.showLoading(); }
                });
                
                $.ajax({
                    url: 'endpoint/delete-admin.php',
                    method: 'POST',
                    data: { user_id: userId },
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                html: `
                                    <div class="text-center">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                        <h5>${response.message}</h5>
                                    </div>
                                `,
                                showConfirmButton: true,
                                confirmButtonText: 'OK',
                                timer: 3000
                            });
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message,
                                showConfirmButton: true,
                                confirmButtonText: 'OK'
                            });
                        }
                    },
                    error: function() {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Failed to Delete',
                            text: `Failed to delete ${userRole} account. Please try again.`,
                            showConfirmButton: true,
                            confirmButtonText: 'OK'
                        });
                    }
                });
            }
        });
    });
    
    // Generate initials from full name in add modal
    $('#add_full_name').on('keyup', function() {
        const fullName = $(this).val();
        if (fullName) {
            const nameParts = fullName.split(' ');
            let initials = nameParts[0].charAt(0).toUpperCase();
            if (nameParts.length > 1) {
                initials += nameParts[1].charAt(0).toUpperCase();
            }
            $('#addProfileInitialsContainer .profile-initials-lg').text(initials);
        } else {
            $('#addProfileInitialsContainer .profile-initials-lg').text('AD');
        }
    });
    
    // Generate initials from full name in edit modal
    $('#edit_full_name').on('keyup', function() {
        const fullName = $(this).val();
        if (fullName && $('#editProfileInitialsContainer').is(':visible')) {
            const nameParts = fullName.split(' ');
            let initials = nameParts[0].charAt(0).toUpperCase();
            if (nameParts.length > 1) {
                initials += nameParts[1].charAt(0).toUpperCase();
            }
            $('#editProfileInitialsContainer .profile-initials-lg').text(initials);
        }
    });
});
</script>
</body>
</html>
