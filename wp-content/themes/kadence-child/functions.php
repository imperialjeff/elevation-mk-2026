<?php
/**
 * Suppress Campaign Tracker "headers already sent" errors
 * These warnings occur because cookies are being set after output starts
 * The plugin still functions correctly, this just prevents log spam
 */
add_action('init', 'kadence_child_suppress_ct_warnings', 1);
function kadence_child_suppress_ct_warnings() {
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // Suppress only "headers already sent" warnings from campaign-tracker
        if ($errno === E_WARNING &&
            strpos($errstr, 'Cannot modify header information') !== false &&
            strpos($errfile, 'campaign-tracker') !== false) {
            return true;
        }
        return false;
    }, E_WARNING);
}

/**
 * Setup Child Theme Palettes
 *
 * @param string $palettes registered palette json.
 * @return string
 */
function kadence_child_change_palette_defaults($palettes) {
    $palettes = '{"palette":[{"color":"#3182CE","slug":"palette1","name":"Palette Color 1"},{"color":"#2B6CB0","slug":"palette2","name":"Palette Color 2"},{"color":"#1a202c","slug":"palette3","name":"Palette Color 3"},{"color":"#2D3748","slug":"palette4","name":"Palette Color 4"},{"color":"#4A5568","slug":"palette5","name":"Palette Color 5"},{"color":"#718096","slug":"palette6","name":"Palette Color 6"},{"color":"#eeeeee","slug":"palette7","name":"Palette Color 7"},{"color":"#F7FAFC","slug":"palette8","name":"Palette Color 8"},{"color":"#ffffff","slug":"palette9","name":"Palette Color 9"}],"second-palette":[{"color":"#3182CE","slug":"palette1","name":"Palette Color 1"},{"color":"#2B6CB0","slug":"palette2","name":"Palette Color 2"},{"color":"#1A202C","slug":"palette3","name":"Palette Color 3"},{"color":"#2D3748","slug":"palette4","name":"Palette Color 4"},{"color":"#4A5568","slug":"palette5","name":"Palette Color 5"},{"color":"#718096","slug":"palette6","name":"Palette Color 6"},{"color":"#EDF2F7","slug":"palette7","name":"Palette Color 7"},{"color":"#F7FAFC","slug":"palette8","name":"Palette Color 8"},{"color":"#ffffff","slug":"palette9","name":"Palette Color 9"}],"third-palette":[{"color":"#3182CE","slug":"palette1","name":"Palette Color 1"},{"color":"#2B6CB0","slug":"palette2","name":"Palette Color 2"},{"color":"#1A202C","slug":"palette3","name":"Palette Color 3"},{"color":"#2D3748","slug":"palette4","name":"Palette Color 4"},{"color":"#4A5568","slug":"palette5","name":"Palette Color 5"},{"color":"#718096","slug":"palette6","name":"Palette Color 6"},{"color":"#EDF2F7","slug":"palette7","name":"Palette Color 7"},{"color":"#F7FAFC","slug":"palette8","name":"Palette Color 8"},{"color":"#ffffff","slug":"palette9","name":"Palette Color 9"}],"active":"palette"}';
    return $palettes;
}
add_filter('kadence_global_palette_defaults', 'kadence_child_change_palette_defaults', 20);





/**
 * Setup Child Theme Defaults
 *
 * @param array $defaults registered option defaults with kadence theme.
 * @return array
 */
function kadence_child_change_option_defaults($defaults) {
    $new_defaults = '{"site_background":{"desktop":{"color":"#ffffff"},"flag":false},"h2_font":{"size":{"desktop":28},"lineHeight":{"desktop":1.5},"family":"inherit","google":false,"weight":"700","variant":"700","color":"palette3","sizeType":"px","lineType":"-","letterSpacing":{"desktop":""},"spacingType":"em","style":"normal","transform":"","fallback":"","flag":true}}';
    $new_defaults = json_decode($new_defaults, true);
    return wp_parse_args($new_defaults, $defaults);
}
add_filter('kadence_theme_options_defaults', 'kadence_child_change_option_defaults', 20);





/**
 * Setup Child Theme Styles
 */
function kadence_child_enqueue_styles() {
    // STYLES
    wp_enqueue_style('cs-contextual-menu', get_stylesheet_directory_uri() . '/css/contextual-menu.css', null, date('Ymd-his'));
    wp_enqueue_style('kadence-child-style', get_stylesheet_directory_uri() . '/style.css', false, date('Ymd-his'));
    // SCRIPTS
    wp_register_script('cw-custom', get_stylesheet_directory_uri() . '/js/custom.js', array('jquery'), date('Ymd-his'), true);
    wp_enqueue_script('cw-custom');
}
add_action('wp_enqueue_scripts', 'kadence_child_enqueue_styles', 20);

// FAQ Schema - FIX for line 52 error
add_action('wp_head', 'pods_get_faqs');
function pods_get_faqs($query_args) {
    global $post;
    
    // FIX: Check if $post exists and has an ID
    if (!$post || !isset($post->ID)) {
        return;
    }
    
    $cw_faqs = get_post_meta($post->ID, 'cw_faqs_to_include', true);
    
    // FIX: Check if $cw_faqs exists and is an array
    if (!$cw_faqs || !is_array($cw_faqs)) {
        return;
    }
    
    $faq_string = '';
    echo '<script type="application/ld+json">';
    echo '{"@context":"https:\/\/schema.org","type":"FAQPage","mainEntity":[';
    
    foreach ($cw_faqs as $cw_faq) {
        // FIX: Check if required array keys exist
        if (isset($cw_faq["post_title"]) && isset($cw_faq["post_content"])) {
            $faq_string.= '{"@type":"Question","name":"' . esc_js($cw_faq["post_title"]) . '",';
            $faq_string.= '"acceptedAnswer":{"@type":"Answer","text":"' . esc_js($cw_faq["post_content"]) . '"}},';
        }
    }
    
    $faq_string = rtrim($faq_string, ',');
    echo $faq_string;
    echo ']}';
    echo '</script>';
}





/**
 * Register all the modules included on the module directory
 * Added by: Lou
 */
add_action('after_setup_theme', 'modules_require');
function modules_require() {
    $modules = glob(plugin_dir_path(__FILE__) . 'modules/' . '*', GLOB_ONLYDIR);
    if ($modules) {
        foreach ($modules as $module) {
            if (file_exists($module . '/module.php')) {
                require_once ($module . '/module.php');
            }
        }
    }
}





/**
 * Add Development Property Shortcode
 * Added by: Jeff
 */
