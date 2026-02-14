<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Media Queue Functions
 */
class PH_Property_Import_Media_Queue {

	public function __construct() {

        add_action( 'propertyhive_update_option', array( $this, 'remove_queued_media_when_storing_as_urls' ), 10 );

        add_action( 'delete_post', array( $this, 'remove_queued_media' ), 10 );

	}

    public function remove_queued_media_when_storing_as_urls( $option )
    {
        global $wpdb;

        $media_option_names = array(
            'propertyhive_images_stored_as' => 'photos',
            'propertyhive_floorplans_stored_as' => 'floorplans',
            'propertyhive_brochures_stored_as' => 'brochures',
            'propertyhive_epcs_stored_as' => 'epcs',
        );

        // One of the media storage options is being saved as URLs, so remove any queued media of that type
        if ( in_array( $option['id'], array_keys($media_option_names)) && isset( $_POST[$option['id']] ) && $_POST[$option['id']] == 'urls' )
        {
            $wpdb->query( "
                DELETE FROM
                    " . $wpdb->prefix . "ph_propertyimport_media_queue
                WHERE
                    `media_type` = '" . $media_option_names[$option['id']] . "'
            " );
        }
    }

    public function remove_queued_media( $post_id )
    {
        global $wpdb;

        $wpdb->query( "
            DELETE FROM
                " . $wpdb->prefix . "ph_propertyimport_media_queue
            WHERE
                `property_id` = '" . (int)$post_id . "'
        " );
    }

}

new PH_Property_Import_Media_Queue();