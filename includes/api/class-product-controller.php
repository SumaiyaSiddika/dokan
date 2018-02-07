<?php

/**
* Store API Controller
*
* @package dokan
*
* @author weDevs <info@wedevs.com>
*/
class Dokan_Product_Controller extends WP_REST_Controller {


    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'dokan/v1';

    /**
     * Route name
     *
     * @var string
     */
    protected $base = 'products';

    /**
     * Post type
     *
     * @var string
     */
    protected $post_type = 'product';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function register_routes() {
         register_rest_route( $this->namespace, '/' . $this->base, array(
            'args' => array(
                'id' => array(
                    'description' => __( 'Unique identifier for the object.' ),
                    'type'        => 'integer',
                ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_products' ),
                'args'                => $this->get_collection_params(),
                'permission_callback' => array( $this, 'get_product_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_product' ),
                'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
                'permission_callback' => array( $this, 'create_product_permissions_check' ),
            ),
            'schema' => array( $this, 'get_public_item_schema' ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->base . '/(?P<product_id>[\d]+)/', array(
            'args' => array(
                'id' => array(
                    'description' => __( 'Unique identifier for the object.' ),
                    'type'        => 'integer',
                ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_product' ),
                'args'                => $this->get_collection_params(),
                'permission_callback' => array( $this, 'get_single_product_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_product' ),
                'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                'permission_callback' => array( $this, 'update_product_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_product' ),
                'permission_callback' => array( $this, 'delete_product_permissions_check' ),
            )
        ) );
    }

    /**
     * Get all products
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function get_products( $request, $args = array() ) {
        $store_id = dokan_get_current_user_id();

        if ( empty( $store_id ) ) {
            return new WP_Error( 'no_store_found', __( 'No seller found' ), array( 'status' => 404 ) );
        }

        $default = array(
            'fields'         => 'ids',
            'posts_per_page' => $request['per_page'],
            'paged'          => $request['page'],
            'author'         => $store_id,
            'post_status'    => array( 'publish', 'pending', 'draft' )
        );

        $args = wp_parse_args( $args, $default );

        $product_ids = dokan()->product->all( $args );

        if ( empty( $product_ids->posts ) ) {
            return new WP_Error( 'no_products_found', __( 'No Products found' ), array( 'status' => 404 ) );
        }

        $data = array();
        foreach ( $product_ids->posts as $product_id ) {
            $data[] = $this->get_product_data( wc_get_product( $product_id ) );
        }

        $response = rest_ensure_response( $data );
        $response = $this->format_collection_response( $response, $request, $product_ids->found_posts );

        return $response;
    }

    /**
     * Get single product
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function get_product( $request ) {
        $store_id = dokan_get_current_user_id();

        if ( empty( $store_id ) ) {
            return new WP_Error( 'no_store_found', __( 'No seller found' ), array( 'status' => 404 ) );
        }

        $product_id = $request['product_id'];

        if ( empty( $product_id ) ) {
            return new WP_Error( 'no_product_found', __( 'No product found' ), array( 'status' => 404 ) );
        }

        $product = wc_get_product( $product_id );

        if ( empty( $product ) ) {
            return new WP_Error( 'no_product', __( 'No product found' ), array( 'status' => 404 ) );
        }

        $data     = $this->get_product_data( $product );
        $response = rest_ensure_response( $data );

        return $response;
    }

    /**
     * Create product
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function create_product( $request ) {
        $params   = $request->get_params();
        $store_id = $params['id'];

        if ( empty( $store_id ) ) {
            return new WP_Error( 'no_store_found', __( 'No seller found' ), array( 'status' => 404 ) );
        }

        if ( ! empty( $request['product_id'] ) ) {
            return new WP_Error( "woocommerce_rest_{$this->post_type}_exists", sprintf( __( 'Cannot create existing %s.', 'dokan-lite' ), 'product' ), array( 'status' => 400 ) );
        }

        if ( empty( $request['name'] ) ) {
            return new WP_Error( "dokan_product_no_title_found", sprintf( __( 'Product title must be required', 'dokan-lite' ), 'product' ), array( 'status' => 404 ) );
        }

        $category_selection = dokan_get_option( 'product_category_style', 'dokan_selling', 'single' );

        if ( empty( $request['categories'] ) ) {
            return new WP_Error( "dokan_product_category", __( 'Category must be required', 'dokan-lite' ), array( 'status' => 404 ) );
        }

        if (  'single' == $category_selection ) {
            if ( count( $request['categories'] ) > 1  ) {
                return new WP_Error( "dokan_product_category_no_more_one", __( 'You can not select more than category', 'dokan-lite' ), array( 'status' => 404 ) );
            }
        }

        try {
            $object = $this->prepare_object_for_database( $request, true );

            if ( is_wp_error( $object ) ) {
                return $object;
            }

            $object->save();

            // Update post author
            wp_update_post( array( 'ID' => $object->get_id(), 'post_author' => $store_id ) );

            $product = wc_get_product( $object->get_id() );
            return $this->get_product_data( $product );
        } catch ( WC_Data_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
        } catch ( WC_REST_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
        }
    }

    /**
     * Update product
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function update_product( $request ) {
        $store_id = dokan_get_current_user_id();

        if ( empty( $store_id ) ) {
            return new WP_Error( 'no_store_found', __( 'No seller found' ), array( 'status' => 404 ) );
        }

        $object = wc_get_product( (int) $request['product_id'] );

        if ( ! $object || 0 === $object->get_id() ) {
            return new WP_Error( "dokan_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'dokan-lite' ), array( 'status' => 400 ) );
        }

        $product_author = get_post_field( 'post_author', $object->get_id() );

        if ( $store_id != $product_author ) {
            return new WP_Error( "dokan_rest_{$this->post_type}_invalid_id", __( 'Sorry, you have no permission to do this. Since it\'s not your product.', 'dokan-lite' ), array( 'status' => 400 ) );
        }

        try {
            $object = $this->prepare_object_for_database( $request, false );

            if ( is_wp_error( $object ) ) {
                return $object;
            }

            $object->save();

            $this->update_additional_fields_for_object( $object, $request );

            return $this->get_product_data( $object );
        } catch ( WC_Data_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
        } catch ( WC_REST_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
        }
    }

    /**
     * Delete product
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function delete_product( $request ) {
        $store_id = dokan_get_current_user_id();
        $object   = wc_get_product( (int) $request['product_id'] );
        $result   = false;

        if ( ! $object || 0 === $object->get_id() ) {
            return new WP_Error( "dokan_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce' ), array( 'status' => 404 ) );
        }

        $product_author = get_post_field( 'post_author', $object->get_id() );

        if ( $store_id != $product_author ) {
            return new WP_Error( "dokan_rest_{$this->post_type}_invalid_id", __( 'Sorry, you have no permission to do this. Since it\'s not your product.', 'dokan-lite' ), array( 'status' => 400 ) );
        }

        $data     = $this->get_product_data( $object );
        $response = rest_ensure_response( $data );

        // If we're forcing, then delete permanently.
        $object->delete( true );
        $result = 0 === $object->get_id();

        if ( ! $result ) {
            return new WP_Error( 'dokan_rest_cannot_delete', sprintf( __( 'The %s cannot be deleted.', 'dokan-lite' ), $this->post_type ), array( 'status' => 500 ) );
        }

        do_action( "dokan_rest_delete_{$this->post_type}_object", $object, $response, $request );

        return $response;
    }

    /**
     * get_product_permissions_check
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function get_product_permissions_check() {
        return current_user_can( 'dokan_view_product_menu' );
    }

    /**
     * create_product_permissions_check
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function create_product_permissions_check() {
        return current_user_can( 'dokan_add_product' );
    }

    /**
     * get_single_product_permissions_check
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function get_single_product_permissions_check() {
        return current_user_can( 'dokandar' );
    }

    /**
     * update_product_permissions_check
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function update_product_permissions_check() {
        return current_user_can( 'dokan_edit_product' );
    }

    /**
     * Delete product permission checking
     *
     * @since 2.8.0
     *
     * @return void
     */
    public function delete_product_permissions_check() {
        return current_user_can( 'dokan_delete_product' );
    }

    /**
     * Get product data.
     *
     * @param WC_Product $product Product instance.
     * @param string     $context Request context.
     *                            Options: 'view' and 'edit'.
     * @return array
     */
    protected function get_product_data( $product, $context = 'view' ) {
        $data = array(
            'id'                    => $product->get_id(),
            'name'                  => $product->get_name( $context ),
            'slug'                  => $product->get_slug( $context ),
            'post_author'           => get_post_field( 'post_author', $product->get_id() ),
            'permalink'             => $product->get_permalink(),
            'date_created'          => wc_rest_prepare_date_response( $product->get_date_created( $context ), false ),
            'date_created_gmt'      => wc_rest_prepare_date_response( $product->get_date_created( $context ) ),
            'date_modified'         => wc_rest_prepare_date_response( $product->get_date_modified( $context ), false ),
            'date_modified_gmt'     => wc_rest_prepare_date_response( $product->get_date_modified( $context ) ),
            'type'                  => $product->get_type(),
            'status'                => $product->get_status( $context ),
            'featured'              => $product->is_featured(),
            'catalog_visibility'    => $product->get_catalog_visibility( $context ),
            'description'           => 'view' === $context ? wpautop( do_shortcode( $product->get_description() ) ) : $product->get_description( $context ),
            'short_description'     => 'view' === $context ? apply_filters( 'woocommerce_short_description', $product->get_short_description() ) : $product->get_short_description( $context ),
            'sku'                   => $product->get_sku( $context ),
            'price'                 => $product->get_price( $context ),
            'regular_price'         => $product->get_regular_price( $context ),
            'sale_price'            => $product->get_sale_price( $context ) ? $product->get_sale_price( $context ) : '',
            'date_on_sale_from'     => wc_rest_prepare_date_response( $product->get_date_on_sale_from( $context ), false ),
            'date_on_sale_from_gmt' => wc_rest_prepare_date_response( $product->get_date_on_sale_from( $context ) ),
            'date_on_sale_to'       => wc_rest_prepare_date_response( $product->get_date_on_sale_to( $context ), false ),
            'date_on_sale_to_gmt'   => wc_rest_prepare_date_response( $product->get_date_on_sale_to( $context ) ),
            'price_html'            => $product->get_price_html(),
            'on_sale'               => $product->is_on_sale( $context ),
            'purchasable'           => $product->is_purchasable(),
            'total_sales'           => $product->get_total_sales( $context ),
            'virtual'               => $product->is_virtual(),
            'downloadable'          => $product->is_downloadable(),
            'downloads'             => $this->get_downloads( $product ),
            'download_limit'        => $product->get_download_limit( $context ),
            'download_expiry'       => $product->get_download_expiry( $context ),
            'external_url'          => $product->is_type( 'external' ) ? $product->get_product_url( $context ) : '',
            'button_text'           => $product->is_type( 'external' ) ? $product->get_button_text( $context ) : '',
            'tax_status'            => $product->get_tax_status( $context ),
            'tax_class'             => $product->get_tax_class( $context ),
            'manage_stock'          => $product->managing_stock(),
            'stock_quantity'        => $product->get_stock_quantity( $context ),
            'in_stock'              => $product->is_in_stock(),
            'backorders'            => $product->get_backorders( $context ),
            'backorders_allowed'    => $product->backorders_allowed(),
            'backordered'           => $product->is_on_backorder(),
            'sold_individually'     => $product->is_sold_individually(),
            'weight'                => $product->get_weight( $context ),
            'dimensions'            => array(
                'length' => $product->get_length( $context ),
                'width'  => $product->get_width( $context ),
                'height' => $product->get_height( $context ),
            ),
            'shipping_required'     => $product->needs_shipping(),
            'shipping_taxable'      => $product->is_shipping_taxable(),
            'shipping_class'        => $product->get_shipping_class(),
            'shipping_class_id'     => $product->get_shipping_class_id( $context ),
            'reviews_allowed'       => $product->get_reviews_allowed( $context ),
            'average_rating'        => 'view' === $context ? wc_format_decimal( $product->get_average_rating(), 2 ) : $product->get_average_rating( $context ),
            'rating_count'          => $product->get_rating_count(),
            'related_ids'           => array_map( 'absint', array_values( wc_get_related_products( $product->get_id() ) ) ),
            'upsell_ids'            => array_map( 'absint', $product->get_upsell_ids( $context ) ),
            'cross_sell_ids'        => array_map( 'absint', $product->get_cross_sell_ids( $context ) ),
            'parent_id'             => $product->get_parent_id( $context ),
            'purchase_note'         => 'view' === $context ? wpautop( do_shortcode( wp_kses_post( $product->get_purchase_note() ) ) ) : $product->get_purchase_note( $context ),
            'categories'            => $this->get_taxonomy_terms( $product ),
            'tags'                  => $this->get_taxonomy_terms( $product, 'tag' ),
            'images'                => $this->get_images( $product ),
            'attributes'            => $this->get_attributes( $product ),
            'default_attributes'    => $this->get_default_attributes( $product ),
            'variations'            => array(),
            'grouped_products'      => array(),
            'menu_order'            => $product->get_menu_order( $context ),
            'meta_data'             => $product->get_meta_data(),
        );

        return $data;
    }

    /**
     * Format item's collection for response
     *
     * @param  object $response
     * @param  object $request
     * @param  array $items
     * @param  int $total_items
     *
     * @return object
     */
    public function format_collection_response( $response, $request, $total_items ) {
        if ( $total_items === 0 ) {
            return $response;
        }

        // Store pagation values for headers then unset for count query.
        $per_page = (int) ( ! empty( $request['per_page'] ) ? $request['per_page'] : 20 );
        $page     = (int) ( ! empty( $request['page'] ) ? $request['page'] : 1 );

        $response->header( 'X-WP-Total', (int) $total_items );

        $max_pages = ceil( $total_items / $per_page );

        $response->header( 'X-WP-TotalPages', (int) $max_pages );
        $base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );

        if ( $page > 1 ) {
            $prev_page = $page - 1;
            if ( $prev_page > $max_pages ) {
                $prev_page = $max_pages;
            }
            $prev_link = add_query_arg( 'page', $prev_page, $base );
            $response->link_header( 'prev', $prev_link );
        }
        if ( $max_pages > $page ) {

            $next_page = $page + 1;
            $next_link = add_query_arg( 'page', $next_page, $base );
            $response->link_header( 'next', $next_link );
        }

        return $response;
    }

    /**
     * Get taxonomy terms.
     *
     * @param WC_Product $product  Product instance.
     * @param string     $taxonomy Taxonomy slug.
     * @return array
     */
    protected function get_taxonomy_terms( $product, $taxonomy = 'cat' ) {
        $terms = array();

        foreach ( wc_get_object_terms( $product->get_id(), 'product_' . $taxonomy ) as $term ) {
            $terms[] = array(
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            );
        }

        return $terms;
    }

    /**
     * Get the images for a product or product variation.
     *
     * @param WC_Product|WC_Product_Variation $product Product instance.
     * @return array
     */
    protected function get_images( $product ) {
        $images = array();
        $attachment_ids = array();

        // Add featured image.
        if ( has_post_thumbnail( $product->get_id() ) ) {
            $attachment_ids[] = $product->get_image_id();
        }

        // Add gallery images.
        $attachment_ids = array_merge( $attachment_ids, $product->get_gallery_image_ids() );

        // Build image data.
        foreach ( $attachment_ids as $position => $attachment_id ) {
            $attachment_post = get_post( $attachment_id );
            if ( is_null( $attachment_post ) ) {
                continue;
            }

            $attachment = wp_get_attachment_image_src( $attachment_id, 'full' );
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $images[] = array(
                'id'                => (int) $attachment_id,
                'date_created'      => wc_rest_prepare_date_response( $attachment_post->post_date, false ),
                'date_created_gmt'  => wc_rest_prepare_date_response( strtotime( $attachment_post->post_date_gmt ) ),
                'date_modified'     => wc_rest_prepare_date_response( $attachment_post->post_modified, false ),
                'date_modified_gmt' => wc_rest_prepare_date_response( strtotime( $attachment_post->post_modified_gmt ) ),
                'src'               => current( $attachment ),
                'name'              => get_the_title( $attachment_id ),
                'alt'               => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
                'position'          => (int) $position,
            );
        }

        // Set a placeholder image if the product has no images set.
        if ( empty( $images ) ) {
            $images[] = array(
                'id'                => 0,
                'date_created'      => wc_rest_prepare_date_response( current_time( 'mysql' ), false ), // Default to now.
                'date_created_gmt'  => wc_rest_prepare_date_response( current_time( 'timestamp', true ) ), // Default to now.
                'date_modified'     => wc_rest_prepare_date_response( current_time( 'mysql' ), false ),
                'date_modified_gmt' => wc_rest_prepare_date_response( current_time( 'timestamp', true ) ),
                'src'               => wc_placeholder_img_src(),
                'name'              => __( 'Placeholder', 'dokan-lite' ),
                'alt'               => __( 'Placeholder', 'dokan-lite' ),
                'position'          => 0,
            );
        }

        return $images;
    }

    /**
     * Get attribute taxonomy label.
     *
     * @deprecated 2.8.0
     *
     * @param  string $name Taxonomy name.
     * @return string
     */
    protected function get_attribute_taxonomy_label( $name ) {
        $tax    = get_taxonomy( $name );
        $labels = get_taxonomy_labels( $tax );

        return $labels->singular_name;
    }

    /**
     * Get product attribute taxonomy name.
     *
     * @since  2.8.0
     * @param  string     $slug    Taxonomy name.
     * @param  WC_Product $product Product data.
     * @return string
     */
    protected function get_attribute_taxonomy_name( $slug, $product ) {
        $attributes = $product->get_attributes();

        if ( ! isset( $attributes[ $slug ] ) ) {
            return str_replace( 'pa_', '', $slug );
        }

        $attribute = $attributes[ $slug ];

        // Taxonomy attribute name.
        if ( $attribute->is_taxonomy() ) {
            $taxonomy = $attribute->get_taxonomy_object();
            return $taxonomy->attribute_label;
        }

        // Custom product attribute name.
        return $attribute->get_name();
    }

    /**
     * Get default attributes.
     *
     * @param WC_Product $product Product instance.
     * @return array
     */
    protected function get_default_attributes( $product ) {
        $default = array();

        if ( $product->is_type( 'variable' ) ) {
            foreach ( array_filter( (array) $product->get_default_attributes(), 'strlen' ) as $key => $value ) {
                if ( 0 === strpos( $key, 'pa_' ) ) {
                    $default[] = array(
                        'id'     => wc_attribute_taxonomy_id_by_name( $key ),
                        'name'   => $this->get_attribute_taxonomy_name( $key, $product ),
                        'option' => $value,
                    );
                } else {
                    $default[] = array(
                        'id'     => 0,
                        'name'   => $this->get_attribute_taxonomy_name( $key, $product ),
                        'option' => $value,
                    );
                }
            }
        }

        return $default;
    }

    /**
     * Get attribute options.
     *
     * @param int   $product_id Product ID.
     * @param array $attribute  Attribute data.
     * @return array
     */
    protected function get_attribute_options( $product_id, $attribute ) {
        if ( isset( $attribute['is_taxonomy'] ) && $attribute['is_taxonomy'] ) {
            return wc_get_product_terms( $product_id, $attribute['name'], array(
                'fields' => 'names',
            ) );
        } elseif ( isset( $attribute['value'] ) ) {
            return array_map( 'trim', explode( '|', $attribute['value'] ) );
        }

        return array();
    }

    /**
     * Get the attributes for a product or product variation.
     *
     * @param WC_Product|WC_Product_Variation $product Product instance.
     * @return array
     */
    protected function get_attributes( $product ) {
        $attributes = array();

        if ( $product->is_type( 'variation' ) ) {
            $_product = wc_get_product( $product->get_parent_id() );
            foreach ( $product->get_variation_attributes() as $attribute_name => $attribute ) {
                $name = str_replace( 'attribute_', '', $attribute_name );

                if ( ! $attribute ) {
                    continue;
                }

                // Taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`.
                if ( 0 === strpos( $attribute_name, 'attribute_pa_' ) ) {
                    $option_term = get_term_by( 'slug', $attribute, $name );
                    $attributes[] = array(
                        'id'     => wc_attribute_taxonomy_id_by_name( $name ),
                        'name'   => $this->get_attribute_taxonomy_name( $name, $_product ),
                        'option' => $option_term && ! is_wp_error( $option_term ) ? $option_term->name : $attribute,
                    );
                } else {
                    $attributes[] = array(
                        'id'     => 0,
                        'name'   => $this->get_attribute_taxonomy_name( $name, $_product ),
                        'option' => $attribute,
                    );
                }
            }
        } else {
            foreach ( $product->get_attributes() as $attribute ) {
                $attributes[] = array(
                    'id'        => $attribute['is_taxonomy'] ? wc_attribute_taxonomy_id_by_name( $attribute['name'] ) : 0,
                    'name'      => $this->get_attribute_taxonomy_name( $attribute['name'], $product ),
                    'position'  => (int) $attribute['position'],
                    'visible'   => (bool) $attribute['is_visible'],
                    'variation' => (bool) $attribute['is_variation'],
                    'options'   => $this->get_attribute_options( $product->get_id(), $attribute ),
                );
            }
        }

        return $attributes;
    }

    /**
     * Get the downloads for a product or product variation.
     *
     * @param WC_Product|WC_Product_Variation $product Product instance.
     * @return array
     */
    protected function get_downloads( $product ) {
        $downloads = array();

        if ( $product->is_downloadable() ) {
            foreach ( $product->get_downloads() as $file_id => $file ) {
                $downloads[] = array(
                    'id'   => $file_id, // MD5 hash.
                    'name' => $file['name'],
                    'file' => $file['file'],
                );
            }
        }

        return $downloads;
    }

        /**
     * Prepare a single product for create or update.
     *
     * @param  WP_REST_Request $request Request object.
     * @param  bool            $creating If is creating a new object.
     * @return WP_Error|WC_Data
     */

    /**
     * Prepare object for database mapping
     *
     * @param objec  $request
     * @param boolean $creating
     *
     * @return object
     */
    protected function prepare_object_for_database( $request, $creating = false ) {
        $id = isset( $request['product_id'] ) ? absint( $request['product_id'] ) : 0;

        // Type is the most important part here because we need to be using the correct class and methods.
        if ( isset( $request['type'] ) ) {
            $classname = WC_Product_Factory::get_classname_from_product_type( $request['type'] );

            if ( ! class_exists( $classname ) ) {
                $classname = 'WC_Product_Simple';
            }

            $product = new $classname( $id );
        } elseif ( isset( $request['product_id'] ) ) {
            $product = wc_get_product( $id );
        } else {
            $product = new WC_Product_Simple();
        }

        if ( 'variation' === $product->get_type() ) {
            return new WP_Error( "woocommerce_rest_invalid_{$this->post_type}_id", __( 'To manipulate product variations you should use the /products/&lt;product_id&gt;/variations/&lt;id&gt; endpoint.', 'dokan-lite' ), array(
                'status' => 404,
            ) );
        }

        // Post title.
        if ( isset( $request['name'] ) ) {
            $product->set_name( wp_filter_post_kses( $request['name'] ) );
        }

        // Post content.
        if ( isset( $request['description'] ) ) {
            $product->set_description( wp_filter_post_kses( $request['description'] ) );
        }

        // Post excerpt.
        if ( isset( $request['short_description'] ) ) {
            $product->set_short_description( wp_filter_post_kses( $request['short_description'] ) );
        }

        // Post status.
        if ( isset( $request['status'] ) ) {
            $product->set_status( get_post_status_object( $request['status'] ) ? $request['status'] : 'draft' );
        }

        // Post slug.
        if ( isset( $request['slug'] ) ) {
            $product->set_slug( $request['slug'] );
        }

        // Menu order.
        if ( isset( $request['menu_order'] ) ) {
            $product->set_menu_order( $request['menu_order'] );
        }

        // Comment status.
        if ( isset( $request['reviews_allowed'] ) ) {
            $product->set_reviews_allowed( $request['reviews_allowed'] );
        }

        // Virtual.
        if ( isset( $request['virtual'] ) ) {
            $product->set_virtual( $request['virtual'] );
        }

        // Tax status.
        if ( isset( $request['tax_status'] ) ) {
            $product->set_tax_status( $request['tax_status'] );
        }

        // Tax Class.
        if ( isset( $request['tax_class'] ) ) {
            $product->set_tax_class( $request['tax_class'] );
        }

        // Catalog Visibility.
        if ( isset( $request['catalog_visibility'] ) ) {
            $product->set_catalog_visibility( $request['catalog_visibility'] );
        }

        // Purchase Note.
        if ( isset( $request['purchase_note'] ) ) {
            $product->set_purchase_note( wc_clean( $request['purchase_note'] ) );
        }

        // Featured Product.
        if ( isset( $request['featured'] ) ) {
            $product->set_featured( $request['featured'] );
        }

        // Shipping data.
        $product = $this->save_product_shipping_data( $product, $request );

        // SKU.
        if ( isset( $request['sku'] ) ) {
            $product->set_sku( wc_clean( $request['sku'] ) );
        }

        // Attributes.
        if ( isset( $request['attributes'] ) ) {
            $attributes = array();

            foreach ( $request['attributes'] as $attribute ) {
                $attribute_id   = 0;
                $attribute_name = '';

                // Check ID for global attributes or name for product attributes.
                if ( ! empty( $attribute['id'] ) ) {
                    $attribute_id   = absint( $attribute['id'] );
                    $attribute_name = wc_attribute_taxonomy_name_by_id( $attribute_id );
                } elseif ( ! empty( $attribute['name'] ) ) {
                    $attribute_name = wc_clean( $attribute['name'] );
                }

                if ( ! $attribute_id && ! $attribute_name ) {
                    continue;
                }

                if ( $attribute_id ) {

                    if ( isset( $attribute['options'] ) ) {
                        $options = $attribute['options'];

                        if ( ! is_array( $attribute['options'] ) ) {
                            // Text based attributes - Posted values are term names.
                            $options = explode( WC_DELIMITER, $options );
                        }

                        $values = array_map( 'wc_sanitize_term_text_based', $options );
                        $values = array_filter( $values, 'strlen' );
                    } else {
                        $values = array();
                    }

                    if ( ! empty( $values ) ) {
                        // Add attribute to array, but don't set values.
                        $attribute_object = new WC_Product_Attribute();
                        $attribute_object->set_id( $attribute_id );
                        $attribute_object->set_name( $attribute_name );
                        $attribute_object->set_options( $values );
                        $attribute_object->set_position( isset( $attribute['position'] ) ? (string) absint( $attribute['position'] ) : '0' );
                        $attribute_object->set_visible( ( isset( $attribute['visible'] ) && $attribute['visible'] ) ? 1 : 0 );
                        $attribute_object->set_variation( ( isset( $attribute['variation'] ) && $attribute['variation'] ) ? 1 : 0 );
                        $attributes[] = $attribute_object;
                    }
                } elseif ( isset( $attribute['options'] ) ) {
                    // Custom attribute - Add attribute to array and set the values.
                    if ( is_array( $attribute['options'] ) ) {
                        $values = $attribute['options'];
                    } else {
                        $values = explode( WC_DELIMITER, $attribute['options'] );
                    }
                    $attribute_object = new WC_Product_Attribute();
                    $attribute_object->set_name( $attribute_name );
                    $attribute_object->set_options( $values );
                    $attribute_object->set_position( isset( $attribute['position'] ) ? (string) absint( $attribute['position'] ) : '0' );
                    $attribute_object->set_visible( ( isset( $attribute['visible'] ) && $attribute['visible'] ) ? 1 : 0 );
                    $attribute_object->set_variation( ( isset( $attribute['variation'] ) && $attribute['variation'] ) ? 1 : 0 );
                    $attributes[] = $attribute_object;
                }
            }
            $product->set_attributes( $attributes );
        }

        // Sales and prices.
        if ( in_array( $product->get_type(), array( 'variable', 'grouped' ), true ) ) {
            $product->set_regular_price( '' );
            $product->set_sale_price( '' );
            $product->set_date_on_sale_to( '' );
            $product->set_date_on_sale_from( '' );
            $product->set_price( '' );
        } else {
            // Regular Price.
            if ( isset( $request['regular_price'] ) ) {
                $product->set_regular_price( $request['regular_price'] );
            }

            // Sale Price.
            if ( isset( $request['sale_price'] ) ) {
                $product->set_sale_price( $request['sale_price'] );
            }

            if ( isset( $request['date_on_sale_from'] ) ) {
                $product->set_date_on_sale_from( $request['date_on_sale_from'] );
            }

            if ( isset( $request['date_on_sale_from_gmt'] ) ) {
                $product->set_date_on_sale_from( $request['date_on_sale_from_gmt'] ? strtotime( $request['date_on_sale_from_gmt'] ) : null );
            }

            if ( isset( $request['date_on_sale_to'] ) ) {
                $product->set_date_on_sale_to( $request['date_on_sale_to'] );
            }

            if ( isset( $request['date_on_sale_to_gmt'] ) ) {
                $product->set_date_on_sale_to( $request['date_on_sale_to_gmt'] ? strtotime( $request['date_on_sale_to_gmt'] ) : null );
            }
        }

        // Product parent ID for groups.
        if ( isset( $request['parent_id'] ) ) {
            $product->set_parent_id( $request['parent_id'] );
        }

        // Sold individually.
        if ( isset( $request['sold_individually'] ) ) {
            $product->set_sold_individually( $request['sold_individually'] );
        }

        // Stock status.
        if ( isset( $request['in_stock'] ) ) {
            $stock_status = true === $request['in_stock'] ? 'instock' : 'outofstock';
        } else {
            $stock_status = $product->get_stock_status();
        }

        // Stock data.
        if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
            // Manage stock.
            if ( isset( $request['manage_stock'] ) ) {
                $product->set_manage_stock( $request['manage_stock'] );
            }

            // Backorders.
            if ( isset( $request['backorders'] ) ) {
                $product->set_backorders( $request['backorders'] );
            }

            if ( $product->is_type( 'grouped' ) ) {
                $product->set_manage_stock( 'no' );
                $product->set_backorders( 'no' );
                $product->set_stock_quantity( '' );
                $product->set_stock_status( $stock_status );
            } elseif ( $product->is_type( 'external' ) ) {
                $product->set_manage_stock( 'no' );
                $product->set_backorders( 'no' );
                $product->set_stock_quantity( '' );
                $product->set_stock_status( 'instock' );
            } elseif ( $product->get_manage_stock() ) {
                // Stock status is always determined by children so sync later.
                if ( ! $product->is_type( 'variable' ) ) {
                    $product->set_stock_status( $stock_status );
                }

                // Stock quantity.
                if ( isset( $request['stock_quantity'] ) ) {
                    $product->set_stock_quantity( wc_stock_amount( $request['stock_quantity'] ) );
                } elseif ( isset( $request['inventory_delta'] ) ) {
                    $stock_quantity  = wc_stock_amount( $product->get_stock_quantity() );
                    $stock_quantity += wc_stock_amount( $request['inventory_delta'] );
                    $product->set_stock_quantity( wc_stock_amount( $stock_quantity ) );
                }
            } else {
                // Don't manage stock.
                $product->set_manage_stock( 'no' );
                $product->set_stock_quantity( '' );
                $product->set_stock_status( $stock_status );
            }
        } elseif ( ! $product->is_type( 'variable' ) ) {
            $product->set_stock_status( $stock_status );
        }

        // Upsells.
        if ( isset( $request['upsell_ids'] ) ) {
            $upsells = array();
            $ids     = $request['upsell_ids'];

            if ( ! empty( $ids ) ) {
                foreach ( $ids as $id ) {
                    if ( $id && $id > 0 ) {
                        $upsells[] = $id;
                    }
                }
            }

            $product->set_upsell_ids( $upsells );
        }

        // Cross sells.
        if ( isset( $request['cross_sell_ids'] ) ) {
            $crosssells = array();
            $ids        = $request['cross_sell_ids'];

            if ( ! empty( $ids ) ) {
                foreach ( $ids as $id ) {
                    if ( $id && $id > 0 ) {
                        $crosssells[] = $id;
                    }
                }
            }

            $product->set_cross_sell_ids( $crosssells );
        }

        // Product categories.
        if ( isset( $request['categories'] ) && is_array( $request['categories'] ) ) {
            $product = $this->save_taxonomy_terms( $product, $request['categories'] );
        }

        // Product tags.
        if ( isset( $request['tags'] ) && is_array( $request['tags'] ) ) {
            $product = $this->save_taxonomy_terms( $product, $request['tags'], 'tag' );
        }

        // Downloadable.
        if ( isset( $request['downloadable'] ) ) {
            $product->set_downloadable( $request['downloadable'] );
        }

        // Downloadable options.
        if ( $product->get_downloadable() ) {

            // Downloadable files.
            if ( isset( $request['downloads'] ) && is_array( $request['downloads'] ) ) {
                $product = $this->save_downloadable_files( $product, $request['downloads'] );
            }

            // Download limit.
            if ( isset( $request['download_limit'] ) ) {
                $product->set_download_limit( $request['download_limit'] );
            }

            // Download expiry.
            if ( isset( $request['download_expiry'] ) ) {
                $product->set_download_expiry( $request['download_expiry'] );
            }
        }

        // Product url and button text for external products.
        if ( $product->is_type( 'external' ) ) {
            if ( isset( $request['external_url'] ) ) {
                $product->set_product_url( $request['external_url'] );
            }

            if ( isset( $request['button_text'] ) ) {
                $product->set_button_text( $request['button_text'] );
            }
        }

        // Save default attributes for variable products.
        if ( $product->is_type( 'variable' ) ) {
            $product = $this->save_default_attributes( $product, $request );
        }

        // Set children for a grouped product.
        if ( $product->is_type( 'grouped' ) && isset( $request['grouped_products'] ) ) {
            $product->set_children( $request['grouped_products'] );
        }

        // Check for featured/gallery images, upload it and set it.
        if ( isset( $request['images'] ) ) {
            $product = $this->set_product_images( $product, $request['images'] );
        }

        // Allow set meta_data.
        if ( is_array( $request['meta_data'] ) ) {
            foreach ( $request['meta_data'] as $meta ) {
                $product->update_meta_data( $meta['key'], $meta['value'], isset( $meta['id'] ) ? $meta['id'] : '' );
            }
        }

        /**
         * Filters an object before it is inserted via the REST API.
         *
         * The dynamic portion of the hook name, `$this->post_type`,
         * refers to the object type slug.
         *
         * @param WC_Data         $product  Object object.
         * @param WP_REST_Request $request  Request object.
         * @param bool            $creating If is creating a new object.
         */
        return apply_filters( "woocommerce_rest_pre_insert_{$this->post_type}_object", $product, $request, $creating );
    }

    /**
     * Set product images.
     *
     * @throws WC_REST_Exception REST API exceptions.
     * @param WC_Product $product Product instance.
     * @param array      $images  Images data.
     * @return WC_Product
     */
    protected function set_product_images( $product, $images ) {
        $images = is_array( $images ) ? array_filter( $images ) : array();

        if ( ! empty( $images ) ) {
            $gallery = array();

            foreach ( $images as $image ) {
                $attachment_id = isset( $image['id'] ) ? absint( $image['id'] ) : 0;

                if ( 0 === $attachment_id && isset( $image['src'] ) ) {
                    $upload = wc_rest_upload_image_from_url( esc_url_raw( $image['src'] ) );

                    if ( is_wp_error( $upload ) ) {
                        if ( ! apply_filters( 'woocommerce_rest_suppress_image_upload_error', false, $upload, $product->get_id(), $images ) ) {
                            throw new WC_REST_Exception( 'woocommerce_product_image_upload_error', $upload->get_error_message(), 400 );
                        } else {
                            continue;
                        }
                    }

                    $attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload, $product->get_id() );
                }

                if ( ! wp_attachment_is_image( $attachment_id ) ) {
                    /* translators: %s: attachment id */
                    throw new WC_REST_Exception( 'woocommerce_product_invalid_image_id', sprintf( __( '#%s is an invalid image ID.', 'woocommerce' ), $attachment_id ), 400 );
                }

                if ( isset( $image['position'] ) && 0 === absint( $image['position'] ) ) {
                    $product->set_image_id( $attachment_id );
                } else {
                    $gallery[] = $attachment_id;
                }

                // Set the image alt if present.
                if ( ! empty( $image['alt'] ) ) {
                    update_post_meta( $attachment_id, '_wp_attachment_image_alt', wc_clean( $image['alt'] ) );
                }

                // Set the image name if present.
                if ( ! empty( $image['name'] ) ) {
                    wp_update_post( array(
                        'ID'         => $attachment_id,
                        'post_title' => $image['name'],
                    ) );
                }

                // Set the image source if present, for future reference.
                if ( ! empty( $image['src'] ) ) {
                    update_post_meta( $attachment_id, '_wc_attachment_source', esc_url_raw( $image['src'] ) );
                }
            }

            $product->set_gallery_image_ids( $gallery );
        } else {
            $product->set_image_id( '' );
            $product->set_gallery_image_ids( array() );
        }

        return $product;
    }

    /**
     * Save product shipping data.
     *
     * @param WC_Product $product Product instance.
     * @param array      $data    Shipping data.
     * @return WC_Product
     */
    protected function save_product_shipping_data( $product, $data ) {
        // Virtual.
        if ( isset( $data['virtual'] ) && true === $data['virtual'] ) {
            $product->set_weight( '' );
            $product->set_height( '' );
            $product->set_length( '' );
            $product->set_width( '' );
        } else {
            if ( isset( $data['weight'] ) ) {
                $product->set_weight( $data['weight'] );
            }

            // Height.
            if ( isset( $data['dimensions']['height'] ) ) {
                $product->set_height( $data['dimensions']['height'] );
            }

            // Width.
            if ( isset( $data['dimensions']['width'] ) ) {
                $product->set_width( $data['dimensions']['width'] );
            }

            // Length.
            if ( isset( $data['dimensions']['length'] ) ) {
                $product->set_length( $data['dimensions']['length'] );
            }
        }

        // Shipping class.
        if ( isset( $data['shipping_class'] ) ) {
            $data_store        = $product->get_data_store();
            $shipping_class_id = $data_store->get_shipping_class_id_by_slug( wc_clean( $data['shipping_class'] ) );
            $product->set_shipping_class_id( $shipping_class_id );
        }

        return $product;
    }

    /**
     * Save downloadable files.
     *
     * @param WC_Product $product    Product instance.
     * @param array      $downloads  Downloads data.
     * @param int        $deprecated Deprecated since 3.0.
     * @return WC_Product
     */
    protected function save_downloadable_files( $product, $downloads, $deprecated = 0 ) {
        if ( $deprecated ) {
            wc_deprecated_argument( 'variation_id', '3.0', 'save_downloadable_files() not requires a variation_id anymore.' );
        }

        $files = array();
        foreach ( $downloads as $key => $file ) {
            if ( empty( $file['file'] ) ) {
                continue;
            }

            $download = new WC_Product_Download();
            $download->set_id( $key );
            $download->set_name( $file['name'] ? $file['name'] : wc_get_filename_from_url( $file['file'] ) );
            $download->set_file( apply_filters( 'woocommerce_file_download_path', $file['file'], $product, $key ) );
            $files[]  = $download;
        }
        $product->set_downloads( $files );

        return $product;
    }

    /**
     * Save taxonomy terms.
     *
     * @param WC_Product $product  Product instance.
     * @param array      $terms    Terms data.
     * @param string     $taxonomy Taxonomy name.
     * @return WC_Product
     */
    protected function save_taxonomy_terms( $product, $terms, $taxonomy = 'cat' ) {
        $term_ids = wp_list_pluck( $terms, 'id' );

        if ( 'cat' === $taxonomy ) {
            $product->set_category_ids( $term_ids );
        } elseif ( 'tag' === $taxonomy ) {
            $product->set_tag_ids( $term_ids );
        }

        return $product;
    }

    /**
     * Save default attributes.
     *
     * @since 3.0.0
     *
     * @param WC_Product      $product Product instance.
     * @param WP_REST_Request $request Request data.
     * @return WC_Product
     */
    protected function save_default_attributes( $product, $request ) {
        if ( isset( $request['default_attributes'] ) && is_array( $request['default_attributes'] ) ) {

            $attributes         = $product->get_attributes();
            $default_attributes = array();

            foreach ( $request['default_attributes'] as $attribute ) {
                $attribute_id   = 0;
                $attribute_name = '';

                // Check ID for global attributes or name for product attributes.
                if ( ! empty( $attribute['id'] ) ) {
                    $attribute_id   = absint( $attribute['id'] );
                    $attribute_name = wc_attribute_taxonomy_name_by_id( $attribute_id );
                } elseif ( ! empty( $attribute['name'] ) ) {
                    $attribute_name = sanitize_title( $attribute['name'] );
                }

                if ( ! $attribute_id && ! $attribute_name ) {
                    continue;
                }

                if ( isset( $attributes[ $attribute_name ] ) ) {
                    $_attribute = $attributes[ $attribute_name ];

                    if ( $_attribute['is_variation'] ) {
                        $value = isset( $attribute['option'] ) ? wc_clean( stripslashes( $attribute['option'] ) ) : '';

                        if ( ! empty( $_attribute['is_taxonomy'] ) ) {
                            // If dealing with a taxonomy, we need to get the slug from the name posted to the API.
                            $term = get_term_by( 'name', $value, $attribute_name );

                            if ( $term && ! is_wp_error( $term ) ) {
                                $value = $term->slug;
                            } else {
                                $value = sanitize_title( $value );
                            }
                        }

                        if ( $value ) {
                            $default_attributes[ $attribute_name ] = $value;
                        }
                    }
                }
            }

            $product->set_default_attributes( $default_attributes );
        }

        return $product;
    }
}