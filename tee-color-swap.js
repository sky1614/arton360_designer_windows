/**
 * Unified T-shirt Color Swap
 * Works for both manual and Arton360 designer products
 * Version 2.0.2 - FIX #2: Added fallback support
 */

jQuery(function ($) {
    console.log('🎨 Tee Color Swap: Initializing...');
    console.log('TEE_SWAP data:', TEE_SWAP);

    // Validate TEE_SWAP exists
    if (typeof TEE_SWAP === 'undefined' || !TEE_SWAP.map) {
        console.error('❌ TEE_SWAP not defined or missing map');
        return;
    }

    // Find the base image element (try both main and fallback)
    var $baseImage = $('#tee-base-image, #tee-base-image-fallback');
    
    console.log('✅ Base image found:', $baseImage.length);

    if ($baseImage.length === 0) {
        console.error('❌ No base image element found!');
        return;
    }

    console.log('✅ Color map loaded:', Object.keys(TEE_SWAP.map).length, 'colors');

    /**
     * Get currently selected color slug
     */
    function getCurrentColor() {
        // Try radio buttons (for Shop Kit swatches)
        var $radioChecked = $('input[type="radio"][name="attribute_pa_color"]:checked');
        if ($radioChecked.length) {
            return $radioChecked.val();
        }

        // Try dropdown (standard WooCommerce)
        var $dropdown = $('select[name="attribute_pa_color"]');
        if ($dropdown.length) {
            return $dropdown.val();
        }

        // Try hidden input (some swatch plugins)
        var $hidden = $('input[name="attribute_pa_color"]');
        if ($hidden.length) {
            return $hidden.val();
        }

        return '';
    }

    /**
     * Swap the base t-shirt image
     */
    function swapBaseImage(colorSlug) {
        if (!colorSlug) {
            console.warn('⚠️ No color slug provided');
            return;
        }

        var newImageUrl = TEE_SWAP.map[colorSlug];

        if (!newImageUrl) {
            console.warn('⚠️ No image found for color:', colorSlug);
            return;
        }

        console.log('🔄 Swapping to color:', colorSlug);
        console.log('📷 New image URL:', newImageUrl);

        // Update the image src
        $baseImage.attr('src', newImageUrl);
        $baseImage.attr('srcset', ''); // Clear srcset to force src

        // If wrapped in an anchor (for zoom/lightbox), update that too
        var $anchor = $baseImage.closest('a');
        if ($anchor.length) {
            $anchor.attr('href', newImageUrl);
            $anchor.attr('data-large_image', newImageUrl);
        }
    }

    /**
     * Initialize with default color
     */
    var initialColor = getCurrentColor();
    if (initialColor) {
        console.log('🎯 Initial color:', initialColor);
        swapBaseImage(initialColor);
    } else {
        console.log('ℹ️ No initial color selected, using default image');
    }

    /**
     * Listen for color changes - Radio buttons (Shop Kit swatches)
     */
    $(document).on('change', 'input[type="radio"][name="attribute_pa_color"]', function () {
        var selectedColor = $(this).val();
        console.log('📻 Radio changed to:', selectedColor);
        swapBaseImage(selectedColor);
    });

    /**
     * Listen for color changes - Dropdown (standard WooCommerce)
     */
    $(document).on('change', 'select[name="attribute_pa_color"]', function () {
        var selectedColor = $(this).val();
        console.log('📋 Dropdown changed to:', selectedColor);
        swapBaseImage(selectedColor);
    });

    /**
     * Listen for WooCommerce variation events
     * (fires when variation data is loaded)
     */
    $('form.variations_form').on('found_variation', function (event, variation) {
        console.log('🔍 Variation found:', variation);

        if (variation.attributes && variation.attributes.attribute_pa_color) {
            var selectedColor = variation.attributes.attribute_pa_color;
            console.log('🎨 Variation color:', selectedColor);
            swapBaseImage(selectedColor);
        }
    });

    /**
     * Also listen to generic 'change' event on variations form
     * (backup for various swatch plugins)
     */
    $('form.variations_form').on('change', function () {
        var currentColor = getCurrentColor();
        if (currentColor) {
            console.log('📝 Form changed, color:', currentColor);
            swapBaseImage(currentColor);
        }
    });

    /**
     * Listen for clicks on color swatches
     * (for visual swatch plugins that use divs/buttons)
     */
    $(document).on('click', '[data-attribute_name="attribute_pa_color"] .swatch-wrapper, [data-attribute_name="attribute_pa_color"] .variable-item', function () {
        // Give it a moment for the actual input to update
        setTimeout(function () {
            var currentColor = getCurrentColor();
            if (currentColor) {
                console.log('🖱️ Swatch clicked, color:', currentColor);
                swapBaseImage(currentColor);
            }
        }, 50);
    });

    console.log('✅ Tee Color Swap: Ready!');
});