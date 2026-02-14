<?php

$attributes = array();

if( !empty(get_field( 'locratingmodule_long' )) ) {
    $attributes['long'] = get_field( 'locratingmodule_long' );
}
if( !empty(get_field( 'locratingmodule_lat' )) ) {
    $attributes['lat'] = get_field( 'locratingmodule_lat' );
}
?>

<div class="locrating-module-container">
    <script type="text/javascript">
        jQuery(document).ready(function($){
            loadLocratingPlugin({ 
                id: 'single_property_map', 
                lat: '<?php echo $attributes['lat']; ?>', 
                lng: '<?php echo $attributes['long']; ?>', 
                type: 'all', 
                mapstyle: 'voyager', 
                menucolor: '#401663', 
                menubackcolor: '#e6e7e8', 
                menuselectcolor: '#feeff8', 
                menuselectbackcolor: '#ae8a65', 
                menuallcaps: 'true', 
                icon: 'https://www.locrating.com/html5/assets/images/house_icon2.png', 
                lazyload:true ,
                // hidemenu: true,
            });
        });
    </script>

    <div class="cw-property-single-section cw-property-single-map">
        <div class="cw-map-above">
            <?php if( !empty(get_field( 'locratingmodule_title' )) ) { ?>
                <h3><?php echo get_field( 'locratingmodule_title' ); ?></h3>
            <?php } else { ?>
                <h3>Map</h3>
            <?php } ?>

            <?php $is_large = get_field( 'locratingmodule_large' ); ?>
            <?php if( $is_large == true ) { ?>
                <div class="cw-property-single-actions">
                    <ul class="clearfix actions-large-map">
                        <li class="action-locrating-all-in-one">
                            <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'all'});}catch (err) {}">Full Map</a>
                        </li>
                        <li class="action-locrating-schools">
                            <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'schools'});}catch (err) {}">Schools</a>
                        </li>
                        <li class="action-locrating-amenities">
                            <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'localinfo'});}catch (err) {}">Amenities</a>
                        </li>
                        <li class="action-locrating-transport">
                            <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'stationslist'});}catch (err) {}">Transport</a>
                        </li>
                        <li class="action-locrating-broadband-checker">
                            <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'broadband', showmap: 'true'});}catch (err) {}">Broadband</a>
                        </li>
                        <li class="action-locrating-all-in-one">
                            <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'all'});}catch (err) {}">Area Info</a>
                        </li>
                    </ul>
                </div>
            <?php } ?>
        </div>
        <div id="single_property_map" style="width:100%; height:480px"></div>
    </div>

    <div class="property_actions cw-property-single-actions">
        <div class="cw-property_actions-notice">
            <p>View fullscreen interactive maps of points of interest around this area.</p>
        </div>

        <?php if( $is_large == false ) { ?>
            <ul class="clearfix">
                <li class="action-locrating-all-in-one">
                    <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'all'});}catch (err) {}">Full Map</a>
                </li>
                <li class="action-locrating-schools">
                    <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'schools'});}catch (err) {}">Schools</a>
                </li>
                <li class="action-locrating-amenities">
                    <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'localinfo'});}catch (err) {}">Amenities</a>
                </li>
                <li class="action-locrating-transport">
                    <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'stationslist'});}catch (err) {}">Transport</a>
                </li>
                <li class="action-locrating-broadband-checker">
                    <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'broadband', showmap: 'true'});}catch (err) {}">Broadband</a>
                </li>
                <li class="action-locrating-all-in-one">
                    <a href="https://www.locrating.com" onclick="try{return openLocratingWindow({lat: <?php echo $attributes['lat']; ?>, lng : <?php echo $attributes['long']; ?>, type:'all'});}catch (err) {}">Area Info</a>
                </li>
            </ul>
        <?php } ?>
    </div>
</div>