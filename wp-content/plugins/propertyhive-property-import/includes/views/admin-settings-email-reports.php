<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Email Reports', 'propertyhive' ); ?></h3>

<p>With email reports enabled you can have the logs automatically emailed to you each time an import finishes running.</p>

<table class="form-table">
	<tbody>
		<tr>
			<th><label for="email_reports"><?php echo __( 'Enable Email Reports', 'propertyhive' ); ?></label></th>
			<td>
				<?php $email_reports = get_option( 'propertyhive_property_import_email_reports', '' ); ?>
				<input type="checkbox" name="email_reports" id="email_reports" value="yes"<?php if ( $email_reports == 'yes' ) { echo ' checked'; } ?>>
			</td>
		</tr>
		<tr id="email_reports_to_row" style="display:none">
			<th><label for="email_reports_to"><?php echo __( 'Email Reports To', 'propertyhive' ); ?></label></th>
			<td>
				<?php 
					$email_reports_to = get_option( 'propertyhive_property_import_email_reports_to', '' ); 
					if ( empty($email_reports_to) )
					{
						$email_reports_to = get_bloginfo('admin_email');
					}
				?>
				<input type="email" name="email_reports_to" id="email_reports_to" style="width:100%; max-width:400px;" value="<?php echo esc_attr(sanitize_email($email_reports_to)); ?>">
			</td>
		</tr>
	</tbody>
</table>