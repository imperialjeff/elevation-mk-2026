<?php

$block_atts = array(
    'teamblock_datasource'          => get_field('teamblock_datasource') ?: '',
    'teamblock_datasource_fieldkey' => get_field('teamblock_datasource_fieldkey') ?: '',
    'teamblock_select_data'         => get_field('teamblock_select_data') ?: '',
    'teamblock_members'             => get_field('teamblock_members') ?: [],
    'teamblock_numposts'            => get_field('teamblock_numposts') ?: -1,
    'teamblock_columns'             => get_field('teamblock_columns') ?: 3,
    'teamblock_branch'              => get_field('teamblock_branch') ?: '',
    'teamblock_dept'                => get_field('teamblock_dept') ?: '',
    'teamblock_show_filter'         => get_field('teamblock_show_filter'),
);

$query_args = array(
    'post_type'   => 'team-member',
    'post_status' => 'publish',
);

// Fix for Data Source ordering
if ($block_atts['teamblock_datasource'] && !empty($block_atts['teamblock_datasource_fieldkey'])) {
    $field_values = get_field($block_atts['teamblock_datasource_fieldkey'], get_the_ID());
    if (!empty($field_values) && is_array($field_values)) {
        $query_args['post__in'] = $field_values;
        $query_args['orderby'] = 'post__in'; // Maintain the order as defined in the custom field
    }
} else {
    if ($block_atts['teamblock_select_data'] === 'Select Individually' && !empty($block_atts['teamblock_members'])) {
        $query_args['post__in'] = $block_atts['teamblock_members'];
        $query_args['orderby'] = 'post__in'; // Maintain the order
    } elseif ($block_atts['teamblock_select_data'] === 'By Team and Department') {
        $query_args['meta_query'] = array();

        if (!empty($block_atts['teamblock_branch']) && is_array($block_atts['teamblock_branch'])) {
            foreach ($block_atts['teamblock_branch'] as $branch_value) {
                $query_args['meta_query'][] = array(
                    'key'     => 'team_details_team_branch',
                    'value'   => '"' . $branch_value . '"',
                    'compare' => 'LIKE',
                );
            }
        }

        if (!empty($block_atts['teamblock_dept']) && is_array($block_atts['teamblock_dept'])) {
            foreach ($block_atts['teamblock_dept'] as $dept_value) {
                $query_args['meta_query'][] = array(
                    'key'     => 'team_details_team_dept',
                    'value'   => '"' . $dept_value . '"',
                    'compare' => 'LIKE',
                );
            }
        }

        if (count($query_args['meta_query']) > 1) {
            $query_args['meta_query']['relation'] = 'OR';
        }
    }
}

$query_args['posts_per_page'] = $block_atts['teamblock_numposts'];

$query = new WP_Query($query_args);

$return = '';

// Enqueue scripts once, outside the loop
if ($block_atts['teamblock_show_filter']) {
    $acf_branches = acf_get_field('teamblock_branch');
    $branch_terms = $acf_branches['choices'];

    wp_enqueue_script('jquery');
    wp_enqueue_script(
        'isotope',
        'https://unpkg.com/isotope-layout@3/dist/isotope.pkgd.min.js',
        array('jquery'),
        '3.0.6',
        true
    );

    $return .= '<div class="team-filter-container">';
    $return .= '<h5>Filter our team by branch</h5>';
    $return .= '<select class="team-filter">';
    $return .= '<option value="*">All Branches</option>';

    foreach ($branch_terms as $branch_term) {
        $branch_class_name = 'branch_' . sanitize_html_class(strtolower(str_replace(' ', '-', $branch_term)));
        $return .= '<option value=".' . esc_attr($branch_class_name) . '">' . esc_html($branch_term) . '</option>';
    }

    $return .= '</select>';
    $return .= '</div>';
}

