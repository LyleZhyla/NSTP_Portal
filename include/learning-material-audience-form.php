<?php // Reusable component/MS selection for upload and existing materials. ?>
<fieldset class="material-audience mb-3">
    <legend class="h6 font-weight-bold">Visible to components</legend>
    <?php foreach (['CWTS', 'LTS', 'ROTC'] as $component): ?>
    <div class="form-check form-check-inline">
        <input class="form-check-input audience-component" type="checkbox" name="components[]" id="<?= materialEscape($audienceFormId . '-' . $component) ?>" value="<?= $component ?>" <?= in_array($component, $audienceComponents, true) ? 'checked' : '' ?>>
        <label class="form-check-label" for="<?= materialEscape($audienceFormId . '-' . $component) ?>"><?= $component ?></label>
    </div>
    <?php endforeach; ?>
    <small class="form-text text-muted">Select one or more. To share with CWTS and ROTC, check both.</small>
    <fieldset class="audience-rotc mt-3" <?= !in_array('ROTC', $audienceComponents, true) ? 'hidden disabled' : '' ?>>
        <legend class="h6 font-weight-bold">ROTC student MS levels</legend>
        <?php foreach (getRotcMsLevels() as $level): ?>
        <div class="form-check form-check-inline">
            <input class="form-check-input audience-level" type="checkbox" name="rotc_levels[]" id="<?= materialEscape($audienceFormId . '-' . $level) ?>" value="<?= $level ?>" <?= in_array($level, $audienceLevels, true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= materialEscape($audienceFormId . '-' . $level) ?>"><?= $level ?></label>
        </div>
        <?php endforeach; ?>
        <small class="form-text text-muted">Select one or more levels, or check all for all ROTC students. ROTC facilitators and coordinators can view all selected levels.</small>
    </fieldset>
</fieldset>