function tile_gallery_shortcode() {
    $return = '';
    $images = get_field('development_gallery');
    
    // Fallback: Check import data if no WordPress images found
    if ( (!isset($images) || !is_array($images) || empty($images) || count($images) < 5) ) {
        $import_data = get_post_meta( get_the_ID(), '_property_import_data', true );
        if ( !empty($import_data) ) {
            $import_json = ph_decode_street_import_data($import_data);
            $import_images = isset($import_json['images']) ? $import_json['images'] : array();

            if ( is_array($import_images) && count($import_images) >= 5 ) {
                $images = array();

                usort($import_images, function($a, $b) {
                    $order_a = isset($a['order']) ? (int)$a['order'] : 999;
                    $order_b = isset($b['order']) ? (int)$b['order'] : 999;
                    return $order_a - $order_b;
                });

                foreach ($import_images as $img) {
                    $url = isset($img['url']) ? $img['url'] : (isset($img['urls']['large']) ? $img['urls']['large'] : '');
                    if ( !empty($url) ) {
                        $images[] = $url;
                    }
                }
            }
        }
    }
    
    if ( isset($images) && is_array($images) && !empty($images) && count($images) >= 5 ) {
        $images_i = 0;
        $return .= '';
        $return .= '<div id="gallery_' . get_the_ID() . '" class="property-gallery development-property-gallery" itemscope itemtype="http://schema.org/ImageGallery">';

        foreach ($images as $image) { 
            // Check if this is a WordPress attachment ID or a direct URL
            if ( is_numeric($image) ) {
                // WordPress attachment ID from ACF
                $imageinfo = wp_getimagesize( wp_get_attachment_image_url( $image, 'Property Large' ) );
                $large_url = wp_get_attachment_image_url( $image, 'Property Large' );
                $medium_url = wp_get_attachment_image_url( $image, 'Property Medium' );
                $first_image_url = wp_get_attachment_image_url( $images[0], 'Property Large' );
            } else {
                // Direct URL from import data fallback
                $large_url = $image;
                $medium_url = $image;
                $first_image_url = $images[0];
                // Get image dimensions from URL
                $imageinfo = @getimagesize( $large_url );
                if ( !$imageinfo || !is_array($imageinfo) ) {
                    $imageinfo = array(1200, 800); // Default dimensions
                }
            }

            $return .= '<figure itemprop="associatedMedia" itemscope itemtype="http://schema.org/ImageObject">';

            $return .= '<a href="' . esc_url($large_url) . '" data-caption="" data-width="' . $imageinfo[0] . '" data-height="' . $imageinfo[1] . '" itemprop="contentUrl">';

            if( $images_i == 0 ) {
                $return .= '<img src="' . esc_url($large_url) . '" itemprop="thumbnail" alt="">';
            } else {
                $return .= '<img src="' . esc_url($medium_url) . '" itemprop="thumbnail" alt="">';
            }

            $return .= '</a></figure>';
            $images_i++;
        }

        $return .= '<a href="' . esc_url($first_image_url) . '" data-caption="" itemprop="contentUrl" class="property-photo-count"><strong><span class="icon-camera"></span>' . count($images) . '</strong> Total Photos</a><div class="clearfix"></div></div>';

        $return .= '<div class="pswp" tabindex="-1" role="dialog" aria-hidden="true"><div class="pswp__bg"></div><div class="pswp__scroll-wrap"><div class="pswp__container"><div class="pswp__item"></div><div class="pswp__item"></div><div class="pswp__item"></div></div><div class="pswp__ui pswp__ui--hidden"><div class="pswp__top-bar"><div class="pswp__counter"></div><button class="pswp__button pswp__button--close" title="Close (Esc)"></button><button class="pswp__button pswp__button--share" title="Share"></button><button class="pswp__button pswp__button--fs" title="Toggle fullscreen"></button><button class="pswp__button pswp__button--zoom" title="Zoom in/out"></button><div class="pswp__preloader"><div class="pswp__preloader__icn"><div class="pswp__preloader__cut"><div class="pswp__preloader__donut"></div></div></div></div></div><div class="pswp__share-modal pswp__share-modal--hidden pswp__single-tap"><div class="pswp__share-tooltip"></div></div><button class="pswp__button pswp__button--arrow--left" title="Previous (arrow left)"></button><button class="pswp__button pswp__button--arrow--right" title="Next (arrow right)"></button><div class="pswp__caption"><div class="pswp__caption__center"></div></div></div></div></div>';

        $return .= '
            <script type="text/javascript">
                \'use strict\';
                (function($) {
                    var container = [];
                    $(\'#gallery_' . get_the_ID() . '\').find(\'figure\').each(function() {
                    var $link = $(this).find(\'a\'),
                        item = {
                        src: $link.attr(\'href\'),
                        w: $link.data(\'width\'),
                        h: $link.data(\'height\'),
                        title: $link.data(\'caption\')
                        };
                    container.push(item);
                    });

                    $(\'#gallery_' . get_the_ID() . ' a\').click(function(event) {
                        event.preventDefault();

                        var $pswp = $(\'.pswp\')[0],
                        options = {
                            index: $(this).parent(\'figure\').index(),
                            bgOpacity: 0.8,
                            captionEl: false,
                            tapToClose: true,
                            shareEl: false,
                            fullscreenEl: false,
                        };

                        var gallery = new PhotoSwipe($pswp, PhotoSwipeUI_Default, container, options);
                        gallery.init();
                    });

                    if ($(window).width() > 720 && $(window).width() < 1440) {
                        $(window).bind(\'load\', function() {
                            var smalltile2_outerHeight = $(\'.property-gallery figure:nth-child(2)\').outerHeight();
                            var smalltile3_outerHeight = $(\'.property-gallery figure:nth-child(3)\').outerHeight();
                            var smalltile4_outerHeight = $(\'.property-gallery figure:nth-child(4)\').outerHeight();
                            var smalltiles_outerHeight = (+smalltile2_outerHeight) + (+smalltile3_outerHeight) + (+smalltile4_outerHeight);

                            console.log("smalltiles_outerHeight: "+smalltiles_outerHeight);
                            
                            $(\'.property-gallery figure:first-child\').css(\'height\', smalltiles_outerHeight);
                        });
                    }

                }(jQuery));
            </script>
        ';

    } else {
        $fewer_than_five = ' fewer-than-five ';
    }
    return $return;
}
add_shortcode('tile_gallery', 'tile_gallery_shortcode');





/**
 * Add Development Share BUttons Shortcode
 * Added by: Jeff
 */
function share_via_shortcode() {
    $return = '';
    $return.= '';
    $return.= '<div class="cw-property-single-share"><span>Share Via: </span>';
    $return.= '<a class="button" href="mailto:?subject=' . urlencode('Great property in Milton Keynes') . '&body=' . urlencode('Check out this property development I found with Compass Elevation - ' . get_permalink() . '.') . '" target="_blank:" rel="noreferrer noopener nofollow"><span class="icon-envelop"></span> Mail</a>&nbsp;&nbsp;';
    $return.= '<a class="button" href="https://wa.me/?text=' . urlencode('Check out this property development I found with Compass Elevation - [Development Name: ' . get_the_title() . '] - ' . get_permalink()) . '" data-action="share/whatsapp/share" target="_blank:" rel="noreferrer noopener nofollow"><span class="icon-whatsapp"></span> WhatsApp</a>&nbsp;&nbsp;';
    $return.= '<a class="button buttonCopyLink" type="button" onclick="CopyURL();"><span class="icon-link"></span> Copy Link</a>';
    $return.= '</div>';
    return $return;
}
add_shortcode('share_via', 'share_via_shortcode');





/**
 * Add Development Locrating Shortcode
 * Added by: Jeff
 */
function locrating_map_shortcode() {
    $return = '';
    $return.= '';
    $return.= '<div class="cw-property-single-section cw-property-single-map">';
    $return.= '<h3>Map</h3>';
    $return.= '<div id="single_property_map" style="width:100%; height:430px"></div>';
    $return.= '<script type="text/javascript">' . "\r\n";
    $return.= 'var lat = \'' . get_field('development_lat') . '\';' . "\r\n";
    $return.= 'var lng = \'' . get_field('development_lng') . '\';' . "\r\n";
    $return.= 'jQuery(document).ready(function($){' . "\r\n";
    $return.= 'loadLocratingPlugin({ ' . "\r\n";
    $return.= 'id: \'single_property_map\', ' . "\r\n";
    $return.= 'lat: \'' . get_field('development_lat') . '\', ' . "\r\n";
    $return.= 'lng: \'' . get_field('development_lng') . '\', ' . "\r\n";
    $return.= 'type: \'all\', ' . "\r\n";
    $return.= 'mapstyle: \'voyager\', ' . "\r\n";
    $return.= 'menucolor: \'#401663\', ' . "\r\n";
    $return.= 'menubackcolor: \'#e6e7e8\', ' . "\r\n";
    $return.= 'menuselectcolor: \'#feeff8\', ' . "\r\n";
    $return.= 'menuselectbackcolor: \'#ae8a65\', ' . "\r\n";
    $return.= 'menuallcaps: \'true\', ' . "\r\n";
    $return.= 'icon: \'https://www.locrating.com/html5/assets/images/house_icon2.png\', ' . "\r\n";
    $return.= 'lazyload:true ,' . "\r\n";
    $return.= '});' . "\r\n";
    $return.= '});' . "\r\n";
    $return.= '</script>';
    $return.= '<div class="property_actions cw-property-single-actions">';
    $return.= '<div class="cw-property_actions-notice">';
    $return.= '<p>View fullscreen interactive maps of points of interest around this property.</p>';
    $return.= '</div>';
    $return.= '<ul class="clearfix">';
    $return.= '<li class="action-locrating-all-in-one">';
    $return.= '<a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: ' . get_field('development_lat') . ', lng : ' . get_field('development_lng') . ', type:\'all\'});}catch (err) {}">Full Map</a>';
    $return.= '</li>';
    $return.= '<li class="action-locrating-schools">';
    $return.= '<a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: ' . get_field('development_lat') . ', lng : ' . get_field('development_lng') . ', type:\'schools\'});}catch (err) {}">Schools</a>';
    $return.= '</li>';
    $return.= '<li class="action-locrating-amenities">';
    $return.= '<a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: ' . get_field('development_lat') . ', lng : ' . get_field('development_lng') . ', type:\'localinfo\'});}catch (err) {}">Amenities</a>';
    $return.= '</li>';
    $return.= '<li class="action-locrating-transport">';
    $return.= '<a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: ' . get_field('development_lat') . ', lng : ' . get_field('development_lng') . ', type:\'stationslist\'});}catch (err) {}">Transport</a>';
    $return.= '</li>';
    $return.= '<li class="action-locrating-broadband-checker">';
    $return.= '<a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: ' . get_field('development_lat') . ', lng : ' . get_field('development_lng') . ', type:\'broadband\', showmap: \'true\'});}catch (err) {}">Broadband</a>';
    $return.= '</li>';
    $return.= '<li class="action-locrating-all-in-one">';
    $return.= '<a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: ' . get_field('development_lat') . ', lng : ' . get_field('development_lng') . ', type:\'all\'});}catch (err) {}">Area Info</a>';
    $return.= '</li>';
    $return.= '</div>';
    $return.= '</div>';
    return $return;
}
add_shortcode('locrating_map', 'locrating_map_shortcode');
// /*faqs*/
// function faqs_shortcode( $shortcode_atts ) {
// 	$shortcode_atts = shortcode_atts(
// 		array(
// 			'show_search' 	=> 'yes',
// 			'category' 		=> '',
// 		),
// 		$shortcode_atts
// 	);
// 	$faq_args = array(
// 		'post_type'     => 'faq',
// 		'tax_query'     => array(
// 			'taxonomy'  => 'faq-category',
// 			'field'     => 'slug',
// 			'terms'     => $shortcode_atts['category'],
// 		),
//         'orderby'  => 'date',
//         'order'    => 'DESC'
// 	);
// 	$faq_query = new WP_Query($faq_args);
// 	$shortcode_return = '';
// 	if($faq_query->have_posts()) :
// 	    if( $shortcode_atts['show_search'] == 'yes' ) {
// 		$shortcode_return .= '<div class="faq-container">';
// 		$shortcode_return .= '<div class="faq-search">';
// 		$shortcode_return .= '<input type="text" placeholder="Search Frequently Asked Questions" data-search />';
// 		$shortcode_return .= '</div>';
// 	    }
// 		$shortcode_return .=  '<div class="faq-items '. $shortcode_atts['category'] .'">';
// 		while($faq_query->have_posts()) :
// 			$faq_query->the_post();
// 			$shortcode_return .= '<div class="faq-item" data-filter-item data-filter-name="' . get_the_title($post->ID) . '">';
// 			$shortcode_return .= '<h5 class="faq-item-title">' . get_the_title($post->ID) . '</h5>';
// 			$shortcode_return .= '<div class="faq-item-content">';
// 			$shortcode_return .= get_the_content($post->ID);
// 			$shortcode_return .= '</div></div>';
// 		endwhile;
// 		$shortcode_return .= '</div></div>';
// 		// $shortcode_return .= '<script type="text/javascript">' . "\r\n";
// 		// $shortcode_return .= '(function($) {' . "\r\n";
// 		// $shortcode_return .= '$(\'[data-search]\').on(\'keyup\', function() {' . "\r\n";
// 		// $shortcode_return .= 'var searchVal = $(this).val();' . "\r\n";
// 		// $shortcode_return .= 'var filterItems = $(\'[data-filter-item]\');' . "\r\n";
// 		// $shortcode_return .= 'if ( searchVal != \'\' ) {' . "\r\n";
// 		// $shortcode_return .= 'filterItems.addClass(\'hidden\');' . "\r\n";
// 		// $shortcode_return .= '$(\'[data-filter-item][data-filter-name*="\' + searchVal.toLowerCase() + \'"]\').removeClass(\'hidden\');' . "\r\n";
// 		// $shortcode_return .= '} else {' . "\r\n";
// 		// $shortcode_return .= 'filterItems.removeClass(\'hidden\');' . "\r\n";
// 		// $shortcode_return .= '}' . "\r\n";
// 		// $shortcode_return .= '});' . "\r\n";
// 		// $shortcode_return .= '})(jQuery);' . "\r\n";
// 		// $shortcode_return .= '</script>' . "\r\n";
// 		$shortcode_return .= '<script type="text/javascript">' . "\r\n";
// 		$shortcode_return .= '(function($) {' . "\r\n";
// 		$shortcode_return .= '$("[data-search]").on("keyup", function() {' . "\r\n";
// 		$shortcode_return .= 'var value = $(this).val().toLowerCase();' . "\r\n";
// 		$shortcode_return .= '$(".faq-item").show().filter(function() {' . "\r\n";
// 		$shortcode_return .= 'return $(this).find(\'.faq-item-title\').text().toLowerCase().indexOf(value) === -1;' . "\r\n";
// 		$shortcode_return .= '}).hide();' . "\r\n";
// 		$shortcode_return .= '});' . "\r\n";
// 		$shortcode_return .= '})(jQuery);' . "\r\n";
// 		$shortcode_return .= '</script>';
// 	endif;
// 	return $shortcode_return;
// }
// add_shortcode( 'faqs', 'faqs_shortcode' );







/* ======================================== */
/* Property Development Tag
 * Added by: Jeff
 * Updated for Street CRM migration (Feb 2026)
 * Street tags: $property['tags'][] = ['tag' => 'tag_name_string']
/* ======================================== */
add_action("propertyhive_property_imported_street_json", 'set_development_tag', 10, 2);
function set_development_tag($post_id, $property) {
    if (!$post_id || !is_array($property)) {
        return;
    }

    if (isset($property['tags']) && is_array($property['tags']) && !empty($property['tags'])) {
        foreach ($property['tags'] as $tag) {
            if (isset($tag['tag']) && is_string($tag['tag']) && strpos($tag['tag'], 'development_') !== false) {
                update_post_meta($post_id, 'property_devt_tag', sanitize_text_field($tag['tag']));
            }
        }
    }
}

/* ======================================== */
/* Store furnished status from Street CRM
 * Street provides lettingsListing.furnished but the import plugin doesn't store it.
 * Added during Street CRM migration (Feb 2026)
/* ======================================== */
add_action("propertyhive_property_imported_street_json", 'set_furnished_status', 10, 2);
function set_furnished_status($post_id, $property) {
    if (!$post_id || !is_array($property)) {
        return;
    }

    if (isset($property['lettingsListing']['furnished']) && !empty($property['lettingsListing']['furnished'])) {
        $furnished_value = sanitize_text_field($property['lettingsListing']['furnished']);

        // Get or create the furnished taxonomy term
        $term = get_term_by('name', $furnished_value, 'furnished');
        if (!$term) {
            $result = wp_insert_term($furnished_value, 'furnished');
            if (!is_wp_error($result)) {
                wp_set_object_terms($post_id, (int)$result['term_id'], 'furnished');
            }
        } else {
            wp_set_object_terms($post_id, (int)$term->term_id, 'furnished');
        }
    } else {
        wp_delete_object_term_relationships($post_id, 'furnished');
    }
}

/* ======================================== */
/* Street CRM - Fetch and store development association on import
 * Calls the Street API with ?include=development for each property
 * and stores the development UUID and name as post meta.
/* ======================================== */
add_action('propertyhive_property_imported_street_json', 'fetch_and_store_development_data', 20, 3);
function fetch_and_store_development_data($post_id, $property, $import_id) {
    if (!$post_id || !is_array($property)) return;

    $dev_id = isset($property['relationships']['development']['data']['id'])
        ? $property['relationships']['development']['data']['id']
        : '';

    if (empty($dev_id)) {
        delete_post_meta($post_id, '_street_development_id');
        delete_post_meta($post_id, '_street_development_name');
        return;
    }

    $self_url = isset($property['relationships']['development']['links']['self'])
        ? $property['relationships']['development']['links']['self']
        : '';
    if (empty($self_url)) return;

    $import_settings = propertyhive_property_import_get_import_settings_from_id($import_id);
    if (empty($import_settings['api_key'])) return;

    $response = wp_remote_get($self_url, array(
        'headers' => array('Authorization' => 'Bearer ' . $import_settings['api_key']),
        'timeout' => 15,
    ));
    if (is_wp_error($response)) return;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['included'])) {
        update_post_meta($post_id, '_street_development_id', sanitize_text_field($dev_id));
        return;
    }

    $dev_name = '';
    foreach ($data['included'] as $included) {
        if (isset($included['type']) && $included['type'] === 'development' && $included['id'] === $dev_id) {
            $dev_name = isset($included['attributes']['name']) ? $included['attributes']['name'] : '';
            break;
        }
    }

    update_post_meta($post_id, '_street_development_id', sanitize_text_field($dev_id));
    update_post_meta($post_id, '_street_development_name', sanitize_text_field($dev_name));
}

/* ======================================== */
/* Replace default PropertyHive placeholder with branded image
/* ======================================== */
add_filter('propertyhive_placeholder_img_src', function() {
    return get_site_url() . '/wp-content/uploads/2026/02/mk-placeholder.png';
});

/* ======================================== */
/* Street CRM - Store EPC ratings on import
 * Street JSON has a top-level "epc" key with numeric efficiency scores.
 * The Street import plugin doesn't extract these into _epc_eec/_epc_eep,
 * so we do it here. Stores numeric scores (e.g. "84") not the letter grade.
/* ======================================== */
add_action('propertyhive_property_imported_street_json', 'set_epc_ratings', 10, 2);
function set_epc_ratings($post_id, $property) {
    if (!$post_id || !is_array($property)) return;
    if (!isset($property['epc']) || !is_array($property['epc'])) {
        delete_post_meta($post_id, '_epc_eec');
        delete_post_meta($post_id, '_epc_eep');
        return;
    }
    $epc = $property['epc'];
    $current   = isset($epc['energy_efficiency_current'])   ? (int)$epc['energy_efficiency_current']   : '';
    $potential = isset($epc['energy_efficiency_potential']) ? (int)$epc['energy_efficiency_potential'] : '';
    if (!empty($current)) {
        update_post_meta($post_id, '_epc_eec', $current);
    } else {
        delete_post_meta($post_id, '_epc_eec');
    }
    if (!empty($potential)) {
        update_post_meta($post_id, '_epc_eep', $potential);
    } else {
        delete_post_meta($post_id, '_epc_eep');
    }
}

/* ======================================== */
/* Street CRM - Decode import data JSON
 * Street's API sometimes emits unescaped double quotes inside string values
 * (e.g. imperial dimensions like 16' 8" x 11' 10"). This helper sanitizes
 * those before decoding so json_decode doesn't fail.
/* ======================================== */
function ph_decode_street_import_data( $raw ) {
    if ( empty( $raw ) ) return null;
    $json = json_decode( $raw, true );
    if ( is_array( $json ) ) return $json;
    // Street API bug: unescaped " inside string values (imperial dimensions)
    // Replace bare " that appear inside a JSON string (not preceded by \ or : or , or { or [ or whitespace)
    $sanitized = preg_replace_callback(
        '/"((?:[^"\\\\]|\\\\.)*)"/s',
        function( $m ) {
            // Re-escape any unescaped double quotes inside the captured string value
            $inner = preg_replace( '/(?<!\\\\)"/', '\\"', $m[1] );
            return '"' . $inner . '"';
        },
        $raw
    );
    return json_decode( $sanitized, true );
}

/* ======================================== */
/* Street CRM - Limit import to MK site branches only
 * Filters out Bedford and Cambridge properties before import.
 * Allowed branches: Milton Keynes, Elevation Lettings, Elevation New Homes
/* ======================================== */
add_filter('propertyhive_street_json_properties_due_import', 'filter_allowed_branch_properties', 10, 2);
function filter_allowed_branch_properties($properties, $import_id) {
    $allowed_branch_uuids = array(
        'f0c77998-800c-4298-91e0-777fd513544a', // Milton Keynes
        'fc211d8f-3165-4f84-919f-a30a442a1fa3', // Elevation Lettings
        'ce5067fc-b54a-4bc2-8ca7-b1ca0c3627be', // Elevation New Homes
    );
    return array_filter($properties, function($property) use ($allowed_branch_uuids) {
        return isset($property['attributes']['branch_uuid'])
            && in_array($property['attributes']['branch_uuid'], $allowed_branch_uuids);
    });
}


/* ======================================== */
/* Available Plots Shortcode (ACF)
/* This is a shortcode that takes intended
/* for use as a highlight section in Devts
 * Added by: Jeff
/* ======================================== */
function available_plots_shortcode() {
    $return = '';
    // WP_Query arguments
    $args = array(
        'post_type' => array('property'), 
        'post_status' => array('publish'), 
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_street_development_id',
                'value' => get_field('street_development_id'),
                'compare' => '='
            ),
            array(
                'key' => '_on_market', 
                'value' => 'yes', 
                'compare' => '='
            )
        )
    );
    // The Query
    $plots_q = new WP_Query($args);
    // The Loop
    if ($plots_q->have_posts()) {
        $return.= '<div class="available-plots-container">';
        while ($plots_q->have_posts()) {
            $plots_q->the_post();
            global $post, $property;
            $return.= '<a class="available-plot" href="' . get_the_permalink() . '" target="_blank">';
            $return.= '<div class="available-plot-image" style="background-image:url(' . $property->get_main_photo_src('large') . ')"></div>';
            $return.= '<div class="available-plot-details">';

            $ph_street_num = get_post_meta( get_the_ID(), '_address_name_number', true );

            $return.= '<h4>' . esc_html($ph_street_num) . '</h4>';
            $return.= '</div></a>';
        }
        $return.= '</div>';
    } else {
        // no posts found - add body class if on developments post type
        // if (get_post_type() === 'developments') {
        //     add_filter('body_class', function($classes) {
        //         $classes[] = 'no-available-plots';
        //         return $classes;
        //     });
        // }
        
        // Add javascript to hide the section
        $return .= '<script>
        (function() {
            var hideElement = function() {
                var element = document.querySelector("div.kadence-available-plots");
                if (element) {
                    element.style.display = "none";
                }
            };
            
            // Try to hide immediately
            hideElement();
            
            // Try again after DOM is ready
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", hideElement);
            }
            
            // Use MutationObserver to catch late-loading elements
            var observer = new MutationObserver(function(mutations) {
                var element = document.querySelector("div.kadence-available-plots");
                if (element) {
                    element.style.display = "none";
                    observer.disconnect();
                }
            });
            
            if (document.body) {
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        })();
        </script>';
    }
    // Restore original Post Data
    wp_reset_postdata();
    return $return;
}
add_shortcode('available_plots', 'available_plots_shortcode');

/* ======================================== */
/* Sold Plots Shortcode (ACF)
/* This is a shortcode that takes intended
/* for use as a highlight section in Devts
 * Added by: Jeff
/* ======================================== */
function sold_plots_shortcode() {
    $return = '';
    // WP_Query arguments
    $args = array(
        'post_type' => array('property'), 
        'post_status' => array('publish'), 
        'posts_per_page' => 6,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_street_development_id',
                'value' => get_field('street_development_id'),
                'compare' => '='
            ),
            array(
                'key' => '_on_market', 
                'value' => 'yes', 
                'compare' => 'NOT IN'
            )
        )
    );
    // The Query
    $plots_q = new WP_Query($args);
    // The Loop
    if ($plots_q->have_posts()) {
        $return.= '<div class="available-plots-container sold-plots">';
        while ($plots_q->have_posts()) {
            $plots_q->the_post();
            global $post, $property;
            $return.= '<div class="available-plot sold-plot-' . get_the_ID() . '">';
            $return.= '<div class="available-plot-image" style="background-image:url(' . $property->get_main_photo_src('large') . ')"><div class="flag flag-sold">Sold</div></div>';
            $return.= '<div class="available-plot-details">';

            $ph_street_num = get_post_meta( get_the_ID(), '_address_name_number', true );

            $return.= '<h4>' . esc_html($ph_street_num) . '</h4>';
            $return.= '</div></div>';
        }
        $return.= '</div>';
    } else {
        // no posts found - add body class if on developments post type
        // if (get_post_type() === 'developments') {
        //     add_filter('body_class', function($classes) {
        //         $classes[] = 'no-sold-plots';
        //         return $classes;
        //     });
        // }
        
        // Add javascript to hide the section
        $return .= '<script>
        (function() {
            var hideElement = function() {
                var element = document.querySelector("div.kadence-sold-plots");
                if (element) {
                    element.style.display = "none";
                }
            };
            
            // Try to hide immediately
            hideElement();
            
            // Try again after DOM is ready
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", hideElement);
            }
            
            // Use MutationObserver to catch late-loading elements
            var observer = new MutationObserver(function(mutations) {
                var element = document.querySelector("div.kadence-sold-plots");
                if (element) {
                    element.style.display = "none";
                    observer.disconnect();
                }
            });
            
            if (document.body) {
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        })();
        </script>';
    }
    // Restore original Post Data
    wp_reset_postdata();
    return $return;
}
add_shortcode('sold_plots', 'sold_plots_shortcode');

/* ======================================== */
/* Team Shortcode (ACF)
/* This is a shortcode that takes data from
/* our standard ACF team members setup
 * Added by: Jeff
/* ======================================== */
function team_shortcode($atts) {
    $atts = shortcode_atts(array('departments' => '', 'members' => '', 'categories' => '', 'layout' => '3-col'), $atts);
    $meta_query = array();
    if (!empty($atts['departments'])) {
        // $departments_string = '"' . str_replace(',', '","', $atts['departments'] ) . '"';
        // $departments_string = preg_replace('/\s+/', '', $departments_string);
        $query_departments = explode(',', $atts['departments']);
        if (sizeof($query_departments) > 1) {
            $meta_query['relation'] = 'OR';
        }
        foreach ($query_departments as $query_department) {
            $meta_query[] = array('key' => 'team_details_team_departments', 'value' => $query_department, 'compare' => 'LIKE');
        }
    }
    if (!empty($atts['categories'])) {
        $query_categories = explode(',', $atts['categories']);
        if (sizeof($query_categories) > 1) {
            $meta_query['relation'] = 'OR';
        }
        foreach ($query_categories as $query_category) {
            $meta_query[] = array('key' => 'team_details_team_category', 'value' => $query_category,);
        }
    }
    if (isset($query_departments) and isset($query_categories)) {
        $meta_query['relation'] = 'OR';
    }
    $args = array(
        'post_type'         => 'team-member', 
        'orderby'           => array(
            'menu_order'    => 'ASC',
            'date'          => 'ASC', 
        ), 
        'posts_per_page' => - 1, 
        'meta_query' => $meta_query
    );
    $the_query = new WP_Query($args);
    if ($the_query->have_posts()):
        $return = '<div class="team-members-container team-' . $atts['layout'] . '">';
        while ($the_query->have_posts()):
            $the_query->the_post();
            if (get_field('team_details_team_bio')) {
                // $return .= '<a class="team-member" href="' . get_field('team_details_team_bio') . '">';
                $return.= '<div class="team-member">';
            } else {
                $return.= '<div class="team-member">';
            }
            $return.= '<div class="team-member-profile-image">';
            $return.= '<img decoding="async" src="' . get_field('team_media_team_profile') . '" />';
            $return.= '</div>';
            $return.= '<div class="team-member-details">';
            $return.= '<div class="team-member-details-text">';
            $return.= '<h4>' . get_the_title() . '</h4>';
            $return.= '<p>' . get_field('team_details_team_position') . '</p>';
            $return.= '</div>';
            $return.= '<div class="team-member-details-whatsapp">';
            $return.= '<a href="https://wa.me/441234271566?text=For%20the%20Attention%20of%20' . urlencode(get_the_title()) . '" target="_blank" norel nofollow><img src="' . get_home_url() . '/wp-content/uploads/2023/10/whatsapp-30.png" alt="" title="WhatsApp" /></a>';
            $return.= '</div>';
            if (get_field('team_details_team_bio')) {
                // $return .= '<button>Read Bio</button>';
                
            }
            $return.= '</div>';
            if (get_field('team_details_team_bio')) {
                // $return .= '</a>';
                $return.= '</div>';
            } else {
                $return.= '</div>';
            }
        endwhile;
        $return.= '</div>';
    endif;
    wp_reset_query();
    // $return .= '<pre>';
    // $return .= print_r($args,true);
    // $return .= '</pre>';
    return $return;
}
add_shortcode('team', 'team_shortcode');

/* ======================================== */
/* FAQ shortcode
 * Added by: Jeff
/* ======================================== */
function faqs_shortcode($shortcode_atts) {
    $shortcode_atts = shortcode_atts(array('show_search' => 'yes', 'category' => '',), $shortcode_atts);
    $faq_args = array('post_type' => 'faq', 'tax_query' => array(array('taxonomy' => 'faq-category', 'field' => 'slug', 'terms' => $shortcode_atts['category'],)), 'orderby' => 'menu_order', 'order' => 'ASC');
    $faq_query = new WP_Query($faq_args);
    $shortcode_return = '';
    if ($faq_query->have_posts()):
        if ($shortcode_atts['show_search'] == 'yes') {
            $shortcode_return.= '<div class="faq-container">';
            $shortcode_return.= '<div class="faq-search">';
            $shortcode_return.= '<input type="text" placeholder="Search Frequently Asked Questions" data-search />';
            $shortcode_return.= '</div>';
        }
        $shortcode_return.= '<div class="faq-items ' . $shortcode_atts['category'] . '">';
        while ($faq_query->have_posts()):
            $faq_query->the_post();
            $shortcode_return.= '<div class="faq-item" data-filter-item data-filter-name="' . get_the_title($post->ID) . '">';
            $shortcode_return.= '<h5 class="faq-item-title">' . get_the_title($post->ID) . '</h5>';
            $shortcode_return.= '<div class="faq-item-content">';
            $shortcode_return.= get_the_content($post->ID);
            $shortcode_return.= '</div></div>';
        endwhile;
        $shortcode_return.= '</div></div>';
        // $shortcode_return .= '<script type="text/javascript">' . "\r\n";
        // $shortcode_return .= '(function($) {' . "\r\n";
        // $shortcode_return .= '$(\'[data-search]\').on(\'keyup\', function() {' . "\r\n";
        // $shortcode_return .= 'var searchVal = $(this).val();' . "\r\n";
        // $shortcode_return .= 'var filterItems = $(\'[data-filter-item]\');' . "\r\n";
        // $shortcode_return .= 'if ( searchVal != \'\' ) {' . "\r\n";
        // $shortcode_return .= 'filterItems.addClass(\'hidden\');' . "\r\n";
        // $shortcode_return .= '$(\'[data-filter-item][data-filter-name*="\' + searchVal.toLowerCase() + \'"]\').removeClass(\'hidden\');' . "\r\n";
        // $shortcode_return .= '} else {' . "\r\n";
        // $shortcode_return .= 'filterItems.removeClass(\'hidden\');' . "\r\n";
        // $shortcode_return .= '}' . "\r\n";
        // $shortcode_return .= '});' . "\r\n";
        // $shortcode_return .= '})(jQuery);' . "\r\n";
        // $shortcode_return .= '</script>' . "\r\n";
        $shortcode_return.= '<script type="text/javascript">' . "\r\n";
        $shortcode_return.= '(function($) {' . "\r\n";
        $shortcode_return.= '$("[data-search]").on("keyup", function() {' . "\r\n";
        $shortcode_return.= 'var value = $(this).val().toLowerCase();' . "\r\n";
        $shortcode_return.= '$(".faq-item").show().filter(function() {' . "\r\n";
        $shortcode_return.= 'return $(this).find(\'.faq-item-title\').text().toLowerCase().indexOf(value) === -1;' . "\r\n";
        $shortcode_return.= '}).hide();' . "\r\n";
        $shortcode_return.= '});' . "\r\n";
        $shortcode_return.= '})(jQuery);' . "\r\n";
        $shortcode_return.= '</script>';
    endif;
    return $shortcode_return;
}
add_shortcode('faqs', 'faqs_shortcode');







/* ======================================== */
/* Sold to Rented Properties Checkbox Label
 * Added by: Jeff
/* ======================================== */
add_action('wp_head', 'sold_label');
function sold_label() { ?>
	<script>
		jQuery(document).ready(function($) {
			$('#department').change(function() {
				if ($(this).val() == 'residential-lettings') {
					$('span', '#SoldPropertyCheckboxLabel').text('Rented Properties');
				} else if ($(this).val() == 'residential-sales') {
					$('span', '#SoldPropertyCheckboxLabel').text('Sold Properties');
				} else {
					$('span', '#SoldPropertyCheckboxLabel').text('Sold Properties');
				}
			});
		});
	</script>
<?php
}







/* ======================================== */
/* Set property flags on import (Street CRM)
 * Added by: Jeff
 * Rewritten for Street CRM data structure (Feb 2026)
 * Flags: 69 = New Build, 74 = Shared Ownership, 75 = Offer Accepted
 * Street sources:
 *   - New Build: attributes.property_age_bracket == "New Build"
 *                OR salesListing.new_home / lettingsListing.new_home == true
 *   - Shared Ownership: details.shared_ownership == true
 *   - Offer Accepted: salesListing.status == "Sold STC" / lettingsListing.status == "Let Agreed"
 *   - Tags: tags[].tag == 'shared_ownership'
/* ======================================== */
add_action("propertyhive_property_imported_street_json", 'set_new_home_flag', 10, 2);
function set_new_home_flag($post_id, $property) {
    if (!$post_id || !is_array($property)) {
        return;
    }

    $flags = array();

    // New Build flag (69)
    if (
        (isset($property['attributes']['property_age_bracket']) && $property['attributes']['property_age_bracket'] === 'New Build')
        || (isset($property['salesListing']['new_home']) && $property['salesListing']['new_home'] === true)
        || (isset($property['lettingsListing']['new_home']) && $property['lettingsListing']['new_home'] === true)
    ) {
        $flags[] = 69;
    }

    // Shared Ownership flag (74)
    if (isset($property['details']['shared_ownership']) && $property['details']['shared_ownership'] === true) {
        $flags[] = 74;
    }
    if (isset($property['tags']) && is_array($property['tags'])) {
        foreach ($property['tags'] as $tag) {
            if (isset($tag['tag']) && $tag['tag'] === 'shared_ownership' && !in_array(74, $flags)) {
                $flags[] = 74;
            }
        }
    }

    // Offer Accepted flag (75) - Street uses status strings
    $sales_status = isset($property['salesListing']['status']) ? $property['salesListing']['status'] : '';
    $lettings_status = isset($property['lettingsListing']['status']) ? $property['lettingsListing']['status'] : '';
    if (in_array($sales_status, array('Sold STC', 'Sold SSTC')) || $lettings_status === 'Let Agreed') {
        $flags[] = 75;
    }

    if (!empty($flags)) {
        wp_set_post_terms($post_id, $flags, 'marketing_flag');
    } else {
        wp_delete_object_term_relationships($post_id, 'marketing_flag');
    }
}







/* ======================================== */
/* Include or Exclude Sold STC
/* Line 26: Change (7, 10) to the
/* Availability IDs of 'Sold STC' and
/* 'Let Agreed', and all other statuses
/* you wish to exclude
 * Added by: Jeff
/* ======================================== */
if (!isset($_GET['marketing_flag']) or !in_array(75, $_GET['marketing_flag'])) {
    add_action('pre_get_posts', 'remove_sold_stc_by_default');
    function remove_sold_stc_by_default($q) {
        if (is_admin()) return;
        if (!$q->is_main_query()) return;
        if (!$q->is_post_type_archive('property') && !$q->is_tax(get_object_taxonomies('property'))) return;
        if (isset($_GET['shortlisted'])) return;
        $tax_query = $q->get('tax_query');
        if (!isset($_REQUEST['include_sold_stc'])) {
            $tax_query[] = array('taxonomy' => 'availability', 'field' => 'term_id', 'terms' => array(7, 8, 10, 11), 'operator' => 'NOT IN');
        }
        $q->set('tax_query', $tax_query);
    }
    add_filter('propertyhive_search_form_fields_after', 'remove_sold_stc_hidden', 10, 1);
    function remove_sold_stc_hidden($form_controls) {
        if (isset($form_controls['include_sold_stc'])) {
            unset($form_controls['include_sold_stc']);
        }
        return $form_controls;
    }
}
if (isset($_GET['marketing_flag']) and in_array(75, $_GET['marketing_flag'])) {
    add_action('pre_get_posts', 'include_only_sold_stc');
    function include_only_sold_stc($q) {
        if (is_admin()) return;
        if (!$q->is_main_query()) return;
        if (!$q->is_post_type_archive('property') && !$q->is_tax(get_object_taxonomies('property'))) return;
        if (isset($_GET['shortlisted'])) return;
        $tax_query = $q->get('tax_query');
        if (isset($_REQUEST['include_sold_stc'])) {
            $tax_query[] = array('taxonomy' => 'availability', 'field' => 'term_id', 'terms' => array(5, 77, 78, 76, 9, 6), 'operator' => 'NOT IN');
        }
        $q->set('tax_query', $tax_query);
        // echo '<pre>';
        // print_r($q);
        // echo '</pre>';
        
    }
    // add_filter( 'propertyhive_search_form_fields_after', 'remove_sold_stc_hidden', 10, 1 );
    // function remove_sold_stc_hidden($form_controls)
    // {
    //     if (isset($form_controls['include_sold_stc'])) { unset($form_controls['include_sold_stc']); }
    //     return $form_controls;
    // }
    
}

/* ======================================== */
/* Copy Property Link Button Script
 * Added by: Jeff
/* ======================================== */
// FIX for Copy URL Button Script - add proper checks
function cw_copy_url_button_script() {
    if (is_single() && get_post_type() === 'property') { ?>
        <script type="text/javascript">
        function CopyURL() {
            try {
                var dummy = document.createElement('input'),
                text = window.location.href;
                document.body.appendChild(dummy);
                dummy.value = text;
                dummy.select();
                document.execCommand('copy');
                document.body.removeChild(dummy);
                
                if (typeof jQuery !== 'undefined') {
                    jQuery('.buttonCopyLink').text('Link Copied');
                }
            } catch(err) {
                console.error('Copy failed:', err);
            }
        }
        </script>
    <?php
    }
}
add_action('wp_head', 'cw_copy_url_button_script');







/* ======================================== */
/* Get Center Point from an array of
/* Longitude, Latitudes
 * Added by: Jeff
/* ======================================== */
function get_center($coords) {
    $count_coords = count($coords);
    $xcos = 0.0;
    $ycos = 0.0;
    $zsin = 0.0;
    foreach ($coords as $lnglat) {
        $lat = $lnglat['lat'] * pi() / 180;
        $lon = $lnglat['lng'] * pi() / 180;
        $acos = cos($lat) * cos($lon);
        $bcos = cos($lat) * sin($lon);
        $csin = sin($lat);
        $xcos+= $acos;
        $ycos+= $bcos;
        $zsin+= $csin;
    }
    $xcos/= $count_coords;
    $ycos/= $count_coords;
    $zsin/= $count_coords;
    $lon = atan2($ycos, $xcos);
    $sqrt = sqrt($xcos * $xcos + $ycos * $ycos);
    $lat = atan2($zsin, $sqrt);
    return array($lat * 180 / pi(), $lon * 180 / pi());
}







/* ======================================== */
/* Override per-page limit for map view
 * Added by: Jeff
/* ======================================== */
add_filter('loop_search_results_per_page', 'change_properties_per_page', 20);
function change_properties_per_page() {
    if (isset($_GET['view']) && $_GET['view'] == 'map') {
        return -1;
    }
}







/* ======================================== */
/* Make reusable blocks accessible in
/* backend
 * Added by: Jeff
/* ======================================== */
function be_reusable_blocks_admin_menu() {
    add_menu_page('Reusable Blocks', 'Reusable Blocks', 'edit_posts', 'edit.php?post_type=wp_block', '', 'dashicons-editor-table', 22);
}
add_action('admin_menu', 'be_reusable_blocks_admin_menu');







/* ======================================== */
/* Return a widget using a shortcode
 * Added by: Jeff
/* ======================================== */
function widget($atts) {
    global $wp_widget_factory;
    extract(shortcode_atts(array('widget_name' => FALSE), $atts));
    $widget_name = wp_specialchars($widget_name);
    if (!is_a($wp_widget_factory->widgets[$widget_name], 'WP_Widget')):
        $wp_class = 'WP_Widget_' . ucwords(strtolower($class));
        if (!is_a($wp_widget_factory->widgets[$wp_class], 'WP_Widget')):
            return '<p>' . sprintf(__("%s: Widget class not found. Make sure this widget exists and the class name is correct"), '<strong>' . $class . '</strong>') . '</p>';
        else:
            $class = $wp_class;
        endif;
    endif;
    ob_start();
    the_widget($widget_name, $instance, array('widget_id' => 'arbitrary-instance-' . $id, 'widget_name' => '', 'after_widget' => '', 'before_title' => '', 'after_title' => ''));
    $output = ob_get_contents();
    ob_end_clean();
    return $output;
}
add_shortcode('widget', 'widget');







/* ======================================== */
/* Return custom field's value using
/* shortcode
 * Added by: Jeff
/* ======================================== */
add_shortcode('field', 'shortcode_field');
function shortcode_field($atts) {
    extract(shortcode_atts(array('post_id' => NULL,), $atts));
    if (!isset($atts[0])) return;
    $field = esc_attr($atts[0]);
    global $post;
    $post_id = (NULL === $post_id) ? $post->ID : $post_id;
    return get_post_meta($post_id, $field, true);
}







/* ======================================== */
/* Customized Property Search Form
/* (Horizontal)
 * Added by: Jeff
/* ======================================== */
function propertysearch_horizontal_shortcode() {
    // Initialize all variables with defaults to prevent undefined variable warnings
    $field_department_residential_sales_selected = '';
    $field_department_residential_lettings_selected = '';
    $field_radius_selected_0 = '';
    $field_radius_selected_1 = '';
    $field_radius_selected_2 = '';
    $field_radius_selected_3 = '';
    $field_radius_selected_5 = '';
    $field_radius_selected_10 = '';
    $field_minimum_price_0 = '';
    $field_minimum_price_100000 = '';
    $field_minimum_price_150000 = '';
    $field_minimum_price_200000 = '';
    $field_minimum_price_250000 = '';
    $field_minimum_price_300000 = '';
    $field_minimum_price_500000 = '';
    $field_minimum_price_750000 = '';
    $field_minimum_price_1000000 = '';
    $field_maximum_price_0 = '';
    $field_maximum_price_100000 = '';
    $field_maximum_price_150000 = '';
    $field_maximum_price_200000 = '';
    $field_maximum_price_250000 = '';
    $field_maximum_price_300000 = '';
    $field_maximum_price_500000 = '';
    $field_maximum_price_750000 = '';
    $field_maximum_price_1000000 = '';
    $field_minimum_rent_0 = '';
    $field_minimum_rent_500 = '';
    $field_minimum_rent_600 = '';
    $field_minimum_rent_750 = '';
    $field_minimum_rent_1000 = '';
    $field_minimum_rent_1250 = '';
    $field_minimum_rent_1500 = '';
    $field_minimum_rent_2000 = '';
    $field_minimum_rent_5000 = '';
    $field_minimum_rent_10000 = '';
    $field_minimum_rent_15000 = '';
    $field_minimum_rent_20000 = '';
    $field_minimum_rent_30000 = '';
    $field_minimum_rent_40000 = '';
    $field_minimum_rent_50000 = '';
    $field_maximum_rent_0 = '';
    $field_maximum_rent_500 = '';
    $field_maximum_rent_600 = '';
    $field_maximum_rent_750 = '';
    $field_maximum_rent_1000 = '';
    $field_maximum_rent_1250 = '';
    $field_maximum_rent_1500 = '';
    $field_maximum_rent_2000 = '';
    $field_maximum_rent_5000 = '';
    $field_maximum_rent_10000 = '';
    $field_maximum_rent_15000 = '';
    $field_maximum_rent_20000 = '';
    $field_maximum_rent_30000 = '';
    $field_maximum_rent_40000 = '';
    $field_maximum_rent_50000 = '';
    $field_minimum_bedrooms_0 = '';
    $field_minimum_bedrooms_1 = '';
    $field_minimum_bedrooms_2 = '';
    $field_minimum_bedrooms_3 = '';
    $field_minimum_bedrooms_4 = '';
    $field_minimum_bedrooms_5 = '';
    $field_minimum_bedrooms_6 = '';
    $field_minimum_bedrooms_7 = '';
    $field_minimum_bedrooms_8 = '';
    $field_minimum_bedrooms_9 = '';
    $field_minimum_bedrooms_10 = '';
    $field_maximum_bedrooms_0 = '';
    $field_maximum_bedrooms_1 = '';
    $field_maximum_bedrooms_2 = '';
    $field_maximum_bedrooms_3 = '';
    $field_maximum_bedrooms_4 = '';
    $field_maximum_bedrooms_5 = '';
    $field_maximum_bedrooms_6 = '';
    $field_maximum_bedrooms_7 = '';
    $field_maximum_bedrooms_8 = '';
    $field_maximum_bedrooms_9 = '';
    $field_maximum_bedrooms_10 = '';
    $field_marketing_flag_chain_free = '';
    $field_marketing_flag_new_construction = '';
    $field_marketing_flag_shared_ownership = '';
    $field_marketing_flag_subject_to_contract = '';
    $is_map_view = '';

    if (isset($_GET['department'])) {
        $field_department = $_GET['department'];
    } else {
        $field_department = null;
    }
    if ($field_department !== null) {
        if ($field_department == 'residential-sales') {
            $field_department_residential_sales_selected = ' selected="selected" ';
        } else {
            $field_department_residential_sales_selected = '';
        }
        if ($field_department == 'residential-lettings') {
            $field_department_residential_lettings_selected = ' selected="selected" ';
        } else {
            $field_department_residential_lettings_selected = '';
        }
    } else {
        $field_department_residential_sales_selected = ' selected="selected" ';
    }
    if (isset($_GET['address_keyword'])) {
        $field_address_keyword = $_GET['address_keyword'];
    } else {
        $field_address_keyword = null;
    }
    if (isset($_GET['radius'])) {
        $field_radius = $_GET['radius'];
    } else {
        $field_radius = null;
    }
    if ($field_radius !== null) {
        if ($field_radius == 1) {
            $field_radius_selected_1 = ' selected="selected" ';
        } else {
            $field_radius_selected_1 = '';
        }
        if ($field_radius == 2) {
            $field_radius_selected_2 = ' selected="selected" ';
        } else {
            $field_radius_selected_2 = '';
        }
        if ($field_radius == 3) {
            $field_radius_selected_3 = ' selected="selected" ';
        } else {
            $field_radius_selected_3 = '';
        }
        if ($field_radius == 5) {
            $field_radius_selected_5 = ' selected="selected" ';
        } else {
            $field_radius_selected_5 = '';
        }
        if ($field_radius == 10) {
            $field_radius_selected_10 = ' selected="selected" ';
        } else {
            $field_radius_selected_10 = '';
        }
    } else {
        $field_radius_selected_0 = ' selected="selected" ';
    }
    if (isset($_GET['minimum_price'])) {
        $field_minimum_price = $_GET['minimum_price'];
    } else {
        $field_minimum_price = null;
    }
    if ($field_minimum_price !== null) {
        if ($field_minimum_price == 100000) {
            $field_minimum_price_100000 = ' selected="selected" ';
        } else {
            $field_minimum_price_100000 = '';
        }
        if ($field_minimum_price == 150000) {
            $field_minimum_price_150000 = ' selected="selected" ';
        } else {
            $field_minimum_price_150000 = '';
        }
        if ($field_minimum_price == 200000) {
            $field_minimum_price_200000 = ' selected="selected" ';
        } else {
            $field_minimum_price_200000 = '';
        }
        if ($field_minimum_price == 250000) {
            $field_minimum_price_250000 = ' selected="selected" ';
        } else {
            $field_minimum_price_250000 = '';
        }
        if ($field_minimum_price == 300000) {
            $field_minimum_price_300000 = ' selected="selected" ';
        } else {
            $field_minimum_price_300000 = '';
        }
        if ($field_minimum_price == 500000) {
            $field_minimum_price_500000 = ' selected="selected" ';
        } else {
            $field_minimum_price_500000 = '';
        }
        if ($field_minimum_price == 750000) {
            $field_minimum_price_750000 = ' selected="selected" ';
        } else {
            $field_minimum_price_750000 = '';
        }
        if ($field_minimum_price == 1000000) {
            $field_minimum_price_1000000 = ' selected="selected" ';
        } else {
            $field_minimum_price_1000000 = '';
        }
    } else {
        $field_minimum_price_0 = ' selected="selected" ';
    }
    if (isset($_GET['maximum_price'])) {
        $field_maximum_price = $_GET['maximum_price'];
    } else {
        $field_maximum_price = null;
    }
    if ($field_maximum_price !== null) {
        if ($field_maximum_price == 100000) {
            $field_maximum_price_100000 = ' selected="selected" ';
        } else {
            $field_maximum_price_100000 = '';
        }
        if ($field_maximum_price == 150000) {
            $field_maximum_price_150000 = ' selected="selected" ';
        } else {
            $field_maximum_price_150000 = '';
        }
        if ($field_maximum_price == 200000) {
            $field_maximum_price_200000 = ' selected="selected" ';
        } else {
            $field_maximum_price_200000 = '';
        }
        if ($field_maximum_price == 250000) {
            $field_maximum_price_250000 = ' selected="selected" ';
        } else {
            $field_maximum_price_250000 = '';
        }
        if ($field_maximum_price == 300000) {
            $field_maximum_price_300000 = ' selected="selected" ';
        } else {
            $field_maximum_price_300000 = '';
        }
        if ($field_maximum_price == 500000) {
            $field_maximum_price_500000 = ' selected="selected" ';
        } else {
            $field_maximum_price_500000 = '';
        }
        if ($field_maximum_price == 750000) {
            $field_maximum_price_750000 = ' selected="selected" ';
        } else {
            $field_maximum_price_750000 = '';
        }
        if ($field_maximum_price == 1000000) {
            $field_maximum_price_1000000 = ' selected="selected" ';
        } else {
            $field_maximum_price_1000000 = '';
        }
    } else {
        $field_maximum_price_0 = ' selected="selected" ';
    }
    if (isset($_GET['minimum_rent'])) {
        $field_minimum_rent = $_GET['minimum_rent'];
    } else {
        $field_minimum_rent = null;
    }
    if ($field_minimum_rent !== null) {
        if ($field_minimum_rent == 500) {
            $field_minimum_rent_500 = ' selected="selected" ';
        } else {
            $field_minimum_rent_500 = '';
        }
        if ($field_minimum_rent == 600) {
            $field_minimum_rent_600 = ' selected="selected" ';
        } else {
            $field_minimum_rent_600 = '';
        }
        if ($field_minimum_rent == 750) {
            $field_minimum_rent_750 = ' selected="selected" ';
        } else {
            $field_minimum_rent_750 = '';
        }
        if ($field_minimum_rent == 1000) {
            $field_minimum_rent_1000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_1000 = '';
        }
        if ($field_minimum_rent == 1250) {
            $field_minimum_rent_1250 = ' selected="selected" ';
        } else {
            $field_minimum_rent_1250 = '';
        }
        if ($field_minimum_rent == 1500) {
            $field_minimum_rent_1500 = ' selected="selected" ';
        } else {
            $field_minimum_rent_1500 = '';
        }
        if ($field_minimum_rent == 2000) {
            $field_minimum_rent_2000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_2000 = '';
        }
        if ($field_minimum_rent == 5000) {
            $field_minimum_rent_5000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_5000 = '';
        }
        if ($field_minimum_rent == 10000) {
            $field_minimum_rent_10000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_10000 = '';
        }
        if ($field_minimum_rent == 15000) {
            $field_minimum_rent_15000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_15000 = '';
        }
        if ($field_minimum_rent == 20000) {
            $field_minimum_rent_20000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_20000 = '';
        }
        if ($field_minimum_rent == 30000) {
            $field_minimum_rent_30000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_30000 = '';
        }
        if ($field_minimum_rent == 40000) {
            $field_minimum_rent_40000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_40000 = '';
        }
        if ($field_minimum_rent == 50000) {
            $field_minimum_rent_50000 = ' selected="selected" ';
        } else {
            $field_minimum_rent_50000 = '';
        }
    } else {
        $field_minimum_rent_0 = ' selected="selected" ';
    }
    if (isset($_GET['maximum_rent'])) {
        $field_maximum_rent = $_GET['maximum_rent'];
    } else {
        $field_maximum_rent = null;
    }
    if ($field_maximum_rent !== null) {
        if ($field_maximum_rent == 500) {
            $field_maximum_rent_500 = ' selected="selected" ';
        } else {
            $field_maximum_rent_500 = '';
        }
        if ($field_maximum_rent == 600) {
            $field_maximum_rent_600 = ' selected="selected" ';
        } else {
            $field_maximum_rent_600 = '';
        }
        if ($field_maximum_rent == 750) {
            $field_maximum_rent_750 = ' selected="selected" ';
        } else {
            $field_maximum_rent_750 = '';
        }
        if ($field_maximum_rent == 1000) {
            $field_maximum_rent_1000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_1000 = '';
        }
        if ($field_maximum_rent == 1250) {
            $field_maximum_rent_1250 = ' selected="selected" ';
        } else {
            $field_maximum_rent_1250 = '';
        }
        if ($field_maximum_rent == 1500) {
            $field_maximum_rent_1500 = ' selected="selected" ';
        } else {
            $field_maximum_rent_1500 = '';
        }
        if ($field_maximum_rent == 2000) {
            $field_maximum_rent_2000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_2000 = '';
        }
        if ($field_maximum_rent == 5000) {
            $field_maximum_rent_5000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_5000 = '';
        }
        if ($field_maximum_rent == 10000) {
            $field_maximum_rent_10000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_10000 = '';
        }
        if ($field_maximum_rent == 15000) {
            $field_maximum_rent_15000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_15000 = '';
        }
        if ($field_maximum_rent == 20000) {
            $field_maximum_rent_20000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_20000 = '';
        }
        if ($field_maximum_rent == 30000) {
            $field_maximum_rent_30000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_30000 = '';
        }
        if ($field_maximum_rent == 40000) {
            $field_maximum_rent_40000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_40000 = '';
        }
        if ($field_maximum_rent == 50000) {
            $field_maximum_rent_50000 = ' selected="selected" ';
        } else {
            $field_maximum_rent_50000 = '';
        }
    } else {
        $field_maximum_rent_0 = ' selected="selected" ';
    }
    if (isset($_GET['minimum_bedrooms'])) {
        $field_minimum_bedrooms = $_GET['minimum_bedrooms'];
    } else {
        $field_minimum_bedrooms = null;
    }
    if ($field_minimum_bedrooms !== null) {
        if ($field_minimum_bedrooms == 1) {
            $field_minimum_bedrooms_1 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_1 = '';
        }
        if ($field_minimum_bedrooms == 2) {
            $field_minimum_bedrooms_2 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_2 = '';
        }
        if ($field_minimum_bedrooms == 3) {
            $field_minimum_bedrooms_3 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_3 = '';
        }
        if ($field_minimum_bedrooms == 4) {
            $field_minimum_bedrooms_4 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_4 = '';
        }
        if ($field_minimum_bedrooms == 5) {
            $field_minimum_bedrooms_5 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_5 = '';
        }
        if ($field_minimum_bedrooms == 6) {
            $field_minimum_bedrooms_6 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_6 = '';
        }
        if ($field_minimum_bedrooms == 7) {
            $field_minimum_bedrooms_7 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_7 = '';
        }
        if ($field_minimum_bedrooms == 8) {
            $field_minimum_bedrooms_8 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_8 = '';
        }
        if ($field_minimum_bedrooms == 9) {
            $field_minimum_bedrooms_9 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_9 = '';
        }
        if ($field_minimum_bedrooms == 10) {
            $field_minimum_bedrooms_10 = ' selected="selected" ';
        } else {
            $field_minimum_bedrooms_10 = '';
        }
    } else {
        $field_minimum_bedrooms_0 = ' selected="selected" ';
    }
    if (isset($_GET['maximum_bedrooms'])) {
        $field_maximum_bedrooms = $_GET['maximum_bedrooms'];
    } else {
        $field_maximum_bedrooms = null;
    }
    if ($field_maximum_bedrooms !== null) {
        if ($field_maximum_bedrooms == 1) {
            $field_maximum_bedrooms_1 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_1 = '';
        }
        if ($field_maximum_bedrooms == 2) {
            $field_maximum_bedrooms_2 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_2 = '';
        }
        if ($field_maximum_bedrooms == 3) {
            $field_maximum_bedrooms_3 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_3 = '';
        }
        if ($field_maximum_bedrooms == 4) {
            $field_maximum_bedrooms_4 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_4 = '';
        }
        if ($field_maximum_bedrooms == 5) {
            $field_maximum_bedrooms_5 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_5 = '';
        }
        if ($field_maximum_bedrooms == 6) {
            $field_maximum_bedrooms_6 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_6 = '';
        }
        if ($field_maximum_bedrooms == 7) {
            $field_maximum_bedrooms_7 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_7 = '';
        }
        if ($field_maximum_bedrooms == 8) {
            $field_maximum_bedrooms_8 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_8 = '';
        }
        if ($field_maximum_bedrooms == 9) {
            $field_maximum_bedrooms_9 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_9 = '';
        }
        if ($field_maximum_bedrooms == 10) {
            $field_maximum_bedrooms_10 = ' selected="selected" ';
        } else {
            $field_maximum_bedrooms_10 = '';
        }
    } else {
        $field_maximum_bedrooms_0 = ' selected="selected" ';
    }
    if (isset($_GET['marketing_flag'])) {
        $field_marketing_flag = $_GET['marketing_flag'];
    } else {
        $field_marketing_flag = null;
    }
    if ($field_marketing_flag !== null) {
        if (in_array('70', $field_marketing_flag)) {
            $field_marketing_flag_chain_free = ' checked="checked" ';
        } else {
            $field_marketing_flag_chain_free = '';
        }
        if (in_array('69', $field_marketing_flag)) {
            $field_marketing_flag_new_construction = ' checked="checked" ';
        } else {
            $field_marketing_flag_new_construction = '';
        }
        if (in_array('74', $field_marketing_flag)) {
            $field_marketing_flag_shared_ownership = ' checked="checked" ';
        } else {
            $field_marketing_flag_shared_ownership = '';
        }
        if (in_array('75', $field_marketing_flag)) {
            $field_marketing_flag_subject_to_contract = ' checked="checked" ';
        } else {
            $field_marketing_flag_subject_to_contract = '';
        }
    }
    if (isset($_GET['lat'])) {
        $field_lat = $_GET['lat'];
    } else {
        $field_lat = null;
    }
    if (isset($_GET['lng'])) {
        $field_lng = $_GET['lng'];
    } else {
        $field_lng = null;
    }
    if (isset($_GET['view']) && $_GET['view'] != '' && $_GET['view'] == 'map') {
        $is_map_view = 'map';
    }
    // echo '<pre>';
    // echo 'Collected fields: <br />';
    // echo '$field_department: ' . $field_department . '<br />';
    // echo '$field_address_keyword: ' . $field_address_keyword . '<br />';
    // echo '$field_radius: ' . $field_radius . '<br />';
    // echo '$field_minimum_price: ' . $field_minimum_price . '<br />';
    // echo '$field_maximum_price: ' . $field_maximum_price . '<br />';
    // echo '$field_minimum_rent: ' . $field_minimum_rent . '<br />';
    // echo '$field_maximum_rent: ' . $field_maximum_rent . '<br />';
    // echo '$field_minimum_bedrooms: ' . $field_minimum_bedrooms . '<br />';
    // echo '$field_maximum_bedrooms: ' . $field_maximum_bedrooms . '<br />';
    // echo '$field_lat: ' . $field_lat . '<br />';
    // echo '$field_lng: ' . $field_lng . '<br />';
    // echo '$field_marketing_flag: <br />';
    // print_r($field_marketing_flag);
    // echo '<br />';
    // echo '</pre>';
    $return = '
	<form name="ph_property_search" class="property-search-form property-search-form-default clear" action="' . get_site_url() . '/properties/" method="get" role="form">
	<div class="cw-fields-wrap">    
		<div class="cw-field-rows">
			<div class="property_search_first_row">
				<div class="cw-field-group-wrapper">
					<div class="control control-department">
						<label for="department">Looking to</label>
						<select name="department" id="department" class="" data-blank-option="">
							<option value="residential-sales" ' . $field_department_residential_sales_selected . '>Buy a Home</option>
							<option value="residential-lettings" ' . $field_department_residential_lettings_selected . '>Rent a Home</option>
						</select>
					</div>
				</div>
				<div class="cw-field-group-wrapper">
					<div class="control control-address_keyword">
						<label for="address_keyword">Location</label><input type="text" name="address_keyword" id="address_keyword" value="' . $field_address_keyword . '" placeholder="Postcode or Town/City" class="" style="">
						<div class="current-location" style="position:absolute; right:15px; bottom:10px; width:30px;"><a href="" title="Use Current Location"><span class="icon-compass"></span></a></div>
					</div>
					<div class="control control-radius">
						<label for="radius">Radius</label>
						<select name="radius" id="radius" class="" data-blank-option="">
							<option value="" ' . $field_radius_selected_0 . '>This Area Only</option>
							<option value="1" ' . $field_radius_selected_1 . '>Within 1 Mile</option>
							<option value="2" ' . $field_radius_selected_2 . '>Within 2 Miles</option>
							<option value="3" ' . $field_radius_selected_3 . '>Within 3 Miles</option>
							<option value="5" ' . $field_radius_selected_5 . '>Within 5 Miles</option>
							<option value="10" ' . $field_radius_selected_10 . '>Within 10 Miles</option>
						</select>
					</div>
				</div>
				<div class="cw-field-group-wrapper sales-only">
					<div class="control control-minimum_price sales-only">
						<label for="minimum_price">Price Range</label>
						<select name="minimum_price" id="minimum_price" class="" data-blank-option="">
							<option value="" ' . $field_minimum_price_0 . '>Minimum Price</option>
							<option value="100000" ' . $field_minimum_price_100000 . '>£100,000</option>
							<option value="150000" ' . $field_minimum_price_150000 . '>£150,000</option>
							<option value="200000" ' . $field_minimum_price_200000 . '>£200,000</option>
							<option value="250000" ' . $field_minimum_price_250000 . '>£250,000</option>
							<option value="300000" ' . $field_minimum_price_300000 . '>£300,000</option>
							<option value="500000" ' . $field_minimum_price_500000 . '>£500,000</option>
							<option value="750000" ' . $field_minimum_price_750000 . '>£750,000</option>
							<option value="1000000" ' . $field_minimum_price_1000000 . '>£1,000,000</option>
						</select>
					</div>
					<div class="control control-maximum_price sales-only">
						<label for="maximum_price">&nbsp;</label>
						<select name="maximum_price" id="maximum_price" class="" data-blank-option="">
							<option value="" ' . $field_maximum_price_0 . '>Maximum Price</option>
							<option value="100000" ' . $field_maximum_price_100000 . '>£100,000</option>
							<option value="150000" ' . $field_maximum_price_150000 . '>£150,000</option>
							<option value="200000" ' . $field_maximum_price_200000 . '>£200,000</option>
							<option value="250000" ' . $field_maximum_price_250000 . '>£250,000</option>
							<option value="300000" ' . $field_maximum_price_300000 . '>£300,000</option>
							<option value="500000" ' . $field_maximum_price_500000 . '>£500,000</option>
							<option value="750000" ' . $field_maximum_price_750000 . '>£750,000</option>
							<option value="1000000" ' . $field_maximum_price_1000000 . '>£1,000,000</option>
						</select>
					</div>
				</div>
				<div class="cw-field-group-wrapper lettings-only">
					<div class="control control-minimum_rent lettings-only">
						<label for="minimum_rent">Price Range</label>
						<select name="minimum_rent" id="minimum_rent" class="" data-blank-option="">
							<option value="" ' . $field_minimum_rent_0 . '>Minimum Rent</option>
							<option value="500" ' . $field_minimum_rent_500 . '>£500 PCM</option>
							<option value="600" ' . $field_minimum_rent_600 . '>£600 PCM</option>
							<option value="750" ' . $field_minimum_rent_750 . '>£750 PCM</option>
							<option value="1000" ' . $field_minimum_rent_1000 . '>£1,000 PCM</option>
							<option value="1250" ' . $field_minimum_rent_1250 . '>£1,250 PCM</option>
							<option value="1500" ' . $field_minimum_rent_1500 . '>£1,500 PCM</option>
							<option value="2000" ' . $field_minimum_rent_2000 . '>£2,000 PCM</option>
							<option value="5000" ' . $field_minimum_rent_5000 . '>£5,000 PCM</option>
							<option value="10000" ' . $field_minimum_rent_10000 . '>£10,000 PCM</option>
							<option value="15000" ' . $field_minimum_rent_15000 . '>£15,000 PCM</option>
							<option value="20000" ' . $field_minimum_rent_20000 . '>£20,000 PCM</option>
							<option value="30000" ' . $field_minimum_rent_30000 . '>£30,000 PCM</option>
							<option value="40000" ' . $field_minimum_rent_40000 . '>£40,000 PCM</option>
							<option value="50000" ' . $field_minimum_rent_50000 . '>£50,000 PCM</option>
						</select>
					</div>
					<div class="control control-maximum_rent lettings-only">
						<label for="maximum_rent">&nbsp;</label>
						<select name="maximum_rent" id="maximum_rent" class="" data-blank-option="">
							<option value="" ' . $field_maximum_rent_0 . '>Maximum Rent</option>
							<option value="500"' . $field_maximum_rent_500 . '>£500 PCM</option>
							<option value="600"' . $field_maximum_rent_600 . '>£600 PCM</option>
							<option value="750"' . $field_maximum_rent_750 . '>£750 PCM</option>
							<option value="1000"' . $field_maximum_rent_1000 . '>£1,000 PCM</option>
							<option value="1250"' . $field_maximum_rent_1250 . '>£1,250 PCM</option>
							<option value="1500"' . $field_maximum_rent_1500 . '>£1,500 PCM</option>
							<option value="2000"' . $field_maximum_rent_2000 . '>£2,000 PCM</option>
							<option value="5000"' . $field_maximum_rent_5000 . '>£5,000 PCM</option>
							<option value="10000"' . $field_maximum_rent_10000 . '>£10,000 PCM</option>
							<option value="15000"' . $field_maximum_rent_15000 . '>£15,000 PCM</option>
							<option value="20000"' . $field_maximum_rent_20000 . '>£20,000 PCM</option>
							<option value="30000"' . $field_maximum_rent_30000 . '>£30,000 PCM</option>
							<option value="40000"' . $field_maximum_rent_40000 . '>£40,000 PCM</option>
							<option value="50000"' . $field_maximum_rent_50000 . '>£50,000 PCM</option>
						</select>
					</div>
				</div>
				<div class="cw-field-group-wrapper">
					<div class="control control-minimum_bedrooms residential-only">
						<label for="minimum_bedrooms">No. of Bedrooms</label>
						<select name="minimum_bedrooms" id="minimum_bedrooms" class="" data-blank-option="">
							<option value="" ' . $field_minimum_bedrooms_0 . '>Minimum Bedrooms</option>
							<option value="1" ' . $field_minimum_bedrooms_1 . '>1</option>
							<option value="2" ' . $field_minimum_bedrooms_2 . '>2</option>
							<option value="3" ' . $field_minimum_bedrooms_3 . '>3</option>
							<option value="4" ' . $field_minimum_bedrooms_4 . '>4</option>
							<option value="5" ' . $field_minimum_bedrooms_5 . '>5</option>
							<option value="6" ' . $field_minimum_bedrooms_6 . '>6</option>
							<option value="7" ' . $field_minimum_bedrooms_7 . '>7</option>
							<option value="8" ' . $field_minimum_bedrooms_8 . '>8</option>
							<option value="9" ' . $field_minimum_bedrooms_9 . '>9</option>
							<option value="10" ' . $field_minimum_bedrooms_10 . '>10</option>
						</select>
					</div>
					<div class="control control-maximum_bedrooms residential-only">
						<label for="maximum_bedrooms">&nbsp;</label>
						<select name="maximum_bedrooms" id="maximum_bedrooms" class="" data-blank-option="">
							<option value="" ' . $field_maximum_bedrooms_0 . '>Maximum Bedrooms</option>
							<option value="1" ' . $field_maximum_bedrooms_1 . '>1</option>
							<option value="2" ' . $field_maximum_bedrooms_2 . '>2</option>
							<option value="3" ' . $field_maximum_bedrooms_3 . '>3</option>
							<option value="4" ' . $field_maximum_bedrooms_4 . '>4</option>
							<option value="5" ' . $field_maximum_bedrooms_5 . '>5</option>
							<option value="6" ' . $field_maximum_bedrooms_6 . '>6</option>
							<option value="7" ' . $field_maximum_bedrooms_7 . '>7</option>
							<option value="8" ' . $field_maximum_bedrooms_8 . '>8</option>
							<option value="9" ' . $field_maximum_bedrooms_9 . '>9</option>
							<option value="10" ' . $field_maximum_bedrooms_10 . '>10</option>
						</select>
					</div>
				</div>
			</div>
			
			<div class="property_search_second_row">
				<div class="control control-marketing_flag">
					<p>Show Only: </p>
					<!--<label for="ms-opt-1"><input name="marketing_flag[]" type="checkbox" title="Chain Free" value="70" ' . $field_marketing_flag_chain_free . '>Chain Free</label>-->
					<label for="ms-opt-2"><input name="marketing_flag[]" type="checkbox" title="New Build" value="69" ' . $field_marketing_flag_new_construction . '>New Build</label>
					<label for="ms-opt-3"><input name="marketing_flag[]" type="checkbox" title="Shared Ownership" value="74" ' . $field_marketing_flag_shared_ownership . '>Shared Ownership</label>
					<label for="ms-opt-4" id="SoldPropertyCheckboxLabel"><input name="marketing_flag[]" type="checkbox" title="Subject to Contract" value="75" ' . $field_marketing_flag_subject_to_contract . ' id="SoldPropertyCheckbox"><span>Sold Properties</span></label>
				</div>
			</div>
		</div>
		<input type="hidden" name="lat" value="">
		<input type="hidden" name="lng" value="">
		<input type="hidden" name = "view" value="' . $is_map_view . '">
		<input type="submit" value="Search">
	</div>
	</form>
	';
    return $return;
}
add_shortcode('propertysearch_horizontal', 'propertysearch_horizontal_shortcode');







/* ======================================== */
/* Enqueue Additional Styles and Scripts
 * Added by: Jeff
/* ======================================== */
function cw_theme_enqueue_styles() {
    wp_enqueue_style('icon-styles', get_stylesheet_directory_uri() . '/css/icon-styles.css');

    wp_enqueue_style('slick-css', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css');

    wp_enqueue_script('slick-js', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js');
}
add_action('wp_enqueue_scripts', 'cw_theme_enqueue_styles', 99);







/* ======================================== */
/* CW Context Menu
 * Added by: Jeff
/* ======================================== */
function yoast_allow_rel() {
    // provides a link to google plus profile
    global $allowedtags;
    $allowedtags['a']['rel'] = array();
}
add_action('wp_loaded', 'yoast_allow_rel');
function yoast_add_google_profile($contactmethods) {
    // Add Google Profiles
    $contactmethods['google_profile'] = 'Google Profile URL';
    return $contactmethods;
}
add_filter('user_contactmethods', 'yoast_add_google_profile', 10, 1);
function yoast_add_linkedin_profile($contactmethods) {
    // Add Linkedin Profiles
    $contactmethods['linkedin_profile'] = 'Linkedin URL';
    return $contactmethods;
}
add_filter('user_contactmethods', 'yoast_add_linkedin_profile', 10, 1);
function yoast_add_redirect_profile($contactmethods) {
    // Add Linkedin Profiles
    $contactmethods['redirect_profile'] = 'Redirect to this Page';
    return $contactmethods;
}
add_filter('user_contactmethods', 'yoast_add_redirect_profile', 11, 1);
function CW_Context_Menu_shortcode() {
    global $id;
    if (!is_page()) {
        return;
    }
    $current = get_post($id);
    $return = '';
    // Fix: Store result in variable before passing to end()
    $ancestors_array = get_post_ancestors($current);
    $top_level = ($current->post_parent && !empty($ancestors_array)) ? end($ancestors_array) : $current->ID;
    $return.= '<div class="context-menu-container">';
    $return.= '<a href="' . get_permalink($top_level) . '" title="' . get_the_title($top_level) . '"><h4 class="menu-title">' . get_the_title($top_level) . '</h4></a>';
    $return.= '<ul class="contextmenu">';
    if (!$current->post_parent) {
        $children = wp_list_pages("title_li=&sort_column=menu_order&child_of=" . $current->ID . "&echo=0");
    } else {
        // Fix: Use get_post_ancestors() instead of $current->ancestors
        $current_ancestors = get_post_ancestors($current);
        if (!empty($current_ancestors)) {
            $ancestors = end($current_ancestors);
            $children = wp_list_pages("title_li=&sort_column=menu_order&child_of=" . $ancestors . "&echo=0");
        }
    }
    if ($children) {
        $return.= $children;
    }
    $return.= '</ul>';
    $return.= '</div>';
    return $return;
}
add_shortcode('CW_Context_Menu', 'CW_Context_Menu_shortcode');







/* ======================================== */
/* Add current slug to body_class
/* Include post and page slugs in
/* body_classes
 * Added by: Jeff
/* ======================================== */
function add_slug_body_class($classes) {
    global $post;
    if (isset($post)) {
        $classes[] = $post->post_type . '-' . $post->post_name;
    }
    return $classes;
}
add_filter('body_class', 'add_slug_body_class');


/* ======================================== */
/* Change page title (browser tab title)
/* for 404 instances in Property CPT
 * Added by: Jeff
/* ======================================== */
function custom_404_property() {
    global $post;

    if( is_singular() ) {
        if( 'property' == $post->post_type and is_404() ) {
            $title = 'This property is being updated - Elevation Real Estate Agents';
            return $title;
        }
    }
}

add_action( 'pre_get_document_title', 'custom_404_property' );


/* ======================================== */
/* Detect Role ID from current URL and then
/* redirect to corresponding property
 * Updated by: Code Copilot
/* ======================================== */
// FIX for redirect function - add better URL validation
function redirect_property_by_roleId() {
    // FIX: Better URL handling
    if (!isset($_SERVER['REQUEST_URI']) || !isset($_SERVER['HTTP_HOST'])) {
        return;
    }
    
    $base_url = is_ssl() ? 'https' : 'http';
    $base_url .= '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $current_url = rtrim($base_url, '/');

    // Check for roleID in the path or as a query parameter
    $property_roleId = null;

    if (preg_match("/\/(\d+)$/", $current_url, $recordMatch)) {
        // Extract roleID from URL path
        $property_roleId = $recordMatch[1];
    } elseif (isset($_GET['pid']) && is_numeric($_GET['pid'])) {
        // Extract roleID from query parameter
        $property_roleId = intval($_GET['pid']);
    }

    if ($property_roleId) {
        // Prepare query to match the property with the roleID
        $args = array(
            'post_type'      => 'property',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => '_reference_number',
                    'value'   => $property_roleId,
                    'compare' => '=',
                ),
            ),
        );

        $dbResult = new WP_Query($args);
        if ($dbResult->have_posts()) {
            // FIX: Add proper exit and cleanup
            wp_redirect(get_permalink($dbResult->posts[0]->ID), 301);
            wp_reset_postdata();
            exit();
        }
        wp_reset_postdata();
    }
}
add_action('template_redirect', 'redirect_property_by_roleId');


// Add Shortcode that prints current URL parameter (query string) value
function url_parameter_shortcode( $atts ) {
	// Attributes
	$atts = shortcode_atts(
		array(
			'parameter_name' => '',
		),
		$atts
	);
    return $_GET[$atts['parameter_name']];
}
add_shortcode( 'url_parameter', 'url_parameter_shortcode' );

// Scripts and styles for the updated property image galleries
function property_gallery_enqueue_styles_scripts(){
    if ( is_singular( 'property' ) or is_singular( 'property-development' ) ) {
        wp_enqueue_script( 'photoswipe-js', 'https://cdnjs.cloudflare.com/ajax/libs/photoswipe/4.1.0/photoswipe.min.js', array(), '4.1.0', true );
        wp_enqueue_script( 'photoswipe-ui', 'https://cdnjs.cloudflare.com/ajax/libs/photoswipe/4.1.0/photoswipe-ui-default.min.js', array(), '4.1.0', true );
        wp_enqueue_style( 'photoswipe-css', 'https://cdnjs.cloudflare.com/ajax/libs/photoswipe/4.1.0/photoswipe.css' );
        wp_enqueue_style( 'photoswipe-skin', 'https://cdnjs.cloudflare.com/ajax/libs/photoswipe/4.1.0/default-skin/default-skin.css' );
    }
}
add_action( 'wp_enqueue_scripts', 'property_gallery_enqueue_styles_scripts' );

// Register a new image size (1280x1024, 640x512) for use with single property images
add_image_size('Property Large', 1280, 1024, true);
add_image_size('Property Medium', 640, 512, true);

/* Disable history notes comments */
add_filter( 'propertyhive_add_property_availability_change_note', 'disable_history_logging' );
add_filter( 'propertyhive_add_property_price_change_note', 'disable_history_logging' );
add_filter( 'propertyhive_add_property_on_market_change_note', 'disable_history_logging' );
function disable_history_logging( $disabled )
{
    return false;
}

/* ======================================== */
/* Shortcode that displays a grid of
/* properties, with ability to filter
/* through attributes
 * Added by: Jeff
/* ======================================== */
// function property_grid_shortcode( $atts ) {

// 	// Attributes
// 	$atts = shortcode_atts(
// 		array(
//         'property_type'         => '',
// 			'availability'      => '',
//             'postcode'          => '',
//             'town_city'         => '',
//             'radius_miles'      => 3,
// 			'new_build'         => false,
// 			'shared_ownership'  => false,
// 			'sold'              => false,
//             'numposts'          => 
// 		),
// 		$atts
// 	);

// }
// add_shortcode( 'property_grid', 'property_grid_shortcode' );

/* ======================================== */
/* Custom block for Property Grid via ACF
 * Added by: Jeff
/* ======================================== */
function register_property_grid_block() {
    register_block_type( __DIR__ . '/blocks/property_grid' );
    register_block_type( __DIR__ . '/blocks/developments_grid' );
    register_block_type( __DIR__ . '/blocks/locrating_module' );
    register_block_type( __DIR__ . '/blocks/team_grid' );
}
add_action( 'init', 'register_property_grid_block' );

add_filter( 'block_categories_all' , function( $categories ) {
	$categories[] = array(
		'slug'  => 'conversationware-blocks',
		'title' => 'Conversationware'
	);
	return $categories;
} );

function ww_load_dashicons(){
    wp_enqueue_style('dashicons');
}
add_action('wp_enqueue_scripts', 'ww_load_dashicons', 999);

/* ======================================== */
/* Add Devt Branch and Status to body_class
 * Added by: Jeff
/* ======================================== */
function add_development_meta_to_single( $classes ) {
    if ( has_term('completed', 'development_status') ) {
        $classes[] = 'completed';
    }
    return $classes;
}
add_filter( 'body_class', 'add_development_meta_to_single' );

/* ======================================== */
/* Property Query Troubleshooting
 * Added by: Jeff
/* ======================================== */
function property_query_shortcode( $atts ) {

    $args = array(
        'post_type'      => array( 'property' ),
        'posts_per_page' => -1,
        'meta_query'     => array(
            'relation'   => 'AND',
            array(
                'key'     => '_department',
                'value'   => 'residential-sales',
                'compare' => '=',
            ),
            array(
                'key'     => '_on_market',
                'value'   => '',
                'compare' => '=',
            ),
        ),
        'tax_query'        => array(
            array(
                'taxonomy'  => 'availability', 
                'field'     => 'slug', 
                'terms'     => 'for-sale',
            )
        ),
    );

    $query = new WP_Query( $args );
    $return = '';

    if ( $query->have_posts() ) {
        $return .= '<h3>FOUND PROPERTIES: ' . $query->found_posts . '</h3>';
        $return .= '<pre>';
        $return .= print_r( $args, true );
        $return .= '</pre>';
        while ( $query->have_posts() ) {
            $query->the_post();
            global $property; 

            $return .= get_the_title() . ' - ';
            $return .= $property->get_formatted_full_address( $separator = ', ' ); 
            $return .= '<br />';
            // $return .= '<pre>';
            // $return .= print_r (get_the_terms( get_the_ID(), 'availability' ), true);
            // $return .= '</pre>';
            // $return .= '<hr />';
        }
    }

    wp_reset_postdata();

    return $return;

}
add_shortcode( 'property_query', 'property_query_shortcode' );


/* ======================================== */
/* Property Hive - remove "For Sale"
 * as they're removed from the market
 * Added by: Jeff
 * With guidance from Property Hive
 * https://gist.github.com/propertyhive/b8a60e6cf29c5d88f0bcb5a39ee419d0
/* ======================================== */
add_action( "propertyhive_property_removed_street_json", 'remove_availability' ); // Updated from dezrez_json for Street CRM migration (Feb 2026)
function remove_availability( $post_id )
{
    wp_delete_object_term_relationships( $post_id, 'availability' );
}


/* ======================================== */
/* Virtual Tour Video Handler Helper Functions
 * 
 * Usage:
 * embedVideos($urls);
/* ======================================== */
// Include shared video helper functions
require_once get_stylesheet_directory() . '/video-handler/video-helper-functions.php';

// Include video embed functions
require_once get_stylesheet_directory() . '/video-handler/video-embed.php';

// Include video broken URL checker functions
require_once get_stylesheet_directory() . '/video-handler/video-check-broken.php';
/* ======================================== */

function GetAllFields($pro_num){
    $tmpArr = get_field_objects($pro_num);
    $fillArray = array();
    foreach( $tmpArr as $tmpFieldObject ) {
        $fillArray = GetAllFieldsCycle($fillArray, $tmpFieldObject["name"], $tmpFieldObject["value"]);
    }
    return $fillArray;
}

function GetAllFieldsCycle($fillArray, $name, $value) {
    if (is_array($value)) {
        foreach( $value as $key => $value ) {
            $fillArray = GetAllFieldsCycle($fillArray, $key, $value);
        }
    } else {
        array_push($fillArray, [$name, $value]);
    }
    return $fillArray;
}

/* Exclude availabilities from search results */
add_action( 'propertyhive_property_query', 'include_sold_stc_properties' );
function include_sold_stc_properties( $q ){

    global $post;

    if ( !is_admin() && is_post_type_archive( 'property' ) )
    {
	        $tax_query = $q->get( 'tax_query' );

	        $tax_query[] = array(
	        	'taxonomy' => 'availability',
			'field'    => 'name',
			'terms'    => array( 'Offer Accepted', 'Sold STC' ),
			'operator' => 'NOT IN',
	        );

	        $q->set( 'tax_query', $tax_query );
    }
}
/* ------------------------------------------ */


/* FAQ Custom Block */
function register_acf_cw_faqs_block() {
    // Ensure ACF is active
    if (function_exists('acf_register_block_type')) {
        acf_register_block_type([
            'name'              => 'cw-faqs',
            'title'             => __('CW FAQs'),
            'description'       => __('A block to display FAQs with search and filtering.'),
            'render_template'   => get_stylesheet_directory() . '/blocks/faq_block/render.php',
            'category'          => 'widgets', // You can customize this category
            'icon'              => 'editor-help',
            'keywords'          => ['faq', 'question', 'answer'],
            'enqueue_assets'    => function () {
                // Load custom JS and CSS for this block
                wp_enqueue_script(
                    'cw-faq-block-frontend',
                    get_stylesheet_directory_uri() . '/blocks/faq_block/frontend.js',
                    ['jquery'],
                    '1.0.0',
                    true
                );
                wp_enqueue_style(
                    'cw-faq-block-style',
                    get_stylesheet_directory_uri() . '/blocks/faq_block/style.css',
                    [],
                    '1.0.0'
                );
            },
        ]);
    }
}
add_action('acf/init', 'register_acf_cw_faqs_block');


// PUSHING THE ENVELOPE - bring the import frequency up to max. 5 minutes
// PERFORMANCE FIX: Reduce import frequency to prevent overload
add_filter( 'propertyhive_property_import_frequencies', 'add_custom_import_frequency' );
function add_custom_import_frequency($frequencies) {
    // FIX: Change from every 5 minutes to every 15 minutes to reduce server load
    $new_frequencies = array('fifteen_minutes' => 'Every Fifteen Minutes');
    return array_merge( $new_frequencies, $frequencies );
}

add_filter( 'propertyhive_property_import_next_due', 'get_next_due', 10, 3 );
function get_next_due( $got_next_due, $next_due, $last_start_date )
{
	if ( ( ($next_due - $last_start_date) / 60 / 60 ) >= 0.083333 ) // 0.083333 = a twelth (i.e. five minutes) of an hour
	{
		$got_next_due = $next_due;
	}
	return $got_next_due;
}

// Add custom cron schedule for 15 minutes
add_filter('cron_schedules', 'add_fifteen_minute_cron_schedule');
function add_fifteen_minute_cron_schedule($schedules) {
    $schedules['fifteen_minutes'] = array(
        'interval' => 900, // 15 minutes in seconds
        'display' => 'Every Fifteen Minutes'
    );
    return $schedules;
}

add_filter( 'propertyhive_property_ok_to_run_import', 'check_ok_to_run_import', 10, 2 );
function check_ok_to_run_import( $ok_to_run_import, $diff_secs )
{
	if (($diff_secs / 60 / 60) < 0.083333) // 0.083333 = a twelth (i.e. five minutes) of an hour
	{
		$ok_to_run_import = false;
	}
	return $ok_to_run_import;
}

function hide_multiple_plugin_updates( $value ) {
    $plugins_to_hide = array(
        // 'propertyhive-valpal/propertyhive-valpal.php',
        // 'plugin-folder-2/plugin-file-2.php',
    );
    
    foreach ( $plugins_to_hide as $plugin ) {
        if ( isset( $value->response[$plugin] ) ) {
            unset( $value->response[$plugin] );
        }
    }
    return $value;
}
add_filter( 'site_transient_update_plugins', 'hide_multiple_plugin_updates' );


/**
 * ValPal Plugin - Custom JavaScript Migration
 * Dequeues the plugin's original JavaScript and loads customized version from child theme
 * This allows safe plugin updates without losing customizations
 */
function kadence_child_custom_valpal_script() {
    // Only load on pages where ValPal form might appear
    global $post;
    
    // Check if we're on a page/post and if it might have the valpal shortcode
    if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'valpal' ) || is_page('instant-valuation') ) ) {
        
        // Dequeue and deregister the plugin's original script
        wp_dequeue_script('ph-valpal');
        wp_deregister_script('ph-valpal');
        
        // Enqueue our custom version from child theme
        wp_enqueue_script(
            'ph-valpal-custom',
            get_stylesheet_directory_uri() . '/js/ph-valpal-custom.js',
            array('jquery'), // Dependencies
            filemtime( get_stylesheet_directory() . '/js/ph-valpal-custom.js' ), // Version for cache busting
            true // Load in footer
        );
        
        // Re-localize the script with the same data the plugin provides
        // This ensures our custom script has access to all the plugin settings
        $current_settings = get_option( 'propertyhive_valpal', array() );
        
        $translation_array = apply_filters( 'propertyhive_valpal_translation_array', array(
            'ajax_url'        => admin_url( 'admin-ajax.php', 'relative' ),
            'address_lookup'  => ( (isset($current_settings['address_lookup']) && $current_settings['address_lookup'] == '1') ? '1' : '' ),
            'sales_min_amount_percentage_modifier' => isset($current_settings['sales_min_amount_percentage_modifier']) ? $current_settings['sales_min_amount_percentage_modifier'] : 0,
            'sales_actual_amount_percentage_modifier' => isset($current_settings['sales_actual_amount_percentage_modifier']) ? $current_settings['sales_actual_amount_percentage_modifier'] : 0,
            'sales_max_amount_percentage_modifier' => isset($current_settings['sales_max_amount_percentage_modifier']) ? $current_settings['sales_max_amount_percentage_modifier'] : 0,
            'lettings_min_amount_percentage_modifier' => isset($current_settings['lettings_min_amount_percentage_modifier']) ? $current_settings['lettings_min_amount_percentage_modifier'] : 0,
            'lettings_actual_amount_percentage_modifier' => isset($current_settings['lettings_actual_amount_percentage_modifier']) ? $current_settings['lettings_actual_amount_percentage_modifier'] : 0,
            'lettings_max_amount_percentage_modifier' => isset($current_settings['lettings_max_amount_percentage_modifier']) ? $current_settings['lettings_max_amount_percentage_modifier'] : 0,
            'show_map_in_results' => apply_filters( 'propertyhive_valpal_show_map_in_results', true ),
            'show_street_view_in_results' => apply_filters( 'propertyhive_valpal_show_street_view_in_results', true ),
            'maps_provider' => isset($current_settings['maps_provider']) ? $current_settings['maps_provider'] : 'google',
        ) );
        
        wp_localize_script( 'ph-valpal-custom', 'ph_valpal', $translation_array );
    }
}
add_action( 'wp_enqueue_scripts', 'kadence_child_custom_valpal_script', 999 );

