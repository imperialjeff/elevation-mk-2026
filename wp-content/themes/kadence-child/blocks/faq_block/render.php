<?php
// Retrieve block settings
$faq_category = get_field('faq_block_category');
$number_of_faqs = get_field('faq_block_numposts') ?: 5;

// Query FAQs
$args = [
    'post_type' => 'faq',
    'posts_per_page' => $number_of_faqs,
];
if ($faq_category) {
    $args['tax_query'] = [
        [
            'taxonomy' => 'faq-category',
            'field'    => 'id',
            'terms'    => $faq_category,
        ],
    ];
}
$query = new WP_Query($args);

if ($query->have_posts()) : ?>
    <div class="faq-container">
        <div class="faq-search">
            <input type="text" placeholder="Search Frequently Asked Questions" data-search />
        </div>
        <div class="faq-items">
            <?php while ($query->have_posts()) : $query->the_post(); ?>
                <div class="faq-item" data-filter-item data-filter-name="<?php echo strtolower(get_the_title()); ?>">
                    <h5 class="faq-item-title"><?php the_title(); ?></h5>
                    <div class="faq-item-content"><?php the_content(); ?></div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
<?php
endif;

wp_reset_postdata();
?>
