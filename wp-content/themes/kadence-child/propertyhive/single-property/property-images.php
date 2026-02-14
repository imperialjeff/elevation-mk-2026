<?php
/**
 * Single Property Images
 *
 * @author      PropertyHive
 * @package     PropertyHive/Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $post, $propertyhive, $property;
?>


    <?php do_action( 'propertyhive_before_single_property_images' ); ?>

    <?php
    // Fallback: Check import data if no WordPress images found
    // Updated for Street CRM migration (Feb 2026) - Street produces valid JSON
    if ( !isset($images) || !is_array($images) || empty($images) || count($images) < 5 ) {
        $import_data = get_post_meta( get_the_ID(), '_property_import_data', true );
        if ( !empty($import_data) ) {
            $import_json = json_decode($import_data, true);

            if ( isset($import_json['images']) && is_array($import_json['images']) && count($import_json['images']) >= 5 ) {
                $images = array();

                foreach ($import_json['images'] as $img) {
                    $url = isset($img['url']) ? $img['url'] : (isset($img['urls']['large']) ? $img['urls']['large'] : '');
                    if ( !empty($url) ) {
                        $images[] = array(
                            'url' => $url,
                            'title' => isset($img['title']) ? $img['title'] : '',
                            'attachment_id' => null
                        );
                    }
                }
            }
        }
    }
    
    if ( isset($images) && is_array($images) && !empty($images) && count($images) >= 5 ) { ?>

        <div id="gallery_<?php echo get_the_ID(); ?>" class="property-gallery <?php if( count($images) < 5 ) { echo ' fewer-than-five '; } ?>" itemscope itemtype="http://schema.org/ImageGallery">
            <?php $images_i = 0; ?>

            <?php foreach ($images as $image) { 
                // Check if using WordPress attachment or fallback URL
                if ( isset($image['attachment_id']) && $image['attachment_id'] !== false ) {
                    // WordPress attachment exists
                    $imageinfo = wp_getimagesize( wp_get_attachment_image_url( $image['attachment_id'], 'Property Large' ) );
                    $large_url = wp_get_attachment_image_url( $image['attachment_id'], 'Property Large' );
                    $medium_url = wp_get_attachment_image_url( $image['attachment_id'], 'Property Medium' );
                } else {
                    // Using fallback URL from import data
                    $large_url = isset($image['url']) ? $image['url'] : '';
                    $medium_url = $large_url;
                    // Get image dimensions from URL
                    $imageinfo = @getimagesize( $large_url );
                    if ( !$imageinfo || !is_array($imageinfo) ) {
                        $imageinfo = array(1200, 800); // Default dimensions
                    }
                }
            ?>
                
                <figure itemprop="associatedMedia" itemscope itemtype="http://schema.org/ImageObject">
                    <a href="<?php echo esc_url($large_url); ?>" data-caption="" data-width="<?php echo $imageinfo[0]; ?>" data-height="<?php echo $imageinfo[1]; ?>" itemprop="contentUrl">
                        <?php if( $images_i == 0 ) { ?>
                            <img src="<?php echo esc_url($large_url); ?>" itemprop="thumbnail" alt="">
                        <?php } else {  ?>
                            <img src="<?php echo esc_url($medium_url); ?>" itemprop="thumbnail" alt="">
                        <?php } ?>
                    </a>
                </figure>
                    
            <?php $images_i++; } ?>

            <?php if (!empty($images) && isset($images[0]) && isset($images[0]['url'])): ?>
                <a href="<?php echo esc_url($images[0]['url']); ?>" 
                data-fancybox="property_images" 
                data-caption="<?php echo esc_attr(get_the_title()); ?>">
                    <button class="property-image-count">
                        <span><?php echo count($images); ?></span>
                        <i class="fa-regular fa-image"></i>
                    </button>
                </a>
            <?php else: ?>
                <!-- Fallback for properties without images -->
                <button class="property-image-count property-image-count--no-images" disabled>
                    <span>0</span>
                    <i class="fa-regular fa-image"></i>
                </button>
            <?php endif; ?>

            <div class="clearfix"></div>
        </div>

        <div class="pswp" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="pswp__bg"></div>
            <div class="pswp__scroll-wrap">
                <div class="pswp__container">
                    <div class="pswp__item"></div>
                    <div class="pswp__item"></div>
                    <div class="pswp__item"></div>
                </div>
                <div class="pswp__ui pswp__ui--hidden">
                    <div class="pswp__top-bar">
                        <div class="pswp__counter"></div>
                        <button class="pswp__button pswp__button--close" title="Close (Esc)"></button>
                        <button class="pswp__button pswp__button--share" title="Share"></button>
                        <button class="pswp__button pswp__button--fs" title="Toggle fullscreen"></button>
                        <button class="pswp__button pswp__button--zoom" title="Zoom in/out"></button>
                        <div class="pswp__preloader">
                            <div class="pswp__preloader__icn">
                                <div class="pswp__preloader__cut">
                                    <div class="pswp__preloader__donut"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="pswp__share-modal pswp__share-modal--hidden pswp__single-tap">
                        <div class="pswp__share-tooltip"></div>
                    </div>
                    <button class="pswp__button pswp__button--arrow--left" title="Previous (arrow left)"></button>
                    <button class="pswp__button pswp__button--arrow--right" title="Next (arrow right)"></button>
                    <div class="pswp__caption">
                        <div class="pswp__caption__center"></div>
                    </div>
                </div>
            </div>
        </div>

        
        <script type="text/javascript">
            'use strict';
            (function($) {
                var container = [];
                $('#gallery_<?php echo get_the_ID(); ?>').find('figure').each(function() {
                var $link = $(this).find('a'),
                    item = {
                    src: $link.attr('href'),
                    w: $link.data('width'),
                    h: $link.data('height'),
                    title: $link.data('caption')
                    };
                container.push(item);
                });

                $('#gallery_<?php echo get_the_ID(); ?> a').click(function(event) {
                    event.preventDefault();

                    var $pswp = $('.pswp')[0],
                    options = {
                        index: $(this).parent('figure').index(),
                        bgOpacity: 0.8,
                        captionEl: false,
                        tapToClose: true,
                        shareEl: false,
                        fullscreenEl: false,
                    };

                    var gallery = new PhotoSwipe($pswp, PhotoSwipeUI_Default, container, options);
                    gallery.init();
                });

                if ($(window).width() > 720 && $(window).width() < 1440) {
                    $(window).bind('load', function() {
                        var smalltile2_outerHeight = $('.property-gallery figure:nth-child(2)').outerHeight();
                        var smalltile3_outerHeight = $('.property-gallery figure:nth-child(3)').outerHeight();
                        var smalltile4_outerHeight = $('.property-gallery figure:nth-child(4)').outerHeight();
                        var smalltiles_outerHeight = (+smalltile2_outerHeight) + (+smalltile3_outerHeight) + (+smalltile4_outerHeight);
                        
                        $('.property-gallery figure:first-child').css('height', smalltiles_outerHeight);
                    });
                }

            }(jQuery));
        </script>

        <?php } else {
            echo apply_filters( 'propertyhive_single_property_image_html', sprintf( '<img src="%s" alt="Placeholder" />', ph_placeholder_img_src() ), $post->ID );
        }
    ?>

    <?php do_action( 'propertyhive_product_thumbnails' ); ?>

    <?php do_action( 'propertyhive_after_single_property_images' ); ?>