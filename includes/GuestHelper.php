<?php
/**
 * Guest Information Normalization & Display Utility
 * The Blessed Purple Bamboo Beach Resort
 */

class GuestHelper {

    /**
     * Normalize a guest's full name to Title Case and standardized "First [Middle] Last" format.
     * Corrects inverted names like "Marquez, Iuhence" -> "Iuhence Marquez"
     * Handles mixed casing like "VINCE GIAN" -> "Vince Gian" or "vince gian" -> "Vince Gian"
     */
    public static function normalizeName(?string $rawName): string {
        $raw = trim($rawName ?? '');
        if ($raw === '' || strcasecmp($raw, 'guest') === 0 || strcasecmp($raw, 'resort guest') === 0) {
            return 'Resort Guest';
        }

        // If format is "Lastname, Firstname [Middle] [Suffix]"
        if (strpos($raw, ',') !== false) {
            $parts = explode(',', $raw, 2);
            $last = trim($parts[0] ?? '');
            $first = trim($parts[1] ?? '');
            if (!empty($first) && !empty($last)) {
                $raw = $first . ' ' . $last;
            } else {
                $raw = trim($first . ' ' . $last);
            }
        }

        // Clean extra spaces
        $raw = preg_replace('/\s+/', ' ', $raw);

        // Title Case capitalization (preserving common Philippine prefixes/suffixes)
        $words = explode(' ', $raw);
        $capitalized = [];
        $lowerExceptions = ['de', 'del', 'dela', 'san', 'van', 'von'];
        $upperExceptions = ['II', 'III', 'IV', 'V', 'JR', 'SR', 'MD', 'RN'];

        foreach ($words as $idx => $w) {
            $wUpper = strtoupper($w);
            $wLower = strtolower($w);

            if (in_array($wUpper, $upperExceptions)) {
                $capitalized[] = $wUpper;
            } elseif ($idx > 0 && in_array($wLower, $lowerExceptions)) {
                $capitalized[] = $wLower;
            } else {
                $capitalized[] = mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return implode(' ', $capitalized);
    }

    /**
     * Format Philippine phone number consistently (e.g., "0961 261 3415")
     */
    public static function formatPhone(?string $rawPhone): string {
        $clean = preg_replace('/[^0-9]/', '', $rawPhone ?? '');
        
        // Convert "+63" or "63" prefix to "0"
        if (str_starts_with($clean, '63') && strlen($clean) === 12) {
            $clean = '0' . substr($clean, 2);
        }

        // 11-digit mobile: 09XX XXX XXXX
        if (strlen($clean) === 11 && str_starts_with($clean, '09')) {
            return substr($clean, 0, 4) . ' ' . substr($clean, 4, 3) . ' ' . substr($clean, 7, 4);
        }

        // 10-digit mobile: 9XX XXX XXXX -> 09XX XXX XXXX
        if (strlen($clean) === 10 && str_starts_with($clean, '9')) {
            return '0' . substr($clean, 0, 3) . ' ' . substr($clean, 3, 3) . ' ' . substr($clean, 6, 4);
        }

        return !empty($rawPhone) ? trim($rawPhone) : 'N/A';
    }

    /**
     * Format a uniform Profile ID (e.g. PBR-GST-00205)
     */
    public static function formatProfileId($id, string $role = 'guest'): string {
        if (!empty($id)) {
            if (is_numeric($id)) {
                $num = intval($id);
                $prefix = ($role === 'admin') ? 'PBR-ADM' : (($role === 'staff') ? 'PBR-STF' : 'PBR-GST');
                return sprintf('%s-%05d', $prefix, $num);
            }
            if (is_string($id) && preg_match('/^PBR-(GST|ADM|STF)-\d+$/i', trim($id))) {
                return strtoupper(trim($id));
            }
        }
        return 'PBR-GST-00101';
    }

    /**
     * Render a standardized Guest Info Badge component for admin interfaces
     */
    public static function renderGuestBadge(array $booking, bool $showContact = true): string {
        $name = self::normalizeName($booking['guest_name'] ?? $booking['registered_name'] ?? 'Resort Guest');
        $phone = self::formatPhone($booking['guest_phone'] ?? $booking['registered_phone'] ?? '');
        $email = htmlspecialchars($booking['guest_email'] ?? $booking['registered_email'] ?? '');
        $profileId = htmlspecialchars($booking['profile_id'] ?? 'PBR-GST-00101');
        $isReg = !empty($booking['is_registered']) || !empty($booking['user_id']);

        $badgeHtml = $isReg
            ? '<span class="admin-badge admin-badge-purple" style="font-size: 0.68rem; margin-bottom: 3px; display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-id-badge"></i> ' . $profileId . '</span>'
            : '<span class="admin-badge admin-badge-gold" style="font-size: 0.68rem; margin-bottom: 3px; display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-user"></i> Walk-In</span>';

        $displayName = mb_strlen($name) > 14 ? mb_substr($name, 0, 12) . '...' : $name;
        $html = '<div class="admin-guest-normalized-card">';
        $html .= $badgeHtml;
        $html .= '<div style="font-weight: 700; color: var(--text-main); font-size: 0.88rem; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;" title="' . htmlspecialchars($name) . '">' . htmlspecialchars($displayName) . '</div>';

        if ($showContact) {
            $html .= '<div style="display: flex; flex-direction: column; gap: 2px; margin-top: 3px; font-size: 0.75rem; color: var(--text-sub);">';
            if (!empty($phone) && $phone !== 'N/A') {
                $html .= '<span style="display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><i class="fa-solid fa-phone" style="font-size: 0.7rem; opacity: 0.7;"></i> ' . htmlspecialchars($phone) . '</span>';
            }
            if (!empty($email)) {
                $html .= '<span style="display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;" title="' . $email . '"><i class="fa-solid fa-envelope" style="font-size: 0.7rem; opacity: 0.7;"></i> ' . $email . '</span>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}
