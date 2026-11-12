/**
 * monthly_calendar_picker.js
 * Interactive Monthly Grid Calendar Date Range Picker for Purple Bamboo Resort
 * Supports: Check-In & Check-Out selection, single-date mode, month navigation,
 * stay duration calculation, cross-month ranges, and booked date blocking.
 */

function initMonthlyCalendarPicker(options) {
    const containerId = options.containerId || 'monthlyCalendarPicker';
    const checkInInputId = options.checkInInputId || 'inputCheckIn';
    const checkOutInputId = options.checkOutInputId || 'inputCheckOut';
    const isSingleDate = options.isSingleDate || false;
    const onRangeSelected = options.onRangeSelected || function () { };
    const isAdmin = options.isAdmin || false;
    const showDurationStrip = options.showDurationStrip !== undefined ? options.showDurationStrip : true;

    // Time Selection Options
    const timeInputId = options.timeInputId || null;
    const checkInTimeInputId = options.checkInTimeInputId || null;
    const checkOutTimeInputId = options.checkOutTimeInputId || null;
    const showTimePicker = options.showTimePicker || (timeInputId !== null || checkInTimeInputId !== null);
    let selectedTime = options.initialTime || (timeInputId && document.getElementById(timeInputId) ? document.getElementById(timeInputId).value : '09:00');
    let checkInTime = options.initialCheckInTime || (checkInTimeInputId && document.getElementById(checkInTimeInputId) ? document.getElementById(checkInTimeInputId).value : '14:00');
    let checkOutTime = options.initialCheckOutTime || (checkOutTimeInputId && document.getElementById(checkOutTimeInputId) ? document.getElementById(checkOutTimeInputId).value : '12:00');

    const bookedRanges = options.bookedRanges || [];
    const bookedDatesSet = new Set(options.bookedDates || []);

    const container = document.getElementById(containerId);
    if (!container) return;

    // Helper: format a Date as local YYYY-MM-DD
    function formatDate(d) {
        if (!d) return '';
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function addDays(d, n) {
        const r = new Date(d);
        r.setDate(r.getDate() + n);
        return r;
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayStr = formatDate(today);

    // Defaults use LOCAL dates and NEVER past dates
    let rawIn = options.initialCheckIn || todayStr;
    if (rawIn < todayStr) rawIn = todayStr;
    const initialCheckIn = rawIn;

    let rawOut = isSingleDate
        ? null
        : (options.initialCheckOut || formatDate(addDays(new Date(initialCheckIn + 'T00:00:00'), 1)));
    if (rawOut && rawOut <= initialCheckIn) {
        rawOut = formatDate(addDays(new Date(initialCheckIn + 'T00:00:00'), 1));
    }
    const initialCheckOut = rawOut;

    // Auto-populate bookedDatesSet from bookedRanges safely
    bookedRanges.forEach(r => {
        if (!r.start) return;
        const sStr = String(r.start).split('T')[0].split(' ')[0];
        const eStr = (r.end ? String(r.end) : String(r.start)).split('T')[0].split(' ')[0];
        if (!sStr) return;
        const s = new Date(sStr + 'T00:00:00');
        const e = eStr ? new Date(eStr + 'T00:00:00') : addDays(s, 1);
        for (let c = new Date(s); c < e; c.setDate(c.getDate() + 1)) {
            bookedDatesSet.add(formatDate(c));
        }
    });

    function isDateBooked(dateStr) {
        return bookedDatesSet.has(dateStr);
    }

    function checkRangeOverlap(startDate, endDate) {
        if (!startDate || !endDate) return false;
        // Overnight stays occupy each night from startDate up to endDate-1
        for (let c = new Date(startDate); c < endDate; c.setDate(c.getDate() + 1)) {
            if (isDateBooked(formatDate(c))) return true;
        }
        return false;
    }

    let checkInDate = initialCheckIn ? new Date(initialCheckIn + 'T00:00:00') : null;
    let checkOutDate = initialCheckOut ? new Date(initialCheckOut + 'T00:00:00') : null;
    let selectedNights = options.initialNights || (checkInDate && checkOutDate ? calculateNights(checkInDate, checkOutDate) : 1);
    let hoveredDate = null;
    let selectingStep = isSingleDate ? 'complete' : (checkOutDate ? 'complete' : (checkInDate ? 'checkout' : 'checkin'));
    let alertTimer = null;

    // Current displayed month in calendar view
    let viewDate = checkInDate ? new Date(checkInDate) : new Date();
    viewDate.setDate(1);

    const monthNames = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];

    function formatDisplayDate(d) {
        if (!d) return 'Select Date';
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function calculateNights(d1, d2) {
        if (!d1 || !d2) return 0;
        return Math.max(0, Math.round((d2.getTime() - d1.getTime()) / 86400000));
    }

    function hasBookingConflict() {
        if (isSingleDate) return !!checkInDate && isDateBooked(formatDate(checkInDate));
        return !!(checkInDate && checkOutDate &&
            (checkRangeOverlap(checkInDate, checkOutDate) || isDateBooked(formatDate(checkInDate))));
    }

    function renderUI() {
        const themeStyles = isAdmin ? `
            --cal-bg: #1a102f;
            --cal-card-bg: #0f172a;
            --cal-border: #4c1d95;
            --cal-text: #ffffff;
            --cal-text-muted: #94a3b8;
            --cal-hover: rgba(124, 58, 237, 0.35);
            --cal-range-bg: rgba(124, 58, 237, 0.25);
            --cal-in-bg: #7c3aed;
            --cal-out-bg: #f59e0b;
            --cal-booked-bg: #450a0a;
            --cal-booked-border: #991b1b;
            --cal-booked-text: #fca5a5;
        ` : `
            --cal-bg: #ffffff;
            --cal-card-bg: #faf5ff;
            --cal-border: #e9d5ff;
            --cal-text: #1e1b4b;
            --cal-text-muted: #64748b;
            --cal-hover: rgba(124, 58, 237, 0.12);
            --cal-range-bg: rgba(124, 58, 237, 0.12);
            --cal-in-bg: #7c3aed;
            --cal-out-bg: #d97706;
            --cal-booked-bg: #fee2e2;
            --cal-booked-border: #f87171;
            --cal-booked-text: #991b1b;
        `;

        const hasConflict = hasBookingConflict();

        container.innerHTML = `
            <style>
                .cal-picker-wrap { ${themeStyles} font-family: inherit; margin-bottom: 1.5rem; }
                .cal-status-strip {
                    display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 0.75rem;
                    background: var(--cal-card-bg); border: 1.5px solid var(--cal-border);
                    border-radius: 12px; padding: 0.85rem 1.25rem; margin-bottom: 1rem;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
                }
                .cal-status-strip.has-conflict { border-color: #ef4444; background: #fff1f2; }
                .cal-status-box {
                    display: flex; flex-direction: column; gap: 2px; cursor: pointer;
                    padding: 0.4rem 0.6rem; border-radius: 8px; transition: all 0.2s ease;
                }
                .cal-status-box:hover { background: var(--cal-hover); }
                .cal-status-box.active { background: var(--cal-hover); box-shadow: 0 0 0 2px var(--cal-in-bg); }
                .cal-status-label {
                    font-size: 0.76rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;
                    color: var(--cal-text-muted); display: flex; align-items: center; gap: 5px;
                }
                .cal-status-val { font-size: 1.05rem; font-weight: 800; color: var(--cal-text); font-family: 'Outfit', sans-serif; }
                .cal-middle-divider { display: flex; flex-direction: column; align-items: center; gap: 4px; }
                .cal-nights-pill {
                    background: linear-gradient(135deg, #7c3aed, #a855f7); color: #ffffff;
                    padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 800;
                    white-space: nowrap; box-shadow: 0 2px 6px rgba(124, 58, 237, 0.25);
                }
                .cal-nights-pill.conflict { background: #dc2626; box-shadow: 0 2px 6px rgba(220, 38, 38, 0.35); }
                .cal-alert-banner {
                    display: ${hasConflict ? 'flex' : 'none'};
                    background: #fef2f2; border: 1.5px solid #f87171; color: #991b1b;
                    padding: 0.75rem 1rem; border-radius: 10px; font-size: 0.85rem; font-weight: 700;
                    margin-bottom: 0.85rem; align-items: center; gap: 10px;
                    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.1);
                }
                .cal-grid-card {
                    background: var(--cal-bg); border: 1.5px solid var(--cal-border);
                    border-radius: 14px; padding: 1.25rem; box-shadow: 0 6px 16px rgba(0,0,0,0.06);
                }
                .cal-nav-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
                .cal-nav-title {
                    font-size: 1.2rem; font-weight: 800; color: var(--cal-text); margin: 0;
                    font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;
                }
                .cal-btn-nav {
                    background: var(--cal-card-bg); border: 1.5px solid var(--cal-border); color: var(--cal-text);
                    width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center;
                    justify-content: center; cursor: pointer; font-size: 0.95rem; transition: all 0.2s ease;
                }
                .cal-btn-nav:hover:not(:disabled) { background: var(--cal-hover); border-color: var(--cal-in-bg); transform: scale(1.05); }
                .cal-btn-nav:disabled { opacity: 0.3; cursor: not-allowed; }
                .cal-week-row { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; text-align: center; margin-bottom: 8px; }
                .cal-weekday-col { font-size: 0.78rem; font-weight: 800; color: var(--cal-text-muted); text-transform: uppercase; padding: 4px 0; }
                .cal-days-grid-inner { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
                .cal-day-cell {
                    aspect-ratio: 1; min-height: 44px; border-radius: 10px; display: flex; flex-direction: column;
                    align-items: center; justify-content: center; font-size: 0.95rem; font-weight: 700;
                    color: var(--cal-text); cursor: pointer; position: relative; transition: all 0.15s ease;
                    user-select: none; border: 1.5px solid transparent;
                }
                .cal-day-cell.empty { cursor: default; pointer-events: none; }
                .cal-day-cell.disabled { opacity: 0.28; cursor: not-allowed; pointer-events: none; text-decoration: line-through; }
                .cal-day-cell:hover:not(.disabled):not(.empty):not(.is-booked) { background: var(--cal-hover); border-color: var(--cal-border); }
                .cal-day-cell.is-booked {
                    background: var(--cal-booked-bg) !important; border: 1.5px solid var(--cal-booked-border) !important;
                    color: var(--cal-booked-text) !important; cursor: not-allowed;
                }
                .cal-day-cell.is-booked:hover { transform: scale(0.98); box-shadow: 0 0 0 2px #ef4444; }
                .cal-day-cell.today::after {
                    content: ''; position: absolute; bottom: 3px; width: 5px; height: 5px;
                    border-radius: 50%; background: var(--cal-out-bg);
                }
                .cal-day-cell.is-in {
                    background: var(--cal-in-bg) !important; color: #ffffff !important;
                    border-radius: 10px 0 0 10px !important; font-weight: 900;
                    box-shadow: 0 4px 10px rgba(124, 58, 237, 0.4); border-color: var(--cal-in-bg);
                }
                .cal-day-cell.is-out {
                    background: var(--cal-out-bg) !important; color: #ffffff !important;
                    border-radius: 0 10px 10px 0 !important; font-weight: 900;
                    box-shadow: 0 4px 10px rgba(217, 119, 6, 0.4); border-color: var(--cal-out-bg);
                }
                .cal-day-cell.is-in.is-out { border-radius: 10px !important; }
                .cal-day-cell.single-selected { border-radius: 10px !important; }
                .cal-day-cell.in-range { background: var(--cal-range-bg) !important; color: var(--cal-text) !important; border-radius: 0 !important; border-color: transparent; }
                .cal-badge-sub { font-size: 0.62rem; text-transform: uppercase; line-height: 1; margin-top: 2px; font-weight: 800; }
                .cal-badge-sub.booked { background: #dc2626; color: #ffffff; padding: 1px 4px; border-radius: 4px; letter-spacing: 0.3px; }
                .cal-instructions {
                    margin-top: 1rem; padding-top: 0.85rem; border-top: 1px solid var(--cal-border);
                    display: flex; align-items: center; justify-content: space-between;
                    font-size: 0.82rem; color: var(--cal-text-muted); flex-wrap: wrap; gap: 0.5rem;
                }
                .cal-legend { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
                .cal-legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
                @media (max-width: 600px) {
                    .cal-status-strip { grid-template-columns: 1fr; gap: 0.5rem; text-align: center; }
                    .cal-status-box { align-items: center; text-align: center; }
                    .cal-status-label { justify-content: center; }
                    .cal-middle-divider { flex-direction: row; justify-content: center; }
                    .cal-day-cell { min-height: 38px; font-size: 0.85rem; }
                }
                @media (max-width: 480px) {
                    .cal-grid-card { padding: 0.75rem 0.5rem; border-radius: 12px; }
                    .cal-status-strip { padding: 0.6rem 0.5rem; gap: 0.35rem; }
                    .cal-status-val { font-size: 0.88rem; }
                    .cal-nights-pill { font-size: 0.72rem; padding: 2px 7px; }
                    .cal-week-row { gap: 2px; margin-bottom: 4px; }
                    .cal-weekday-col { font-size: 0.65rem; padding: 2px 0; }
                    .cal-days-grid-inner { gap: 2px; }
                    .cal-day-cell { min-height: 34px; font-size: 0.82rem; border-radius: 6px; }
                    .cal-badge-sub { font-size: 0.55rem; }
                    .cal-instructions { font-size: 0.75rem; }
                }
            </style>

            <div class="cal-picker-wrap">
                <!-- Alert Banner for Booked Dates Warning -->
                <div class="cal-alert-banner" id="calAlertBanner">
                    <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444; font-size: 1.1rem;"></i>
                    <span id="calAlertBannerMsg">${hasConflict
                ? (isSingleDate
                    ? `Notice: ${formatDate(checkInDate)} is already booked. Please click an open date on the calendar.`
                    : `Notice: The dates (${formatDate(checkInDate)} to ${formatDate(checkOutDate)}) are already booked. Please click open dates on the calendar.`)
                : ''}</span>
                </div>

                <!-- Status Bar with Selected Dates -->
                <div class="cal-status-strip ${hasConflict ? 'has-conflict' : ''}" id="calStatusStrip" style="${isSingleDate ? 'grid-template-columns: 1fr; text-align: center;' : ''}">
                    <div class="cal-status-box ${selectingStep === 'checkin' || isSingleDate ? 'active' : ''}" id="btnBoxCheckIn" style="${isSingleDate ? 'cursor: default;' : ''}">
                        <span class="cal-status-label">
                            ${isSingleDate ? '<i class="fa-regular fa-calendar-check" style="color: #7c3aed;"></i> Selected Date' : '<i class="fa-solid fa-plane-arrival" style="color: #7c3aed;"></i> Check-In Date'}
                        </span>
                        <span class="cal-status-val" id="valDisplayCheckIn">${formatDisplayDate(checkInDate)}</span>
                    </div>

                    ${isSingleDate ? '' : `
                    <div class="cal-middle-divider">
                        <i class="fa-solid fa-arrow-right" style="color: var(--cal-text-muted); font-size: 0.85rem;"></i>
                        <span class="cal-nights-pill ${hasConflict ? 'conflict' : ''}" id="valDisplayNights">
                            ${hasConflict ? 'Dates Booked' : (calculateNights(checkInDate, checkOutDate) + ' Night(s) Stay')}
                        </span>
                    </div>

                    <div class="cal-status-box ${selectingStep === 'checkout' ? 'active' : ''}" id="btnBoxCheckOut">
                        <span class="cal-status-label"><i class="fa-solid fa-plane-departure" style="color: #d97706;"></i> Check-Out Date</span>
                        <span class="cal-status-val" id="valDisplayCheckOut">${formatDisplayDate(checkOutDate)}</span>
                    </div>
                    `}
                </div>

                ${(isSingleDate || !showDurationStrip) ? '' : `
                <!-- Quick Stay Duration Selector Strip -->
                <div class="cal-duration-strip" style="margin-bottom: 0.85rem; padding: 0.65rem 0.85rem; background: var(--cal-card-bg); border: 1.5px solid var(--cal-border); border-radius: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem; flex-wrap: wrap; gap: 0.25rem;">
                        <span style="font-size: 0.78rem; font-weight: 800; color: var(--cal-text); text-transform: uppercase; letter-spacing: 0.4px;">
                            <i class="fa-solid fa-moon" style="color: var(--cal-in-bg);"></i> Stay Duration (Days &amp; Nights)
                        </span>
                        <span style="font-size: 0.72rem; color: var(--cal-text-muted); font-weight: 600;">Click how many nights to stay:</span>
                    </div>
                    <div class="cal-duration-btns" style="display: flex; gap: 6px; flex-wrap: wrap;">
                        ${[1, 2, 3, 4, 5, 7].map(n => {
                            const curN = calculateNights(checkInDate, checkOutDate);
                            const isCur = curN === n;
                            return `<button type="button" class="cal-dur-btn ${isCur ? 'active' : ''}" data-dur="${n}" style="padding: 5px 11px; border-radius: 8px; font-size: 0.8rem; font-weight: 800; border: 1.5px solid ${isCur ? 'var(--cal-in-bg)' : 'var(--cal-border)'}; background: ${isCur ? 'var(--cal-in-bg)' : 'var(--cal-card-bg)'}; color: ${isCur ? '#ffffff' : 'var(--cal-text)'}; cursor: pointer; transition: all 0.15s ease;">${n} ${n === 1 ? 'Night' : 'Nights'}</button>`;
                        }).join('')}
                    </div>
                </div>
                `}

                <!-- Monthly Grid Card -->
                <div class="cal-grid-card">
                    <div class="cal-nav-bar">
                        <button type="button" class="cal-btn-nav" id="btnCalPrev" aria-label="Previous Month">
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <h3 class="cal-nav-title" id="txtCalMonthTitle">
                            <i class="fa-regular fa-calendar-days" style="color: #7c3aed;"></i>
                            ${monthNames[viewDate.getMonth()]} ${viewDate.getFullYear()}
                        </h3>
                        <button type="button" class="cal-btn-nav" id="btnCalNext" aria-label="Next Month">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>

                    <div class="cal-week-row">
                        <div class="cal-weekday-col" style="color: #ef4444;">Sun</div>
                        <div class="cal-weekday-col">Mon</div>
                        <div class="cal-weekday-col">Tue</div>
                        <div class="cal-weekday-col">Wed</div>
                        <div class="cal-weekday-col">Thu</div>
                        <div class="cal-weekday-col">Fri</div>
                        <div class="cal-weekday-col" style="color: #3b82f6;">Sat</div>
                    </div>

                    <div class="cal-days-grid-inner" id="calDaysGridBody"></div>

                    <div class="cal-instructions">
                        <div id="calInstructionText" style="font-weight: 700; color: var(--cal-text);">
                            ${isSingleDate ? '👉 Click an available date for your activity' : (selectingStep === 'checkout' ? '👉 Click your Check-Out departure date' : '👉 Click your Check-In arrival date')}
                        </div>
                        <div class="cal-legend">
                            <span><span class="cal-legend-dot" style="background: #dc2626;"></span> Booked</span>
                            ${isSingleDate ? '<span><span class="cal-legend-dot" style="background: #7c3aed;"></span> Selected Date</span>' : `
                            <span><span class="cal-legend-dot" style="background: #7c3aed;"></span> Check-In</span>
                            <span><span class="cal-legend-dot" style="background: #d97706;"></span> Check-Out</span>
                            `}
                        </div>
                    </div>

                    ${showTimePicker ? `
                    <!-- Integrated Time Picker Section -->
                    <div class="cal-time-picker-section" style="margin-top: 1rem; padding-top: 0.85rem; border-top: 1.5px dashed var(--cal-border);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem; flex-wrap: wrap; gap: 0.5rem;">
                            <label style="font-weight: 800; font-size: 0.88rem; color: var(--cal-text); margin: 0; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-clock" style="color: var(--cal-in-bg);"></i> ${isSingleDate ? 'Select Time Slot' : 'Select Check-In & Check-Out Times'}
                            </label>
                            <span style="font-size: 0.74rem; color: var(--cal-text-muted); font-weight: 600;">Resort Hours: 8:00 AM – 10:00 PM</span>
                        </div>

                        ${isSingleDate ? `
                        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                            <input type="time" class="cal-native-time-input" value="${selectedTime}" min="08:00" max="22:00" style="padding: 0.5rem 0.75rem; border-radius: 8px; border: 1.5px solid var(--cal-border); background: var(--cal-card-bg); color: var(--cal-text); font-weight: 800; font-family: inherit; font-size: 0.95rem;">
                            <div class="cal-time-presets" style="display: flex; gap: 5px; flex-wrap: wrap;">
                                ${['09:00', '11:00', '13:00', '15:00', '17:00'].map(t => {
                                    const parts = t.split(':');
                                    const hr = parseInt(parts[0], 10);
                                    const ampm = hr >= 12 ? 'PM' : 'AM';
                                    const dhr = hr % 12 || 12;
                                    const isSel = selectedTime === t;
                                    return `<button type="button" class="cal-time-chip" data-time="${t}" style="padding: 4px 9px; border-radius: 6px; font-size: 0.76rem; font-weight: 800; border: 1.5px solid ${isSel ? 'var(--cal-in-bg)' : 'var(--cal-border)'}; background: ${isSel ? 'var(--cal-in-bg)' : 'var(--cal-card-bg)'}; color: ${isSel ? '#ffffff' : 'var(--cal-text)'}; cursor: pointer; transition: all 0.15s ease;">${dhr}:${parts[1]} ${ampm}</button>`;
                                }).join('')}
                            </div>
                        </div>
                        ` : `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                            <div>
                                <span style="font-size: 0.75rem; font-weight: 800; color: var(--cal-text-muted); display: block; margin-bottom: 4px;">
                                    <i class="fa-solid fa-plane-arrival" style="color: var(--cal-in-bg);"></i> Check-In Time
                                </span>
                                <input type="time" class="cal-in-time-input" value="${checkInTime}" min="08:00" max="22:00" style="width: 100%; padding: 0.5rem 0.65rem; border-radius: 8px; border: 1.5px solid var(--cal-border); background: var(--cal-card-bg); color: var(--cal-text); font-weight: 800; font-family: inherit; font-size: 0.92rem;">
                            </div>
                            <div>
                                <span style="font-size: 0.75rem; font-weight: 800; color: var(--cal-text-muted); display: block; margin-bottom: 4px;">
                                    <i class="fa-solid fa-plane-departure" style="color: var(--cal-out-bg);"></i> Check-Out Time
                                </span>
                                <input type="time" class="cal-out-time-input" value="${checkOutTime}" min="08:00" max="22:00" style="width: 100%; padding: 0.5rem 0.65rem; border-radius: 8px; border: 1.5px solid var(--cal-border); background: var(--cal-card-bg); color: var(--cal-text); font-weight: 800; font-family: inherit; font-size: 0.92rem;">
                            </div>
                        </div>
                        `}
                    </div>
                    ` : ''}
                </div>
            </div>
        `;

        // Bind nav buttons (scoped to this container)
        const btnPrev = container.querySelector('#btnCalPrev') || container.querySelector('.cal-btn-nav:first-child');
        const btnNext = container.querySelector('#btnCalNext') || container.querySelector('.cal-btn-nav:last-child');
        const btnBoxIn = container.querySelector('#btnBoxCheckIn');
        const btnBoxOut = container.querySelector('#btnBoxCheckOut');

        // Bind time picker inputs
        if (showTimePicker) {
            const timeIn = container.querySelector('.cal-native-time-input');
            if (timeIn) {
                timeIn.oninput = timeIn.onchange = function() {
                    selectedTime = this.value;
                    container.querySelectorAll('.cal-time-chip').forEach(btn => {
                        const isMatch = btn.dataset.time === selectedTime;
                        btn.style.background = isMatch ? 'var(--cal-in-bg)' : 'var(--cal-card-bg)';
                        btn.style.color = isMatch ? '#ffffff' : 'var(--cal-text)';
                        btn.style.borderColor = isMatch ? 'var(--cal-in-bg)' : 'var(--cal-border)';
                    });
                    syncHiddenInputs();
                    fireRangeSelected();
                };
            }

            container.querySelectorAll('.cal-time-chip').forEach(btn => {
                btn.onclick = function() {
                    selectedTime = this.dataset.time;
                    if (timeIn) timeIn.value = selectedTime;
                    container.querySelectorAll('.cal-time-chip').forEach(b => {
                        const isMatch = b.dataset.time === selectedTime;
                        b.style.background = isMatch ? 'var(--cal-in-bg)' : 'var(--cal-card-bg)';
                        b.style.color = isMatch ? '#ffffff' : 'var(--cal-text)';
                        b.style.borderColor = isMatch ? 'var(--cal-in-bg)' : 'var(--cal-border)';
                    });
                    syncHiddenInputs();
                    fireRangeSelected();
                };
            });

            const inTimeIn = container.querySelector('.cal-in-time-input');
            const outTimeIn = container.querySelector('.cal-out-time-input');
            if (inTimeIn) {
                inTimeIn.oninput = inTimeIn.onchange = function() {
                    checkInTime = this.value;
                    syncHiddenInputs();
                    fireRangeSelected();
                };
            }
            if (outTimeIn) {
                outTimeIn.oninput = outTimeIn.onchange = function() {
                    checkOutTime = this.value;
                    syncHiddenInputs();
                    fireRangeSelected();
                };
            }
        }

        const currentYearMonth = today.getFullYear() * 12 + today.getMonth();
        const viewYearMonth = viewDate.getFullYear() * 12 + viewDate.getMonth();
        if (btnPrev) btnPrev.disabled = viewYearMonth <= currentYearMonth;

        if (btnPrev) {
            btnPrev.onclick = function () {
                viewDate.setMonth(viewDate.getMonth() - 1);
                renderUI();
            };
        }

        if (btnNext) {
            btnNext.onclick = function () {
                viewDate.setMonth(viewDate.getMonth() + 1);
                renderUI();
            };
        }

        if (btnBoxIn) {
            btnBoxIn.onclick = function () {
                if (isSingleDate) return;
                selectingStep = 'checkin';
                renderUI();
            };
        }

        if (btnBoxOut) {
            btnBoxOut.onclick = function () {
                if (checkInDate) {
                    selectingStep = 'checkout';
                    renderUI();
                }
            };
        }

        container.querySelectorAll('.cal-dur-btn').forEach(btn => {
            btn.onclick = function (e) {
                e.stopPropagation();
                const nights = parseInt(this.dataset.dur, 10);
                selectedNights = nights;
                if (!checkInDate) {
                    checkInDate = new Date();
                    checkInDate.setHours(0, 0, 0, 0);
                }
                const targetOut = addDays(checkInDate, nights);
                if (checkRangeOverlap(checkInDate, targetOut)) {
                    showToastAlert(`⚠️ Cannot select ${nights} night(s) from ${formatDisplayDate(checkInDate)} because some dates in this range are already booked.`);
                    return;
                }
                checkOutDate = targetOut;
                selectingStep = 'complete';
                syncHiddenInputs();
                renderUI();
                fireRangeSelected();
            };
        });

        renderDays();
    }

    function showToastAlert(msg) {
        const banner = container.querySelector('#calAlertBanner');
        const msgEl = container.querySelector('#calAlertBannerMsg');
        if (banner && msgEl) {
            msgEl.innerHTML = msg;
            banner.style.display = 'flex';
            clearTimeout(alertTimer);
            alertTimer = setTimeout(() => {
                if (!hasBookingConflict()) banner.style.display = 'none';
            }, 4500);
        }
    }

    function renderDays() {
        const gridBody = container.querySelector('.cal-days-grid-inner') || container.querySelector('#calDaysGridBody');
        if (!gridBody) return;
        gridBody.innerHTML = '';

        const year = viewDate.getFullYear();
        const month = viewDate.getMonth();

        const firstDayOfWeek = new Date(year, month, 1).getDay();
        const totalDaysInMonth = new Date(year, month + 1, 0).getDate();

        // Empty cells for offset
        for (let i = 0; i < firstDayOfWeek; i++) {
            const emptyCell = document.createElement('div');
            emptyCell.className = 'cal-day-cell empty';
            gridBody.appendChild(emptyCell);
        }

        // Days of current month
        for (let day = 1; day <= totalDaysInMonth; day++) {
            const cellDate = new Date(year, month, day, 0, 0, 0);
            const cellTime = cellDate.getTime();
            const dateStr = formatDate(cellDate);
            const isBooked = isDateBooked(dateStr);

            const dayCell = document.createElement('div');
            dayCell.className = 'cal-day-cell';
            dayCell.dataset.date = dateStr;

            if (cellTime < today.getTime()) dayCell.classList.add('disabled');
            if (cellTime === today.getTime()) dayCell.classList.add('today');

            const isCheckIn = checkInDate && cellTime === checkInDate.getTime();
            const isCheckOut = checkOutDate && cellTime === checkOutDate.getTime();

            let isInRange = false;
            if (!isSingleDate) {
                if (checkInDate && checkOutDate) {
                    isInRange = cellTime > checkInDate.getTime() && cellTime < checkOutDate.getTime();
                } else if (checkInDate && hoveredDate && selectingStep === 'checkout') {
                    isInRange = cellTime > checkInDate.getTime() && cellTime < hoveredDate.getTime();
                }
            }

            if (isBooked) dayCell.classList.add('is-booked');
            if (isCheckIn) dayCell.classList.add('is-in');
            if (isCheckOut) dayCell.classList.add('is-out');
            if (isSingleDate && isCheckIn) dayCell.classList.add('single-selected');
            if (isInRange) dayCell.classList.add('in-range');

            let badgeHtml = '';
            if (isBooked && !isCheckIn && !isCheckOut) {
                badgeHtml = '<span class="cal-badge-sub booked">Booked</span>';
            } else if (isSingleDate && isCheckIn) {
                badgeHtml = '<span class="cal-badge-sub">Picked</span>';
            } else if (isCheckIn && isCheckOut) {
                badgeHtml = '<span class="cal-badge-sub">Stay</span>';
            } else if (isCheckIn) {
                badgeHtml = '<span class="cal-badge-sub">In</span>';
            } else if (isCheckOut) {
                badgeHtml = '<span class="cal-badge-sub">Out</span>';
            }

            dayCell.innerHTML = `<span>${day}</span>${badgeHtml}`;

            // Hover preview for range selection
            dayCell.onmouseenter = function () {
                if (!isSingleDate && selectingStep === 'checkout' && checkInDate && cellTime > checkInDate.getTime()) {
                    hoveredDate = cellDate;
                    highlightHoverRange();
                }
            };

            dayCell.onclick = function () {
                handleDateClick(cellDate);
            };

            gridBody.appendChild(dayCell);
        }
    }

    function highlightHoverRange() {
        if (isSingleDate) return;
        if (!checkInDate || !hoveredDate || selectingStep !== 'checkout') return;
        const cells = container.querySelectorAll('.cal-day-cell:not(.empty):not(.disabled)');
        const crossesConflict = checkRangeOverlap(checkInDate, hoveredDate);

        cells.forEach(cell => {
            const d = new Date(cell.dataset.date + 'T00:00:00');
            const t = d.getTime();
            const isRange = t > checkInDate.getTime() && t < hoveredDate.getTime();
            if (isRange) {
                cell.classList.add('in-range');
                cell.style.background = crossesConflict ? 'rgba(239, 68, 68, 0.2)' : '';
            } else if (!checkOutDate || t <= checkInDate.getTime() || t >= checkOutDate.getTime()) {
                cell.classList.remove('in-range');
                cell.style.background = '';
            }
        });
    }

    // Set check-in and auto-configure stay based on selected duration (defaults to 1 night, NOT auto 3 days)
    function setCheckInWithDuration(clickedDate) {
        checkInDate = clickedDate;
        const curNights = Math.max(1, selectedNights || 1);
        const autoOut = addDays(clickedDate, curNights);
        if (!checkRangeOverlap(clickedDate, autoOut)) {
            checkOutDate = autoOut;
            selectingStep = 'complete';
        } else {
            const oneNightOut = addDays(clickedDate, 1);
            if (!checkRangeOverlap(clickedDate, oneNightOut)) {
                checkOutDate = oneNightOut;
                selectedNights = 1;
                selectingStep = 'complete';
            } else {
                checkOutDate = null;
                selectingStep = 'checkout';
            }
        }
    }

    function handleDateClick(clickedDate) {
        const clickedTime = clickedDate.getTime();
        const clickedStr = formatDate(clickedDate);

        if (clickedTime < today.getTime()) return; // Past date

        if (isSingleDate) {
            if (isDateBooked(clickedStr)) {
                showToastAlert(`⚠️ <strong>${formatDisplayDate(clickedDate)}</strong> is not available! Please choose an available date.`);
                return;
            }
            checkInDate = clickedDate;
            checkOutDate = null;
            selectingStep = 'complete';
            syncHiddenInputs();
            renderUI();
            onRangeSelected({
                checkIn: formatDate(checkInDate),
                isAvailable: true
            });
            return;
        }

        if (selectingStep === 'checkin' || !checkInDate || selectingStep === 'complete') {
            if (isDateBooked(clickedStr)) {
                showToastAlert(`⚠️ <strong>${formatDisplayDate(clickedDate)}</strong> is already booked! Please select an available arrival date.`);
                return;
            }
            setCheckInWithDuration(clickedDate);
        } else if (selectingStep === 'checkout') {
            if (clickedTime > checkInDate.getTime()) {
                if (checkRangeOverlap(checkInDate, clickedDate)) {
                    showToastAlert(`⚠️ The selected stay range overlaps with an existing reservation. Please pick dates that do not include booked days.`);
                    return;
                }
                checkOutDate = clickedDate;
                selectedNights = calculateNights(checkInDate, checkOutDate);
                selectingStep = 'complete';
            } else {
                // Clicked on/before check-in: treat as a new check-in
                if (isDateBooked(clickedStr)) {
                    showToastAlert(`⚠️ <strong>${formatDisplayDate(clickedDate)}</strong> is already booked! Please choose an available arrival date.`);
                    return;
                }
                setCheckInWithDuration(clickedDate);
            }
        }

        syncHiddenInputs();
        renderUI();
        fireRangeSelected();
    }

    function fireRangeSelected() {
        if (isSingleDate) {
            onRangeSelected({
                checkIn: formatDate(checkInDate),
                time: selectedTime,
                isAvailable: !isDateBooked(formatDate(checkInDate))
            });
            return;
        }
        if (checkInDate && checkOutDate) {
            onRangeSelected({
                checkIn: formatDate(checkInDate),
                checkOut: formatDate(checkOutDate),
                checkInTime: checkInTime,
                checkOutTime: checkOutTime,
                nights: calculateNights(checkInDate, checkOutDate),
                isAvailable: !checkRangeOverlap(checkInDate, checkOutDate) && !isDateBooked(formatDate(checkInDate))
            });
        }
    }

    function syncHiddenInputs() {
        const inInput = document.getElementById(checkInInputId);
        const outInput = document.getElementById(checkOutInputId);
        if (inInput && checkInDate) inInput.value = formatDate(checkInDate);
        if (outInput && checkOutDate) outInput.value = formatDate(checkOutDate);

        if (timeInputId) {
            const tEl = document.getElementById(timeInputId);
            if (tEl) tEl.value = selectedTime;
        }
        if (checkInTimeInputId) {
            const citEl = document.getElementById(checkInTimeInputId);
            if (citEl) citEl.value = checkInTime;
        }
        if (checkOutTimeInputId) {
            const cotEl = document.getElementById(checkOutTimeInputId);
            if (cotEl) cotEl.value = checkOutTime;
        }
    }

    container._setStayDuration = function(nights) {
        selectedNights = Math.max(1, parseInt(nights, 10) || 1);
        if (!checkInDate) {
            checkInDate = new Date();
            checkInDate.setHours(0, 0, 0, 0);
        }
        const targetOut = addDays(checkInDate, selectedNights);
        if (checkRangeOverlap(checkInDate, targetOut)) {
            showToastAlert(`⚠️ Cannot select ${selectedNights} night(s) from ${formatDisplayDate(checkInDate)} because some dates in this range are already booked.`);
            return false;
        }
        checkOutDate = targetOut;
        selectingStep = 'complete';
        syncHiddenInputs();
        renderUI();
        fireRangeSelected();
        return true;
    };

    // Initial sync and render
    syncHiddenInputs();
    renderUI();
    fireRangeSelected();
}