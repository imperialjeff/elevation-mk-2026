<?php
/**
 * Property Grid Block Template
 *
 * Displays a filterable grid or carousel of properties using PropertyHive
 * Rebuilt to use direct WP_Query instead of do_shortcode()
 *
 * @package Kadence Child
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build WP_Query arguments from block parameters
 *
 * @param array $params Block parameters
 * @return array WP_Query arguments
 */
if (!function_exists('property_grid_build_query_args')) {
    function property_grid_build_query_args($params) {
    // Base query arguments
    // Over-fetch by 5x to account for properties that will be filtered out (placeholders, empty addresses)
    // This is especially important when filtering by specific availability/marketing flags
    $requested_posts = !empty($params['numposts']) ? (int)$params['numposts'] : 6;
    $fetch_posts = $requested_posts * 5; // Fetch 5x more than needed

    $args = array(
        'post_type' => 'property',
        'posts_per_page' => $fetch_posts,
        'post_status' => 'publish',
    );

    // Set ordering based on propertygrid_order_by field
    $order_by = !empty($params['order_by']) ? $params['order_by'] : 'date';

    switch ($order_by) {
        case 'price_1':
            // Cheapest first (smallest _price_actual value first)
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price_actual';
            $args['order'] = 'ASC';
            break;

        case 'price_2':
            // Cheapest last (largest _price_actual value first)
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price_actual';
            $args['order'] = 'DESC';
            break;

        case 'distance':
            // Distance ordering is handled by radial search plugin via $_REQUEST['orderby']
            // Set default WP_Query ordering to date in case radial search is not active
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            break;

        case 'date':
        default:
            // Order by publish date (newest first)
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            break;
    }

    // Build meta query based on on_market_filter setting
    $meta_query = array('relation' => 'AND');

    // Handle _on_market field based on propertygrid_on_market setting
    // Default to 'either_market' for backward compatibility with existing blocks
    $on_market_filter = !empty($params['on_market_filter']) ? $params['on_market_filter'] : 'either_market';

    if ($on_market_filter === 'on_market') {
        // Only show properties where _on_market = 'yes'
        $meta_query[] = array(
            'key' => '_on_market',
            'value' => 'yes',
        );
    } elseif ($on_market_filter === 'off_market') {
        // Show properties where _on_market is NOT 'yes' (includes empty, null, or any other value)
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key' => '_on_market',
                'value' => 'yes',
                'compare' => '!=',
            ),
            array(
                'key' => '_on_market',
                'compare' => 'NOT EXISTS',
            ),
        );
    }
    // If 'either_market', don't add any _on_market filter (show all properties)

    // Only set meta_query if we added filters
    if (count($meta_query) > 1) {
        $args['meta_query'] = $meta_query;
    }

    // Build tax query
    $tax_query = array('relation' => 'AND');

    // Only add property_type filter if types are actually selected
    // Empty or false means show ALL property types
    if (!empty($params['property_type']) && is_array($params['property_type'])) {
        $tax_query[] = array(
            'taxonomy' => 'property_type',
            'field' => 'term_id',
            'terms' => $params['property_type'],
            'operator' => 'IN',
        );
    }

    if (!empty($params['availability']) && is_array($params['availability'])) {
        $tax_query[] = array(
            'taxonomy' => 'availability',
            'field' => 'term_id',
            'terms' => $params['availability'],
            'operator' => 'IN',
        );
    }

    if (!empty($params['marketing_flags']) && is_array($params['marketing_flags'])) {
        $tax_query[] = array(
            'taxonomy' => 'marketing_flag',
            'field' => 'term_id',
            'terms' => $params['marketing_flags'],
            'operator' => 'IN',
        );
    }

    if (count($tax_query) > 1) {
        $args['tax_query'] = $tax_query;
    }

    return $args;
    }
}

/**
 * Setup radial search by setting $_REQUEST variables
 *
 * IMPORTANT: The PropertyHive Radial Search plugin only indexes properties where _on_market='yes'
 * into the wp_ph_radial_search_lat_lng_post table. Properties with _on_market='' (empty) or other
 * values will NOT be found by the radial distance calculation.
 *
 * This function disables the address fallback to ensure ONLY properties within the actual GPS radius
 * are returned. Properties not in the radial index will be excluded from results.
 *
 * @param string $address_keyword Location to search from
 * @param string $radius Radius in miles
 * @param string $order_by Order by setting from ACF field
 */
