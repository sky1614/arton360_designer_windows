<?php
/**
 * Plugin Name: Arton360 Designer Bridge
 * Description: REST endpoint + shortcode to connect Vercel Designer with WooCommerce + Dokan.
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================
 * CONFIG — Vercel front-end origin
 * ====================================================== */

define( 'ARTON360_ALLOWED_ORIGIN', 'https://arton360-designer-i756.vercel.app' );

// Attribute taxonomies (must match Products → Attributes slugs)
if ( ! defined( 'ARTON360_COLOR_TAX' ) ) {
    define( 'ARTON360_COLOR_TAX', 'pa_color' );
}
if ( ! defined( 'ARTON360_SIZE_TAX' ) ) {
    define( 'ARTON360_SIZE_TAX', 'pa_size' );
}

/* ======================================================
 * (A) CORS for REST API
 * ====================================================== */

add_action( 'rest_api_init', function () {
    add_filter( 'rest_pre_serve_request', function ( $value ) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ( $origin === ARTON360_ALLOWED_ORIGIN ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
            header( 'Vary: Origin' );
        }

        return $value;
    } );
} );

/* ======================================================
 * (B) Helper: get vendor ID
 * ====================================================== */

function arton360_get_current_vendor_id() {
    $uid = get_current_user_id();
    if ( ! $uid ) {
        return 0;
    }

    $user = get_userdata( $uid );
    if ( ! $user ) {
        return 0;
    }

    return in_array( 'seller', (array) $user->roles, true ) ? $uid : 0;
}

/* ======================================================
 * (C) Register REST Endpoint
 * ====================================================== */

add_action( 'rest_api_init', function () {
    register_rest_route(
        'arton360/v1',
        '/save-design',
        array(
            'methods'             => 'POST',
            'callback'            => 'arton360_save_design',
            'permission_callback' => function () {
                return is_user_logged_in() && arton360_get_current_vendor_id() > 0;
            },
        )
    );
} );

/**
 * Base prices (in store currency – assumed USD in your UI).
 */
define( 'ARTON360_BASE_PRICE_TSHIRT', 15.00 );
define( 'ARTON360_BASE_PRICE_GRAPHIC_TSHIRT', 30.00 );

/**
 * Return minimum allowed price based on product category slug.
 */
function arton360_get_min_price_for_category( $slug ) {
    $slug = sanitize_title( $slug );

    // Graphic T-shirt category
    if ( in_array( $slug, array( 'graphic-tshirt', 'graphic_tshirt', 'graphic tshirt' ), true ) ) {
        return ARTON360_BASE_PRICE_GRAPHIC_TSHIRT;
    }

    // Regular T-shirts
    if ( in_array( $slug, array( 'tshirts', 'tshirt', 't-shirt' ), true ) ) {
        return ARTON360_BASE_PRICE_TSHIRT;
    }

    // No minimum for other categories
    return 0;
}

/* ======================================================
 * Save design from React app and create a NEW Woo product
 * ====================================================== */

