<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once './conn/conn.php';
require_once './include/user-permissions.php';
require_once './include/attendance-settings.php';
require_once './include/automatic-sectioning.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator'], true)) {
    header('Location: index.php');
    exit();
}

$role = $currentUser['role'] ?? '';
$isSuperAdmin = $role === 'super_admin';
$managedComponent = $role === 'coordinator' ? normalizeProgram($currentUser['program'] ?? null) : null;
$canManageSectioning = $isSuperAdmin || ($managedComponent && in_array($managedComponent, autoSectionComponentOptions(), true));
$visibleSectionComponents = $isSuperAdmin ? autoSectionComponentOptions() : array_filter([$managedComponent]);
$componentChangeEnabled = isStudentComponentChangeEnabled($conn);
$openComponents = getOpenStudentComponents($conn);
$openRotcLevels = getOpenRotcMsLevels($conn);
$scanRestrictionEnabled = isFacilitatorScanRestrictionEnabled($conn);
$sectionMax = getAutoSectionMaxStudents($conn, $managedComponent);
$sectionMin = getAutoSectionMinStudents($conn, $managedComponent);
$sectionGrouping = getAutoSectionGroupingMode($conn, $managedComponent);
$collegeOptions = autoSectionCollegeOptions();
$collegeGroups = getAutoSectionCollegeGroups($conn, $managedComponent);
$enabledSectionComponents = getEnabledAutoSectionComponents($conn);
$selectedComponentCount = 0;

