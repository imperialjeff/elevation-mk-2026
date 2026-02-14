<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="notice notice-error no-format-notice inline"><p>Please select an import format in order to configure the following page.</p></div>

<?php if ( isset($_GET['import_id']) ) { ?><div class="notice notice-info only-updated-warning inline" style="display:none"><p><?php echo esc_html(__( 'You currently have the \'Only Import Updated Properties\' setting checked. For changes below to take effect for existing properties you\'ll need to unselect this option.', 'propertyhive' )); ?></p></div><?php } ?>

<h3><?php echo esc_html(__( 'Custom Field Mapping', 'propertyhive' )); ?></h3>

<p>On the left are the custom field values from <span class="phpi-import-format-name"></span>, and on the right are the <a href="<?php echo admin_url('admin.php?page=ph-settings&tab=customfields'); ?>">custom fields setup in Property Hive</a>. Simply match as many of them as possible to ensure properties are imported with as much data as possible.</p>
<p><strong>Need help?</strong> Our <a href="https://docs.wp-property-hive.com/category/294-property-import" target="_blank">documentation</a> covers this step in more detail.</p>

<hr>

<div id="phpi_taxonomy_values">Loading...</div>