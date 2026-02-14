<?php
/**
 * Property Search Results Loop End
 *
 * @author      PropertyHive
 * @package     PropertyHive/Templates
 * @version     1.0.0
 */
?>

<?php

if ( isset($_GET['view']) && $_GET['view'] != '' ) {
    if( $_GET['view'] == 'map' ) {
        
    } else {
        echo '</ul>';
    }
} else {
    echo '</ul>';
}

?>