if ($query->have_posts()) {
    $return .= '<div class="team-members-container team-columns' . esc_attr($block_atts['teamblock_columns']) . ' team-grid-block">';
    while ($query->have_posts()) {
        $query->the_post();

        $team_profile_id = get_field('team_media_team_profile', get_the_ID(), false); // Get image ID
        $columns = $block_atts['teamblock_columns'];

        $image_size = 'medium'; // Default size
        if ($columns == 1) {
            $image_size = 'large'; // For one column
        } elseif ($columns == 3 || $columns == 4) {
            $image_size = 'medium_large'; // For three or four columns
        }

        $team_profile = wp_get_attachment_image_src($team_profile_id, $image_size);
        $team_position = get_field('team_details_team_position', get_the_ID());
        $team_branch = get_field('team_details_team_branch', get_the_ID());
        $team_dept = get_field('team_details_team_dept', get_the_ID());

        $team_dept_branch = array_merge(is_array($team_dept) ? $team_dept : [], is_array($team_branch) ? $team_branch : []);

        // Use array_map consistently for both branch and department classes
        $branch_classes = array_map(function($city) {
            return 'branch_' . sanitize_html_class(strtolower(str_replace(' ', '-', $city)));
        }, is_array($team_branch) ? $team_branch : []);
        
        $dept_classes = array_map(function($dept) {
            return 'dept_' . sanitize_html_class(strtolower(str_replace(' ', '-', $dept)));
        }, is_array($team_dept) ? $team_dept : []);
         
        $all_classes = array_merge($branch_classes, $dept_classes);
        $classes_output = implode(" ", $all_classes);

        $return .= '<div class="team-member ' . esc_attr($classes_output) . '">';
        if ($team_profile) {
            $return .= '<div class="team-member-profile-image">';
            $return .= '<img decoding="async" src="' . esc_url($team_profile[0]) . '" width="' . esc_attr($team_profile[1]) . '" height="' . esc_attr($team_profile[2]) . '" alt="' . esc_attr(get_the_title()) . '" />';
            $return .= '</div>';
        }
        $return .= '<div class="team-member-details">';
        $return .= '<div class="team-member-details-text">';
        $return .= '<h4>' . esc_html(get_the_title()) . '</h4>';
        
        if (!empty($team_position)) {
            $return .= '<p>' . esc_html($team_position) . '</p>';
        }
        if (!empty($team_branch) && is_array($team_branch)) {
            $return .= '<p>' . esc_html(implode(" | ", $team_branch)) . '</p>';
        }
        
        $return .= '</div>'; // Close team-member-details-text
        $return .= '<div class="team-member-details-whatsapp">';
        $return .= '<a href="https://wa.me/441234271566?text=For%20the%20Attention%20of%20' . urlencode(esc_attr(get_the_title())) . '" target="_blank" rel="noopener">';
        $return .= '<img src="' . esc_url(get_home_url()) . '/wp-content/uploads/2023/10/whatsapp-30.png" alt="WhatsApp" title="WhatsApp" />';
        $return .= '</a>';
        $return .= '</div>'; // Close team-member-details-whatsapp
        $return .= '</div>'; // Close team-member-details
        $return .= '</div>'; // Close team-member
    }
    $return .= '</div>';
}

if ($block_atts['teamblock_show_filter']) {
    $return .= '<script type="text/javascript">
    jQuery(document).ready(function($) {
        Isotope.Item.prototype._create = function() {
            // assign id, used for original-order sorting
            this.id = this.layout.itemGUID++;
            // transition objects
            this._transn = {
                ingProperties: {},
                clean: {},
                onEnd: {}
            };
            this.sortData = {};
        };

        Isotope.prototype.arrange = function(opts) {
            // set any options pass
            this.option(opts);
            this._getIsInstant();
            // just filter
            this.filteredItems = this._filter(this.items);
            // flag for initalized
            this._isLayoutInited = true;
        };
        
        var $grid = $(".team-members-container").isotope({
            itemSelector: ".team-member",
            layoutMode: "none"
        });

        var filterFns = {
            // show if number is greater than 50
            numberGreaterThan50: function() {
                var number = $(this).find(".number").text();
                return parseInt(number, 10) > 50;
            },
            // show if name ends with -ium
            ium: function() {
                var name = $(this).find(".name").text();
                return name.match(/ium$/);
            }
        };

        $(".team-filter").on("change", function() {
            // get filter value from option value
            var filterValue = this.value;
            // use filterFn if matches value
            filterValue = filterFns[filterValue] || filterValue;
            $grid.isotope({ filter: filterValue });
        });
    });
    </script>';
}

echo $return;

wp_reset_postdata();