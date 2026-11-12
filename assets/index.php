<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/inventory_store.php';
require_once __DIR__ . '/review_store.php';
require_once __DIR__ . '/content_store.php';
require_once __DIR__ . '/booking_store.php';

$home_content = ContentStore::getContent();

$all_inventory_kubos = InventoryStore::getAllKubos();
$featured_kubos = array_slice($all_inventory_kubos, 0, 3);
$homepage_reviews = array_slice(ReviewStore::getAll(), 0, 3);

$user_role = $_SESSION['role'] ?? 'guest';
$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#2e1065">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">
    <title>The Blessed Purple Bamboo Resort | Luxury Getaway & House Kubos</title>
    <link rel="icon" type="image/jpeg" href="assets/logo.jpg">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
    /* ==========================================================================
       HOMEPAGE — RESPONSIVE DESIGN SYSTEM (Mobile-First)
       Tested: 280px → 1920px+ | iOS Safari | Android Chrome | Firefox
       ========================================================================== */

    /* --- Global box-sizing safety --- */
    .main-content *,
    .main-content *::before,
    .main-content *::after {
        box-sizing: border-box;
    }

    /* --- Prevent horizontal scroll on all devices --- */
    html, body {
        overflow-x: hidden;
        max-width: 100%;
        -webkit-text-size-adjust: 100%;
    }

    .main-content img {
        max-width: 100%;
        height: auto;
    }

    /* ==========================================================================
       1. ANNOUNCEMENT BAR
       ========================================================================== */
    .home-announcement {
        background: linear-gradient(90deg, #7c3aed, #d97706);
        color: #fff;
        padding: 0.85rem 1.1rem;
        border-radius: 12px;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        font-weight: 700;
        font-size: 0.88rem;
        box-shadow: 0 6px 18px rgba(124, 58, 237, 0.25);
        line-height: 1.4;
    }

    .home-announcement-text {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1 1 220px;
        min-width: 0;
    }

    .home-announcement-text span {
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .home-announcement-cta {
        background: #fbbf24;
        color: #000;
        font-weight: 800;
        font-size: 0.8rem;
        padding: 8px 16px;
        border-radius: 8px;
        white-space: nowrap;
        text-decoration: none;
        flex-shrink: 0;
        min-height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s ease;
    }

    .home-announcement-cta:hover {
        transform: translateY(-1px);
    }

    /* ==========================================================================
       2. HERO SECTION
       ========================================================================== */
    .home-hero {
        position: relative;
        border-radius: 20px;
        padding: clamp(1.75rem, 5vw, 4.5rem) clamp(1rem, 4vw, 3rem);
        margin-bottom: clamp(1.5rem, 3vw, 2.5rem);
        overflow: hidden;
        background:
            linear-gradient(135deg, rgba(15, 8, 38, 0.9) 0%, rgba(46, 16, 101, 0.75) 50%, rgba(15, 8, 38, 0.92) 100%),
            url('assets/hundred_islands_hero.jpg') center/cover no-repeat;
        box-shadow: 0 20px 45px rgba(15, 8, 38, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: #ffffff;
    }

    .home-hero-inner {
        max-width: 820px;
        position: relative;
        z-index: 2;
    }

    .home-hero-title {
        font-size: clamp(1.5rem, 5.5vw, 3rem);
        margin-bottom: 0.85rem;
        color: #ffffff;
        line-height: 1.15;
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        word-break: break-word;
    }

    .home-hero-subtitle {
        font-size: clamp(0.88rem, 2.3vw, 1.15rem);
        margin-bottom: clamp(1.25rem, 3vw, 2rem);
        color: rgba(255, 255, 255, 0.92);
        line-height: 1.6;
    }



    .home-hero-pills {
        display: flex;
        gap: 0.85rem;
        flex-wrap: wrap;
        border-top: 1px solid rgba(255, 255, 255, 0.14);
        padding-top: clamp(1rem, 2vw, 1.25rem);
    }

    .home-hero-pill {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #ffffff;
        font-weight: 700;
        font-size: clamp(0.78rem, 1.5vw, 0.9rem);
        line-height: 1.4;
    }

    /* ==========================================================================
       3. FEATURES STRIP
       ========================================================================== */
    .home-features {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
        gap: 1rem;
        margin-bottom: clamp(1.75rem, 3vw, 2.75rem);
    }

    .home-feature-card {
        background: #fff;
        border: 1.5px solid #e9d5ff;
        border-radius: 14px;
        padding: 1.15rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        box-shadow: 0 6px 18px rgba(124, 58, 237, 0.06);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .home-feature-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 26px rgba(124, 58, 237, 0.12);
    }

    .home-feature-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        background: rgba(124, 58, 237, 0.08);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .home-feature-title {
        color: #0f172a;
        font-size: 1rem;
        margin: 0 0 2px 0;
        font-family: 'Outfit', sans-serif;
        font-weight: 700;
        line-height: 1.3;
    }

    .home-feature-desc {
        color: #475569;
        font-size: 0.82rem;
        margin: 0;
        line-height: 1.4;
    }



    /* ==========================================================================
       5. SECTION HEADERS
       ========================================================================== */
    .home-section-header {
        margin-bottom: clamp(1rem, 2.5vw, 1.5rem);
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .home-section-header > div:first-child {
        flex: 1 1 280px;
        min-width: 0;
    }

    .home-section-header h2 {
        font-size: clamp(1.25rem, 3.5vw, 1.9rem);
        color: #0f172a;
        margin: 0;
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        line-height: 1.25;
        word-break: break-word;
    }

    .home-section-header p {
        color: #475569;
        font-size: clamp(0.82rem, 1.8vw, 0.92rem);
        margin: 0.35rem 0 0 0;
        line-height: 1.5;
    }

    .home-section-cta {
        background: #fff;
        border: 1.5px solid #c4b5fd;
        color: #7c3aed;
        font-weight: 800;
        font-size: 0.85rem;
        padding: 0.6rem 1.15rem;
        border-radius: 8px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        transition: all 0.2s ease;
        min-height: 44px;
        justify-content: center;
    }

    .home-section-cta:hover {
        background: #f3e8ff;
        transform: translateY(-1px);
    }

    /* ==========================================================================
       6. CONTENT GRIDS (Kubos, Activities, Reviews)
       ========================================================================== */
    .home-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
        gap: clamp(0.85rem, 2vw, 1.25rem);
        margin-bottom: clamp(2rem, 4vw, 3rem);
    }

    /* ==========================================================================
       7. KUBO CARDS
       ========================================================================== */
    .home-kubo-card {
        background: #fff;
        border: 1.5px solid #e9d5ff;
        border-radius: 14px;
        padding: clamp(1rem, 2vw, 1.25rem);
        display: flex;
        flex-direction: column;
        box-shadow: 0 8px 24px rgba(124, 58, 237, 0.07);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        min-width: 0;
    }

    .home-kubo-card:hover {
        transform: translateY(-4px);
        border-color: #7c3aed;
        box-shadow: 0 15px 35px rgba(124, 58, 237, 0.16);
    }

    .home-kubo-card.is-renovation {
        border-color: #ea580c;
    }

    .home-kubo-img {
        position: relative;
        width: 100%;
        aspect-ratio: 16 / 10;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 1rem;
        background: #1e1035;
    }

    .home-kubo-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.35s ease;
    }

    .home-kubo-card:hover .home-kubo-img img {
        transform: scale(1.04);
    }

    .home-kubo-reno-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        background: #ea580c;
        color: #fff;
        padding: 4px 10px;
        border-radius: 20px;
        font-weight: 800;
        font-size: 0.7rem;
        z-index: 5;
        line-height: 1.3;
    }

    .home-kubo-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .home-kubo-title {
        margin-bottom: 0.5rem;
        color: #7c02ff;
        font-size: clamp(1.05rem, 2.2vw, 1.2rem);
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        line-height: 1.3;
        word-break: break-word;
    }

    .home-kubo-desc {
        color: #475569;
        font-size: 0.88rem;
        margin-bottom: 1rem;
        line-height: 1.55;
        flex-grow: 1;
    }

    .home-kubo-reno-panel {
        background: #fff7ed;
        border: 1.5px solid #ea580c;
        border-left: 5px solid #ea580c;
        padding: 0.75rem 0.9rem;
        border-radius: 8px;
        margin-bottom: 1rem;
    }

    .home-kubo-badges {
        display: flex;
        gap: 0.4rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .home-kubo-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid #e9d5ff;
        padding-top: 0.85rem;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .home-kubo-price-label {
        font-size: 0.7rem;
        color: #64748b;
        display: block;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .home-kubo-price {
        font-weight: 900;
        color: #f59e0b;
        font-size: clamp(1.15rem, 2.5vw, 1.3rem);
        font-family: 'Outfit', sans-serif;
    }

    /* ==========================================================================
       8. ACTIVITY CARDS
       ========================================================================== */
    .home-activity-card {
        background: #fff;
        border: 1.5px solid #e9d5ff;
        border-radius: 14px;
        padding: clamp(1rem, 2vw, 1.25rem);
        display: flex;
        flex-direction: column;
        box-shadow: 0 8px 24px rgba(124, 58, 237, 0.07);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        min-width: 0;
    }

    .home-activity-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 15px 35px rgba(124, 58, 237, 0.16);
    }

    .home-activity-img {
        width: 100%;
        aspect-ratio: 16 / 10;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 1rem;
        background: #1e1035;
    }

    .home-activity-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .home-activity-title {
        margin-bottom: 0.5rem;
        color: #7c02ff;
        font-size: clamp(1.05rem, 2.2vw, 1.2rem);
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        line-height: 1.3;
        word-break: break-word;
    }

    .home-activity-desc {
        color: #475569;
        font-size: 0.88rem;
        margin-bottom: 1rem;
        line-height: 1.55;
        flex-grow: 1;
    }

    /* ==========================================================================
       9. REVIEW CARDS
       ========================================================================== */
    .home-review-card {
        background: #fff;
        border: 1.5px solid #e9d5ff;
        border-radius: 14px;
        padding: clamp(1rem, 2vw, 1.25rem);
        box-shadow: 0 8px 24px rgba(124, 58, 237, 0.07);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        min-width: 0;
    }

    .home-review-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(124, 58, 237, 0.14);
    }

    /* ==========================================================================
       10. GCASH SHOWCASE
       ========================================================================== */
    .home-gcash-card {
        background: linear-gradient(135deg, #0d122e 0%, #1e1342 50%, #110d29 100%);
        border: 1.5px solid rgba(59, 130, 246, 0.4);
        box-shadow: 0 12px 36px rgba(0, 0, 0, 0.45), 0 0 20px rgba(59, 130, 246, 0.15);
        border-radius: 18px;
        padding: clamp(1.25rem, 3vw, 2.25rem);
        margin-bottom: clamp(1.75rem, 3vw, 2.5rem);
        position: relative;
        overflow: hidden;
    }

    .home-gcash-layout {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.75rem;
        align-items: center;
    }

    @media (min-width: 900px) {
        .home-gcash-layout {
            grid-template-columns: 1.3fr 1fr;
            gap: 2.5rem;
        }
    }

    .home-gcash-account-box {
        background: rgba(15, 8, 38, 0.7);
        border: 1px solid rgba(139, 92, 246, 0.3);
        border-radius: 12px;
        padding: 1rem 1.15rem;
        margin-bottom: 1.25rem;
    }

    .home-gcash-account-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
    }

    .home-gcash-account-row + .home-gcash-account-row {
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        padding-top: 0.85rem;
        margin-top: 0.85rem;
    }

    .home-gcash-steps {
        margin-bottom: 1.25rem;
    }

    .home-gcash-step {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        font-size: clamp(0.82rem, 1.6vw, 0.88rem);
        color: #e2e8f0;
        line-height: 1.5;
        margin-bottom: 0.45rem;
    }

    .home-gcash-cta-row {
        display: flex;
        gap: 0.6rem;
        flex-wrap: wrap;
    }

    .home-gcash-cta-row a,
    .home-gcash-cta-row button {
        flex: 1 1 180px;
        min-height: 46px;
        justify-content: center;
        padding: 0.75rem 1rem;
        font-size: 0.9rem;
    }

    .home-gcash-qr-wrap {
        text-align: center;
        order: -1;
    }

    @media (min-width: 900px) {
        .home-gcash-qr-wrap {
            order: 0;
        }
    }

    .home-gcash-qr-card {
        background: #ffffff;
        padding: 12px;
        border-radius: 14px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.6), 0 0 20px rgba(0, 125, 254, 0.3);
        cursor: pointer;
        max-width: 260px;
        margin: 0 auto;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .home-gcash-qr-card:hover {
        transform: scale(1.03);
    }

    .home-gcash-qr-card img {
        width: 100%;
        max-width: 220px;
        height: auto;
        object-fit: contain;
        display: block;
        margin: 0 auto;
        border-radius: 4px;
    }

    /* ==========================================================================
       11. GUARANTEE BANNER
       ========================================================================== */
    .home-guarantee {
        background: linear-gradient(135deg, #1e1035 0%, #31104e 50%, #1e1035 100%);
        border: 1px solid rgba(139, 92, 246, 0.4);
        border-radius: 18px;
        text-align: center;
        padding: clamp(1.75rem, 4vw, 2.5rem) clamp(1rem, 3vw, 1.75rem);
        margin-bottom: clamp(1.75rem, 3vw, 2.5rem);
    }

    .home-guarantee h2 {
        font-size: clamp(1.25rem, 3.5vw, 1.9rem);
        color: #7c02ff;
        margin-bottom: 0.75rem;
        font-family: 'Outfit', sans-serif;
        line-height: 1.25;
        word-break: break-word;
    }

    .home-guarantee p {
        color: #475569;
        font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        margin-bottom: 1.5rem;
        line-height: 1.55;
    }

    .home-guarantee a {
        min-height: 48px;
        padding: 0.85rem 1.75rem;
        font-size: clamp(0.85rem, 1.8vw, 1rem);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    /* ==========================================================================
       12. GCASH QR MODAL
       ========================================================================== */
    .home-qr-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(8, 4, 20, 0.88);
        -webkit-backdrop-filter: blur(8px);
        backdrop-filter: blur(8px);
        align-items: center;
        justify-content: center;
        padding: max(1rem, env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right)) max(1rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left));
    }

    .home-qr-modal.active {
        display: flex;
    }

    .home-qr-modal-content {
        background: linear-gradient(135deg, #140b2e 0%, #1c0f3d 100%);
        border: 2px solid #3b82f6;
        border-radius: 20px;
        padding: clamp(1.25rem, 3vw, 2rem);
        max-width: 420px;
        width: 100%;
        max-height: 92vh;
        overflow-y: auto;
        text-align: center;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8), 0 0 30px rgba(59, 130, 246, 0.3);
        position: relative;
    }

    .home-qr-modal-close {
        position: absolute;
        top: 12px;
        right: 12px;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #ffffff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        transition: background 0.2s;
    }

    .home-qr-modal-close:hover {
        background: rgba(244, 63, 94, 0.5);
    }

    .home-qr-modal-qr-wrap {
        background: #ffffff;
        padding: 14px;
        border-radius: 14px;
        display: inline-block;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        margin-bottom: 1.25rem;
        max-width: 100%;
    }

    .home-qr-modal-qr-wrap img {
        max-width: min(280px, 80vw);
        width: 100%;
        height: auto;
        display: block;
        border-radius: 4px;
    }

    /* ==========================================================================
       13. FLOATING RESERVE BUTTON
       ========================================================================== */
    .hs-btn-float-reply {
        position: fixed;
        bottom: max(1.25rem, env(safe-area-inset-bottom));
        right: max(1.25rem, env(safe-area-inset-right));
        z-index: 999;
    }

    @media (max-width: 480px) {
        .hs-btn-float-reply {
            bottom: max(0.85rem, env(safe-area-inset-bottom));
            right: max(0.85rem, env(safe-area-inset-right));
            padding: 0.75rem 1.15rem;
            font-size: 0.85rem;
        }
    }

    /* ==========================================================================
       14. UTILITY — hide text on tiny screens
       ========================================================================== */
    .hide-xs {
        display: inline;
    }

    @media (max-width: 400px) {
        .hide-xs {
            display: none;
        }
    }

    /* ==========================================================================
       15. RESPONSIVE BREAKPOINTS — All Devices
       ========================================================================== */

    /* --- Extra small phones (iPhone SE, Galaxy Fold outer) --- */
    @media (max-width: 360px) {
        .home-hero {
            border-radius: 14px;
            padding: 1.5rem 0.9rem;
        }

        .home-hero-pill {
            font-size: 0.76rem;
        }

        .home-announcement {
            padding: 0.7rem 0.85rem;
            font-size: 0.8rem;
        }

        .home-feature-card {
            padding: 0.9rem 1rem;
        }

        .home-feature-icon {
            width: 38px;
            height: 38px;
            font-size: 1rem;
        }

        .home-gcash-card {
            padding: 1.15rem 0.9rem;
        }

        .home-gcash-qr-card {
            max-width: 200px;
            padding: 10px;
        }

        .home-section-cta {
            width: 100%;
            justify-content: center;
        }

        .home-kubo-footer {
            flex-direction: column;
            align-items: stretch;
        }

        .home-kubo-footer > * {
            width: 100%;
            justify-content: center;
            text-align: center;
        }
    }

    /* --- Small phones --- */
    @media (max-width: 480px) {


        .home-hero-pills {
            flex-direction: column;
            gap: 0.6rem;
            padding-top: 1rem;
        }


        .home-gcash-cta-row a,
        .home-gcash-cta-row button {
            flex: 1 1 100%;
        }

        .home-announcement {
            flex-direction: column;
            align-items: flex-start;
            text-align: left;
        }

        .home-announcement-cta {
            width: 100%;
        }

        .home-qr-modal-content {
            border-radius: 16px;
            padding: 1.5rem 1.15rem;
        }
    }

    /* --- Phones landscape (iPhone landscape, etc.) --- */
    @media (max-height: 500px) and (orientation: landscape) {
        .home-hero {
            padding: 1.5rem 1.25rem;
        }

        .home-hero-title {
            font-size: clamp(1.15rem, 3.5vw, 1.5rem);
        }

        .home-hero-subtitle {
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        .home-hero-pills {
            display: none;
        }

        .hs-btn-float-reply {
            bottom: 0.75rem;
            right: 0.75rem;
            padding: 0.55rem 1rem;
            font-size: 0.78rem;
        }
    }

    /* --- Tablets portrait & small laptops --- */
    @media (min-width: 769px) and (max-width: 1024px) {
        .home-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .home-features {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    /* --- Large laptops & desktops --- */
    @media (min-width: 1280px) {
        .home-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    /* --- Ultra-wide / 4K --- */
    @media (min-width: 1600px) {
        .home-hero {
            padding: 5rem 3.5rem;
        }

        .home-grid {
            gap: 1.75rem;
        }
    }

    /* --- Reduce motion for accessibility --- */
    @media (prefers-reduced-motion: reduce) {
        .home-hero *,
        .home-feature-card,
        .home-kubo-card,
        .home-activity-card,
        .home-review-card,
        .home-gcash-qr-card {
            transition: none !important;
            animation: none !important;
        }
    }

    /* --- Dark mode support (optional but nice) --- */
    @media (prefers-color-scheme: dark) {
        /* Kept light for brand consistency; enable below if needed
        .home-feature-card,
        .home-kubo-card,
        .home-activity-card,
        .home-review-card {
            background: #1e1035;
            color: #fff;
        }
        */
    }

    /* --- Print styles --- */
    @media print {
        .hs-btn-float-reply,
        .home-qr-modal,
        .home-announcement {
            display: none !important;
        }

        .home-hero {
            background: #f1f5f9 !important;
            color: #0f172a !important;
        }
    }
    </style>
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="main-content">

        <!-- ==================== ANNOUNCEMENTS ==================== -->
        <?php if (!empty($home_content['announcement_enabled']) && !empty($home_content['announcement_text'])): ?>
            <div class="home-announcement">
                <div class="home-announcement-text">
                    <i class="fa-solid fa-bullhorn" style="color: #fbbf24; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($home_content['announcement_text']) ?></span>
                </div>
                <?php if (!empty($home_content['announcement_link'])): ?>
                    <a href="<?= htmlspecialchars($home_content['announcement_link']) ?>" class="home-announcement-cta">
                        View Promo &rarr;
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['registered']) && $_GET['registered'] == 1): ?>
            <div class="home-announcement" style="background: rgba(16,185,129,0.15); color: #047857; box-shadow: none; border: 1px solid #a7f3d0;">
                <div class="home-announcement-text" style="color: #047857;">
                    <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.15rem; flex-shrink: 0;"></i>
                    <span>Welcome to Purple Bamboo Resort, <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Guest') ?></strong>! Account registered.</span>
                </div>
                <a href="user-dashboard.php" class="home-announcement-cta">
                    <i class="fa-solid fa-user"></i> Guest Profile
                </a>
            </div>
        <?php elseif (isset($_GET['login']) && $_GET['login'] == 1): ?>
            <div class="home-announcement" style="background: rgba(124,58,237,0.15); color: #5b21b6; box-shadow: none; border: 1px solid #ddd6fe;">
                <div class="home-announcement-text" style="color: #5b21b6;">
                    <i class="fa-solid fa-right-to-bracket" style="color: #7c3aed; font-size: 1.15rem; flex-shrink: 0;"></i>
                    <span>Welcome back, <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Guest') ?></strong>!</span>
                </div>
                <a href="user-dashboard.php" class="home-announcement-cta" style="background: #7c3aed; color: #fff;">
                    <i class="fa-solid fa-user"></i> Guest Portal
                </a>
            </div>
        <?php endif; ?>

        <!-- ==================== HERO ==================== -->
        <div class="home-hero">
            <div class="home-hero-inner">
                <div class="resort-pulse-badge">
                    <span class="resort-pulse-dot"></span> <?= htmlspecialchars($home_content['hero_badge']) ?>
                </div>
                <h1 class="home-hero-title">
                    <?= htmlspecialchars($home_content['hero_title']) ?>
                </h1>
                <p class="home-hero-subtitle">
                    <?= htmlspecialchars($home_content['hero_subtitle']) ?>
                </p>


                <div class="home-hero-pills">
                    <div class="home-hero-pill">
                        <i class="fa-solid fa-shield-halved" style="color: #d97706;"></i>
                        <span><?= htmlspecialchars($home_content['hero_pill1'] ?? 'Instant GCash Verification') ?></span>
                    </div>
                    <div class="home-hero-pill">
                        <i class="fa-solid fa-snowflake" style="color: #a78bfa;"></i>
                        <span><?= htmlspecialchars($home_content['hero_pill2'] ?? 'Air-Conditioned Kubos') ?></span>
                    </div>
                    <div class="home-hero-pill">
                        <i class="fa-solid fa-star" style="color: #d97706;"></i>
                        <span><?= htmlspecialchars($home_content['hero_pill3'] ?? '4.9 ★ Guest Rating') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== FEATURES ==================== -->
        <div class="home-features">
            <?php foreach (($home_content['features'] ?? []) as $feat): ?>
                <div class="home-feature-card">
                    <div class="home-feature-icon" style="color: <?= htmlspecialchars($feat['color'] ?? '#7c3aed') ?>;">
                        <i class="fa-solid <?= htmlspecialchars($feat['icon'] ?? 'fa-star') ?>"></i>
                    </div>
                    <div style="min-width: 0;">
                        <h4 class="home-feature-title"><?= htmlspecialchars($feat['title'] ?? '') ?></h4>
                        <p class="home-feature-desc"><?= htmlspecialchars($feat['desc'] ?? '') ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>


        <!-- ==================== FEATURED KUBOS ==================== -->
        <div class="home-section-header">
            <div>
                <h2>
                    <i class="fa-solid fa-house-chimney-window" style="color: #f59e0b;"></i>
                    <?= htmlspecialchars($home_content['kubos_section_title'] ?? 'Featured House Kubos') ?>
                </h2>
                <p><?= htmlspecialchars($home_content['kubos_section_subtitle'] ?? 'Designed with eco-friendly bamboo aesthetics and modern air-conditioned comforts.') ?></p>
            </div>
            <a href="browse_kubos.php" class="home-section-cta">
                View All <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <div class="home-grid">
            <?php foreach ($featured_kubos as $kubo): 
                $k_status = strtolower($kubo['status'] ?? 'available');
                $k_is_reno = ($k_status === 'renovation');
                $k_is_booked_today = false;
                if (class_exists('BookingStore')) {
                    $k_is_booked_today = !BookingStore::isItemAvailable($kubo['kubo_id'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), 'house');
                }
                $k_is_unavailable = ($k_status !== 'available') || $k_is_booked_today;

                $status_label = 'Not Available';
                $badge_bg = 'rgba(239, 68, 68, 0.15)';
                $badge_color = '#ef4444';
                $status_icon = 'fa-solid fa-circle-xmark';

                if ($k_is_reno) {
                    $status_label = 'Under Renovation';
                    $badge_bg = 'rgba(234, 88, 12, 0.15)';
                    $badge_color = '#ea580c';
                    $status_icon = 'fa-solid fa-hammer';
                } elseif ($k_status === 'maintenance') {
                    $status_label = 'Under Maintenance';
                    $badge_bg = 'rgba(234, 88, 12, 0.15)';
                    $badge_color = '#ea580c';
                    $status_icon = 'fa-solid fa-wrench';
                } elseif ($k_status === 'occupied') {
                    $status_label = 'Currently Occupied';
                    $badge_bg = 'rgba(59, 130, 246, 0.15)';
                    $badge_color = '#3b82f6';
                    $status_icon = 'fa-solid fa-user-check';
                } elseif ($k_is_booked_today) {
                    $status_label = 'Booked Today';
                    $badge_bg = 'rgba(239, 68, 68, 0.15)';
                    $badge_color = '#ef4444';
                    $status_icon = 'fa-solid fa-calendar-xmark';
                }
            ?>
                <div class="home-kubo-card <?= $k_is_unavailable ? 'is-renovation' : '' ?>">
                    <div class="home-kubo-img">
                        <?php if ($k_is_unavailable): ?>
                            <div class="home-kubo-reno-badge" style="background: <?= $badge_color ?>;">
                                <i class="<?= $status_icon ?>"></i> <?= htmlspecialchars($status_label) ?>
                            </div>
                        <?php endif; ?>
                        <img src="<?= htmlspecialchars($kubo['image']) ?>" alt="<?= htmlspecialchars($kubo['name']) ?>" style="<?= $k_is_unavailable ? 'filter: saturate(65%) brightness(90%);' : '' ?>" loading="lazy">
                    </div>
                    <div class="home-kubo-meta">
                        <span class="admin-badge admin-badge-purple"><?= htmlspecialchars($kubo['category'] ?? 'Resort Kubo') ?></span>
                        <?php if ($k_is_unavailable): ?>
                            <span class="admin-badge" style="background: <?= $badge_bg ?>; color: <?= $badge_color ?>; border: 1px solid <?= $badge_color ?>; font-weight: 800;">
                                <i class="<?= $status_icon ?>"></i> Not Available
                            </span>
                        <?php else: ?>
                            <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Available</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="home-kubo-title"><?= htmlspecialchars($kubo['name']) ?></h3>
                    <p class="home-kubo-desc"><?= htmlspecialchars($kubo['description']) ?></p>

                    <?php if ($k_is_reno || (!empty($kubo['renovation_notes']) && $k_status === 'maintenance')): ?>
                        <div class="home-kubo-reno-panel">
                            <div style="font-size: 0.75rem; font-weight: 800; color: #9a3412; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-triangle-exclamation" style="color: #ea580c;"></i>
                                <span style="text-transform: uppercase; letter-spacing: 0.5px;">Status Note:</span>
                            </div>
                            <div style="font-size: 0.88rem; color: #1e293b; font-weight: 700; line-height: 1.5; background: #ffffff; padding: 0.6rem 0.8rem; border-radius: 6px; border: 1px solid #fed7aa;">
                                <i class="fa-solid fa-wrench" style="color: #ea580c; margin-right: 6px;"></i>
                                <?= !empty($kubo['renovation_notes']) ? htmlspecialchars($kubo['renovation_notes']) : 'Currently under scheduled maintenance.' ?>
                            </div>
                        </div>
                    <?php elseif ($k_is_booked_today): ?>
                        <div style="margin: 0.75rem 0; padding: 0.6rem 0.8rem; background: rgba(239, 68, 68, 0.08); border-left: 3px solid #ef4444; border-radius: 4px; font-size: 0.82rem; color: #991b1b; font-weight: 600;">
                            <i class="fa-solid fa-calendar-xmark" style="color: #ef4444; margin-right: 4px;"></i> Currently booked for today. You can view details or check future dates in Explore House.
                        </div>
                    <?php endif; ?>

                    <div class="home-kubo-badges">
                        <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-users"></i> Max <?= htmlspecialchars($kubo['capacity']) ?></span>
                        <span class="admin-badge admin-badge-success"><i class="fa-solid fa-snowflake"></i> Aircon</span>
                        <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-wifi"></i> Wifi</span>
                    </div>

                    <div class="home-kubo-footer">
                        <div>
                            <span class="home-kubo-price-label">Per Night</span>
                            <span class="home-kubo-price">₱<?= number_format($kubo['price']) ?></span>
                        </div>
                        <?php if ($k_is_unavailable): ?>
                            <div style="display: flex; gap: 0.4rem; align-items: center;">
                                <a href="kubo_details.php?id=<?= $kubo['kubo_id'] ?>" class="admin-btn admin-btn-outline" style="min-height: 40px; padding: 0.4rem 0.8rem; font-size: 0.85rem;" title="View Details">
                                    <i class="fa-solid fa-eye"></i> Details
                                </a>
                                <button type="button" class="btn-book-kubo btn-sm" disabled style="min-height: 40px; opacity: 0.6; cursor: not-allowed; background: #94a3b8; border-color: #94a3b8; padding: 0.4rem 0.8rem;" title="This accommodation is currently not available for reservation">
                                    <i class="fa-solid fa-ban btn-icon"></i>
                                    <span>Not Available</span>
                                </button>
                            </div>
                        <?php else: ?>
                            <a href="book_house.php?id=<?= $kubo['kubo_id'] ?>" class="btn-book-kubo btn-sm" style="min-height: 44px;">
                                <i class="fa-solid fa-calendar-check btn-icon"></i>
                                <span>Book Kubo</span>
                                <i class="fa-solid fa-arrow-right btn-arrow"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ==================== ACTIVITIES ==================== -->
        <div class="home-section-header">
            <div>
                <h2>
                    <i class="fa-solid fa-water-ladder" style="color: #a855f7;"></i>
                    <?= htmlspecialchars($home_content['activities_section_title'] ?? 'Pool Resort Activities') ?>
                </h2>
                <p><?= htmlspecialchars($home_content['activities_section_subtitle'] ?? 'Enjoy our infinity pools, clear kayaking, lagoon paddle boarding, and tropical bamboo spa massages.') ?></p>
            </div>
            <a href="browse_activities.php" class="home-section-cta">
                Explore All <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <div class="home-grid">
            <div class="home-activity-card">
                <div class="home-activity-img">
                    <img src="https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80" alt="Infinity Pool Day Pass" loading="lazy">
                </div>
                <div class="home-kubo-meta">
                    <span class="admin-badge admin-badge-purple">Water Activity</span>
                    <span style="font-weight: 900; color: #f59e0b; font-size: 1.1rem; font-family: 'Outfit', sans-serif;">₱350<span style="font-size: 0.75rem; font-weight: 600; color: #64748b;">/person</span></span>
                </div>
                <h3 class="home-activity-title">Main Infinity Pool Day Pass</h3>
                <p class="home-activity-desc">Full access to our adult infinity pool and kids water park slide from 8:00 AM to 8:00 PM.</p>
                <div class="home-kubo-badges">
                    <span class="admin-badge admin-badge-success"><i class="fa-solid fa-shield-halved"></i> Lifeguard Monitored</span>
                </div>
                <a href="browse_activities.php" class="admin-btn admin-btn-gold" style="width: 100%; justify-content: center; min-height: 44px;">
                    <i class="fa-solid fa-calendar-check"></i> Reserve Pool Pass
                </a>
            </div>

            <div class="home-activity-card">
                <div class="home-activity-img">
                    <img src="https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80" alt="Clear Kayak" loading="lazy">
                </div>
                <div class="home-kubo-meta">
                    <span class="admin-badge admin-badge-purple">Water Sport</span>
                    <span style="font-weight: 900; color: #f59e0b; font-size: 1.1rem; font-family: 'Outfit', sans-serif;">₱500<span style="font-size: 0.75rem; font-weight: 600; color: #64748b;">/hour</span></span>
                </div>
                <h3 class="home-activity-title">Clear-Bottom Kayak & Paddle Board</h3>
                <p class="home-activity-desc">Glide across our resort lagoon in transparent tandem kayaks. Perfect for photo sessions!</p>
                <div class="home-kubo-badges">
                    <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-life-ring"></i> Lifevest Included</span>
                </div>
                <a href="browse_activities.php" class="admin-btn admin-btn-gold" style="width: 100%; justify-content: center; min-height: 44px;">
                    <i class="fa-solid fa-calendar-check"></i> Reserve Kayak Slot
                </a>
            </div>

            <div class="home-activity-card">
                <div class="home-activity-img">
                    <img src="https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=600&q=80" alt="Bamboo Spa" loading="lazy">
                </div>
                <div class="home-kubo-meta">
                    <span class="admin-badge admin-badge-purple">Wellness</span>
                    <span style="font-weight: 900; color: #f59e0b; font-size: 1.1rem; font-family: 'Outfit', sans-serif;">₱1,200<span style="font-size: 0.75rem; font-weight: 600; color: #64748b;">/session</span></span>
                </div>
                <h3 class="home-activity-title">Traditional Bamboo Spa & Sauna</h3>
                <p class="home-activity-desc">Relaxing 60-minute warm bamboo body massage using organic coconut oils at our open-air spa.</p>
                <div class="home-kubo-badges">
                    <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-spa"></i> Private Massage Hut</span>
                </div>
                <a href="browse_activities.php" class="admin-btn admin-btn-gold" style="width: 100%; justify-content: center; min-height: 44px;">
                    <i class="fa-solid fa-spa"></i> View Spa Packages
                </a>
            </div>
        </div>

        <!-- ==================== REVIEWS ==================== -->
        <div class="home-section-header">
            <div>
                <h2>
                    <i class="fa-solid fa-comments" style="color: #fbbf24;"></i>
                    <?= htmlspecialchars($home_content['reviews_section_title'] ?? 'Guest Reviews & Ratings') ?>
                </h2>
                <p><?= htmlspecialchars($home_content['reviews_section_subtitle'] ?? 'Read what our verified guests say about their stay at Purple Bamboo Resort.') ?></p>
            </div>
            <a href="reviews.php" class="home-section-cta">
                All Reviews <i class="fa-solid fa-star"></i>
            </a>
        </div>

        <div class="home-grid">
            <?php foreach ($homepage_reviews as $hr): ?>
                <?php
                $initial = strtoupper(substr($hr['guest'] ?? 'G', 0, 1));
                $bg_colors = [
                    'linear-gradient(135deg, #7c3aed, #a855f7)',
                    'linear-gradient(135deg, #d97706, #f59e0b)',
                    'linear-gradient(135deg, #10b981, #059669)',
                    'linear-gradient(135deg, #0284c7, #38bdf8)'
                ];
                $bg = $bg_colors[($hr['id'] ?? 1) % count($bg_colors)];
                ?>
                <div class="home-review-card">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 0.85rem;">
                        <div class="admin-avatar" style="background: <?= $bg ?>; font-weight: 700; color: #fff; flex-shrink: 0;">
                            <?= $initial ?>
                        </div>
                        <div style="min-width: 0;">
                            <h4 style="color: #7c02ff; font-size: 0.98rem; margin: 0; font-family: 'Outfit', sans-serif; font-weight: 700; word-break: break-word;"><?= htmlspecialchars($hr['guest']) ?></h4>
                            <span class="admin-badge admin-badge-success" style="font-size: 0.63rem; margin-top: 2px;">
                                <?= !empty($hr['item']) ? htmlspecialchars($hr['item']) : 'Verified Stay' ?>
                            </span>
                        </div>
                    </div>
                    <div style="margin-bottom: 0.65rem; color: #fbbf24; font-size: 0.9rem;">
                        <?php for ($i = 0; $i < (int)$hr['rating']; $i++): ?>
                            <i class="fa-solid fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <p style="color: #475569; font-size: 0.86rem; font-style: italic; line-height: 1.55; margin-bottom: 0.5rem;">
                        "<?= htmlspecialchars($hr['comment']) ?>"
                    </p>
                    <?php if (!empty($hr['staff_reply'])): ?>
                        <div style="font-size: 0.74rem; background: rgba(124, 58, 237, 0.08); padding: 0.5rem 0.7rem; border-radius: 6px; border-left: 3px solid #8b5cf6; color: #6d28d9; line-height: 1.45;">
                            <strong>🌿 Resort Response:</strong> <?= htmlspecialchars($hr['staff_reply']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ==================== GUARANTEE BANNER ==================== -->
        <div class="home-guarantee">
            <div style="max-width: 650px; margin: 0 auto;">
                <span class="admin-badge admin-badge-gold" style="margin-bottom: 0.75rem;">
                    <i class="fa-solid fa-shield-check"></i> <?= htmlspecialchars($home_content['guarantee_badge'] ?? 'Safe & Instant Guarantee') ?>
                </span>
                <h2><?= htmlspecialchars($home_content['guarantee_title'] ?? 'Book Your Stay at The Blessed Purple Bamboo Resort') ?></h2>
                <p><?= htmlspecialchars($home_content['guarantee_subtitle'] ?? 'Get an instant booking reference number immediately upon reservation. No waiting time needed!') ?></p>
                <a href="browse_kubos.php" class="admin-btn admin-btn-gold">
                    <i class="fa-solid fa-calendar-check"></i> Explore House Kubos &amp; Reserve Now
                </a>
            </div>
        </div>

    </div>

  

    <?php 
    if (file_exists(__DIR__ . '/includes/footer.php')) {
        include __DIR__ . '/includes/footer.php';
    } elseif (file_exists(__DIR__ . '/footer.php')) {
        include __DIR__ . '/footer.php';
    }
    ?>

</body>
</html>