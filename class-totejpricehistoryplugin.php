<?php
/**
 * Totej Price History
 *
 * @package           TotejPriceHistory
 * @author            Mattias Nording
 * @copyright         2023 Totej Media
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Totej Price History
 * Plugin URI:        https://totejmedia.se/prisinformation-widget/
 * Description:       Display a price history graph  on your product page
 * Version:           1.1.4
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * WC tested up to:   6.5.1
 * Author:            totejmedia
 * Author URI:        https://totejmedia.se/
 * Text Domain:       totej-price-history
 * License:           GPL
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 
 /**
  * Main Plugin file
  */
class TotejPriceHistoryPlugin {

	public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ), 10, 3 );
	}
	public function init() {
		add_shortcode( 'totej_price_history_widget', array( $this, 'render_price_history' ) );
		add_action( 'woocommerce_new_product', array( $this, 'totej_media_price_history_save_prices' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'totej_media_price_history_save_prices' ), 10, 1 );
		add_action( 'totej_media_price_history_cron_hook', array( $this, 'totej_price_history_cron_handler' ) );

		add_filter( 'woocommerce_product_tabs', array( $this, 'totej_add_price_history_tab' ) );
		if ( ! wp_next_scheduled( 'totej_media_price_history_cron_hook' ) ) {
			wp_schedule_event( time(), 'daily', 'totej_media_price_history_cron_hook' );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'totej_price_history_load_scripts' ) );

	}
	public function render_price_history( $atts ) {

		$wporg_atts = shortcode_atts(
			array(
				'product_id' => null,
			),
			$atts
		);
		ob_start();

		$this->totej_price_history_tab_callback( $wporg_atts['product_id'], true );
		$out1 = ob_get_contents();
		ob_end_clean();

		return $out1;
	}
	function totej_price_history_load_scripts( $alternative_product_id = null, $force = false, ) {

		wp_enqueue_script( 'chart_js_lib', plugins_url( 'js/chart_js_lib.js', __FILE__ ), array(), '1.0', true );
			wp_enqueue_script( 'chart_js_init', plugins_url( 'js/chart_init.js', __FILE__ ), array(), '1.0', true );

			global $post;

		if ( is_product() || has_shortcode( $post->post_content, 'totej_price_history_widget' ) ) {

			$product = wc_get_product( $post->ID );
			if ( $alternative_product_id ) {

				$product = wc_get_product( $alternative_product_id );

			}
			if ( $product ) {
				if ( $this->should_totej_price_history_be_hidden_for_product( $product ) ) {
					return;
				}
			} else {
				return;
			}

			$price_array = array();
			$dates_array = array();
			foreach ( $this->get_price_history_for_product( $product->get_id() ) as $historic_price ) {
				$price_array[] = $historic_price->price;
				$dates_array[] = $historic_price->date;
			}

			wp_add_inline_script( 'chart_js_init', 'const labels = ' . wp_json_encode( $dates_array ), 'before' );

			wp_add_inline_script( 'chart_js_init', 'const totej_label_text = "' . esc_html__( 'Historic Price', 'totej-price-history' ) . '"', 'before' );
			wp_add_inline_script( 'chart_js_init', 'const  totej_prices_array = ' . wp_json_encode( $price_array ), 'before' );
			wp_add_inline_script( 'chart_js_init', 'const  totej_prices_base_color = "' . esc_html( get_theme_mod( 'color_primary' ) ) . '"', 'before' );

		}

	}
	function totej_media_price_history_save_prices( $product_id ) {
		$product = wc_get_product( $product_id );

		$this->save_product_price( $product );
	}
	private function should_totej_price_history_be_hidden_for_product( $product ) {
		if ( get_post_meta( $product->get_id(), '_totej_price_history_hide_tab' ) ) {
			return true;
		}
		$product_category_ids = $product->get_category_ids();
		$ignored_categories   = get_option( 'totej_price_history_ignored_categories' );
		if ( $ignored_categories ) {
			foreach ( $product_category_ids as $product_category ) {
				if ( in_array( $product_category, $ignored_categories ) ) {
					return true;
				}
			}
		}

		return false;
	}
	/**
	 * Render new widget for tabs.
	 *
	 * @param Array $tabs The tabs to be rendered on the product page.
	 *
	 * @return Array The items to be rendered
	 */
	public function totej_add_price_history_tab( $tabs ) {
		global $product;
		// First we check if we actually should show the price history for this product
		if ( $this->should_totej_price_history_be_hidden_for_product( $product ) ) {
			return $tabs;
		}

		global $post;

		if ( ! has_shortcode( $post->post_content, 'totej_price_history_widget' ) ) {

			$tabs['totej_price_widget_tab'] = array(
				'title'    => __( 'Price history', 'woocommerce' ),
				'priority' => 50,
				'callback' => array( $this, 'totej_price_history_tab_callback' ),
			);
		}
		return $tabs;
	}
	public function get_price_history_for_product( $product_id ) {
		$results = wp_cache_get( 'totej_media_prices_for_' . $product_id, 'totej-media-price-history' );
		if ( ! $results ) {
			global $wpdb;

			$tablename    = $wpdb->prefix . 'totej_price_history';
			$query_string = $wpdb->prepare(
				'SELECT MIN(price) as price,DATE(date) as date FROM  %1$s WHERE `productid` =  %2$d  GROUP BY UNIX_TIMESTAMP(date) DIV 600  ORDER BY date ASC LIMIT 30',
				array( $tablename, $product_id )
			);

			$results = $wpdb->get_results( $query_string, OBJECT );

			if ( $wpdb->last_error ) {
				echo 'wpdb error: ' . esc_html( $wpdb->last_error );
			}
			wp_cache_set( 'totej_media_prices_for_' . $product_id, $results, 'totej-media-price-history', 3600 * 24 );

		}
		return $results;
	}
	// The new tab content
	function totej_price_history_tab_callback( $alternative_product_id = false ) {

		?>
		<div class="totej-media-tabs-container">
		
		<div class="totej-media-chart-container" >
  <canvas id="myChart" ></canvas>
</div>
</div>
		<?php
	}
	public function save_product_price( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
				$this->add_timestamps_to_db( $product->get_id(), $variation->get_price(), $variation->get_variation_id() );
			}
		} else {
			$this->add_timestamps_to_db( $product->get_id(), $product->get_price() );
		}
		wp_cache_delete( 'totej_media_prices_for_' . $product->get_id(), 'totej-media-price-history' );
	}
	public function totej_price_history_cron_handler() {
		$query    = new WC_Product_Query(
			array(
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$products = $query->get_products();
		$logger   = wc_get_logger();
		$logger->debug( 'got number of products ' . count( $products ), array( 'source' => 'totej-price-history' ) );
		foreach ( $products as $product ) {
			$this->save_product_price( $product );
		}

	}
	/**
	 *
	 */
	public function add_timestamps_to_db( $product_id, $cost, $variation_id = null ) {
		global $wpdb;
		$tablename = $wpdb->prefix . 'totej_price_history';
		$wpdb->insert(
			$tablename,
			array(
				'productid'   => $product_id,
				'variationid' => $variation_id,
				'price'       => $cost,
			),
			array( '%d', '%d', '%f' )
		);
	}
	function create_the_custom_table() {

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_name = $wpdb->prefix . 'totej_price_history';

		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table_name . " (
        id int(11) NOT NULL AUTO_INCREMENT,
        productid int(11) NOT NULL,
        variationid int(11) NULL,
        price DECIMAL(5,2) NOT NULL,
        date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}


}
$totej_price_plugin = new TotejPriceHistoryPlugin();
register_activation_hook( __FILE__, array( $totej_price_plugin, 'create_the_custom_table' ) );