function arton360_save_design( \WP_REST_Request $req ) {
    $vendor_id = arton360_get_current_vendor_id();
    if ( ! $vendor_id ) {
        return new \WP_Error( 'not_vendor', 'User is not a vendor', array( 'status' => 403 ) );
    }

    $data = $req->get_json_params();

    // Core payload from React
    $design_name   = sanitize_text_field( $data['designName'] ?? '' );
    $previewPng    = $data['previewPng'] ?? '';
    $tshirtDesigns = $data['tshirtDesigns'] ?? array();
    $printBox      = $data['printBox'] ?? null;
    $productMeta   = $data['productMeta'] ?? array();

    if ( ! $design_name || ! $previewPng ) {
        return new \WP_Error(
            'bad_request',
            'designName and previewPng are required',
            array( 'status' => 400 )
        );
    }

    // --- Extract productMeta fields safely ---
    $pm_title       = sanitize_text_field( $productMeta['title'] ?? $design_name );
    $pm_desc        = wp_kses_post( $productMeta['description'] ?? '' );
    $pm_category    = sanitize_title( $productMeta['categorySlug'] ?? '' );
    $pm_tags        = $productMeta['tags'] ?? array();
    $pm_price       = isset( $productMeta['price'] ) ? floatval( $productMeta['price'] ) : 499.0;
    $pm_currency    = sanitize_text_field( $productMeta['currency'] ?? 'USD' );
    $pm_art_type    = sanitize_text_field( $productMeta['artType'] ?? '' );
    $pm_style       = sanitize_text_field( $productMeta['style'] ?? '' );
    $pm_vendor_flag = ! empty( $productMeta['vendorMatureFlag'] ) ? 1 : 0;

    // --- Enforce base price per category ---
    $min_price = arton360_get_min_price_for_category( $pm_category );

    if ( $min_price > 0 && $pm_price < $min_price ) {
        return new \WP_Error(
            'price_too_low',
            sprintf(
                'Minimum price for this category is %s%.2f, but you entered %s%.2f.',
                get_woocommerce_currency_symbol( $pm_currency ),
                $min_price,
                get_woocommerce_currency_symbol( $pm_currency ),
                $pm_price
            ),
            array( 'status' => 400 )
        );
    }

    // Normalise tags to array of strings
    if ( is_string( $pm_tags ) ) {
        $pm_tags = array_filter( array_map( 'trim', explode( ',', $pm_tags ) ) );
    } elseif ( ! is_array( $pm_tags ) ) {
        $pm_tags = array();
    }

    // --- Save preview PNG to media library ---
    $thumb_id = arton360_save_dataurl_image( $previewPng, $pm_title, $vendor_id );
    if ( is_wp_error( $thumb_id ) ) {
        return $thumb_id;
    }

    // --- ALWAYS create a NEW product (do NOT reuse by slug) ---
    $slug   = sanitize_title( $pm_title ); // WP will auto-unique this if necessary
    $postarr = array(
        'post_title'   => $pm_title,
        'post_name'    => $slug,
        'post_type'    => 'product',
        'post_status'  => 'publish',
        'post_author'  => $vendor_id,
        'post_content' => $pm_desc,
    );

    $product_id = wp_insert_post( $postarr, true );
    if ( is_wp_error( $product_id ) ) {
        return $product_id;
    }

    // --- Base meta (for compatibility; final price comes from variations) ---
    update_post_meta( $product_id, '_regular_price', $pm_price );
    update_post_meta( $product_id, '_price', $pm_price );

    // Store currency separately (for your logic / reporting)
    update_post_meta( $product_id, '_arton360_currency', $pm_currency );

    // --- Auto-generate SKU if empty ---
    $existing_sku = get_post_meta( $product_id, '_sku', true );
    if ( empty( $existing_sku ) ) {
        $sku = 'TSHIRT-' . $vendor_id . '-' . time();
        update_post_meta( $product_id, '_sku', $sku );
    }

    // --- Category ---
    if ( ! empty( $pm_category ) ) {
        wp_set_object_terms( $product_id, $pm_category, 'product_cat', false );
    }

    // --- Tags ---
    if ( ! empty( $pm_tags ) ) {
        wp_set_object_terms( $product_id, $pm_tags, 'product_tag', false );
    }

    // --- Internal meta (art type, style, mature flag, canvas JSON, print box) ---
    if ( ! empty( $pm_art_type ) ) {
        update_post_meta( $product_id, '_arton360_art_type', $pm_art_type );
    }

    if ( ! empty( $pm_style ) ) {
        update_post_meta( $product_id, '_arton360_style', $pm_style );
    }

    update_post_meta( $product_id, '_vendor_mature_flag', $pm_vendor_flag ? '1' : '0' );
    update_post_meta( $product_id, '_arton360_canvas_json', wp_json_encode( $tshirtDesigns ) );
    update_post_meta( $product_id, '_arton360_print_box', wp_json_encode( $printBox ) );

    // Thumbnail
    if ( $thumb_id ) {
        set_post_thumbnail( $product_id, $thumb_id );
    }

    // --- Make product VARIABLE with Color + Size variations (for swatches) ---
    arton360_setup_color_size_variations( $product_id, $pm_price );

    return new \WP_REST_Response(
        array(
            'ok'          => true,
            'product_id'  => $product_id,
            'status'      => get_post_status( $product_id ),
            'product_url' => get_permalink( $product_id ),
        ),
        200
    );
}

