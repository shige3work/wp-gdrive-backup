<?php
// mock functions
function get_option($key, $default = false) {
    $options = [
        'wpgb_backup_interval' => 'monthly',
        'timezone_string' => 'Asia/Tokyo',
        'wpgb_backup_monthly_day' => '15',
        'wpgb_backup_monthly_hour' => '9',
    ];
    return isset($options[$key]) ? $options[$key] : $default;
}

$tz_string = get_option('timezone_string');
$tz = new DateTimeZone($tz_string);

// Let's pretend today is Aug 2nd 03:00
$now = current_time_mock();

function current_time_mock() {
    global $tz;
    return new DateTime('2026-08-02 03:00:00', $tz);
}

$now = current_time_mock();
$interval = get_option('wpgb_backup_interval', 'weekly');

if ($interval === 'monthly') {
    $day = (int) get_option('wpgb_backup_monthly_day', '1');
    $hour = (int) get_option('wpgb_backup_monthly_hour', '3');
    
    $target = clone $now;
    // Prevent day overflow
    $max_day = (int) date('t', mktime(0,0,0, (int)$now->format('n'), 1, (int)$now->format('Y')));
    $actual_day = min($day, $max_day);

    $target->setDate((int)$now->format('Y'), (int)$now->format('n'), $actual_day);
    $target->setTime($hour, 0, 0);
    
    if ($target <= $now) {
        // Move to next month
        $target->modify('first day of next month');
        $max_day_next = (int) $target->format('t');
        $actual_day_next = min($day, $max_day_next);
        $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $actual_day_next);
        $target->setTime($hour, 0, 0);
    }
    echo "Next run: " . $target->format('Y-m-d H:i:s') . "\n";
}
