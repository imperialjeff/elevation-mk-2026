<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-property-import-admin-header">

	<div class="add-import-export-button">
		<a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport')); ?>" class="button button-primary button-hero"><span class="dashicons dashicons-plus-alt2"></span> <?php echo esc_html(__( 'Create New Import', 'propertyhive' )); ?></a>
	</div>

	<div class="logo">
		<h1><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties')); ?>"><?php echo esc_html( __( 'Import Properties', 'propertyhive' ) ); ?></a></h1>
	</div>

	<div class="buttons">
		<a href="https://docs.wp-property-hive.com/category/294-property-import" class="button" target="_blank"><?php echo esc_html( __( 'Documentation', 'propertyhive' ) ); ?></a>
	</div>

	<div class="clear"></div>

</div>
