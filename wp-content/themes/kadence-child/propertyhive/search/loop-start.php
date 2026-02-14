<?php
/**
 * Property Search Results Loop Start
 *
 * @author 		PropertyHive
 * @package 	PropertyHive/Templates
 * @version     1.0.0
 */
?>
<?php 

if ( isset($_GET['view']) && $_GET['view'] != '' ) {
    if( $_GET['view'] == 'map' ) {
        
    } else {
        echo '<ul class="properties clear view-' . $_GET['view'] . '">';
    }
} else {
    echo '<ul class="properties clear">';
}