/* ======================================================
 * Helper: get all terms for an attribute taxonomy
 * ====================================================== */

function arton360_get_all_terms_for_taxonomy( $taxonomy ) {
    if ( ! taxonomy_exists( $taxonomy ) ) {
        return array();
    }

    $terms = get_terms(
        array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        )
    );

    if ( is_wp_error( $terms ) ) {
        return array();
    }

    return $terms;
}

/**
 * Turn a newly created product into a variable product
 * with Color (pa_color) and Size (pa_size) variations.
 */
function arton360_setup_color_size_variations( $product_id, $base_price ) {
    if (
        ! function_exists( 'wc_get_product' ) ||
        ! class_exists( 'WC_Product_Variable' ) ||
        ! class_exists( 'WC_Product_Attribute' ) ||
        ! class_exists( 'WC_Product_Variation' )
    ) {
        return;
    }

    // Get all global Color / Size terms
    $color_tax   = ARTON360_COLOR_TAX;
    $size_tax    = ARTON360_SIZE_TAX;
    $color_terms = arton360_get_all_terms_for_taxonomy( $color_tax );
    $size_terms  = arton360_get_all_terms_for_taxonomy( $size_tax );

    if ( empty( $color_terms ) && empty( $size_terms ) ) {
        return;
    }

    // Convert this product to a Variable product
    $product = new WC_Product_Variable( $product_id );

    $attributes = array();
    $position   = 0;

    // Color attribute
    if ( ! empty( $color_terms ) ) {
        $attr_color = new WC_Product_Attribute();
        $attr_color->set_id( wc_attribute_taxonomy_id_by_name( $color_tax ) );
        $attr_color->set_name( $color_tax );
        $attr_color->set_options( wp_list_pluck( $color_terms, 'term_id' ) );
        $attr_color->set_position( $position++ );
        $attr_color->set_visible( true );
        $attr_color->set_variation( true );
        $attributes[] = $attr_color;

        wp_set_object_terms(
            $product_id,
            wp_list_pluck( $color_terms, 'slug' ),
            $color_tax,
            false
        );
    }

    // Size attribute
    if ( ! empty( $size_terms ) ) {
        $attr_size = new WC_Product_Attribute();
        $attr_size->set_id( wc_attribute_taxonomy_id_by_name( $size_tax ) );
        $attr_size->set_name( $size_tax );
        $attr_size->set_options( wp_list_pluck( $size_terms, 'term_id' ) );
        $attr_size->set_position( $position++ );
        $attr_size->set_visible( true );
        $attr_size->set_variation( true );
        $attributes[] = $attr_size;

        wp_set_object_terms(
            $product_id,
            wp_list_pluck( $size_terms, 'slug' ),
            $size_tax,
            false
        );
    }

    if ( empty( $attributes ) ) {
        return;
    }

    $product->set_attributes( $attributes );
    $product->set_status( 'publish' );
    $product->save();

    wp_set_object_terms( $product_id, 'variable', 'product_type', false );

    $color_slugs = ! empty( $color_terms ) ? wp_list_pluck( $color_terms, 'slug' ) : array();
    $size_slugs  = ! empty( $size_terms ) ? wp_list_pluck( $size_terms, 'slug' ) : array();

    $created        = 0;
    $max_variations = 300;

    // Color + Size combinations
    if ( ! empty( $color_slugs ) && ! empty( $size_slugs ) ) {
        foreach ( $color_slugs as $c_slug ) {
            foreach ( $size_slugs as $s_slug ) {
                if ( $created >= $max_variations ) {
                    break 2;
                }
                arton360_create_single_variation(
                    $product_id,
                    array(
                        'attribute_' . $color_tax => $c_slug,
                        'attribute_' . $size_tax  => $s_slug,
                    ),
                    $base_price
                );
                $created++;
            }
        }
    } elseif ( ! empty( $color_slugs ) ) {
        foreach ( $color_slugs as $c_slug ) {
            if ( $created >= $max_variations ) {
                break;
            }
            arton360_create_single_variation(
                $product_id,
                array(
                    'attribute_' . $color_tax => $c_slug,
                ),
                $base_price
            );
            $created++;
        }
    } elseif ( ! empty( $size_slugs ) ) {
        foreach ( $size_slugs as $s_slug ) {
            if ( $created >= $max_variations ) {
                break;
            }
            arton360_create_single_variation(
                $product_id,
                array(
                    'attribute_' . $size_tax => $s_slug,
                ),
                $base_price
            );
            $created++;
        }
    }

    // Default selection on front-end = first color/size
    $defaults = array();
    if ( ! empty( $color_slugs ) ) {
        $defaults[ $color_tax ] = reset( $color_slugs );
    }
    if ( ! empty( $size_slugs ) ) {
        $defaults[ $size_tax ] = reset( $size_slugs );
    }
    if ( ! empty( $defaults ) ) {
        $product->set_default_attributes( $defaults );
        $product->save();
    }
}

