<?php
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (is_multisite()) {
    $bicho_offset = 0;
    do {
        $bicho_sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $bicho_offset]);
        foreach ($bicho_sites as $bicho_site) {
            switch_to_blog((int) $bicho_site);
            delete_option('bicho_settings');
            restore_current_blog();
        }
        $bicho_offset += count($bicho_sites);
    } while (count($bicho_sites) === 100);
} else {
    delete_option('bicho_settings');
}
