<?php
/**
 * Loop - Marketing Flag
 *
 * Override PropertyHive template to implement priority flag logic.
 * This template is called by PropertyHive when displaying marketing flags.
 *
 * @package PropertyHive/Templates
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

global $property;

if (!$property || empty($property->id)) {
    return;
}

// Use our helper function to get the display-ready flag text
$flag_text = cw_get_property_marketing_flag_display($property);

if (!empty($flag_text)) {
    echo '<div class="flag">' . esc_html($flag_text) . '</div>';
}
