<?php
// Expects:
// $is_edit (bool)
// $event_name, $start_date, $start_time_val, $end_time_val
// $recurrence, $recur_until, $recur_forever, $recur_days
// $selected_tag_ids, $tags
// $priority, $asset_id, $selected_asset_name
// $dashboard_state (array)
// $is_series (bool) - only for edit
// $event_id (int) - only for edit
?>

<div class="card">
    <form action="<?php echo $is_edit ? "edit_event.php?" . $_SERVER['QUERY_STRING'] : "create_event.php"; ?>"
        method="POST">

        <?php if ($is_edit): ?>
            <input type="hidden" name="action" value="update_event">
        <?php else: ?>
            <!-- Preserve Dashboard State for Create -->
            <input type="hidden" name="dashboard_view" value="<?php echo htmlspecialchars($dashboard_state['view']); ?>">
            <input type="hidden" name="dashboard_date" value="<?php echo htmlspecialchars($dashboard_state['date']); ?>">
            <input type="hidden" name="dashboard_page" value="<?php echo htmlspecialchars($dashboard_state['page']); ?>">
            <input type="hidden" name="dashboard_tag_id"
                value="<?php echo htmlspecialchars($dashboard_state['tag_id']); ?>">
            <input type="hidden" name="dashboard_hide_past"
                value="<?php echo htmlspecialchars($dashboard_state['hide_past']); ?>">
            <input type="hidden" name="dashboard_type" value="<?php echo htmlspecialchars($dashboard_state['type']); ?>">
        <?php endif; ?>

        <div class="form-group">
            <label>Event Name</label>
            <input type="text" name="event_name" value="<?php echo htmlspecialchars($event_name); ?>" required <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
        </div>

        <div class="form-group">
            <label>Start Date</label>
            <input type="date" name="start_date" id="start_date" value="<?php echo $start_date; ?>" required <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
        </div>

        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time" value="<?php echo $start_time_val; ?>" required <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" value="<?php echo $end_time_val; ?>" required <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Recurrence</label>
            <select name="recurrence" id="recurrence" onchange="toggleRecurrence()" <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                <option value="none" <?php if ($recurrence == 'none')
                    echo 'selected'; ?>>None (One-time)</option>
                <option value="daily" <?php if ($recurrence == 'daily')
                    echo 'selected'; ?>>Daily</option>
                <option value="weekly" <?php if ($recurrence == 'weekly')
                    echo 'selected'; ?>>Weekly</option>
            </select>
        </div>

        <div id="recur-options"
            style="display:none; padding:10px; background:#2a2a2a; margin-bottom:15px; border-radius:4px;">
            <div class="form-group">
                <label>Repeat Until</label>
                <div style="display:flex; align-items:center; gap:10px;">
                    <input type="date" name="recur_until" id="recur_until" value="<?php echo $recur_until; ?>" <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                    <label><input type="checkbox" name="recur_forever" id="recur_forever"
                            onchange="toggleRecurForever()" <?php if ($recur_forever)
                                echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Forever</label>
                </div>
            </div>

            <div class="form-group" id="recur-days-group" style="display:none;">
                <label>Repeat On (Weekly)</label>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <label><input type="checkbox" name="recur_days[]" value="0" <?php if (in_array(0, $recur_days))
                        echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Sun</label>
                    <label><input type="checkbox" name="recur_days[]" value="1" <?php if (in_array(1, $recur_days))
                        echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Mon</label>
                    <label><input type="checkbox" name="recur_days[]" value="2" <?php if (in_array(2, $recur_days))
                        echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Tue</label>
                    <label><input type="checkbox" name="recur_days[]" value="3" <?php if (in_array(3, $recur_days))
                        echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Wed</label>
                    <label><input type="checkbox" name="recur_days[]" value="4" <?php if (in_array(4, $recur_days))
                        echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Thu</label>
                    <label><input type="checkbox" name="recur_days[]" value="5" <?php if (in_array(5, $recur_days))
                        echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Fri</label>
                    <label><input type="checkbox" name="recur_days[]" value="6" <?php if (in_array(6, $recur_days))
                        echo 'checked'; ?> <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        Sat</label>
                </div>
            </div>
        </div>

        <script>
            // Trigger initial state
            document.addEventListener('DOMContentLoaded', function () {
                toggleRecurrence();
                toggleRecurForever();
            });
        </script>

        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Output Tags</label>
                    <div class="tag-button-group" id="eventTagButtons" style="display:flex; flex-wrap:wrap; gap:8px;">
                        <?php foreach ($tags as $tag): ?>
                            <button type="button"
                                class="tag-btn <?php echo in_array($tag['id'], $selected_tag_ids) ? 'active' : ''; ?>"
                                data-id="<?php echo $tag['id']; ?>"
                                onclick="<?php echo (isset($is_read_only) && $is_read_only) ? '' : 'toggleEventTag(this)'; ?>"
                                style="padding:6px 12px; background:<?php echo in_array($tag['id'], $selected_tag_ids) ? 'var(--accent-color)' : '#333'; ?>; border:1px solid <?php echo in_array($tag['id'], $selected_tag_ids) ? 'var(--accent-color)' : '#555'; ?>; color:<?php echo in_array($tag['id'], $selected_tag_ids) ? '#000' : '#ccc'; ?>; border-radius:20px; cursor:<?php echo (isset($is_read_only) && $is_read_only) ? 'default' : 'pointer'; ?>; font-size:0.9em; font-weight:<?php echo in_array($tag['id'], $selected_tag_ids) ? 'bold' : 'normal'; ?>;">
                                <?php echo htmlspecialchars($tag['tag_name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div id="eventTagInputs">
                        <?php foreach ($selected_tag_ids as $tid): ?>
                            <input type="hidden" name="tag_ids[]" value="<?php echo $tid; ?>">
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority" <?php echo (isset($is_read_only) && $is_read_only) ? 'disabled' : ''; ?>>
                        <option value="0" <?php if ($priority == 0)
                            echo 'selected'; ?>>Normal (Default)</option>
                        <option value="1" <?php if ($priority == 1)
                            echo 'selected'; ?>>Medium</option>
                        <option value="2" <?php if ($priority == 2)
                            echo 'selected'; ?>>High (Preempts others)</option>
                    </select>
                    <small style="color:#aaa; display:block; margin-top:5px;">Higher priority events will
                        interrupt lower priority events playing at the same time.</small>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Asset</label>
            <div style="display:flex; gap:10px;">
                <input type="hidden" name="existing_asset_id" id="selected_asset_id" value="<?php echo $asset_id; ?>">
                <input type="text" id="selected_asset_name" readonly
                    value="<?php echo htmlspecialchars($selected_asset_name); ?>"
                    style="flex:1; background:#333; border:1px solid #444; color:#fff; padding:8px;">
                <?php if (!isset($is_read_only) || !$is_read_only): ?>
                    <button type="button" class="btn btn-secondary" onclick="openAssetModal()">Select Asset</button>
                <?php endif; ?>
            </div>
            <div id="asset-warning" style="color:orange; display:none; margin-top:5px;"></div>
        </div>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:nowrap;">
            <?php if (!isset($is_read_only) || !$is_read_only): ?>
                <button type="submit" class="btn"
                    style="flex:1; white-space:nowrap;"><?php echo $is_edit ? 'Update Event' : 'Create Event'; ?></button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Asset Selection Modal -->
<div id="assetSelectionModal" class="modal">
    <div class="modal-content" style="width: 80%; max-width: 800px;">
        <span class="close" onclick="closeAssetModal()">&times;</span>
        <h2>Select Asset</h2>
        <div style="margin-bottom: 15px; display:flex; justify-content:space-between; gap:10px;">
            <input type="text" id="assetModalSearch" placeholder="Search assets..." onkeyup="filterAssetModal()"
                style="flex:1;">
            <select id="assetModalTagFilter" onchange="filterAssetModal()"
                style="flex:0 0 150px; padding: 5px; background: #333; color: #fff; border: 1px solid #444; border-radius: 4px;">
                <option value="">All Tags</option>
                <?php foreach ($tags as $tag): ?>
                    <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-sm" onclick="toggleAssetModalUpload()">+ Upload New</button>
        </div>

        <div id="assetModalUpload"
            style="display:none; margin-bottom:15px; padding:15px; background:#222; border-radius:4px;">
            <h3>Upload New Asset</h3>
            <input type="file" id="modalAssetFile" style="margin-bottom:10px;">
            <button type="button" class="btn btn-sm" onclick="uploadAssetInModal()">Upload & Select</button>
        </div>

        <div id="assetModalList"
            style="display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:10px; max-height:400px; overflow-y:auto;">
            <!-- JS populates this -->
        </div>
    </div>
</div>