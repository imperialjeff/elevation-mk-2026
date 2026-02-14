<?php if ( !isset($current_settings['local_schools_button']) || ( isset($current_settings['local_schools_button']) && $current_settings['local_schools_button'] == 1 ) ) { ?>
<li class="action-locrating-schools">
    
    <a data-fancybox data-src="#locrating_schools_<?php echo $post->ID; ?>" href="javascript:;"><?php _e( 'Local Schools', 'propertyhive' ); ?></a>

    <!-- LIGHTBOX IFRAME -->
    <div id="locrating_schools_<?php echo $post->ID; ?>" style="display:none;">
        
        <h2><?php _e( 'Local Schools', 'propertyhive' ); ?></h2>
        
        <?php $this->propertyhive_locrating_schools_iframe(); ?>
        
    </div>
    <!-- END LIGHTBOX IFRAME -->
    
</li>
<?php } ?>

<?php if ( !isset($current_settings['local_amenities_button']) || ( isset($current_settings['local_amenities_button']) && $current_settings['local_amenities_button'] == 1 ) ) { ?>
<li class="action-locrating-amenities">
    
    <a data-fancybox data-src="#locrating_amenities_<?php echo $post->ID; ?>" href="javascript:;"><?php _e( 'Local Amenities', 'propertyhive' ); ?></a>

    <!-- LIGHTBOX IFRAME -->
    <div id="locrating_amenities_<?php echo $post->ID; ?>" style="display:none;">
        
        <h2><?php _e( 'Local Amenities', 'propertyhive' ); ?></h2>
        
        <?php $this->propertyhive_locrating_amenities_iframe(); ?>
        
    </div>
    <!-- END LIGHTBOX IFRAME -->
    
</li>
<?php } ?>

<?php if ( !isset($current_settings['local_transport_button']) || ( isset($current_settings['local_transport_button']) && $current_settings['local_transport_button'] == 1 ) ) { ?>
<li class="action-locrating-transport">
    
    <a data-fancybox data-src="#locrating_transport_<?php echo $post->ID; ?>" href="javascript:;"><?php _e( 'Local Transport', 'propertyhive' ); ?></a>

    <!-- LIGHTBOX IFRAME -->
    <div id="locrating_transport_<?php echo $post->ID; ?>" style="display:none;">
        
        <h2><?php _e( 'Local Transport', 'propertyhive' ); ?></h2>
        
        <?php $this->propertyhive_locrating_transport_iframe(); ?>
        
    </div>
    <!-- END LIGHTBOX IFRAME -->
    
</li>
<?php } ?>

<?php if ( !isset($current_settings['broadband_checker_button']) || ( isset($current_settings['broadband_checker_button']) && $current_settings['broadband_checker_button'] == 1 ) ) { ?>
<li class="action-locrating-broadband-checker">
    
    <a data-fancybox data-src="#locrating_broadband_checker_<?php echo $post->ID; ?>" href="javascript:;"><?php _e( 'Broadband Checker', 'propertyhive' ); ?></a>

    <!-- LIGHTBOX IFRAME -->
    <div id="locrating_broadband_checker_<?php echo $post->ID; ?>" style="display:none;">
        
        <h2><?php _e( 'Broadband Checker', 'propertyhive' ); ?></h2>
        
        <?php $this->propertyhive_locrating_broadband_checker_iframe(); ?>
        
    </div>
    <!-- END LIGHTBOX IFRAME -->
    
</li>
<?php } ?>

<?php if ( isset($current_settings['all_in_one_button']) && $current_settings['all_in_one_button'] == 1 ) { ?>
<li class="action-locrating-all-in-one">
    
    <a data-fancybox data-src="#locrating_all_in_one_<?php echo $post->ID; ?>" href="javascript:;"><?php _e( 'Local Area Information', 'propertyhive' ); ?></a>

    <!-- LIGHTBOX IFRAME -->
    <div id="locrating_all_in_one_<?php echo $post->ID; ?>" style="display:none;">
        
        <h2><?php _e( 'Local Area Information', 'propertyhive' ); ?></h2>
        
        <?php $this->propertyhive_locrating_all_in_one_iframe(); ?>
        
    </div>
    <!-- END LIGHTBOX IFRAME -->
    
</li>
<?php } ?>