<?php

// if( !empty(get_field('propertygrid-column_count')) ) {
//     echo '
//         <style>
//             .propertyhive-properties-shortcode.propertyhive.columns-' . get_field('propertygrid-column_count') . ' ul.properties li {
//                 flex-grow: 1;
//                 width: calc((100% / ' . get_field('propertygrid-column_count') . ') - 16px);
//                 max-width: calc((100% / ' . get_field('propertygrid-column_count') . ') - 16px);
//             }
//         </style>
//     ';
// }

$attributes = array();
$nogap_class = ''; // Initialize the variable to prevent undefined variable warning

if( !empty(get_field( 'developmentsgrid-branch' )) ) {
    $attributes['developments_branch'] = get_field( 'developmentsgrid-branch' );
}
if( !empty(get_field( 'developmentsgrid-status' )) ) {
    $attributes['developments_status'] = get_field( 'developmentsgrid-status' );
}
if( !empty(get_field( 'developmentsgrid-numposts' )) ) {
    $attributes['developments_numposts'] = get_field( 'developmentsgrid-numposts' );
}
if( !empty(get_field( 'developmentsgrid-column_count' )) ) {
    $attributes['column_count'] = get_field( 'developmentsgrid-column_count' );
}

$args = array(
	'post_type'                 => array( 'property-development' ),
	'post_status'               => array( 'publish' ),
    'posts_per_page'            => $attributes['developments_numposts'],
    'tax_query'                 => array(
        'relation'              => 'AND',
        array(
            'taxonomy'          => 'development_branch',
            'terms'             => $attributes['developments_branch']
        ),
        array(
            'taxonomy'          => 'development_status',
            'terms'             => $attributes['developments_status']
        )
    )
);

// The Query
$query = new WP_Query( $args );

// The Loop
if ( $query->have_posts() ) {
    if( $attributes['column_count'] == 1 ) {
        echo '
            <style>
                .development-grid-columns-1 > a {
                    width: 100%;
                }
                .developments-grid-container {
                    flex-direction: column;
                }
            </style>
        ';
    } else {
        echo '
            <style>
                .development-grid-columns-' . $attributes['column_count'] . ' > a {
                    width: calc( (100% - (35px / ' . $attributes['column_count'] . ') ) / ' . $attributes['column_count'] . ' );
                }
            </style>
        ';
    }

    echo '<div class="developments-grid-container development-grid-columns-' . $attributes['column_count'] . $nogap_class . '">';
	while ( $query->have_posts() ) {
		$query->the_post();

        $development_gallery = get_field( 'field_655cd99440c00', get_the_ID() );
        $status_terms = get_the_terms( get_the_ID(), 'development_status' );

        $status_class = null;
        $status_ribbon = null;
        if( has_term( 'completed', 'development_status' ) ) {
            $status_class = 'development-completed';
            $status_ribbon = '<div class="flag flag-completed">Sold Out</div>';
        } else {
            $status_class = 'development-current';
            // $status_ribbon = '<div class="flag flag-current">Plots Available</div>';
        }


        echo '<a href="' . get_the_permalink() . '" title="' . get_the_title() . '" class="' . $status_class . '">';
        echo '<div class="development-grid-thumbnail" style="background-image:url(' . wp_get_attachment_image_url( $development_gallery[0], 'medium' ) . ')">';
        echo $status_ribbon;
        echo '</div>';
        echo '<div class="development-grid-details">';

        $string = strip_tags(get_field( 'field_655c8e3f4d2dd', get_the_ID() ));
        if (strlen($string) > 240) {

            // truncate string
            $stringCut = substr($string, 0, 240);
            $endPoint = strrpos($stringCut, ' ');

            //if the string doesn't contain any space then it will cut without word basis.
            $string = $endPoint? substr($stringCut, 0, $endPoint) : substr($stringCut, 0);
            $string .= '...';
        }

        echo '<span>' . get_field( 'field_655c8ae84d2d6', get_the_ID() ) . '</span>';
		echo '<h3>' . get_the_title() . '</h3>';
        echo '<p>' . $string . '</p>';
        echo '<button class="">View Development</button>';
        echo '</div>';
        echo '</a>';
	}
    echo '</div>';
} else {
	// no posts found
}

// Restore original Post Data
wp_reset_postdata();