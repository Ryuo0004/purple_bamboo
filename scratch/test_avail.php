<?php
require __DIR__ . '/../booking_store.php';
var_dump(BookingStore::getActivityAvailability(1, '2026-10-21', '09:00:00', '10:00:00'));
