<?php
$cssFile = 'c:/xamppp/htdocs/purple_bamboo/admin-style.css';
$css = file_get_contents($cssFile);

$replacements = [
    // Variables
    '--admin-bg: #0f172a;' => '--admin-bg: #f8fafc;',
    '--admin-card-bg: #1e293b;' => '--admin-card-bg: #ffffff;',
    '--admin-card-border: #334155;' => '--admin-card-border: #e2e8f0;',
    '--admin-sidebar-border: #1e293b;' => '--admin-sidebar-border: #1e1035;',
    '--admin-header-bg: #0f172a;' => '--admin-header-bg: #ffffff;',
    '--text-main: #ffffff;' => '--text-main: #0f172a;',
    '--text-sub: #cbd5e1;' => '--text-sub: #334155;',
    '--text-muted: #94a3b8;' => '--text-muted: #64748b;',
    
    // Hardcoded
    'background: #0f172a;' => 'background: #f8fafc;',
    'background: #1e293b;' => 'background: #ffffff;',
    'color: #ffffff;' => 'color: var(--text-main);', // Generic white text to main text
    'rgba(30, 41, 59, 0.5)' => 'rgba(241, 245, 249, 0.8)', // table hover
    'background: rgba(15, 23, 42, 0.6);' => 'background: #f1f5f9;', // admin sidebar footer, etc.
];

// Special care for sidebar brand and links so they stay white
// We will do generic replacements first, then restore the sidebar.
$css = str_replace(array_keys($replacements), array_values($replacements), $css);

// Fix sidebar text
$css = preg_replace('/(\.admin-sidebar-brand\s*{[^}]*color:\s*)var\(--text-main\)/s', '$1#ffffff', $css);
$css = preg_replace('/(\.admin-nav-link\s*{[^}]*color:\s*)var\(--text-main\)/s', '$1#ffffff', $css);
$css = preg_replace('/(\.admin-nav-link:hover,\s*\.admin-nav-link\.active\s*{[^}]*color:\s*)var\(--text-main\)/s', '$1#ffffff', $css);
$css = preg_replace('/(\.admin-nav-link i\s*{[^}]*color:\s*)var\(--text-main\)/s', '$1#c084fc', $css);
$css = preg_replace('/(\.admin-sidebar-brand span\s*{[^}]*color:\s*)var\(--text-main\)/s', '$1var(--gold-glow)', $css);
$css = preg_replace('/(\.admin-btn-primary\s*{[^}]*color:\s*)var\(--text-main\)/s', '$1#ffffff', $css);
$css = preg_replace('/(\.admin-btn-gold\s*{[^}]*color:\s*)var\(--text-main\)/s', '$1#ffffff', $css);
$css = preg_replace('/(\.btn-book-kubo[^}]*color:\s*)var\(--text-main\)( !important)?;?/s', '$1#ffffff !important;', $css);

file_put_contents($cssFile, $css);
echo "Done";
?>