/**
 * Create a single variation for a variable product.
 */
function arton360_create_single_variation( $product_id, $attributes, $price ) {
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $product_id );
    $variation->set_status( 'publish' );
    $variation->set_regular_price( $price );
    $variation->set_manage_stock( false );
    $variation->set_virtual( false );
    $variation->set_downloadable( false );
    $variation->set_attributes( $attributes );
    $variation->save();
}

/* ======================================================
 * Helper: Save preview PNG to WP Media Library
 * ====================================================== */

function arton360_save_dataurl_image( $dataurl, $design_name, $author_id ) {
    if ( strpos( $dataurl, 'data:image' ) !== 0 ) {
        return new \WP_Error( 'bad_image', 'Invalid data URL' );
    }

    list( , $base64 ) = explode( ',', $dataurl, 2 );
    $bin = base64_decode( $base64 );
    if ( ! $bin ) {
        return new \WP_Error( 'bad_image', 'Base64 decode failed' );
    }

    $filename = sanitize_title( $design_name ) . '-' . time() . '.png';
    $upload   = wp_upload_bits( $filename, null, $bin );

    if ( ! empty( $upload['error'] ) ) {
        return new \WP_Error( 'upload_error', $upload['error'] );
    }

    $filetype   = wp_check_filetype( $upload['file'], null );
    $attachment = array(
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name( $filename ),
        'post_status'    => 'inherit',
        'post_author'    => $author_id,
    );

    $attach_id   = wp_insert_attachment( $attachment, $upload['file'] );
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    return $attach_id;
}

/* ======================================================
 * (D) Shortcode — loads iframe + sends config
 * ====================================================== */