/**
 * ValPal Plugin - Custom Email Notification
 * Sends instant valuation notifications to specific email addresses
 * Uses WordPress hook to intercept successful valuations
 */
function kadence_child_valpal_email_notification( $post_data, $response_data ) {
    // Email recipients - customize per site if needed
    $to = 'Peter@elevationlettings.com, grant@elevationestateagents.com, mail@elevationestateagents.com, reports@conversationware.co.uk';
    // $to = 'jeff@conversationware.co.uk';

    // Email subject
    $subject = 'New Instant Online Valuation';
    
    // Build email body
    $body = "A new instant online valuation was just completed. Please find details below:\n\n";
    
    // Customer Details
    $body .= "=== CUSTOMER DETAILS ===\n";
    $body .= "Name: " . ( isset($post_data['name']) ? $post_data['name'] : 'N/A' ) . "\n";
    $body .= "Email Address: " . ( isset($post_data['email']) ? $post_data['email'] : 'N/A' ) . "\n";
    $body .= "Telephone Number: " . ( isset($post_data['telephone']) ? $post_data['telephone'] : 'N/A' ) . "\n";
    
    if ( isset($post_data['comments']) && !empty($post_data['comments']) ) {
        $body .= "Comments: " . $post_data['comments'] . "\n";
    }
    
    $body .= "\n=== PROPERTY DETAILS ===\n";
    
    // Property Address
    if ( isset($response_data['buildname']) && !empty($response_data['buildname']) ) {
        $body .= "Building Name: " . $response_data['buildname'] . "\n";
    }
    if ( isset($response_data['subBname']) && !empty($response_data['subBname']) ) {
        $body .= "Sub Building Name: " . $response_data['subBname'] . "\n";
    }
    if ( isset($response_data['number']) && !empty($response_data['number']) ) {
        $body .= "Number: " . $response_data['number'] . "\n";
    }
    if ( isset($response_data['street']) && !empty($response_data['street']) ) {
        $body .= "Street: " . $response_data['street'] . "\n";
    }
    if ( isset($response_data['depstreet']) && !empty($response_data['depstreet']) ) {
        $body .= "Dependent Street: " . $response_data['depstreet'] . "\n";
    }
    if ( isset($response_data['postcode']) && !empty($response_data['postcode']) ) {
        $body .= "Postcode: " . $response_data['postcode'] . "\n";
    }
    
    // Property Characteristics
    $body .= "\n";
    if ( isset($response_data['propertytype']) && !empty($response_data['propertytype']) ) {
        $body .= "Property Type: " . $response_data['propertytype'] . "\n";
    }
    if ( isset($response_data['tenure']) && !empty($response_data['tenure']) ) {
        $body .= "Tenure: " . $response_data['tenure'] . "\n";
    }
    if ( isset($response_data['bedrooms']) && !empty($response_data['bedrooms']) ) {
        $body .= "Bedrooms: " . $response_data['bedrooms'] . "\n";
    }
    
    // Valuation Results
    $body .= "\n=== VALUATION RESULTS ===\n";
    $body .= "Valuation Type: " . ( isset($post_data['type']) ? ucfirst($post_data['type']) : 'N/A' ) . "\n";
    $body .= "Min Valuation: " . ( isset($response_data['minvaluation']) ? $response_data['minvaluation'] : 'N/A' ) . "\n";
    $body .= "Valuation: " . ( isset($response_data['valuation']) ? $response_data['valuation'] : 'N/A' ) . "\n";
    $body .= "Max Valuation: " . ( isset($response_data['maxvaluation']) ? $response_data['maxvaluation'] : 'N/A' ) . "\n";
    
    // Timestamp
    $body .= "\n=== SUBMISSION INFO ===\n";
    $body .= "Date/Time: " . date('d/m/Y H:i:s') . "\n";
    $body .= "Website: " . get_bloginfo('name') . " (" . home_url() . ")\n";
    
    // Send the email
    wp_mail( $to, $subject, $body );
}
add_action( 'propertyhive_valpal_send_success', 'kadence_child_valpal_email_notification', 10, 2 );




