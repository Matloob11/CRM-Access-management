<?php
/**
 * Example phone-mask config. Copy to a7_tl_phone_mask.config.php on the CRM host.
 * Set the unlock password via environment A7_TL_PHONE_MASK_PASSWORD — never commit it.
 */
return [
    'password' => getenv('A7_TL_PHONE_MASK_PASSWORD') !== false
        && getenv('A7_TL_PHONE_MASK_PASSWORD') !== ''
            ? (string) getenv('A7_TL_PHONE_MASK_PASSWORD')
            : '',
    'reveal_seconds' => 30,
    'leaders' => [
        // Team heads — last-4 mask + eye unlock
        2560 => [
            'name' => 'Hassan',
            'department_id' => 69,
            'agents' => [2344, 2429, 2379, 2380, 2376, 2434, 2291],
        ],
        2551 => [
            'name' => 'Hussnain',
            'department_id' => 70,
            'agents' => [2369, 2381, 2371, 2535, 2368, 2367, 2350],
        ],
        2297 => [
            'name' => 'Jaweria',
            'department_id' => 71,
            'agents' => [2302, 2298, 2299, 2390, 2392],
        ],
        // Mask-only users (no permission changes)
        2319 => ['name' => 'Amna Khan', 'department_id' => 58, 'agents' => []],
        2191 => ['name' => 'Raoof Ahmad', 'department_id' => 15, 'agents' => []],
        454 => ['name' => 'Hina Sultan', 'department_id' => 43, 'agents' => []],
        2257 => ['name' => 'Sreya Gopi', 'department_id' => 2, 'agents' => []],
        2628 => ['name' => 'HR Usman', 'department_id' => 58, 'agents' => []],
        2632 => ['name' => 'Furqan Khalid', 'department_id' => 58, 'agents' => []],
    ],
];
