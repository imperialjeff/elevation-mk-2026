(function ($) {
    $(document).on('keyup', '[data-search]', function () {
        const searchVal = $(this).val().toLowerCase();
        const filterItems = $('.faq-item');

        if (searchVal) {
            filterItems.addClass('hidden').filter(function () {
                return $(this).find('.faq-item-title').text().toLowerCase().includes(searchVal);
            }).removeClass('hidden');
        } else {
            filterItems.removeClass('hidden');
        }
    });
})(jQuery);
