<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

	<div class="ph-import-wizard-import-not-completing"<?php if ( isset($_POST['issue']) && esc_attr(sanitize_text_field($_POST['issue'])) == 'import-not-completing' ) { }else{ echo ' style="display:none"'; } ?>>

		<form method="post">

			<input type="hidden" name="primary_issue" value="<?php echo esc_attr(sanitize_text_field($_POST['issue'])); ?>">
			<input type="hidden" name="import_id" value="<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>">
			<input type="hidden" name="issue" value="other">

			<p style="margin-bottom:18px; font-size:1.1em"><strong>Imports not completing</strong></p>

			<?php
				$media_processing = get_option( 'propertyhive_property_import_media_processing', '' );

				$row = $wpdb->get_row( "
                    SELECT id, start_date, end_date
                    FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
                    WHERE 
                        import_id = '" . (int)$_POST['import_id'] . "'
                   	AND
                   		media = 0
                    ORDER BY start_date DESC 
                    LIMIT 1
                ", ARRAY_A);
                if ( null !== $row )
                {
                	if ( $row['end_date'] == '0000-00-00 00:00:00' )
                	{
                		// yep, didn't complete
                		$row1 = $wpdb->get_row( "
		                    SELECT log_date
		                    FROM " . $wpdb->prefix . "ph_propertyimport_instance_log_v3
		                    WHERE 
		                        instance_id = '" . $row['id'] . "'
		                    ORDER BY log_date DESC 
		                    LIMIT 1
		                ", ARRAY_A);

		                echo '<p>You\'re right. I can see that the import has started but didn\'t finish';
		                if ( null !== $row1 )
		                {
		                	$time_ran_in_seconds = strtotime($row1['log_date']) - strtotime($row['start_date']);
			                echo ' after running for approximately ' . number_format($time_ran_in_seconds) . ' seconds.';

			                $max_execution_time = ini_get('max_execution_time');
			                $showing_exec_warning = false;

			                if ($max_execution_time == 0 || $max_execution_time == -1) 
			                {
							    //echo 'Max execution time is not limited by PHP settings.';
							}
							else
							{
							    // Calculate the threshold (90% of max_execution_time)
							    $threshold = $max_execution_time * 0.8;

							    // Compare time_ran_in_seconds with max_execution_time
							    if ( ($time_ran_in_seconds >= $threshold && $time_ran_in_seconds <= $max_execution_time) || ($time_ran_in_seconds > $max_execution_time) ) 
							    {
							        echo '</p><p>This is close to, or exceeds, the PHP max_execution_time setting of ' . $max_execution_time . ' seconds you have in place so is likely timing out';
							        $showing_exec_warning = true;
							    }
							}

			                $row2 = $wpdb->get_row( "
			                    SELECT log_date, entry
			                    FROM " . $wpdb->prefix . "ph_propertyimport_instance_log_v3
			                    WHERE 
			                        instance_id = '" . $row['id'] . "'
			                    AND
			                    	severity <> 0
			                    ORDER BY log_date DESC
			                    LIMIT 1
			                ", ARRAY_A);
			                if ( null !== $row2 )
			                {
			                	echo '</p><p>I did ' . ( $showing_exec_warning ? 'also' : '' ) . ' notice in the last import that ran there were some errors. I recommend <a href="' . admin_url('admin.php?page=propertyhive_import_properties&tab=logs&import_id=' . (int)$_POST['import_id'] . '&log=' . (int)$row['id'] ) .'" target="_blank">checking the logs</a> to inspect these errors';
			                }
			            }
			            echo '.</p>';
                	}
                	else
                	{
                		$diff_seconds = strtotime($row['end_date']) - strtotime($row['start_date']);
                		echo '<p>Hmmm... The last property import that started at ' . get_date_from_gmt( $row['start_date'], "H:i:s jS F Y" ) . ' looks like it completed successfully and ran for ' . number_format($diff_seconds) . ' seconds.</p>';

                		// check if background queue enabled and check if they're referring to media queue
                		$options = get_option( 'propertyhive_property_import' );
					    if ( is_array($options) && !empty($options) )
					    {
					    	foreach ( $options as $import_id => $option )
							{
								if ( $import_id == (int)$_POST['import_id']  )
					            {
					            	if ( $media_processing === 'background' )
					            	{
					            		$row = $wpdb->get_row( "
						                    SELECT id, start_date, end_date
						                    FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
						                    WHERE 
						                        import_id = '" . (int)$_POST['import_id'] . "'
						                   	AND
						                   		media = 1
						                    ORDER BY start_date DESC 
						                    LIMIT 1
						                ", ARRAY_A);
						                if ( null !== $row )
						                {
						                	if ( $row['end_date'] == '0000-00-00 00:00:00' )
						                	{
						                		echo '<p>I can however see that you have it set to process media in a background queue and that the last media queue import didn\'t complete.</p>
						                		<p>In most cases this is just down to the fact that media takes a long to process and it\'s likely hitting a timeout limit on your server.</p>';
						                	}
						                }
					            	}
					            }
							}
						}
                	}
                }
                else
                {
                	echo '<p>It doesn\'t look like this import has ran recently so I don\'t have any logs to analyse.</p>';
                }
            ?>

            <p>For more information on troubleshooting issues with imports not completing please view our docs below:<p>

            <a href="https://docs.wp-property-hive.com/article/306-troubleshooting#heading-1" target="_blank" class="button button-primary button-hero">View Troubleshooting Documentation <span class="dashicons dashicons-external" style="vertical-align:middle; margin-top:-2px;"></span></a>


			<div class="buttons">
				<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&tab=troubleshooting'); ?>">Back</a>
				<input type="submit" value="This didn't solve my issue" class="button button-primary">
			</div>

		</form>

	</div>