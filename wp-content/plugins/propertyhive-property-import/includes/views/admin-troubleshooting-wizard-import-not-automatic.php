<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-import-wizard-import-not-automatic"<?php if ( isset($_POST['issue']) && esc_attr(sanitize_text_field($_POST['issue'])) == 'import-not-automatic' ) { }else{ echo ' style="display:none"'; } ?>>

	<form method="post">

		<input type="hidden" name="primary_issue" value="<?php echo esc_attr(sanitize_text_field($_POST['issue'])); ?>">
		<input type="hidden" name="import_id" value="<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>">
		<input type="hidden" name="issue" value="other">

		<p style="margin-bottom:18px; font-size:1.1em"><strong>Imports not running automatically</strong></p>

		<?php
			$errors = '';
			$successes = '';
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) 
			{
			    $errors .= '<div class="notice notice-error inline"><p>WordPress cron is disabled via <code>DISABLE_WP_CRON</code>. Automatic imports will not run unless a server-level cron job is set up.</p></div>';
			}
			else
			{
				$successes .= '<div class="notice notice-success inline"><p><code>DISABLE_WP_CRON</code> is not present in wp-config.php.</p></div>';
			}

			$last_cron = get_option( 'cron' );
			if ( ! $last_cron || empty( $last_cron ) ) 
			{
			    $errors .= '<div class="notice notice-error inline"><p>It looks like WordPress hasn’t scheduled any cron events. This could indicate a problem with the WP Cron system. We recommend speaking to your hosting company.</p></div>';
			}
			else
			{
				$successes .= '<div class="notice notice-success inline"><p>WordPress has scheduled cron events.</p></div>';
			}

			if ( !wp_next_scheduled( 'phpropertyimportcronhook' ) ) 
			{
			    $errors .= '<div class="notice notice-error inline"><p>The property import cron job hook <code>phpropertyimportcronhook</code> is not currently scheduled. Please try <a href="' . esc_url( admin_url('admin.php?page=ph-settings&tab=features') ) . '">deactivating and reactivating the Property Import add on</a> feature to see if that reinstates the task.</p></div>';
			}
			else
			{
				$successes .= '<div class="notice notice-success inline"><p>The property import cron job hook <code>phpropertyimportcronhook</code> is scheduled.</p></div>';
			}

			$response = wp_remote_post( site_url( '/wp-cron.php' ), array(
			    'timeout' => 5,
			    'blocking' => true,
			    'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			) );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) 
			{
			    $errors .= '<div class="notice notice-error inline"><p>We tried triggering <code>wp-cron.php</code> directly, but it may be blocked. Check with your host or security plugins to make sure it\'s accessible.</p></div>';
			}
			else
			{
				$successes .= '<div class="notice notice-success inline"><p>We were able to trigger <code>wp-cron.php</code> directly.</p></div>';
			}

			echo $successes;

			if ( !empty($errors) )
			{
				echo '<p>We found the following issues that might help point to why imports aren\'t running automatically:</p>';

				echo $errors;
			}
		?>

		<p style="margin-bottom:18px; font-size:1.1em"><strong>Learn more</strong></p>

		<p>View our documentation for more information regarding how automated tasks work and how to debug them further:</p>

		<a href="https://docs.wp-property-hive.com/article/295-requirements#heading-0" target="_blank" class="button button-primary button-hero">View Troubleshooting Documentation <span class="dashicons dashicons-external" style="vertical-align:middle; margin-top:-2px;"></span></a>
		

		<div class="buttons">
			<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&tab=troubleshooting'); ?>">Back</a>
			<input type="submit" value="This didn't solve my issue" class="button button-primary">
		</div>

	</form>

</div>