if (!function_exists('property_grid_setup_radial_search')) {
    function property_grid_setup_radial_search($address_keyword, $radius, $order_by = 'date') {
        if (!empty($address_keyword) && !empty($radius)) {
            $_REQUEST['address_keyword'] = sanitize_text_field($address_keyword);
            $_REQUEST['radius'] = sanitize_text_field($radius);

            // Only set orderby to 'radius' if user selected 'distance' ordering
            if ($order_by === 'distance') {
                $_REQUEST['orderby'] = 'radius'; // Order by distance from search location (closest first)
            }

            // Disable address fallback matching - use ONLY radial distance calculation
            // This prevents properties from appearing just because the location name appears in their address
            // CRITICAL: Only properties in radial search table will be returned (requires _on_market='yes')
            add_filter('propertyhive_radial_search_ignore_address', '__return_true', 999);

            // Note: Debug logging removed - use PropertyHive's built-in radial search debugging if needed
        }
    }
}

/**
 * Cleanup radial search $_REQUEST variables
 */
if (!function_exists('property_grid_cleanup_radial_search')) {
    function property_grid_cleanup_radial_search() {
        unset($_REQUEST['address_keyword']);
        unset($_REQUEST['radius']);
        unset($_REQUEST['orderby']);

        // Remove the address ignore filter
        remove_filter('propertyhive_radial_search_ignore_address', '__return_true');
    }
}

/**
 * Check if property should be excluded from display
 *
 * @param WP_Post $post Property post object
 * @return bool True if property should be excluded
 */
if (!function_exists('property_grid_should_exclude_property')) {
    function property_grid_should_exclude_property($post) {
        $property = new PH_Property($post->ID);

        // Check for placeholder thumbnail
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $thumbnail_url = wp_get_attachment_url($thumbnail_id);
            // Exclude if using placeholder.png
            if (strpos($thumbnail_url, 'placeholder.png') !== false) {
                return true;
            }
        }

        // Check for empty address (street and town both empty)
        $street = trim($property->address_street);
        $town = trim($property->address_three);

        // Exclude if both street and town are empty
        if (empty($street) && empty($town)) {
            return true;
        }

        return false;
    }
}

/**
 * Render properties using PropertyHive templates
 *
 * @param WP_Query $properties Query object
 * @param string $display_layout 'Columns' or 'Carousel'
 * @param int $column_count Number of columns
 * @param int $carousel_count Number of carousel slides
 * @param int $max_properties Maximum number of properties to display
 */
if (!function_exists('property_grid_render_properties')) {
    function property_grid_render_properties($properties, $display_layout, $column_count, $carousel_count, $max_properties = 6) {
    global $propertyhive_loop;

    // Set PropertyHive loop globals
    $slides_to_show = ($display_layout === 'Carousel') ? (int)$carousel_count : (int)$column_count;
    $propertyhive_loop = array(
        'columns' => $slides_to_show,
        'loop' => 0,
    );

    // Start PropertyHive wrapper (matches shortcode structure)
    echo '<div class="propertyhive propertyhive-properties-shortcode columns-' . (int)$slides_to_show . '">';

    // Handle carousel layout
    if ($display_layout === 'Carousel') {
        ob_start();
        propertyhive_property_loop_start();
        $loop_start = ob_get_clean();

        // Add carousel class (matches PropertyHive's shortcode approach)
        $loop_start = str_replace(
            'class="properties',
            'class="properties propertyhive-shortcode-carousel',
            $loop_start
        );

        echo $loop_start;
    } else {
        // Standard column layout
        propertyhive_property_loop_start();
    }

    // Loop through properties
    $displayed_count = 0;
    while ($properties->have_posts() && $displayed_count < $max_properties) {
        $properties->the_post();

        // Skip properties with placeholder images or empty addresses
        if (property_grid_should_exclude_property($properties->post)) {
            continue;
        }

        ph_get_template_part('content', 'property');
        $propertyhive_loop['loop']++;
        $displayed_count++;
    }

    // Loop end
    propertyhive_property_loop_end();

    // Close PropertyHive wrapper
    echo '</div>';

    wp_reset_postdata();

    // Add CSS for grayscale off-market properties
    ?>
    <style>
    .propertyhive-properties-shortcode li.off-market .thumbnail img {
        filter: grayscale(100%);
        -webkit-filter: grayscale(100%);
        opacity: 0.8;
    }
    </style>
    <script>
    jQuery(document).ready(function($) {
        // Add off-market class to properties that are not on the market
        $('.propertyhive-properties-shortcode li.property').each(function() {
            var $propertyItem = $(this);
            var propertyId = $propertyItem.attr('class').match(/post-(\d+)/);

            if (propertyId && propertyId[1]) {
                // Check via AJAX if property is off-market
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'check_property_market_status',
                        property_id: propertyId[1]
                    },
                    success: function(response) {
                        if (response.data && response.data.off_market) {
                            $propertyItem.addClass('off-market');
                        }
                    }
                });
            }
        });
    });
    </script>
    <?php

    // Initialize Slick carousel if needed
    if ($display_layout === 'Carousel') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            if ($('.propertyhive-shortcode-carousel').length && typeof $.fn.slick !== 'undefined') {
                $('.propertyhive-shortcode-carousel').slick({
                    slidesToShow: <?php echo (int)$carousel_count; ?>,
                    slidesToScroll: 1,
                    dots: true,
                    arrows: true,
                    infinite: true,
                    autoplay: false,
                    responsive: [
                        {
                            breakpoint: 1024,
                            settings: {
                                slidesToShow: Math.min(3, <?php echo (int)$carousel_count; ?>)
                            }
                        },
                        {
                            breakpoint: 768,
                            settings: {
                                slidesToShow: Math.min(2, <?php echo (int)$carousel_count; ?>)
                            }
                        },
                        {
                            breakpoint: 480,
                            settings: {
                                slidesToShow: 1
                            }
                        }
                    ]
                });
            }
        });
        </script>
        <?php
    }
    }
}

