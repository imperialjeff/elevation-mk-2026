<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Media Processing', 'propertyhive' ); ?></h3>

<p>Here you can control at what time media is imported; either at the same time as properties, or in a separate queue.</p>

<table class="form-table">
	<tbody>
		<tr>
			<th><label for="media_processing"><?php echo __( 'Import media', 'propertyhive' ); ?></label></th>
			<td style="padding-top:20px;">

				<?php $media_processing = get_option( 'propertyhive_property_import_media_processing', '' ); ?>

				<div style="padding:3px 0"><label><input type="radio" name="media_processing" id="media_processing" value="" <?php if ( $media_processing == '' ) { echo ' checked'; } ?>> Immediately as each property is imported</label></div>

				<div style="padding:3px 0">
					<label><input type="radio" name="media_processing" id="media_processing_background" value="background" <?php if ( $media_processing == 'background' ) { echo ' checked'; } ?>> After imports have all completed in a separate queue</label>
				</div>

				<br>
				<small><em>Media can take a long time to import. If you have a lot of properties and find that imports are timing out or not completing we recommend switching to processing media in a separate queue. This will ensure the core property data is imported first, and then a separate process will run in the background importing media shortly after at a later date.</em></small>

			</td>
		</tr>
	</tbody>
</table>