<?php
/**
 * Locrating iFrame
 *
 * @author      PropertyHive
 * @package     PropertyHive/Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
?>

<iframe id="propertyhive_locrating_amenities_frame" width="800" height="600" noborder="true" frameborder="0" data-lat="<?php echo $property_coords['lat'];?>" data-lng="<?php echo $property_coords['lng'];?>"></iframe>