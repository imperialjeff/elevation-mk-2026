<?php
/**
 * The Template for displaying property archives, also referred to as 'Search Results'
 *
 * Override this template by copying it to yourtheme/propertyhive/archive-property.php
 *
 * @author      PropertyHive
 * @package     PropertyHive/Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

get_header( 'propertyhive' ); global $wpdb; ?>

    <?php
        /**
         * propertyhive_before_main_content hook
         *
         * @hooked propertyhive_output_content_wrapper - 10 (outputs opening divs for the content)
         */
        do_action( 'propertyhive_before_main_content' );
    ?>

        <?php if ( apply_filters( 'propertyhive_show_page_title', true ) ) : ?>

            <h1 class="page-title"><?php propertyhive_page_title(); ?></h1>

        <?php endif; ?>
        
        <?php
            /**
             * propertyhive_before_search_results_loop hook
             * @hooked propertyhive_search_form - 10
             * @hooked propertyhive_result_count - 20
             * @hooked propertyhive_catalog_ordering - 30
             */
            do_action( 'propertyhive_before_search_results_loop' );
        ?>

        <?php 
            // Output results. Filter allows us to not display the results whilst maintaining the main query. True by default
            // Used primarily by the Map Search add on - https://wp-property-hive.com/addons/map-search/
            if ( apply_filters( 'propertyhive_show_results', true ) ) : 
        ?>

            <?php
                if ( isset($_GET['view']) && $_GET['view'] != '' ) {
                    if ( have_posts() ) :
                        propertyhive_property_loop_start();
                            while ( have_posts() ) : the_post();
                                
                                $center_coords = array();
                                $center_coords[] = array('lat' => $property->latitude,'lng'=>$property->longitude);

                            endwhile;
                        propertyhive_property_loop_end();
                    endif;

                    $center_coords_long_lat = get_center($center_coords);
                    $center_coords_lat = $center_coords_long_lat[0];
                    $center_coords_long = $center_coords_long_lat[1];
                }
            ?>
            <?php if ( have_posts() ) : ?>

                <?php if ( isset($_GET['view']) && $_GET['view'] != '' ) { ?>
                    <div class="cw-map-view-container">
                        <script type="text/javascript">
                            document.addEventListener("DOMContentLoaded", function() {
                                (function($) {
                                    loadLocratingPlugin({
                                        id: 'properties_map',
                                        <?php
                                            if( empty($_GET['department']) ) {
                                                echo 'lat: 52.120164,' . "\n";
                                                echo 'lng: -0.440282,' . "\n";
                                                echo 'zoom: 12' . ',' . "\n";
                                            } else {
                                                echo 'lat:' . $center_coords_lat . ',' . "\n";
                                                echo 'lng:' . $center_coords_long . ',' . "\n";
                                                echo 'zoom: 12' . ',' . "\n";
                                            }
                                        ?>
                                        icon: '.',
                                        type: 'transport',
                                        hidestationswhenzoomedout: true,
                                        onLoaded: function() {
                    <?php } ?>
                                            <?php propertyhive_property_loop_start(); ?>

                                                <?php while ( have_posts() ) : the_post(); ?>

                                                    <?php
                                                        $_photos = get_post_meta( $property->id, '_photos', true );
                                                        $_photo_url = wp_get_attachment_image_url( $_photos[0] );

                                                        if( get_post_meta( $property->id, '_department', true ) == 'residential-sales' ) {
                                                            $_price = $property->currency . number_format(get_post_meta( $property->id, '_price', true ));
                                                        } else {
                                                            $_price = $property->currency . number_format(get_post_meta( $property->id, '_rent', true )) . ' ' . $property->rent_frequency ;
                                                        }

                                                        if ( isset($_GET['view']) && $_GET['view'] != '' ) {
                                                            if( $_GET['view'] == 'map' ) { ?>
                                                                addLocratingMapMarker('properties_map',{
                                                                    id: 'marker_<?php echo $property->id ?>', 
                                                                    lat: '<?php echo $property->latitude ?>', 
                                                                    lng: '<?php echo $property->longitude ?>', 
                                                                    html: '<div style="width: 250px;"><table style="width:100%;text-align:left;border-spacing:0;border-collapse: collapse;font-family: \'Work Sans\', sans-serif;" cellspacing="0" cellpadding="0"><tbody><tr><td style="width: 30%;padding: 0px 5px 0px 0px;"><img src="<?php echo $_photo_url; ?>" style="width:100px;"></td><td style="width: 70%;padding: 5px 0 5px 5px;"><div style=""><div style="font-size:16px;font-weight: bold;line-height:15px;"><?php echo get_the_title( $property->id ); ?></div><span style="font-weight:bold;font-size:18px;color:#E50070;line-height:27px;"><?php echo $_price; ?></span></div></td></tr><tr style=";"><td colspan="2"><a style="font-weight: 500;background-color: #FFFFFF;color: #E50070;width: 100%;line-height: 30px;display: block;text-align: center;text-decoration: none;font-size: 16px;border-radius: 26px;border: 4px solid #E50070;" href="<?php echo get_permalink( $property->id ); ?>" target="_blank">View Details</a></td></tr></tbody></table></div>',
                                                                    icon: 'https://elevation-mk.website-build.info/wp-content/uploads/2023/03/map-marker-icon-default.png',
                                                                    clickedIcon: 'https://elevation-mk.website-build.info/wp-content/uploads/2023/03/map-marker-icon-active.png',
                                                                    iconHeight: 50,
                                                                    iconHeight: 35,
                                                                });
                                                            <?php } else {
                                                                ph_get_template_part( 'content', 'property' );
                                                            }
                                                        } else {
                                                            ph_get_template_part( 'content', 'property' );
                                                        }
                                                    ?>

                                                <?php endwhile; // end of the loop. ?>

                                            <?php propertyhive_property_loop_end(); ?>

                    <?php if ( isset($_GET['view']) && $_GET['view'] != '' ) { ?>
                                        }
                                    });
                                })(jQuery);
                            });
                        </script>
                    </div>
                    <div id="properties_map" style="width:100%; height:calc(100vh - 80px);"></div>
                <?php } ?>

            <?php else: ?>

                <?php ph_get_template( 'search/no-properties-found.php' ); ?>

            <?php endif; ?>

        <?php endif; ?>

        <?php
            /**
             * propertyhive_after_search_results_loop hook
             *
             * @hooked propertyhive_pagination - 10
             */
            do_action( 'propertyhive_after_search_results_loop' );
        ?>

    <?php
        /**
         * propertyhive_after_main_content hook
         *
         * @hooked propertyhive_output_content_wrapper_end - 10 (outputs closing divs for the content)
         */
        do_action( 'propertyhive_after_main_content' );
    ?>

<?php get_footer( 'propertyhive' ); ?>