if ($isSuperAdmin) {
    try {
        $countStmt = $conn->prepare("
            SELECT COUNT(DISTINCT u.user_id)
            FROM tbl_users u
            LEFT JOIN tbl_public_student_registrations r
              ON r.user_id = u.user_id
              OR (u.username REGEXP '^[0-9]{10}$' AND r.student_number = u.username)
            WHERE u.role = 'student'
              AND (u.program IS NOT NULL OR (r.component IS NOT NULL AND r.component <> ''))
        ");
        $countStmt->execute();
        $selectedComponentCount = (int) $countStmt->fetchColumn();
    } catch (Throwable $error) {
        $selectedComponentCount = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Assignment Settings - TAU-NSTP</title>
    <?php include './include/theme-loader.php'; ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .assignment-grid{display:grid;grid-template-columns:minmax(340px,5fr) minmax(0,7fr);gap:16px;align-items:start}
        .component-panel{grid-column:1;grid-row:1/span 2}.scan-panel,.sectioning-panel{grid-column:2}
        .assignment-grid.single{grid-template-columns:1fr}.assignment-grid.single .scan-panel,.assignment-grid.single .sectioning-panel{grid-column:1}
        .settings-card{margin:0;border-top:3px solid #0d6efd}.setting-summary{display:flex;align-items:center;gap:14px;padding:16px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff}
        .setting-summary>i{width:44px;height:44px;flex:0 0 44px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#eef5ff;color:#0d6efd}
        .setting-row{display:flex;align-items:center;justify-content:space-between;padding:13px 15px;margin-bottom:9px;border:1px solid #dee2e6;border-radius:7px}
        .sectioning-card{overflow:hidden;border-top-color:#198754}.sectioning-card .card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;background:linear-gradient(135deg,#f5fbf7,#fff);border-bottom:1px solid #dce9e1}
        .sectioning-card .card-title{display:flex;align-items:center;flex-wrap:wrap;margin:0;font-weight:700;color:#173d2a}.sectioning-card .card-title>i{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;margin-right:10px!important;border-radius:9px;background:#dff3e7;color:#198754}
        .sectioning-card .card-body{padding:18px}.sectioning-scope{display:flex;align-items:flex-start;gap:12px;margin:0 0 18px;padding:13px 15px;border:1px solid #cce8d6;border-radius:10px;background:#eff9f3;color:#285b3f}.sectioning-scope>i{margin-top:2px;font-size:1.05rem}.sectioning-scope strong{display:block;font-size:.92rem}.sectioning-scope small{display:block;margin-top:2px;color:#5f7567}
        .sectioning-label{display:block;margin:0 0 9px;font-size:.78rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#5e6c64}.sectioning-components{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:18px}.sectioning-component{position:relative;margin:0;padding:0}.sectioning-component .custom-control-label{display:flex;align-items:center;min-height:54px;padding:10px 12px 10px 42px;border:1px solid #d9e2dc;border-radius:9px;background:#fff;cursor:pointer;transition:border-color .18s,background .18s,box-shadow .18s}.sectioning-component .custom-control-label:before,.sectioning-component .custom-control-label:after{left:14px;top:50%;transform:translateY(-50%)}.sectioning-component .custom-control-input:checked~.custom-control-label{border-color:#198754;background:#f1faf5;box-shadow:0 0 0 2px rgba(25,135,84,.08);color:#146c43}.sectioning-component-name{font-weight:700}.sectioning-component small{display:block;font-weight:400;color:#7a8880}
        .sectioning-controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px;border:1px solid #e0e6e2;border-radius:10px;background:#fafcfb}.sectioning-field{min-width:0}.sectioning-field label{margin-bottom:6px;font-size:.88rem;color:#344b3d}.sectioning-field label i{width:18px;color:#198754}.sectioning-field .form-control{height:42px;border-color:#cfdad3;border-radius:7px}.sectioning-field small{display:block;margin-top:5px;color:#7a8780}
        .college-grouping-panel{margin-top:12px;padding:15px;border:1px solid #d7e6dc;border-radius:10px;background:#f7fbf8}.college-grouping-heading{display:flex;align-items:flex-start;gap:10px;margin-bottom:12px}.college-grouping-heading>i{margin-top:3px;color:#198754}.college-grouping-heading strong{display:block;color:#285b3f}.college-grouping-heading small{display:block;color:#718078}.college-choice-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.college-choice{display:flex;align-items:flex-start;gap:8px;margin:0;padding:9px 10px;border:1px solid #e1e8e3;border-radius:8px;background:#fff;cursor:pointer}.college-choice input{margin-top:3px}.college-choice strong{display:block;font-size:.82rem;color:#344b3d}.college-choice small{display:block;color:#7a8780}.college-group-builder-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px}.college-group-list{display:grid;gap:8px;margin-top:12px}.college-group-card{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid #bcdcc8;border-radius:8px;background:#fff}.college-group-card strong{color:#285b3f}.college-solo-note{padding:10px 12px;border:1px dashed #ced9d1;border-radius:8px;color:#6f7d74;background:#fff}
        .sectioning-guide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:14px}.sectioning-step{display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;background:#f3f6f4;color:#5f6d64;font-size:.78rem;line-height:1.25}.sectioning-step span{display:inline-flex;align-items:center;justify-content:center;flex:0 0 23px;width:23px;height:23px;border-radius:50%;background:#dbe9e0;color:#146c43;font-weight:700}
        .sectioning-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid #e4e9e6}.sectioning-actions .btn{min-height:42px;margin:0!important;padding:9px 16px;border-radius:8px;font-weight:600}.sectioning-actions .btn-primary{background:#198754;border-color:#198754}.sectioning-actions .btn-primary:hover{background:#146c43;border-color:#146c43}.sectioning-actions .btn-outline-primary{color:#146c43;border-color:#198754}.sectioning-actions .btn-outline-primary:hover{color:#fff;background:#198754}
        .student-assignment-page .main-footer{min-height:0;padding:8px 14px!important;font-size:.76rem}.student-assignment-page .main-footer.super-admin-footer{padding:0!important}
        .student-assignment-page .super-admin-footer__inner{min-height:46px;padding:6px 14px;gap:12px;flex-wrap:wrap}.student-assignment-page .super-admin-footer__brand{flex:1 1 280px}.student-assignment-page .super-admin-footer__meta{flex-wrap:wrap}
        .student-assignment-page .main-footer.super-admin-footer .super-admin-footer__identity strong{color:#143d2a!important}.student-assignment-page .main-footer.super-admin-footer .super-admin-footer__identity>span,.student-assignment-page .main-footer.super-admin-footer .super-admin-footer__copyright{color:#708078!important}.student-assignment-page .main-footer.super-admin-footer .super-admin-footer__portal{color:#146c43!important}
        @media(max-width:991.98px){.assignment-grid{grid-template-columns:1fr}.component-panel,.scan-panel,.sectioning-panel{grid-column:1;grid-row:auto}}
        @media(max-width:767.98px){.sectioning-components,.sectioning-guide,.college-choice-grid{grid-template-columns:1fr}.sectioning-controls{grid-template-columns:1fr}.sectioning-actions{align-items:stretch;flex-direction:column-reverse}.sectioning-actions .btn{width:100%}}
        @media(max-width:575.98px){.setting-summary{align-items:flex-start;flex-wrap:wrap}.sectioning-card .card-header,.sectioning-card .card-body{padding:13px}.sectioning-card .card-title{font-size:1rem}.sectioning-scope{padding:11px 12px}.student-assignment-page .super-admin-footer__brand,.student-assignment-page .super-admin-footer__meta{flex-basis:100%;width:100%;justify-content:flex-start}}
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed student-assignment-page">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav"><li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a></li></ul>
        <ul class="navbar-nav ml-auto"><?php include './include/header-notifications.php'; ?></ul>
    </nav>
    <?php include 'adminlte-sidebar.php'; ?>
    <div class="content-wrapper">
        <div class="content-header"><div class="container-fluid"><div class="row mb-2"><div class="col-sm-6"><h1 class="m-0"><i class="fas fa-users-gear mr-2"></i>Student Assignment Settings</h1></div><div class="col-sm-6"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="index.php">Home</a></li><li class="breadcrumb-item active">Student Assignment Settings</li></ol></div></div></div></div>
        <section class="content"><div class="container-fluid"><div class="assignment-grid<?php echo $isSuperAdmin ? '' : ' single'; ?>">
            <?php if ($isSuperAdmin): ?>
            <div class="component-panel"><div class="card settings-card">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-layer-group mr-2"></i>Student Component Selection</h3></div>
                <div class="card-body">
                    <div class="setting-summary mb-3"><i class="fas fa-people-arrows"></i><div class="flex-fill"><strong>Allow all students one component change</strong><small class="text-muted d-block">Each student can save once per reopening.</small></div><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="componentChangeToggle" <?php echo $componentChangeEnabled?'checked':''; ?>><label class="custom-control-label font-weight-bold" id="componentChangeLabel" for="componentChangeToggle"><?php echo $componentChangeEnabled?'Enabled':'Disabled'; ?></label></div></div>
                    <div class="setting-summary mb-3"><i class="fas fa-toggle-on"></i><div><strong id="componentStatus"><?php echo count($openComponents); ?> component(s) open.</strong><small class="text-muted">Closed components do not appear on the public form.</small></div></div>
                    <?php foreach (['CWTS','LTS','ROTC'] as $component): $isOpen=in_array($component,$openComponents,true); ?>
                    <div class="setting-row"><strong><?php echo $component; ?></strong><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input component-toggle" id="component<?php echo $component; ?>" data-component="<?php echo $component; ?>" <?php echo $isOpen?'checked':''; ?>><label class="custom-control-label font-weight-bold" for="component<?php echo $component; ?>"><?php echo $isOpen?'Open':'Closed'; ?></label></div></div>
                    <?php endforeach; ?>
                    <div class="ml-3 pl-3 border-left"><small class="text-muted d-block mb-2">ROTC MS levels available for selection</small><?php foreach(getRotcMsLevels() as $level):$isOpen=in_array($level,$openRotcLevels,true);?><div class="setting-row py-2"><strong><?php echo htmlspecialchars($level); ?></strong><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input rotc-toggle" id="rotc<?php echo str_replace('-','',$level); ?>" data-level="<?php echo htmlspecialchars($level); ?>" <?php echo $isOpen?'checked':''; ?>><label class="custom-control-label" for="rotc<?php echo str_replace('-','',$level); ?>"><?php echo $isOpen?'Open':'Closed'; ?></label></div></div><?php endforeach;?></div>
                    <div class="mt-3 p-3 border rounded"><strong>Reset selected components</strong><small class="text-muted d-block mb-2"><?php echo $selectedComponentCount; ?> student account(s) currently selected.</small><button class="btn btn-outline-danger" type="button" id="resetComponents"><i class="fas fa-rotate-left mr-1"></i>Reset Components</button></div>
                </div>
            </div></div>
            <?php endif; ?>

            <div class="scan-panel"><div class="card settings-card"><div class="card-header"><h3 class="card-title"><i class="fas fa-qrcode mr-2"></i>Facilitator Scan Restriction</h3></div><div class="card-body"><div class="setting-summary"><i class="fas fa-user-shield"></i><div class="flex-fill"><strong id="scanStatus">Restriction is <?php echo $scanRestrictionEnabled?'active':'off'; ?>.</strong><small class="text-muted">Facilitators can scan only assigned students when active.</small></div><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="scanToggle" <?php echo $scanRestrictionEnabled?'checked':''; ?>><label class="custom-control-label font-weight-bold" for="scanToggle"><?php echo $scanRestrictionEnabled?'Active':'Off'; ?></label></div></div></div></div></div>

            <?php if($canManageSectioning): ?>
            <div class="sectioning-panel"><div class="card settings-card sectioning-card"><div class="card-header"><h3 class="card-title"><i class="fas fa-folder-tree"></i>Automatic Folder Sectioning<?php if($managedComponent):?><span class="badge badge-success ml-2"><?php echo htmlspecialchars($managedComponent); ?></span><?php endif;?></h3></div>
                <form id="sectioningForm"><div class="card-body">
                    <div class="sectioning-scope"><i class="fas fa-shield-halved"></i><div><strong><?php echo $isSuperAdmin?'All components access':'Component-limited access'; ?></strong><small><?php echo $isSuperAdmin?'Select which NSTP components will receive automatically generated sections.':'You can configure only the '.htmlspecialchars($managedComponent).' component assigned to you.'; ?></small></div></div>

                    <span class="sectioning-label">1. Select components to include</span>
                    <div class="sectioning-components">
                        <?php foreach($visibleSectionComponents as $component):?>
                        <div class="custom-control custom-checkbox sectioning-component">
                            <input type="checkbox" class="custom-control-input" id="section<?php echo $component;?>" name="section_components[]" value="<?php echo $component;?>" <?php echo in_array($component,$enabledSectionComponents,true)?'checked':'';?>>
                            <label class="custom-control-label" for="section<?php echo $component;?>"><span><span class="sectioning-component-name"><?php echo $component;?></span><small>Generate sections</small></span></label>
                        </div>
                        <?php endforeach;?>
                    </div>

                    <span class="sectioning-label">2. Set the section rules</span>
                    <div class="sectioning-controls">
                        <div class="sectioning-field"><label for="groupingMode"><i class="fas fa-layer-group"></i> Group students by</label><select class="form-control" name="grouping_mode" id="groupingMode"><?php foreach(autoSectionGroupingOptions() as $value=>$label):?><option value="<?php echo $value;?>" <?php echo $sectionGrouping===$value?'selected':'';?>><?php echo htmlspecialchars($label);?></option><?php endforeach;?></select><small>Students are grouped using this category first.</small></div>
                        <div class="sectioning-field"><label for="sectionMin"><i class="fas fa-user-check"></i> Minimum students per folder</label><select class="form-control" name="min_students" id="sectionMin"><?php foreach(autoSectionMinOptions() as $min):?><option value="<?php echo $min;?>" <?php echo $sectionMin===$min?'selected':'';?>><?php echo $min;?> students</option><?php endforeach;?></select><small>A completed folder should not fall below this number when enough students are available.</small></div>
                        <div class="sectioning-field"><label for="sectionMax"><i class="fas fa-users"></i> Target students per folder</label><select class="form-control" name="max_students" id="sectionMax"><?php foreach(autoSectionMaxOptions() as $max):?><option value="<?php echo $max;?>" <?php echo $sectionMax===$max?'selected':'';?>><?php echo $max;?> students</option><?php endforeach;?></select><small>Folders fill toward this target; a small remainder is redistributed to satisfy the minimum.</small></div>
                    </div>

                    <div class="college-grouping-panel" id="collegeGroupingPanel">
                        <div class="college-grouping-heading"><i class="fas fa-building-columns"></i><div><strong>User-selected college groups</strong><small>Select the exact colleges that should share folders, then create the group. Colleges outside a created group remain Solo.</small></div></div>
                        <div class="college-choice-grid">
                            <?php foreach($collegeOptions as $collegeCode=>$collegeName): ?>
                            <label class="college-choice"><input type="checkbox" class="college-group-choice" value="<?php echo htmlspecialchars($collegeCode);?>"><span><strong><?php echo htmlspecialchars($collegeCode);?></strong><small><?php echo htmlspecialchars($collegeName);?></small></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="college-group-builder-actions"><small class="text-muted">Select at least two colleges. A college can belong to only one group.</small><button type="button" class="btn btn-sm btn-success" id="createCollegeGroup"><i class="fas fa-link mr-1"></i>Group Selected Colleges</button></div>
                        <div class="college-group-list" id="collegeGroupList"></div>
                        <div id="collegeGroupInputs"></div>
                    </div>

                    <div class="sectioning-guide" aria-label="Sectioning process">
                        <div class="sectioning-step"><span>1</span>Separate by NSTP component</div>
                        <div class="sectioning-step"><span>2</span>Apply college mixing rules</div>
                        <div class="sectioning-step"><span>3</span>Fill each folder to the target</div>
                    </div>

                    <div class="sectioning-actions"><button class="btn btn-outline-primary" type="button" id="rebuildSections"><i class="fas fa-sync-alt mr-1"></i>Rebuild Existing Sections</button><button class="btn btn-primary" id="saveSectioning"><i class="fas fa-save mr-1"></i>Save Settings</button></div>
                </div></form>
            </div></div>
            <?php endif; ?>
        </div></div></section>
    </div>
    <?php if ($role === 'super_admin') include 'footer.php'; ?>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){
 const toggleCollegeGrouping=()=>$('#collegeGroupingPanel').toggle($('#groupingMode').val()==='college_course');
 $('#groupingMode').on('change',toggleCollegeGrouping);toggleCollegeGrouping();
 const syncMinimumOptions=()=>{const max=Number($('#sectionMax').val()||0),minimum=$('#sectionMin');minimum.find('option').each(function(){$(this).prop('disabled',Number(this.value)>max)});if(Number(minimum.val())>max){minimum.val(minimum.find('option:not(:disabled)').last().val())}};
 $('#sectionMax').on('change',syncMinimumOptions);syncMinimumOptions();
 const collegeLabels=<?php echo json_encode($collegeOptions, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);?>;
 let collegeGroupState=<?php echo json_encode($collegeGroups, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);?>;
 const cleanCollegeGroups=()=>{const members={};Object.keys(collegeLabels).forEach(code=>{const group=collegeGroupState[code]||code;if(group!==code)(members[group]||(members[group]=[])).push(code)});Object.keys(members).forEach(group=>{if(members[group].length<2)members[group].forEach(code=>collegeGroupState[code]=code)})};
 const renderCollegeGroups=()=>{cleanCollegeGroups();const groups={};const solo=[];const inputs=$('#collegeGroupInputs').empty(),list=$('#collegeGroupList').empty();Object.keys(collegeLabels).forEach(code=>{const group=collegeGroupState[code]||code;$('<input>',{type:'hidden',name:`college_groups[${code}]`,value:group}).appendTo(inputs);if(group===code)solo.push(code);else(groups[group]||(groups[group]=[])).push(code)});Object.keys(groups).forEach(group=>{const members=groups[group];const card=$('<div>',{class:'college-group-card'});$('<strong>').text(members.join(' + ')).appendTo(card);$('<button>',{type:'button',class:'btn btn-sm btn-outline-danger dissolve-college-group','data-group':group,html:'<i class="fas fa-unlink mr-1"></i>Dissolve'}).appendTo(card);list.append(card)});$('<div>',{class:'college-solo-note'}).text('Solo: '+(solo.join(', ')||'None')).appendTo(list)};
 const nextCollegeGroupToken=()=>{const used=new Set(Object.values(collegeGroupState));for(let i=1;i<=Object.keys(collegeLabels).length;i++){const token='G'+i;if(!used.has(token))return token}return 'G'+Date.now()};
 $('#createCollegeGroup').on('click',function(){const selected=$('.college-group-choice:checked').map(function(){return this.value}).get();if(selected.length<2){Swal.fire('Select colleges','Choose at least two colleges to create a group.','info');return}const token=nextCollegeGroupToken();selected.forEach(code=>collegeGroupState[code]=token);$('.college-group-choice').prop('checked',false);renderCollegeGroups()});
 $(document).on('click','.dissolve-college-group',function(){const group=$(this).data('group');Object.keys(collegeGroupState).forEach(code=>{if(collegeGroupState[code]===group)collegeGroupState[code]=code});renderCollegeGroups()});
 renderCollegeGroups();
 const request=(toggle,url,data,done,message)=>$.ajax({url:url,method:'POST',data:data,dataType:'json'}).done(r=>{if(r.success){done(r)}else{toggle.prop('checked',!toggle.is(':checked'));Swal.fire('Error',r.message||message,'error')}}).fail(()=>{toggle.prop('checked',!toggle.is(':checked'));Swal.fire('Error',message,'error')});
 $('#componentChangeToggle').on('change',function(){const t=$(this);request(t,'endpoint/toggle-student-component-change.php',{enabled:t.is(':checked')?1:0},r=>{$('#componentChangeLabel').text(r.enabled?'Enabled':'Disabled')},'Unable to update component changes.')});
 const sync=r=>{const components=r.open_components||[],levels=r.open_rotc_ms_levels||[];$('.component-toggle').each(function(){const open=components.includes($(this).data('component'));$(this).prop('checked',open).next().text(open?'Open':'Closed')});$('.rotc-toggle').each(function(){const open=levels.includes($(this).data('level'));$(this).prop('checked',open).next().text(open?'Open':'Closed')});$('#componentStatus').text(components.length+' component(s) open.')};
 $('.component-toggle').on('change',function(){const t=$(this);request(t,'endpoint/toggle-component-selection.php',{enabled:t.is(':checked')?1:0,component:t.data('component')},sync,'Unable to update component selection.')});
 $('.rotc-toggle').on('change',function(){const t=$(this);request(t,'endpoint/toggle-component-selection.php',{enabled:t.is(':checked')?1:0,component:'ROTC',rotc_ms_level:t.data('level')},sync,'Unable to update ROTC level.')});
 $('#scanToggle').on('change',function(){const t=$(this);request(t,'endpoint/toggle-facilitator-scan-restriction.php',{enabled:t.is(':checked')?1:0},r=>{t.next().text(r.enabled?'Active':'Off');$('#scanStatus').text('Restriction is '+(r.enabled?'active':'off')+'.')},'Unable to update scan restriction.')});
 $('#resetComponents').on('click',function(){Swal.fire({icon:'warning',title:'Reset selected components?',text:'Student component choices will be cleared.',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Reset'}).then(x=>{if(!x.isConfirmed)return;$.post('endpoint/reset-student-components.php').done(r=>{if(typeof r==='string')r=JSON.parse(r);Swal.fire(r.success?'Reset Complete':'Unable to reset',r.message,r.success?'success':'error').then(()=>{if(r.success)location.reload()})})})});
 $('#sectioningForm').on('submit',function(e){e.preventDefault();const b=$('#saveSectioning'),html=b.html();b.prop('disabled',true).html('Saving...');$.ajax({url:'endpoint/update-auto-section-settings.php',method:'POST',data:$(this).serialize(),dataType:'json'}).done(r=>Swal.fire(r.success?'Saved':'Unable to save',r.message,r.success?'success':'error')).fail(()=>Swal.fire('Error','Unable to save settings.','error')).always(()=>b.prop('disabled',false).html(html))});
 $('#rebuildSections').on('click',function(){const b=$(this),html=b.html(),components=$('[name="section_components[]"]:checked').map(function(){return this.value}).get().join(', ')||'none';Swal.fire({icon:'question',title:'Rebuild sections?',text:'Selected components: '+components+'. Existing manual assignments will be recalculated.',showCancelButton:true,confirmButtonText:'Rebuild'}).then(x=>{if(!x.isConfirmed)return;b.prop('disabled',true).html('Rebuilding...');$.ajax({url:'endpoint/rebuild-auto-section-folders.php',method:'POST',data:$('#sectioningForm').serialize(),dataType:'json'}).done(r=>Swal.fire(r.success?'Done':'Unable to rebuild',r.message,r.success?'success':'error')).fail(()=>Swal.fire('Error','Unable to rebuild sections.','error')).always(()=>b.prop('disabled',false).html(html))})});
});
</script>
<?php include './include/shared-data-sync.php'; ?>
</body></html>
