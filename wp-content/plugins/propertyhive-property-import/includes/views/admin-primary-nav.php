<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-property-import-admin-primary-nav">

	<nav>
		<ul>
			<?php
				foreach ( $tabs as $key => $value )
				{
					echo '<li' . ( $key == $active_tab ? ' class="active"' : '' ) . '><a href="' . admin_url('admin.php?page=propertyhive_import_properties&tab=' . esc_attr($key)) . '">' . esc_html($value) . '</a></li>';
				}
			?>
		</ul>
	</nav>

</div>