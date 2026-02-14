<?php
/**
 * Elementor Property Street Book Viewing Link Widget.
 *
 * @since 1.0.0
 */
class Elementor_Property_Street_Book_Viewing_Link_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'property-street-book-viewing-link';
    }

    public function get_title() {
        return __( 'Street Book Viewing Link', 'propertyhive' );
    }

    public function get_icon() {
        return 'eicon-link';
    }

    public function get_categories() {
        return [ 'property-hive' ];
    }

    public function get_keywords() {
        return [ 'property hive', 'propertyhive', 'property', 'enquiry', 'form' ];
    }

    protected function _register_controls() {

        $this->start_controls_section(
            'style_section',
            [
                'label' => __( 'Street Book Viewing Link', 'propertyhive' ),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->end_controls_section();

    }

    protected function render() {

        global $property;

        $settings = $this->get_settings_for_display();

        if ( !isset($property->id) ) 
        {
            global $post;

            if ( !isset($post->ID) )
                return;

            $propertyhive_post_id = get_post_meta($post->ID, '_propertyhive_property_id', true);
            $property = new PH_Property((int)$propertyhive_post_id);

            if ( !isset($property->id) || $property->id == '' || $property->id == 0 ) 
            {
                // get here and still don't have a property. only scenario I know of this is Houzez
                if ( isset($post->ID) )
                {
                    $property = new PH_Property((int)$post->ID);
                }
            }
        }

        if ( !isset($property->id) )
            return;

        echo '<a href="' . $property->_book_viewing_url . '" target="_blank" class="button">'. __( 'Book Viewing', 'propertyhive' ) . '</a>';
    }
}