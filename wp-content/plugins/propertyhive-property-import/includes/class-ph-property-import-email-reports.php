<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Email Report Functions
 */
class PH_Property_Import_Email_Reports {

	public function __construct() {

        add_action( 'propertyhive_property_import_cron_end', array( $this, 'send_email_report' ), 10, 2 );

	}

	public function send_email_report( $instance_id, $import_id )
	{
		$email_reports = get_option( 'propertyhive_property_import_email_reports', '' );

        if ( $email_reports === 'yes' )
        {
        	$email_reports_to = sanitize_email(get_option( 'propertyhive_property_import_email_reports_to', '' ));

            if ( !empty($email_reports_to) )
            {
                global $wpdb;

                $to = $email_reports_to;
                $subject = get_bloginfo('name') . ' Property Import Log';
                $body = "";

                $logs = $wpdb->get_results( 
                    "
                    SELECT *
                    FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
                    INNER JOIN 
                        " . $wpdb->prefix . "ph_propertyimport_instance_log_v3 ON  " . $wpdb->prefix . "ph_propertyimport_instance_v3.id = " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.instance_id
                    WHERE 
                        instance_id = '" . $instance_id . "'
                    ORDER BY " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.id ASC
                    "
                );

                if ( !empty($logs) )
                {
	                foreach ( $logs as $log ) 
	                {
	                    $body .= get_date_from_gmt( $log->log_date, "H:i:s jS F Y" ) . ' - ' . $log->entry;
	                    $body .= "\n";
	                }

	                wp_mail( $to, $subject, $body );
	            }
            }
        }
	}

}

new PH_Property_Import_Email_Reports();