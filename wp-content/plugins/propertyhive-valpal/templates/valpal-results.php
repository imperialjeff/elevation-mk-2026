<div id="valuation_results" style="display:none;">
                
    <div class="amounts">

        <div class="min-amount-label">Min Valuation</div>
        <div class="actual-amount-label">Valuation</div>
        <div class="max-amount-label">Max Valuation</div>

        <div class="min-amount"><span></span></div>
        <div class="actual-amount">
            <span></span>
        </div>
        <div class="max-amount"><span></span></div>

        <div style="clear:both;"></div>

    </div>

    <div class="info-container">

        <div class="container">

            <?php if ( apply_filters( 'propertyhive_valpal_show_map_in_results', true ) === true ) { ?>
            <div class="map-view" id="map_canvas" style="height:400px;"></div>
            <?php } ?>

            <?php if ( get_option( 'propertyhive_maps_provider' ) == '' && apply_filters( 'propertyhive_valpal_show_street_view_in_results', true ) === true ) { ?>
            <div class="street-view" id="street_map_canvas" style="height:400px;"></div>
            <?php } ?>

            <div class="area-info"></div>

        </div>

    </div>

</div>