add_shortcode(
    'arton360_designer',
    function ( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>You must be logged in to use the designer.</p>';
        }

        $vendor_id = arton360_get_current_vendor_id();
        if ( ! $vendor_id ) {
            return '<p>You must be a vendor to use the designer.</p>';
        }

        $nonce  = wp_create_nonce( 'wp_rest' );
        $origin = ARTON360_ALLOWED_ORIGIN;
        $site   = site_url();
        $api    = site_url( '/wp-json' );

        ob_start();
        ?>
        <div id="arton360-wrapper" style="height: calc(100vh - 120px);">
            <iframe
                id="arton360-iframe"
                src="https://arton360-designer-i756.vercel.app/"
                style="width:100%;height:100%;border:0;"
                allow="clipboard-write"
            ></iframe>
        </div>

        <script>
            (function () {
                const iframe = document.getElementById('arton360-iframe');
                iframe.addEventListener('load', function () {
                    const payload = {
                        type: 'ARTON360_CONFIG',
                        site: '<?php echo esc_js( $site ); ?>',
                        apiBase: '<?php echo esc_js( $api ); ?>',
                        nonce: '<?php echo esc_js( $nonce ); ?>',
                        vendorId: <?php echo intval( $vendor_id ); ?>,
                    };
                    iframe.contentWindow.postMessage(payload, '<?php echo esc_js( $origin ); ?>');
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }
);

/* ======================================================
 * (E) Add menu in Dokan Vendor Dashboard
 * ====================================================== */

add_filter(
    'dokan_get_dashboard_nav',
    function ( $menus ) {
        $menus['arton360-designer'] = array(
            'title' => __( 'Design & Publish', 'dokan' ),
            'icon'  => '<i class="fas fa-paint-brush"></i>',
            'url'   => site_url( '/design-tool/' ),
            'pos'   => 55,
        );
        return $menus;
    }
);

/* ======================================================
 * FRONTEND: COLOR-SWITCHING PREVIEW FOR ARTON360 PRODUCTS
 * ====================================================== */

/**
 * Is this product created by the Arton360 designer?
 */
function arton360_is_designer_product( $product_id ) {
    return (bool) get_post_meta( $product_id, '_arton360_canvas_json', true );
}

/**
 * Map color slug -> base T-shirt image.
 * Place PNGs here: /wp-content/plugins/arton360-designer/assets/tshirts/
 */
function arton360_get_color_image_map() {
    $base_url = plugin_dir_url( __FILE__ ) . 'assets/tshirts/';

    return array(
        'white' => $base_url . 'white.png',
        'black' => $base_url . 'black.png',
        'red'   => $base_url . 'red.png',
        'gray'  => $base_url . 'gray.png',
        'navy'  => $base_url . 'navy.png',
        // add more as needed
    );
}

/**
 * Get default color slug for a variable product.
 */
function arton360_get_default_color_slug( $product_id ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return '';
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return '';
    }

    $defaults = $product->get_default_attributes();

    foreach ( $defaults as $attr => $slug ) {
        if ( $attr === 'pa_color' ) {
            return sanitize_title( $slug );
        }
    }

    return '';
}

/**
 * Replace WooCommerce main image HTML with layered shirt+art preview
 * for Arton360 designer products.
 */
function arton360_render_designer_preview( $html, $attachment_id ) {
    if ( ! is_product() ) {
        return $html;
    }

    global $post;
    if ( ! $post ) {
        return $html;
    }

    $product_id = $post->ID;

    if ( ! arton360_is_designer_product( $product_id ) ) {
        return $html;
    }

    // Artwork image = product featured image
    $design_url = get_the_post_thumbnail_url( $product_id, 'large' );
    if ( ! $design_url ) {
        return $html;
    }

    $color_map     = arton360_get_color_image_map();
    $default_color = arton360_get_default_color_slug( $product_id );
    $default_color = $default_color ?: 'white';

    $base_img = isset( $color_map[ $default_color ] )
        ? $color_map[ $default_color ]
        : reset( $color_map );

    ob_start();
    ?>
    <div class="arton360-preview-wrapper" data-arton360-product="<?php echo esc_attr( $product_id ); ?>">
        <div class="arton360-preview-inner">
            <img
                id="arton360-shirt-base"
                class="arton360-shirt-base"
                src="<?php echo esc_url( $base_img ); ?>"
                alt=""
            />
            <img
                class="arton360-shirt-design"
                src="<?php echo esc_url( $design_url ); ?>"
                alt=""
            />
        </div>
    </div>
    <?php
    return ob_get_clean();
}
//add_filter( 'woocommerce_single_product_image_thumbnail_html', 'arton360_render_designer_preview', 10, 2 );

/**
 * Enqueue frontend JS + CSS on designer product pages.
 */
function arton360_enqueue_color_sync_script() {
    if ( ! is_product() ) {
        return;
    }

    global $post;
    if ( ! $post || ! arton360_is_designer_product( $post->ID ) ) {
        return;
    }

    // CSS for positioning
    wp_enqueue_style(
        'arton360-frontend',
        plugin_dir_url( __FILE__ ) . 'assets/arton360-frontend.css',
        array(),
        '1.0.0'
    );

    // JS that syncs swatch selection with base shirt image
    wp_enqueue_script(
        'arton360-color-sync',
        plugin_dir_url( __FILE__ ) . 'assets/js/arton360-color-sync.js',
        array( 'jquery' ),
        '1.0.0',
        true
    );

    wp_localize_script(
        'arton360-color-sync',
        'arton360ColorConfig',
        array(
            'map'         => arton360_get_color_image_map(),
            'defaultSlug' => arton360_get_default_color_slug( $post->ID ) ?: 'white',
        )
    );
}
add_action( 'wp_enqueue_scripts', 'arton360_enqueue_color_sync_script' );

add_filter('body_class', function($classes){
    if (is_product()) {
        global $post;
        if ($post && get_post_meta($post->ID, '_arton360_canvas_json', true)) {
            $classes[] = 'arton360-designer-product';
        }
    }
    return $classes;
});
