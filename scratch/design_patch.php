<?php
$file = 'book_house.php';
$content = file_get_contents($file);

// 1. Inject CSS
$css_to_inject = '
        /* Custom Selection Grid */
        .selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
            margin-top: 8px;
            max-height: 220px;
            overflow-y: auto;
            padding: 4px;
        }
        .custom-card-checkbox {
            position: relative;
            display: block;
            cursor: pointer;
        }
        .custom-card-checkbox input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }
        .custom-card-checkbox .card-content {
            display: flex;
            flex-direction: column;
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            transition: all 0.2s ease;
        }
        .custom-card-checkbox:hover .card-content {
            border-color: #c4b5fd;
            background: #faf5ff;
        }
        .custom-card-checkbox input:checked ~ .card-content {
            border-color: #7c3aed;
            background: #f5f3ff;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.15);
        }
        .custom-card-checkbox input:disabled ~ .card-content {
            border-color: #cbd5e1;
            background: #f1f5f9;
            opacity: 0.6;
            cursor: not-allowed;
        }
        .custom-card-checkbox .card-title {
            font-weight: 800;
            font-size: 0.92rem;
            color: #1e293b;
            margin-bottom: 2px;
        }
        .custom-card-checkbox input:checked ~ .card-content .card-title {
            color: #5b21b6;
        }
        .custom-card-checkbox .card-price {
            font-size: 0.82rem;
            font-weight: 700;
            color: #64748b;
        }
        .custom-card-checkbox input:checked ~ .card-content .card-price {
            color: #7c3aed;
        }
        .custom-card-checkbox .check-icon {
            position: absolute;
            top: 12px;
            right: 12px;
            color: #7c3aed;
            opacity: 0;
            transform: scale(0.5);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .custom-card-checkbox input:checked ~ .card-content .check-icon {
            opacity: 1;
            transform: scale(1);
        }
        /* Custom scrollbar for grids */
        .selection-grid::-webkit-scrollbar {
            width: 6px;
        }
        .selection-grid::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        .selection-grid::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        .selection-grid::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
';
$content = str_replace('</style>', $css_to_inject, $content);

// 2. Replace "Add Additional Kubos" HTML
$old_kubos_html = '<div style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 0.85rem 1rem; max-height: 150px; overflow-y: auto;">
                                <?php 
                                $all_kubos = InventoryStore::getAllKubos();
                                foreach ($all_kubos as $k) {
                                    $kid = intval($k[\'kubo_id\'] ?? $k[\'id\']);
                                    if ($kid == $kubo_id) continue;
                                    $k_status = strtolower(trim($k[\'status\'] ?? \'available\'));
                                    if (in_array($k_status, [\'renovation\', \'maintenance\', \'unavailable\'])) continue;
                                    $k_price = floatval($k[\'price\'] ?? 3500);
                                    echo "<label style=\'display: flex; align-items: center; gap: 8px; margin-bottom: 0.5rem; cursor: pointer; font-size: 0.95rem; color: #0f172a;\'>";
                                    echo "<input type=\'checkbox\' name=\'additional_kubos[]\' value=\'{$kid}\' data-price=\'{$k_price}\' data-name=\'" . htmlspecialchars($k[\'name\'], ENT_QUOTES) . "\' class=\'additional-kubo-cb\' onchange=\'recalculateTotals()\'>";
                                    echo htmlspecialchars($k[\'name\']) . " (₱" . number_format($k_price) . "/night)";
                                    echo "</label>";
                                }
                                ?>
                            </div>';
                            
$new_kubos_html = '<div class="selection-grid">
                                <?php 
                                $all_kubos = InventoryStore::getAllKubos();
                                foreach ($all_kubos as $k) {
                                    $kid = intval($k[\'kubo_id\'] ?? $k[\'id\']);
                                    if ($kid == $kubo_id) continue;
                                    $k_status = strtolower(trim($k[\'status\'] ?? \'available\'));
                                    if (in_array($k_status, [\'renovation\', \'maintenance\', \'unavailable\'])) continue;
                                    $k_price = floatval($k[\'price\'] ?? 3500);
                                ?>
                                <label class="custom-card-checkbox">
                                    <input type="checkbox" name="additional_kubos[]" value="<?= $kid ?>" data-price="<?= $k_price ?>" data-name="<?= htmlspecialchars($k[\'name\'], ENT_QUOTES) ?>" class="additional-kubo-cb" onchange="recalculateTotals()">
                                    <div class="card-content">
                                        <span class="card-title"><?= htmlspecialchars($k[\'name\']) ?></span>
                                        <span class="card-price">₱<?= number_format($k_price) ?> / night</span>
                                        <i class="fa-solid fa-circle-check check-icon"></i>
                                    </div>
                                </label>
                                <?php } ?>
                            </div>';
$content = str_replace($old_kubos_html, $new_kubos_html, $content);

// 3. Replace "Select Activity" HTML (from <select> to custom checkboxes)
$old_act_html = '<select name="addon_activity_ids[]" multiple id="addonActivitySelect" class="form-control" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 0.85rem 1rem; font-size: 0.95rem; font-weight: 700; color: #0f172a; width: 100%; min-height: 120px;">
                                <option value="0" data-price="0" data-name="" data-duration="" <?= ($existing_addon_id == 0 ? \'selected\' : \'\') ?>>-- None (No Activity Add-on) --</option>
                                <?php foreach ($available_activities as $act): 
                                    $aid = intval($act[\'activity_id\']);
                                    $act_price = floatval($act[\'price\'] ?? 0);
                                    $act_dur = (!empty($act[\'duration_minutes\']) && intval($act[\'duration_minutes\']) > 0) ? htmlspecialchars($act[\'duration\'] ?? ($act[\'duration_minutes\'] . \' Mins\')) : \'No time limit\';
                                    $act_name = htmlspecialchars($act[\'activity_name\']);
                                    
                                    $eq = json_decode($act[\'equipment\'] ?? \'[]\', true);
                                    $disabled = false;
                                    if (is_array($eq) && count($eq) > 0) {
                                        $qty = 0;
                                        foreach($eq as $e) $qty += intval($e[\'qty\'] ?? 0);
                                        if ($qty == 0) $disabled = true;
                                    } else {
                                        if (intval($act[\'total_units\'] ?? 1) == 0) $disabled = true;
                                    }
                                ?>
                                    <option value="<?= $aid ?>" <?= $disabled ? \'disabled style="color:#94a3b8; background:#f1f5f9;"\' : \'\' ?> 
                                            data-price="<?= $act_price ?>" 
                                            data-name="<?= $act_name ?>" 
                                            data-duration="<?= $act_dur ?>"
                                            data-type="<?= htmlspecialchars($act[\'activity_type\'] ?? \'full_day\') ?>"
                                            <?= (in_array($aid, (array)($existing_addon_ids ?? [$existing_addon_id])) ? \'selected\' : \'\') ?>>
                                        <?= $act_name ?> (₱<?= number_format($act_price) ?> / <?= $act_dur ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>';

$new_act_html = '<div class="selection-grid" id="addonActivitySelectGrid">
                                <?php foreach ($available_activities as $act): 
                                    $aid = intval($act[\'activity_id\']);
                                    $act_price = floatval($act[\'price\'] ?? 0);
                                    $act_dur = (!empty($act[\'duration_minutes\']) && intval($act[\'duration_minutes\']) > 0) ? htmlspecialchars($act[\'duration\'] ?? ($act[\'duration_minutes\'] . \' Mins\')) : \'No time limit\';
                                    $act_name = htmlspecialchars($act[\'activity_name\']);
                                    
                                    $eq = json_decode($act[\'equipment\'] ?? \'[]\', true);
                                    $disabled = false;
                                    if (is_array($eq) && count($eq) > 0) {
                                        $qty = 0;
                                        foreach($eq as $e) $qty += intval($e[\'qty\'] ?? 0);
                                        if ($qty == 0) $disabled = true;
                                    } else {
                                        if (intval($act[\'total_units\'] ?? 1) == 0) $disabled = true;
                                    }
                                ?>
                                <label class="custom-card-checkbox">
                                    <input type="checkbox" name="addon_activity_ids[]" value="<?= $aid ?>" class="addon-activity-cb" onchange="recalculateTotals()"
                                            data-price="<?= $act_price ?>" 
                                            data-name="<?= htmlspecialchars($act_name, ENT_QUOTES) ?>" 
                                            data-duration="<?= htmlspecialchars($act_dur, ENT_QUOTES) ?>"
                                            data-type="<?= htmlspecialchars($act[\'activity_type\'] ?? \'full_day\', ENT_QUOTES) ?>"
                                            <?= $disabled ? \'disabled\' : \'\' ?>
                                            <?= (in_array($aid, (array)($existing_addon_ids ?? [$existing_addon_id])) ? \'checked\' : \'\') ?>>
                                    <div class="card-content">
                                        <span class="card-title"><?= $act_name ?></span>
                                        <span class="card-price">₱<?= number_format($act_price) ?> / <?= $act_dur ?></span>
                                        <i class="fa-solid fa-circle-check check-icon"></i>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>';
$content = str_replace($old_act_html, $new_act_html, $content);

// 4. Update JS in recalculateTotals()
$old_js_loop = 'if (actSelect && actSelect.selectedOptions && actSelect.selectedOptions.length > 0) {
            const pax = Math.max(1, parseInt(paxInput ? paxInput.value : 1, 10) || 1);
            
            for(let i=0; i<actSelect.selectedOptions.length; i++) {
                const opt = actSelect.selectedOptions[i];
                if(opt.value === "0") continue;
                hasAct = true;
                const price = parseFloat(opt.dataset.price) || 0;
                const name = opt.dataset.name || opt.text;
                const type = opt.dataset.type || \'full_day\';';
$new_js_loop = '
        const selectedActivities = document.querySelectorAll(\'.addon-activity-cb:checked\');
        if (selectedActivities.length > 0) {
            const pax = Math.max(1, parseInt(paxInput ? paxInput.value : 1, 10) || 1);
            
            selectedActivities.forEach(opt => {
                hasAct = true;
                const price = parseFloat(opt.dataset.price) || 0;
                const name = opt.dataset.name || opt.value;
                const type = opt.dataset.type || \'full_day\';';
$content = str_replace($old_js_loop, $new_js_loop, $content);

$old_js_loop_close = '}
            if (hasAct) {
                if (detailsWrapper) detailsWrapper.style.display = \'block\';
            }
        }';
$new_js_loop_close = '});
            if (hasAct) {
                if (detailsWrapper) detailsWrapper.style.display = \'block\';
            }
        }';
$content = str_replace($old_js_loop_close, $new_js_loop_close, $content);


file_put_contents($file, $content);
echo "Done patching design for book_house.php\n";
?>
