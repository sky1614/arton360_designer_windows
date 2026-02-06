/**
 * Arton360 Color Sync - Swaps T-shirt base when color changes
 */

jQuery(function ($) {
    console.log('🎨 Arton360 Color Sync: Init');
    
    if (typeof arton360ColorConfig === 'undefined') {
        console.error('❌ arton360ColorConfig not found');
        return;
    }

    console.log('Color map:', arton360ColorConfig.map);
    console.log('Default color:', arton360ColorConfig.defaultSlug);

    var $baseImage = $('#arton360-shirt-base, .arton360-shirt-base');

    if ($baseImage.length === 0) {
        console.error('❌ Base shirt image not found');
        return;
    }

    console.log('✅ Base image found');

    /**
     * Get currently selected color
     */
    function getCurrentColor() {
        // Try radio (swatches)
        var $radio = $('input[type="radio"][name="attribute_pa_color"]:checked');
        if ($radio.length) {
            return $radio.val();
        }

        // Try dropdown
        var $select = $('select[name="attribute_pa_color"]');
        if ($select.length) {
            return $select.val();
        }

        // Try hidden input
        var $hidden = $('input[name="attribute_pa_color"]');
        if ($hidden.length) {
            return $hidden.val();
        }

        return '';
    }

    /**
     * Swap the base shirt image
     */
    function swapShirtBase(colorSlug) {
        if (!colorSlug) {
            console.warn('⚠️ No color provided');
            return;
        }

        var newImageUrl = arton360ColorConfig.map[colorSlug];

        if (!newImageUrl) {
            console.warn('⚠️ No image for color:', colorSlug);
            return;
        }

        console.log('🔄 Swapping to:', colorSlug);
        console.log('📷 Image URL:', newImageUrl);

        $baseImage.attr('src', newImageUrl);
    }

    // Initial swap
    var initialColor = getCurrentColor() || arton360ColorConfig.defaultSlug;
    swapShirtBase(initialColor);

    // Listen for color changes
    $(document).on('change', 'input[name="attribute_pa_color"], select[name="attribute_pa_color"]', function () {
        var color = getCurrentColor();
        console.log('Color changed to:', color);
        swapShirtBase(color);
    });

    // WooCommerce variation events
    $('form.variations_form').on('found_variation', function (event, variation) {
        if (variation.attributes && variation.attributes.attribute_pa_color) {
            var color = variation.attributes.attribute_pa_color;
            console.log('Variation found, color:', color);
            swapShirtBase(color);
        }
    });

    console.log('✅ Arton360 Color Sync: Ready');
});