/* ======================================== */
/* Property Grid Block - Cache Invalidation
 * Clear property grid caches when properties are updated
 * Added by: Claude Code
/* ======================================== */

/**
 * Clear all property_grid transient caches when a property is saved
 *
 * @param int $post_id Property post ID
 */
function property_grid_clear_cache($post_id) {
    // Only clear cache for property post type
    if (get_post_type($post_id) !== 'property') {
        return;
    }

    // Clear all property_grid transients from database
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_property_grid_%'
        )
    );
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_timeout_property_grid_%'
        )
    );
}
add_action('save_post_property', 'property_grid_clear_cache', 10, 1);

/**
 * AJAX handler to check if a property is off-market
 * Used to apply grayscale styling to off-market property thumbnails
 */
function check_property_market_status_ajax() {
    $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;

    if (!$property_id) {
        wp_send_json_error(array('message' => 'Invalid property ID'));
        return;
    }

    // Get the _on_market meta value
    $on_market = get_post_meta($property_id, '_on_market', true);

    // Property is off-market if _on_market is not 'yes'
    $is_off_market = ($on_market !== 'yes');

    wp_send_json_success(array('off_market' => $is_off_market));
}
add_action('wp_ajax_check_property_market_status', 'check_property_market_status_ajax');
add_action('wp_ajax_nopriv_check_property_market_status', 'check_property_market_status_ajax');

