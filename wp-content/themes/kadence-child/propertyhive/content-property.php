<?php
/**
 * The template for displaying a single property within search results loops.
 *
 * Override this template by copying it to yourtheme/propertyhive/content-property.php
 *
 * @author 		PropertyHive
 * @package 	PropertyHive/Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $property, $propertyhive_loop;

// Store loop count we're currently on
if ( empty( $propertyhive_loop['loop'] ) )
	$propertyhive_loop['loop'] = 0;

// Store column count for displaying the grid
if ( empty( $propertyhive_loop['columns'] ) )
	$propertyhive_loop['columns'] = apply_filters( 'loop_search_results_columns', 1 );

// Ensure visibility
if ( ! $property )
	return;

// Increase loop count
++$propertyhive_loop['loop'];

// Extra post classes
$classes = array('clear');
if ( 0 == ( $propertyhive_loop['loop'] - 1 ) % $propertyhive_loop['columns'] || 1 == $propertyhive_loop['columns'] )
	$classes[] = 'first';
if ( 0 == $propertyhive_loop['loop'] % $propertyhive_loop['columns'] )
	$classes[] = 'last';
if ( $property->featured == 'yes' )
    $classes[] = 'featured';
?>
<li <?php post_class( $classes ); ?>>

	<?php do_action( 'propertyhive_before_search_results_loop_item' ); ?>

    <div class="thumbnail">
    	<a href="<?php the_permalink(); ?>">
    		<?php
    			/**
    			 * propertyhive_before_search_results_loop_item_title hook
    			 *
    			 * @hooked propertyhive_template_loop_property_thumbnail - 10
    			 */
    			do_action( 'propertyhive_before_search_results_loop_item_title' );
    		?>
        </a>
    </div>
    
    <div class="details">
    
		<?php
			$property_address = array();
			$property_address['street'] = $property->address_street;
			$property_address['locality'] = $property->address_two;
			$property_address['town'] = $property->address_three;

			if( !empty($property_address['locality']) ) {
				$address_title_string = $property_address['street'] . ', ' . $property_address['locality'];
			} else {
				$address_title_string = $property_address['street'] . ', ' . $property_address['town'];
			}
		?>
    	<h3><a href="<?php the_permalink(); ?>"><?php echo $address_title_string; ?></a></h3>
        <span class="cw-property-tenure"><?php echo $property->tenure; ?></span>
		
    	<?php
    		/**
    		 * propertyhive_after_search_results_loop_item_title hook
    		 *
             * @hooked propertyhive_template_loop_floor_area - 5 (commercial only)
    		 * @hooked propertyhive_template_loop_price - 10
             * @hooked propertyhive_template_loop_summary - 20
             * @hooked propertyhive_template_loop_actions - 30
    		 */
    		do_action( 'propertyhive_after_search_results_loop_item_title' );

            $num_photos = count($property->get_gallery_attachment_ids());
            if( $num_photos == 1 ) {
                $num_photos_label = "Photo";
            } elseif( $num_photos == 0 or $num_photos == null ) {
                $num_photos_label = "No Photos";
            } else {
                $num_photos_label = "Photos";
            }
    	?>
		
		<div class="photos-count-container">
			<div class="room room-photos">
				<span class="room-count"><?php echo $num_photos; ?></span> 
				<span class="room-label"><?php echo $num_photos_label; ?></span>
			</div>
			
			<?php if( count($property->floorplans) > 0 ) : ?>
				<div class="room room-photos room-floorplans">
					<?php
						$num_floorplans = count($property->floorplans);
						if( $num_floorplans == 1 ) {
							$num_floorplans_label = "Floorplan";
						} elseif( $num_floorplans == 0 or $num_floorplans == null ) {
							$num_floorplans_label = "No Floorplans";
						} else {
							$num_floorplans_label = "Floorplans";
						}
					?>
					<span class="room-count"><?php echo count($property->floorplans); ?></span> 
					<span class="room-label"><?php echo $num_floorplans_label; ?></span>
				</div>
			<?php endif; ?>
		</div>
		
    </div>
    
	<?php do_action( 'propertyhive_after_search_results_loop_item' ); ?>
	<a href="<?php echo get_the_permalink(); ?>" class="button">View Property</a>
</li>