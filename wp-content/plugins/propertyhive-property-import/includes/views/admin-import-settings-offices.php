<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Offices', 'propertyhive' ); ?></h3>

<p><?php echo __( 'On the left are the <a href="' . admin_url('admin.php?page=ph-settings&tab=offices') . '">offices set up in Property Hive</a>, and on the right you should enter how each office is sent in the <span class="phpi-import-format-name"></span> feed. This will normally be an ID or a name. The third party sending the feed will be able to confirm this.', 'propertyhive' ); ?>

<?php
	$primary_office = '';
    $args = array(
        'post_type' => 'office',
        'nopaging' => true
    );
    $office_query = new WP_Query($args);
    
    if ( $office_query->have_posts() )
    {
        while ( $office_query->have_posts() )
        {
            $office_query->the_post();

            if ( get_post_meta(get_the_ID(), 'primary', TRUE) == '1' )
            {
                $primary_office = get_the_title();
            }
        }
    }
    $office_query->reset_postdata();
?>
<p>Note: If no office is entered or no matching office is found, we'll default to the primary office (<?php echo esc_html($primary_office); ?>).</p>

<table class="form-table">
	<tbody>
	<?php
		$args = array(
	        'post_type' => 'office',
	        'nopaging' => true
	    );
	    $office_query = new WP_Query($args);
	    
	    if ( $office_query->have_posts() )
	    {
	    	while ( $office_query->have_posts() )
	        {
	            $office_query->the_post();
	?>
		<tr>
			<th><label for="office_mapping_<?php echo esc_attr(get_the_ID()); ?>"><?php echo esc_html(get_the_title()); ?></label></th>
			<td style="padding-top:20px;">
				<input type="text" id="office_mapping_<?php echo esc_attr(get_the_ID()); ?>" name="office_mapping[<?php echo esc_attr(get_the_ID()); ?>]" value="<?php 
					if ( isset($import_settings['offices'][get_the_ID()]) )
					{
						echo esc_attr($import_settings['offices'][get_the_ID()]);
					}
				?>">
			</td>
		</tr>
	<?php
	        }
	    }

	    $office_query->reset_postdata();
	?>
	</tbody>
</table>