/**
 * Show no results message for logged-in users
 *
 * @param array $params Block parameters for debugging
 */
if (!function_exists('property_grid_show_no_results')) {
    function property_grid_show_no_results($params) {
    if (is_user_logged_in()) {
        echo '<div class="no-properties-message" style="padding: 20px; background: #f9f9f9; border: 1px solid #ddd; margin: 20px 0;">';
        echo '<p><strong>No properties found matching the selected criteria.</strong></p>';
        echo '<details>';
        echo '<summary style="cursor: pointer; margin: 10px 0;"><strong>Debug Info (Admin only)</strong></summary>';
        echo '<ul style="margin: 10px 0; padding-left: 20px;">';
        echo '<li>Property Types: ' . (!empty($params['property_type']) ? implode(', ', $params['property_type']) : 'None (ALL types)') . '</li>';
        echo '<li>Availability: ' . (!empty($params['availability']) ? implode(', ', $params['availability']) : 'None') . '</li>';
        echo '<li>Marketing Flags: ' . (!empty($params['marketing_flags']) ? implode(', ', $params['marketing_flags']) : 'None') . '</li>';
        echo '<li>Marketing Status: ' . (!empty($params['on_market_filter']) ? esc_html($params['on_market_filter']) : 'either_market (default)') . '</li>';
        echo '<li>Order By: ' . (!empty($params['order_by']) ? esc_html($params['order_by']) : 'date (default)') . '</li>';
        echo '<li>Location: ' . (!empty($params['address_keyword']) ? esc_html($params['address_keyword']) : 'None') . '</li>';
        echo '<li>Radius: ' . (!empty($params['radius']) ? esc_html($params['radius']) . ' miles' : 'None') . '</li>';
        echo '<li>Posts per page: ' . (!empty($params['numposts']) ? esc_html($params['numposts']) : '6') . '</li>';
        echo '</ul>';
        echo '</details>';
        echo '</div>';
    }
    // Output nothing for non-logged-in users
    }
}

// ============================================
// MAIN BLOCK EXECUTION
// ============================================

// Get ACF field values
$property_types = get_field('propertygrid-property_type');
$availability_ids = get_field('propertygrid-availability');
$marketing_flag_ids = get_field('propertygrid-marketing_flags');
$on_market_filter = get_field('propertygrid_on_market');
$order_by = get_field('propertygrid_order_by');
$address_keyword = get_field('propertygrid-post_code_town');
$radius = get_field('propertygrid-radius');
$numposts = get_field('propertygrid-numposts');
$display_layout = get_field('propertygrid-display_layout');
$column_count = get_field('propertygrid-column_count');
$carousel_count = get_field('propertygrid-carousel_count');

// Prepare parameters array
$params = array(
    'property_type' => $property_types,
    'availability' => $availability_ids,
    'marketing_flags' => $marketing_flag_ids,
    'on_market_filter' => $on_market_filter,
    'order_by' => $order_by,
    'address_keyword' => $address_keyword,
    'radius' => $radius,
    'numposts' => $numposts,
    'display_layout' => $display_layout,
    'column_count' => $column_count,
    'carousel_count' => $carousel_count,
);

// Build query arguments
$args = property_grid_build_query_args($params);

// Setup radial search if needed
property_grid_setup_radial_search($address_keyword, $radius, $order_by);

// Execute query
$properties = new WP_Query($args);

// Cleanup radial search
property_grid_cleanup_radial_search();

// Output dynamic column CSS if needed
if ($display_layout === 'Columns' && !empty($column_count) && is_numeric($column_count) && $column_count > 0) {
    echo '<style>
        .propertyhive-properties-shortcode.propertyhive.columns-' . (int)$column_count . ' ul.properties li {
            flex-grow: 1;
            width: calc((100% / ' . (int)$column_count . ') - 16px);
            max-width: calc((100% / ' . (int)$column_count . ') - 16px);
        }
    </style>';
}

// Render properties or show error
if ($properties->have_posts()) {
    $max_properties = !empty($numposts) ? (int)$numposts : 6;
    property_grid_render_properties($properties, $display_layout, $column_count, $carousel_count, $max_properties);
} else {
    property_grid_show_no_results($params);
}