// ============================================
// MARKETING FLAG PRIORITY DISPLAY FEATURE
// ============================================
// Feature toggle: Set to false to disable this feature
if (!defined('CW_ENABLE_MARKETING_FLAG_PRIORITY')) {
    define('CW_ENABLE_MARKETING_FLAG_PRIORITY', true);
}

/**
 * Get priority marketing flags that should display as "Sold"
 *
 * @return array Array of term names that trigger "Sold" display
 */
function cw_get_priority_marketing_flags() {
    return array(
        'Sold STC',      // Term ID 7
        'Sold',          // Term ID 8
        'Offer Accepted', // Term IDs 75 and 78
    );
}

/**
 * Check if property has priority marketing flag
 *
 * @param int $property_id Property post ID
 * @return bool True if property has priority flag
 */
function cw_has_priority_marketing_flag($property_id) {
    if (!CW_ENABLE_MARKETING_FLAG_PRIORITY) {
        return false;
    }

    $flag_terms = wp_get_post_terms($property_id, 'marketing_flag', array('fields' => 'names'));

    if (is_wp_error($flag_terms) || empty($flag_terms)) {
        return false;
    }

    $priority_flags = cw_get_priority_marketing_flags();

    foreach ($flag_terms as $flag_name) {
        if (in_array($flag_name, $priority_flags, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Filter marketing flag display to show only "Sold" for priority flags
 *
 * This filter hooks into PropertyHive's magic __get method via the
 * 'propertyhive_get_detail' filter that fires for all property details.
 *
 * @param mixed $value The value being retrieved
 * @param string $key The property key being accessed
 * @param PH_Property $property The property object
 * @return mixed Modified value if key is 'marketing_flag', otherwise original value
 */
add_filter('propertyhive_get_detail', 'cw_filter_marketing_flag_display', 10, 3);
function cw_filter_marketing_flag_display($value, $key, $property) {
    // Only process marketing_flag key
    if ($key !== 'marketing_flag') {
        return $value;
    }

    // Feature toggle check
    if (!CW_ENABLE_MARKETING_FLAG_PRIORITY) {
        return $value;
    }

    // Don't filter in admin area
    if (is_admin()) {
        return $value;
    }

    // If no flags, return empty
    if (empty($value)) {
        return $value;
    }

    // Check if property has priority flag
    if (cw_has_priority_marketing_flag($property->id)) {
        return 'Sold';
    }

    // No priority flags found - return original string
    return $value;
}

/**
 * Get display-ready marketing flag text for a property
 *
 * Use this in templates to get the correct flag text with priority logic applied.
 *
 * @param int|WP_Post|PH_Property $property Property ID, post object, or PH_Property object
 * @return string Marketing flag text to display
 */
function cw_get_property_marketing_flag_display($property) {
    // Feature toggle check
    if (!CW_ENABLE_MARKETING_FLAG_PRIORITY) {
        // Feature disabled - get original flag
        if (is_numeric($property)) {
            $prop = new PH_Property($property);
        } elseif (is_a($property, 'WP_Post')) {
            $prop = new PH_Property($property->ID);
        } elseif (is_a($property, 'PH_Property')) {
            $prop = $property;
        } else {
            return '';
        }
        return $prop->marketing_flag;
    }

    // Get property ID
    if (is_numeric($property)) {
        $property_id = $property;
    } elseif (is_a($property, 'WP_Post')) {
        $property_id = $property->ID;
    } elseif (is_a($property, 'PH_Property')) {
        $property_id = $property->id;
    } else {
        return '';
    }

    // Check for priority flags
    if (cw_has_priority_marketing_flag($property_id)) {
        return 'Sold';
    }

    // Get all flags
    $flag_terms = wp_get_post_terms($property_id, 'marketing_flag', array('fields' => 'names'));

    if (is_wp_error($flag_terms) || empty($flag_terms)) {
        return '';
    }

    return implode(', ', $flag_terms);
}