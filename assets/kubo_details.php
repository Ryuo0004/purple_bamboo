<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/inventory_store.php';

$kubo_id = intval($_GET['id'] ?? 1);
$kubo = InventoryStore::getKuboById($kubo_id);

if (!$kubo) {
    header("Location: browse_kubos.php");
    exit();
}

$status = strtolower($kubo['status'] ?? 'available');
$is_renovation = ($status === 'renovation');
$is_unavailable = in_array($status, ['renovation', 'maintenance', 'occupied']);
$all_kubos = InventoryStore::getAllKubos();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($kubo['name']) ?> | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container" style="max-width: 1100px; padding: 2rem 1rem;">
        
        <!-- Breadcrumb / Back Link -->
        <div style="margin-bottom: 1.5rem;">
            <a href="browse_kubos.php" style="color: var(--primary-purple); text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrow-left"></i> Back to House Kubos
            </a>
        </div>

        <!-- Renovation High-Impact Banner for Guests -->
        <?php if ($is_renovation): ?>
            <div style="background: linear-gradient(135deg, #c2410c, #ea580c); color: #ffffff; padding: 1.25rem 1.5rem; border-radius: 12px; margin-bottom: 2rem; box-shadow: 0 10px 25px rgba(234, 88, 12, 0.25); display: flex; align-items: flex-start; gap: 1rem;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 2.2rem; flex-shrink: 0; margin-top: 2px;"></i>
                <div>
                    <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; margin: 0 0 0.4rem 0; color: #ffffff;">
                        🚧 NOTICE: THIS ROOM IS CURRENTLY UNDER RENOVATION (NOT AVAILABLE)
                    </h2>
                    <p style="margin: 0; font-size: 0.95rem; line-height: 1.5; color: #ffedd5;">
                        This room is temporarily unavailable for booking while facility repairs and maintenance are underway to ensure high-quality and safe accommodations.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2.5rem; margin-bottom: 3rem;">
            <!-- Left Column: Image -->
            <div>
                <div style="position: relative; overflow: hidden; border-radius: 16px; box-shadow: var(--shadow-md); <?= $is_renovation ? 'border: 3px solid #ea580c;' : '' ?>">
                    <?php if ($is_renovation): ?>
                        <div style="position: absolute; top: 15px; left: 15px; background: #ea580c; color: #fff; padding: 6px 14px; border-radius: 30px; font-weight: 800; font-size: 0.85rem; z-index: 5; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-hammer"></i> Under Renovation — Not Available
                        </div>
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars($kubo['image'] ?? 'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=800&q=80') ?>" 
                         alt="<?= htmlspecialchars($kubo['name']) ?>" 
                         style="width: 100%; height: 380px; object-fit: cover; display: block; <?= $is_renovation ? 'filter: saturate(70%);' : '' ?>">
                </div>
            </div>

            <!-- Right Column: Details & Damage Report -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <span class="badge badge-purple" style="font-size: 0.9rem; padding: 0.4rem 1rem;">
                        <?= htmlspecialchars($kubo['category'] ?? 'Resort Kubo') ?>
                    </span>
                    <span style="font-weight: 800; color: var(--bamboo-gold); font-size: 1.6rem;">
                        ₱<?= number_format($kubo['price']) ?> <span style="font-size: 0.9rem; color: var(--text-muted); font-weight: 500;">/ gabi</span>
                    </span>
                </div>

                <h1 style="font-family: 'Outfit', sans-serif; font-size: 2rem; color: var(--primary-purple); margin-bottom: 0.75rem;">
                    <?= htmlspecialchars($kubo['name']) ?>
                </h1>

                <!-- Status Badge -->
                <div style="margin-bottom: 1.25rem;">
                    <?php if ($is_renovation): ?>
                        <span class="badge" style="background: #ea580c; color: #ffffff; font-size: 0.9rem; padding: 0.4rem 0.9rem; font-weight: 800;">
                            <i class="fa-solid fa-hammer"></i> Under Renovation
                        </span>
                        <span class="badge" style="background: #fee2e2; color: #b91c1c; font-size: 0.9rem; padding: 0.4rem 0.9rem; font-weight: 800; border: 1px solid #fca5a5;">
                            <i class="fa-solid fa-ban"></i> Not Available
                        </span>
                    <?php elseif ($status === 'available'): ?>
                        <span class="badge badge-success" style="font-size: 0.9rem; padding: 0.4rem 0.9rem; font-weight: 800;">
                            <i class="fa-solid fa-circle-check"></i> Available for Reservation
                        </span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size: 0.9rem; padding: 0.4rem 0.9rem; font-weight: 800;">
                            <i class="fa-solid fa-ban"></i> Not Available (<?= ucfirst($status) ?>)
                        </span>
                    <?php endif; ?>
                </div>

                <p style="color: var(--text-muted); font-size: 1rem; line-height: 1.6; margin-bottom: 1.5rem;">
                    <?= htmlspecialchars($kubo['description'] ?? 'Cozy authentic traditional bamboo accommodation with air conditioning and resort amenities.') ?>
                </p>

                <!-- Key Amenities -->
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                    <span class="badge badge-gold" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                        <i class="fa-solid fa-users"></i> Hanggang <?= htmlspecialchars($kubo['capacity'] ?? 4) ?> Guests
                    </span>
                    <span class="badge badge-purple" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                        <i class="fa-solid fa-house-chimney"></i> Available Units: <?= htmlspecialchars($kubo['available_units'] ?? 5) ?>
                    </span>
                    <?php 
                    $badges = [];
                    if (!empty($kubo['badges'])) {
                        $badges = is_array($kubo['badges']) ? $kubo['badges'] : explode(',', $kubo['badges']);
                    }
                    foreach ($badges as $badge): 
                    ?>
                        <span class="badge badge-gold" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                            <i class="fa-solid fa-check"></i> <?= trim(htmlspecialchars($badge)) ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <!-- Admin's Damage Report & Renovation Details for Guests -->
                <?php if ($is_renovation): ?>
                    <div style="background: #fff7ed; border: 2px solid #ea580c; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.75rem;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.75rem;">
                            <span style="background: #ea580c; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 800;">ADMIN NOTICE</span>
                            <h3 style="margin: 0; font-size: 1.05rem; color: #9a3412; font-family: 'Outfit', sans-serif;">
                                <i class="fa-solid fa-wrench"></i> Damage / Reason for Renovation:
                            </h3>
                        </div>

                        <div style="background: #ffffff; padding: 0.85rem 1rem; border-radius: 8px; border: 1px solid #fed7aa; border-left: 4px solid #ea580c; margin-bottom: 0.85rem;">
                            <p style="margin: 0; color: #0f172a; font-weight: 800; font-size: 1rem; line-height: 1.5;">
                                <i class="fa-solid fa-wrench" style="color: #ea580c; margin-right: 6px;"></i>
                                <?= !empty($kubo['renovation_notes']) ? nl2br(htmlspecialchars($kubo['renovation_notes'])) : 'This room is currently undergoing repairs and maintenance.' ?>
                            </p>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.85rem; color: #9a3412;">
                            <div style="background: #ffffff; padding: 0.6rem 0.85rem; border-radius: 6px; border: 1px solid #fed7aa;">
                                <i class="fa-regular fa-calendar" style="color: #ea580c;"></i> Started: 
                                <strong style="color: #0f172a;"><?= !empty($kubo['renovation_start']) ? date('M d, Y', strtotime($kubo['renovation_start'])) : 'Ongoing' ?></strong>
                            </div>
                            <div style="background: #ffffff; padding: 0.6rem 0.85rem; border-radius: 6px; border: 1px solid #fed7aa;">
                                <i class="fa-regular fa-calendar-check" style="color: #ea580c;"></i> Target Completion: 
                                <strong style="color: #0f172a;"><?= !empty($kubo['renovation_end']) ? date('M d, Y', strtotime($kubo['renovation_end'])) : 'To be announced' ?></strong>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Booking Actions -->
                <div>
                    <?php if ($is_renovation): ?>
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <button disabled class="btn" style="width: 100%; background: #ea580c; color: #ffffff; cursor: not-allowed; border: none; padding: 1rem; border-radius: 8px; font-weight: 800; font-size: 1rem; opacity: 0.95; display: flex; align-items: center; justify-content: center; gap: 8px;">
                                <i class="fa-solid fa-ban"></i> Not Available (Under Renovation)
                            </button>
                            <a href="browse_kubos.php" class="btn btn-primary" style="width: 100%; text-align: center; padding: 0.85rem;">
                                <i class="fa-solid fa-house-chimney"></i> Explore Other Available Kubos
                            </a>
                        </div>
                    <?php elseif ($is_unavailable): ?>
                        <button disabled class="btn" style="width: 100%; background: #9ca3af; color: #ffffff; cursor: not-allowed; border: none; padding: 1rem; border-radius: 8px; font-weight: 800; font-size: 1rem;">
                            <i class="fa-solid fa-ban"></i> Currently Unavailable
                        </button>
                    <?php else: ?>
                        <a href="book_house.php?id=<?= $kubo['kubo_id'] ?>" class="btn-book-kubo btn-lg" style="width: 100%; justify-content: center; font-size: 1.15rem; padding: 1.05rem;">
                            <i class="fa-solid fa-calendar-check btn-icon"></i>
                            <span>Book Kubo Now</span>
                            <i class="fa-solid fa-arrow-right btn-arrow"></i>
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- Other Kubos Section -->
        <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 3rem 0 2rem;">
        <h2 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 1.5rem;">
            Tingnan ang Iba Pang mga House Kubos
        </h2>
        
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
            <?php foreach ($all_kubos as $item): 
                if ($item['kubo_id'] == $kubo['kubo_id']) continue;
                $item_status = strtolower($item['status'] ?? 'available');
                $item_reno = ($item_status === 'renovation');
            ?>
                <div class="card" style="position: relative; <?= $item_reno ? 'border: 1.5px solid #ea580c;' : '' ?>">
                    <?php if ($item_reno): ?>
                        <div style="position: absolute; top: 10px; left: 10px; background: #ea580c; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 800; z-index: 2;">
                            Under Renovation
                        </div>
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="height: 180px; width: 100%; object-fit: cover; border-radius: var(--radius-sm); margin-bottom: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span class="badge badge-purple"><?= htmlspecialchars($item['category']) ?></span>
                        <strong style="color: var(--bamboo-gold);">₱<?= number_format($item['price']) ?> / gabi</strong>
                    </div>
                    <h4 style="font-family: 'Outfit', sans-serif; margin: 0 0 0.5rem 0;"><?= htmlspecialchars($item['name']) ?></h4>
                    <a href="kubo_details.php?id=<?= $item['kubo_id'] ?>" class="btn btn-outline btn-sm" style="width: 100%; text-align: center; margin-top: 0.5rem;">
                        Tingnan ang Detalye
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <?php include 'footer.php'; ?>

</body>
</html>
