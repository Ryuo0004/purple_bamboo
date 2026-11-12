<?php
$file = 'book_house.php';
$content = file_get_contents($file);

// 1. Replace single select with multiple select
$old_select = '<select name="addon_activity_id" id="addonActivitySelect" class="form-control" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 0.85rem 1rem; font-size: 0.95rem; font-weight: 700; color: #0f172a; width: 100%;">';
$new_select = '<select name="addon_activity_ids[]" multiple id="addonActivitySelect" class="form-control" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 0.85rem 1rem; font-size: 0.95rem; font-weight: 700; color: #0f172a; width: 100%; min-height: 120px;">';
$content = str_replace($old_select, $new_select, $content);

// 2. Add equipment check and disable option
$old_loop = '<?php foreach ($available_activities as $act): 
                                    $aid = intval($act[\'activity_id\']);
                                    $act_price = floatval($act[\'price\'] ?? 0);
                                    $act_dur = (!empty($act[\'duration_minutes\']) && intval($act[\'duration_minutes\']) > 0) ? htmlspecialchars($act[\'duration\'] ?? ($act[\'duration_minutes\'] . \' Mins\')) : \'No time limit\';
                                    $act_name = htmlspecialchars($act[\'activity_name\']);
                                ?>
                                    <option value="<?= $aid ?>"';
$new_loop = '<?php foreach ($available_activities as $act): 
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
                                    <option value="<?= $aid ?>" <?= $disabled ? \'disabled style="color:#94a3b8; background:#f1f5f9;"\' : \'\' ?>';
$content = str_replace($old_loop, $new_loop, $content);

// 3. Fix the selected option logic for multiple activities (we will just remove the single existing_addon_id logic for brevity)
$content = str_replace('<?= ($existing_addon_id === $aid ? \'selected\' : \'\') ?>', '<?= (in_array($aid, (array)($existing_addon_ids ?? [$existing_addon_id])) ? \'selected\' : \'\') ?>', $content);

// 4. Add "Additional Kubos" section before the Addon section
$old_addon_label = '<div class="form-group" style="margin-top: 0.5rem; margin-bottom: 1.25rem;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.95rem; color: #1e1b4b; margin-bottom: 0.5rem; font-family: \'Outfit\', sans-serif;">
                                <i class="fa-solid fa-water-ladder" style="color: #7c3aed;"></i> Select Activity
                            </label>';
$new_kubo_section = '<div class="form-group" style="margin-top: 0.5rem; margin-bottom: 1.25rem;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.95rem; color: #1e1b4b; margin-bottom: 0.5rem; font-family: \'Outfit\', sans-serif;">
                                <i class="fa-solid fa-house" style="color: #7c3aed;"></i> Add Additional Kubos
                            </label>
                            <div style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 0.85rem 1rem; max-height: 150px; overflow-y: auto;">
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
                            </div>
                        </div>' . "\n\n" . $old_addon_label;
$content = str_replace($old_addon_label, $new_kubo_section, $content);

// 5. Update PHP Processing for Multiple Activities
$old_php_process = '$addon_act_id = intval($_POST[\'addon_activity_id\'] ?? 0);
        if ($addon_act_id > 0) {';
$new_php_process = '$addon_activity_ids = $_POST[\'addon_activity_ids\'] ?? [];
        $additional_kubos = $_POST[\'additional_kubos\'] ?? [];
        
        // Add additional kubos to cost and notes
        foreach($additional_kubos as $ak_id) {
            $ak_id = intval($ak_id);
            $ak = InventoryStore::getKuboById($ak_id);
            if ($ak) {
                $ak_price = floatval($ak[\'price\'] ?? 0);
                $room_total += ($calc_nights * $ak_price);
                $addons_summary_list[] = "• Additional Kubo: {$ak[\'name\']} ({$calc_nights} Nights) - ₱" . number_format($calc_nights * $ak_price, 2);
            }
        }
        
        foreach((array)$addon_activity_ids as $addon_act_id) {
            $addon_act_id = intval($addon_act_id);
            if ($addon_act_id > 0) {';
$content = str_replace($old_php_process, $new_php_process, $content);

// Add the closing brace for the new foreach loop
$old_close = '                    $addons_summary_list[] = "• {$act_name} ({$pax} Pax) - ₱" . number_format($subtotal, 2) . " [Scheduled: {$formatted_date} at " . ($is_full_day ? \'Full Day\' : $addon_time) . "]";
                }
            }
        }';
$new_close = '                    $addons_summary_list[] = "• {$act_name} ({$pax} Pax) - ₱" . number_format($subtotal, 2) . " [Scheduled: {$formatted_date} at " . ($is_full_day ? \'Full Day\' : $addon_time) . "]";
                }
            }
        }
        } // end foreach addon_activity_ids';
$content = str_replace($old_close, $new_close, $content);

// 6. Update JS recalculateTotals()
$old_js = '    function recalculateTotals() {
        const roomSubtotal = currentNights * ratePerNight;
        let addonsTotal = 0;

        const selectedActId = actSelect ? parseInt(actSelect.value, 10) : 0;

        if (selectedActId > 0 && actSelect && actSelect.selectedOptions && actSelect.selectedOptions[0]) {
            if (detailsWrapper) detailsWrapper.style.display = \'block\';
            const opt = actSelect.selectedOptions[0];
            const price = parseFloat(opt.dataset.price) || 0;
            const name = opt.dataset.name || opt.text;
            const type = opt.dataset.type || \'full_day\';
            const pax = Math.max(1, parseInt(paxInput ? paxInput.value : 1, 10) || 1);
            addonsTotal = pax * price;

            const timePicker = document.getElementById(\'modalAddonTimePickerSection\');
            if (timePicker) {
                timePicker.style.display = (type === \'full_day\') ? \'none\' : \'block\';
            }

            const dateVal = hiddenAddonDate ? hiddenAddonDate.value : \'\';
            const timeVal = hiddenAddonTime ? hiddenAddonTime.value : \'\';
            let schedText = \'\';
            if (type !== \'full_day\' && dateVal) {
                const parts = dateVal.split(\'-\');
                if (parts.length === 3) {
                    const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                    schedText = `<div style="font-size: 0.76rem; color: #64748b; margin-top: 3px;"><i class="fa-regular fa-clock" style="color: #7c3aed;"></i> ${d.toLocaleDateString(\'en-US\', { month: \'short\', day: \'numeric\' })} at ${escapeHtml(timeVal)}</div>`;
                }
            }

            if (summaryAddonsBox) {
                summaryAddonsBox.style.display = \'block\';
                if (summaryAddonsList) {
                    summaryAddonsList.innerHTML = `
                        <div class="summary-addon-row" style="flex-direction: column; align-items: flex-start; gap: 2px;">
                            <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                                <span class="summary-addon-name">• ${escapeHtml(name)} (${pax} Pax)</span>
                                <span class="summary-addon-val">₱${addonsTotal.toLocaleString(\'en-US\', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                            </div>
                            ${schedText}
                        </div>
                    `;
                }
                if (summaryAddonsSubtotal) {
                    summaryAddonsSubtotal.textContent = \'₱\' + addonsTotal.toLocaleString(\'en-US\', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            }
        } else {
            if (detailsWrapper) detailsWrapper.style.display = \'none\';
            if (summaryAddonsBox) {
                summaryAddonsBox.style.display = \'none\';
                if (summaryAddonsList) summaryAddonsList.innerHTML = \'\';
            }
        }

        const total = roomSubtotal + addonsTotal;';
        
$new_js = '    function recalculateTotals() {
        let roomSubtotal = currentNights * ratePerNight;
        let addonsTotal = 0;
        let addonsHtml = \'\';
        let hasAct = false;
        
        // Add additional kubos
        const additionalKubos = document.querySelectorAll(\'.additional-kubo-cb:checked\');
        additionalKubos.forEach(cb => {
            const price = parseFloat(cb.dataset.price) || 0;
            const sub = currentNights * price;
            roomSubtotal += sub;
            addonsHtml += `<div class="summary-addon-row" style="flex-direction: column; align-items: flex-start; gap: 2px;">
                                <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                                    <span class="summary-addon-name">• Addl Kubo: ${escapeHtml(cb.dataset.name)}</span>
                                    <span class="summary-addon-val">₱${sub.toLocaleString(\'en-US\', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                </div>
                            </div>`;
            hasAct = true;
        });

        if (actSelect && actSelect.selectedOptions && actSelect.selectedOptions.length > 0) {
            const pax = Math.max(1, parseInt(paxInput ? paxInput.value : 1, 10) || 1);
            
            for(let i=0; i<actSelect.selectedOptions.length; i++) {
                const opt = actSelect.selectedOptions[i];
                if(opt.value === "0") continue;
                hasAct = true;
                const price = parseFloat(opt.dataset.price) || 0;
                const name = opt.dataset.name || opt.text;
                const type = opt.dataset.type || \'full_day\';
                const sub = pax * price;
                addonsTotal += sub;

                const dateVal = hiddenAddonDate ? hiddenAddonDate.value : \'\';
                const timeVal = hiddenAddonTime ? hiddenAddonTime.value : \'\';
                let schedText = \'\';
                if (type !== \'full_day\' && dateVal) {
                    const parts = dateVal.split(\'-\');
                    if (parts.length === 3) {
                        const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                        schedText = `<div style="font-size: 0.76rem; color: #64748b; margin-top: 3px;"><i class="fa-regular fa-clock" style="color: #7c3aed;"></i> ${d.toLocaleDateString(\'en-US\', { month: \'short\', day: \'numeric\' })} at ${escapeHtml(timeVal)}</div>`;
                    }
                }

                addonsHtml += `
                    <div class="summary-addon-row" style="flex-direction: column; align-items: flex-start; gap: 2px;">
                        <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                            <span class="summary-addon-name">• ${escapeHtml(name)} (${pax} Pax)</span>
                            <span class="summary-addon-val">₱${sub.toLocaleString(\'en-US\', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        ${schedText}
                    </div>
                `;
            }
            if (hasAct) {
                if (detailsWrapper) detailsWrapper.style.display = \'block\';
            }
        } 
        
        if (hasAct) {
            if (summaryAddonsBox) {
                summaryAddonsBox.style.display = \'block\';
                if (summaryAddonsList) {
                    summaryAddonsList.innerHTML = addonsHtml;
                }
                if (summaryAddonsSubtotal) {
                    summaryAddonsSubtotal.textContent = \'₱\' + addonsTotal.toLocaleString(\'en-US\', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            }
        } else {
            if (detailsWrapper) detailsWrapper.style.display = \'none\';
            if (summaryAddonsBox) {
                summaryAddonsBox.style.display = \'none\';
                if (summaryAddonsList) summaryAddonsList.innerHTML = \'\';
            }
        }

        const total = roomSubtotal + addonsTotal;';
$content = str_replace($old_js, $new_js, $content);

file_put_contents($file, $content);
echo "Done patching book_house.php\n";
?>
