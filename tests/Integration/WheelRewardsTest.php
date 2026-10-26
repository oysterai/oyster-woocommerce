<?php
declare( strict_types=1 );
namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Webhooks\Webhook_Secret;
use Oyster\Woo\Wheel\Controller;
use Oyster\Woo\Wheel\Gift;
use Oyster\Woo\Wheel\Outbox;
use Oyster\Woo\Wheel\Provisioner;
use Oyster\Woo\Wheel\Redemptions;
use WC_Coupon;
use WC_Product_Simple;
use WP_REST_Request;
use WP_UnitTestCase;

final class WheelRewardsTest extends WP_UnitTestCase {
	private Connection $connection;
	private Provisioner $provisioner;
	private Webhook_Secret $secret;
	private array $grants = array();
	private array $products = array();
	private array $orders = array();
	private array $zones = array();
	public function set_up(): void {
		parent::set_up();
		// WordPress reinstalls posts between suite runs but leaves WooCommerce tables.
		// Old reservations, order items and zones must not attach to reused fixture IDs.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->wc_reserved_stock}" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
		$zone_ids = $wpdb->get_col( "SELECT zone_id FROM {$wpdb->prefix}woocommerce_shipping_zones WHERE zone_name = 'Wheel test delivery'" );
		foreach ( $zone_ids as $zone_id ) {
			( new \WC_Shipping_Zone( (int) $zone_id ) )->delete();
		}
		$this->connection = new Connection();
		$this->connection->save( array( 'bearer' => 'test-store-bearer', 'vendor_id' => 9201 ) );
		$this->provisioner = new Provisioner( $this->connection );
		$this->secret = new Webhook_Secret();
		$this->secret->save( 12, 'test-wheel-secret' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_enable_coupons', 'yes' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		Outbox::install();
		wc_load_cart();
		WC()->cart->empty_cart();
	}
	public function tear_down(): void {
		foreach ( $this->grants as $reward ) {
			$grant = $this->provisioner->grant( $reward );
			if ( $grant ) {
				if ( 'free_item' === $grant['terms']['type'] ) {
					$order = wc_get_order( (int) $grant['result']['external_id'] );
					if ( $order ) {
						wc_release_stock_for_order( $order );
						$order->delete( true );
					}
				} else {
					( new WC_Coupon( (int) $grant['result']['external_id'] ) )->delete( true );
				}
			}
			delete_option( $this->provisioner->key( $reward ) );
		}
		foreach ( $this->products as $product ) { $product->delete( true ); }
		foreach ( $this->orders as $order ) { $order->delete( true ); }
		foreach ( $this->zones as $zone ) { $zone->delete(); }
		global $wpdb;
		$wpdb->delete( Outbox::table(), array( 'vendor_id' => 9201 ) );
		$this->secret->clear();
		$this->connection->clear();
		WC()->cart->empty_cart();
		parent::tear_down();
	}
	private function terms( string $type = 'percentage_discount' ): array {
		$id = wp_generate_uuid4();
		$this->grants[] = $id;
		return array( 'reward_id' => $id, 'code' => 'wheel-' . $id, 'terms_hash' => hash( 'sha256', $id ), 'email' => 'winner@example.test', 'expires_at' => gmdate( DATE_ATOM, time() + DAY_IN_SECONDS ), 'usage_limit' => 1, 'type' => $type, 'value' => 10, 'currency' => 'USD', 'product_id' => null, 'variant_id' => null, 'min_order_amount' => null, 'instructions' => 'Use at checkout.', 'restrictions' => array( 'allowed_product_ids' => array(), 'excluded_product_ids' => array(), 'combines_with_discounts' => false ) );
	}
	private function product( int $stock = 2 ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Reserved reward' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '25' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_backorders( 'no' );
		$product->save();
		$this->products[] = $product;
		return $product;
	}
	public function test_privacy_erases_reward_email_and_prevents_reissuance_without_touching_other_customers(): void {
		$terms = $this->terms();
		$result = $this->provisioner->provision( $terms );
		$other = $this->terms();
		$other['email'] = 'other@example.test';
		$this->provisioner->provision( $other );
		$privacy = new \Oyster\Woo\Wheel\Privacy();
		self::assertTrue( $privacy->erase_data( 'WINNER@example.test' )['items_removed'] );
		$grant = $this->provisioner->grant( $terms['reward_id'] );
		self::assertSame( '', $grant['terms']['email'] );
		self::assertSame( '', $grant['terms']['code'] );
		self::assertTrue( $grant['erased'] );
		self::assertNull( get_post( (int) $result['external_id'] ) );
		self::assertSame( 'other@example.test', $this->provisioner->grant( $other['reward_id'] )['terms']['email'] );
		self::assertFalse( $privacy->erase_data( 'winner@example.test' )['items_removed'] );
		self::assertFalse( $this->provisioner->apply( array( 'reward_id' => $terms['reward_id'], 'claim_token' => $terms['code'], 'redemption_authority' => 'woocommerce' ) ) );
		$this->expectExceptionMessage( 'reward_unavailable' );
		$this->provisioner->provision( $terms );
	}

	public function test_privacy_clears_reserved_gift_terms_and_releases_stock(): void {
		$product = $this->product();
		$terms = $this->terms( 'free_item' );
		$terms['product_id'] = $product->get_id();
		$result = $this->provisioner->provision( $terms );
		self::assertTrue( ( new \Oyster\Woo\Wheel\Privacy() )->erase_data( $terms['email'] )['items_removed'] );
		$order = wc_get_order( (int) $result['external_id'] );
		self::assertSame( '', $order->get_meta( Provisioner::META_TERMS ) );
		self::assertSame( 'cancelled', $order->get_status() );
		self::assertNull( $this->provisioner->grant( $terms['reward_id'] )['result']['redemption_url'] );
		self::assertSame( 0, wc_get_held_stock_quantity( $product ) );
		self::assertNull( ( new Gift( $this->connection, $this->provisioner ) )->authenticated_order( $terms['reward_id'], $terms['code'] ) );
	}

	public function test_signed_provisioning_and_retry_create_exactly_one_coupon_with_original_terms(): void {
		$terms = $this->terms();
		$terms['min_order_amount'] = 30;
		$terms['restrictions']['allowed_product_ids'] = array( $this->product()->get_id() );
		$controller = new Controller( $this->connection, $this->secret, $this->provisioner );
		$request = new WP_REST_Request( 'POST', '/oyster/v1/wheel/rewards/provision' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $terms ) );
		self::assertSame( 401, $controller->provision( $request )->get_status() );
		$timestamp = time();
		$request->set_header( 'Oyster-Signature', 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), 'test-wheel-secret' ) );
		$first = $controller->provision( $request );
		self::assertSame( 200, $first->get_status() );
		self::assertSame( $first->get_data(), $controller->provision( $request )->get_data() );
		$coupon = new WC_Coupon( (int) $first->get_data()['data']['external_id'] );
		self::assertSame( 'percent', $coupon->get_discount_type() );
		self::assertSame( 1, $coupon->get_usage_limit() );
		self::assertSame( array( 'winner@example.test' ), $coupon->get_email_restrictions() );
		self::assertSame( '30', $coupon->get_minimum_amount() );
		self::assertSame( $terms['restrictions']['allowed_product_ids'], $coupon->get_product_ids() );
		self::assertSame( strtotime( $terms['expires_at'] ), $coupon->get_date_expires()->getTimestamp() );
		$changed = $terms;
		$changed['terms_hash'] = str_repeat( 'b', 64 );
		$this->expectException( \RuntimeException::class );
		$this->provisioner->provision( $changed );
	}
	public function test_claim_handoff_rejects_arbitrary_codes_and_other_vendor_rewards(): void {
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id() );
		WC()->customer->set_billing_email( 'winner@example.test' );
		$terms = $this->terms();
		$this->provisioner->provision( $terms );
		$claim = array( 'reward_id' => $terms['reward_id'], 'claim_token' => $terms['code'], 'redemption_authority' => 'woocommerce' );
		self::assertTrue( $this->provisioner->apply( $claim ) );
		$claim['claim_token'] = 'arbitrary';
		self::assertFalse( $this->provisioner->apply( $claim ) );
		$this->connection->save( array( 'bearer' => 'another-store-bearer', 'vendor_id' => 9202 ) );
		$claim['claim_token'] = $terms['code'];
		self::assertFalse( $this->provisioner->apply( $claim ) );
		$this->connection->save( array( 'bearer' => 'test-store-bearer', 'vendor_id' => 9201 ) );
	}
	public function test_rejected_checkout_retries_restore_cart_and_persisted_quantities(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'winner@example.test' ) );
		wp_set_current_user( $user_id );
		WC()->customer->set_billing_email( 'winner@example.test' );
		$product = $this->product( 20 );
		$terms = $this->terms();
		$terms['restrictions']['combines_with_discounts'] = true;
		$this->provisioner->provision( $terms );
		$existing = new WC_Coupon();
		$existing->set_code( 'existing-' . wp_generate_uuid4() );
		$existing->set_discount_type( 'percent' );
		$existing->set_amount( 5 );
		$existing->save();
		$resolve = static function () use ( $product ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array( '321' => array( 'woocommerce_product_id' => $product->get_id() ) ) ) ) );
		};
		add_filter( 'pre_http_request', $resolve );
		try {
			foreach ( array( 0, 2 ) as $initial_quantity ) {
				WC()->cart->empty_cart();
				if ( $initial_quantity ) {
					WC()->cart->add_to_cart( $product->get_id(), $initial_quantity, 0, array(), array( 'oyster_attribution' => array() ) );
					WC()->cart->apply_coupon( $existing->get_code() );
				}
				$coupons_before = WC()->cart->get_applied_coupons();
				$controller = new \Oyster\Woo\Checkout\Cart_Controller( new \Oyster\Woo\Checkout\Cart_Filler( $this->connection, new Client() ) );
				$request = new WP_REST_Request( 'POST', '/oyster-woocommerce/v1/cart/add' );
				$request->set_param( 'items', array( array( 'product_id' => 321, 'quantity' => 3 ) ) );
				$claim = array( 'reward_id' => $terms['reward_id'], 'claim_token' => 'wrong-token', 'redemption_authority' => 'woocommerce' );
				$request->set_param( 'wheel_reward', $claim );
				for ( $attempt = 0; $attempt < 2; ++$attempt ) {
					self::assertSame( 409, $controller->handle_add( $request )->get_status() );
					self::assertSame( $initial_quantity, WC()->cart->get_cart_contents_count() );
					self::assertSame( $coupons_before, WC()->cart->get_applied_coupons() );
					self::assertSame( $initial_quantity, array_sum( array_column( WC()->session->get( 'cart', array() ) ?: array(), 'quantity' ) ) );
					$persistent = get_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
					self::assertSame( $initial_quantity, array_sum( array_column( $persistent['cart'], 'quantity' ) ) );
				}
				$claim['claim_token'] = $terms['code'];
				$request->set_param( 'wheel_reward', $claim );
				self::assertSame( 200, $controller->handle_add( $request )->get_status() );
				self::assertSame( $initial_quantity + 3, WC()->cart->get_cart_contents_count() );
				self::assertTrue( WC()->cart->has_discount( $terms['code'] ) );
			}
		} finally {
			remove_filter( 'pre_http_request', $resolve );
			$existing->delete( true );
			wp_set_current_user( 0 );
		}
	}
	public function test_guest_handoff_supplies_email_and_native_checkout_still_rejects_a_different_email(): void {
		wp_set_current_user( 0 );
		WC()->customer = new \WC_Customer( 0, true );
		WC()->customer->set_billing_email( '' );
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id() );
		$terms = $this->terms();
		$result = $this->provisioner->provision( $terms );
		self::assertTrue( $this->provisioner->apply( array( 'reward_id' => $terms['reward_id'], 'claim_token' => $terms['code'], 'redemption_authority' => 'woocommerce' ) ) );
		self::assertSame( 'winner@example.test', WC()->customer->get_billing_email() );
		self::assertSame( 'winner@example.test', WC()->session->get( 'customer' )['email'] );
		$coupon = new WC_Coupon( (int) $result['external_id'] );
		self::assertSame( array( 'winner@example.test' ), $coupon->get_email_restrictions() );
		WC()->customer->set_billing_email( 'different@example.test' );
		self::assertWPError( ( new \WC_Discounts( WC()->cart ) )->is_coupon_valid( $coupon ) );
	}
	public function test_rejected_guest_reward_restores_prior_checkout_email(): void {
		wp_set_current_user( 0 );
		WC()->customer = new \WC_Customer( 0, true );
		WC()->customer->set_billing_email( 'previous@example.test' );
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id() );
		$terms = $this->terms();
		$terms['min_order_amount'] = 999999;
		$this->provisioner->provision( $terms );
		self::assertFalse( $this->provisioner->apply( array( 'reward_id' => $terms['reward_id'], 'claim_token' => $terms['code'], 'redemption_authority' => 'woocommerce' ) ) );
		self::assertSame( 'previous@example.test', WC()->customer->get_billing_email() );
		self::assertSame( 'previous@example.test', WC()->session->get( 'customer' )['email'] );
	}
	public function test_reward_for_another_account_cannot_overwrite_signed_in_customer(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'different@example.test' ) );
		wp_set_current_user( $user_id );
		WC()->customer = new \WC_Customer( $user_id, true );
		WC()->customer->set_billing_email( 'different@example.test' );
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id() );
		$terms = $this->terms();
		$this->provisioner->provision( $terms );
		try {
			self::assertFalse( $this->provisioner->apply( array( 'reward_id' => $terms['reward_id'], 'claim_token' => $terms['code'], 'redemption_authority' => 'woocommerce' ) ) );
			self::assertSame( 'different@example.test', WC()->customer->get_billing_email() );
			self::assertSame( 'different@example.test', get_userdata( $user_id )->user_email );
			self::assertSame( '', get_user_meta( $user_id, 'billing_email', true ) );
		} finally {
			wp_set_current_user( 0 );
			WC()->customer = new \WC_Customer( 0, true );
		}
	}
	public function test_gift_stock_is_reserved_before_ack_and_a_second_reward_cannot_oversell(): void {
		$product = $this->product( 1 );
		$terms = $this->terms( 'free_item' );
		$terms['product_id'] = $product->get_id();
		$result = $this->provisioner->provision( $terms );
		self::assertSame( 1, wc_get_held_stock_quantity( $product ) );
		self::assertStringContainsString( 'oyster_wheel_claim=', $result['redemption_url'] );
		self::assertSame( $result, $this->provisioner->provision( $terms ) );
		$second = $this->terms( 'free_item' );
		$second['product_id'] = $product->get_id();
		try {
			$this->provisioner->provision( $second );
			self::fail( 'The reserved last item was awarded twice.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertNull( $this->provisioner->grant( $second['reward_id'] ) );
		}
		self::assertSame( 1, wc_get_held_stock_quantity( $product ) );
	}
	public function test_gift_expiry_releases_stock_and_invalid_claim_tokens_cannot_access_it(): void {
		$terms = $this->terms( 'free_item' );
		$product = $this->product( 1 );
		$terms['product_id'] = $product->get_id();
		$result = $this->provisioner->provision( $terms );
		$gift = new Gift( $this->connection, $this->provisioner );
		self::assertNull( $gift->authenticated_order( $terms['reward_id'], 'wrong-token' ) );
		$order = $gift->authenticated_order( $terms['reward_id'], $terms['code'] );
		self::assertSame( (int) $result['external_id'], $order->get_id() );
		self::assertFalse( $gift->keep_reservation( true, $order ) );
		$order->update_meta_data( Gift::META_EXPIRY, time() - 1 );
		$order->save();
		$gift->expire( $order->get_id() );
		self::assertSame( 0, wc_get_held_stock_quantity( $product ) );
		self::assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
	}
	public function test_paid_redemptions_are_durable_idempotent_and_retry_after_network_failure(): void {
		$terms = $this->terms();
		$result = $this->provisioner->provision( $terms );
		$coupon = new WC_Coupon( (int) $result['external_id'] );
		$order = wc_create_order();
		$this->orders[] = $order;
		$order->add_product( $this->product(), 1 );
		$order->set_billing_email( $terms['email'] );
		$order->apply_coupon( $coupon );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete();
		$reporter = new Redemptions( $this->connection, new Client() );
		$reporter->on_paid( $order->get_id() );
		$reporter->on_paid( $order->get_id() );
		global $wpdb;
		$table = Outbox::table();
		self::assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE vendor_id=9201" ) );
		$failed = static fn() => new \WP_Error( 'offline', 'Offline' );
		add_filter( 'pre_http_request', $failed );
		$reporter->flush();
		remove_filter( 'pre_http_request', $failed );
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE vendor_id=9201", ARRAY_A );
		self::assertSame( '0', $row['sent_at'] );
		self::assertSame( '1', $row['attempts'] );
		$wpdb->update( $table, array( 'next_at' => 0 ), array( 'id' => $row['id'] ) );
		$calls = array();
		$success = static function ( $pre, $args, $url ) use ( &$calls ) {
			$calls[] = array( $args, $url );
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}', 'headers' => array() );
		};
		add_filter( 'pre_http_request', $success, 10, 3 );
		$reporter->flush();
		$reporter->flush();
		remove_filter( 'pre_http_request', $success, 10 );
		self::assertCount( 1, $calls );
		self::assertSame( 'Bearer test-store-bearer', $calls[0][0]['headers']['Authorization'] );
		self::assertSame( $terms['reward_id'], json_decode( $calls[0][0]['body'], true )['reward_id'] );
	}
	public function test_gift_claim_uses_only_this_stores_saved_reward_and_leaves_the_cart_alone(): void {
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id() );
		$original_cart = WC()->cart->get_cart();
		$terms = $this->terms( 'free_item' );
		$terms['product_id'] = $product->get_id();
		$result = $this->provisioner->provision( $terms );
		$controller = new Controller( $this->connection, $this->secret, $this->provisioner );
		$request = new WP_REST_Request( 'POST', '/oyster/v1/wheel/rewards/claim' );
		$request->set_header( 'Content-Type', 'application/json' );
		$claim = array( 'reward_id' => $terms['reward_id'], 'claim_token' => $terms['code'], 'redemption_authority' => 'woocommerce', 'redirect' => 'https://untrusted.example/' );
		$request->set_body( wp_json_encode( $claim ) );
		self::assertSame( $result['redemption_url'], $controller->claim( $request )->get_data()['redirect'] );
		self::assertSame( $original_cart, WC()->cart->get_cart() );
		$claim['claim_token'] = 'another-token';
		$request->set_body( wp_json_encode( $claim ) );
		self::assertSame( 404, $controller->claim( $request )->get_status() );
	}
	private function delivery_zone(): array {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Wheel test delivery' );
		$zone->add_location( 'US', 'country' );
		$zone->save();
		$this->zones[] = $zone;
		$paid = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $paid . '_settings', array( 'enabled' => 'yes', 'title' => 'Standard delivery', 'cost' => '7', 'tax_status' => 'none' ) );
		return array( $zone, $paid );
	}
	public function test_free_delivery_is_unavailable_without_native_coupon_shipping_in_every_delivery_zone(): void {
		list( $zone ) = $this->delivery_zone();
		self::assertFalse( \Oyster\Woo\Wheel\Free_Delivery::available() );
		$terms = $this->terms( 'free_delivery' );
		$terms['value'] = null;
		try {
			$this->provisioner->provision( $terms );
			self::fail( 'A free delivery reward was issued without a compatible shipping method.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertSame( 0, wc_get_coupon_id_by_code( $terms['code'] ) );
		}
		$free = $zone->add_shipping_method( 'free_shipping' );
		update_option( 'woocommerce_free_shipping_' . $free . '_settings', array( 'enabled' => 'yes', 'requires' => 'both', 'min_amount' => '50' ) );
		self::assertFalse( \Oyster\Woo\Wheel\Free_Delivery::available() );
		update_option( 'woocommerce_free_shipping_' . $free . '_settings', array( 'enabled' => 'yes', 'requires' => 'coupon' ) );
		self::assertTrue( \Oyster\Woo\Wheel\Free_Delivery::available() );
		list( $other ) = $this->delivery_zone();
		self::assertFalse( \Oyster\Woo\Wheel\Free_Delivery::available() );
	}
	public function test_native_free_delivery_waives_shipping_only_and_replay_keeps_one_coupon(): void {
		list( $zone, $paid ) = $this->delivery_zone();
		$free = $zone->add_shipping_method( 'free_shipping' );
		update_option( 'woocommerce_free_shipping_' . $free . '_settings', array( 'enabled' => 'yes', 'requires' => 'coupon' ) );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_state( 'NY' );
		WC()->customer->set_shipping_postcode( '10001' );
		WC()->customer->set_shipping_city( 'New York' );
		WC()->customer->set_shipping_address_1( '1 Main Street' );
		WC()->customer->set_billing_email( 'winner@example.test' );
		WC()->customer->set_calculated_shipping( true );
		WC()->cart->add_to_cart( $this->product()->get_id() );
		WC()->cart->calculate_totals();
		self::assertSame( 7.0, (float) WC()->cart->get_shipping_total() );
		$terms = $this->terms( 'free_delivery' );
		$terms['value'] = null;
		$terms['min_order_amount'] = 20;
		$result = $this->provisioner->provision( $terms );
		self::assertSame( $result, $this->provisioner->provision( $terms ) );
		$coupon = new WC_Coupon( (int) $result['external_id'] );
		self::assertTrue( $coupon->get_free_shipping() );
		self::assertSame( 0.0, (float) $coupon->get_amount() );
		self::assertSame( array( 'winner@example.test' ), $coupon->get_email_restrictions() );
		self::assertSame( strtotime( $terms['expires_at'] ), $coupon->get_date_expires()->getTimestamp() );
		self::assertTrue( $this->provisioner->apply( array( 'reward_id' => $terms['reward_id'], 'claim_token' => $terms['code'], 'redemption_authority' => 'woocommerce' ) ) );
		WC()->cart->calculate_totals();
		self::assertSame( 25.0, (float) WC()->cart->get_subtotal() );
		self::assertSame( 0.0, (float) WC()->cart->get_discount_total() );
		self::assertSame( 0.0, (float) WC()->cart->get_shipping_total() );
		self::assertSame( 25.0, (float) WC()->cart->get_total( 'edit' ) );
		$rates = WC()->shipping()->get_packages()[0]['rates'];
		self::assertArrayHasKey( 'free_shipping:' . $free, $rates );
		self::assertArrayNotHasKey( 'flat_rate:' . $paid, $rates );
		$order_id = WC()->checkout()->create_order( array( 'billing_email' => $terms['email'], 'payment_method' => 'cod' ) );
		self::assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->orders[] = $order;
		self::assertSame( 0.0, (float) $order->get_shipping_total() );
		self::assertSame( 25.0, (float) $order->get_total() );
		self::assertCount( 1, $order->get_items( 'coupon' ) );
		$order->payment_complete();
		$reporter = new Redemptions( $this->connection, new Client() );
		$reporter->on_paid( $order_id );
		$reporter->on_paid( $order_id );
		global $wpdb;
		self::assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Outbox::table() . ' WHERE reward_id = %s', $terms['reward_id'] ) ) );
		self::assertSame( 1, ( new WC_Coupon( $coupon->get_id() ) )->get_usage_count() );
		WC()->cart->remove_coupon( $terms['code'] );
		WC()->cart->calculate_totals();
		self::assertSame( 7.0, (float) WC()->cart->get_shipping_total() );
		$coupon->set_usage_count( 1 );
		$coupon->save();
		self::assertWPError( ( new \WC_Discounts( WC()->cart ) )->is_coupon_valid( $coupon ) );
	}
	public function test_gift_delivery_is_charged_and_a_confirmed_checkout_keeps_its_item_until_payment(): void {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Wheel test delivery' );
		$zone->add_location( 'US', 'country' );
		$zone->save();
		$this->zones[] = $zone;
		$method = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $method . '_settings', array( 'enabled' => 'yes', 'title' => 'Standard delivery', 'cost' => '7', 'tax_status' => 'none' ) );
		$free_method = $zone->add_shipping_method( 'free_shipping' );
		update_option( 'woocommerce_free_shipping_' . $free_method . '_settings', array( 'enabled' => 'yes', 'requires' => 'min_amount', 'min_amount' => '50' ) );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		$routine_product = $this->product( 4 );
		WC()->cart->add_to_cart( $routine_product->get_id(), 3 );
		WC()->cart->calculate_totals();
		$cart = WC()->cart;
		$cart_contents = $cart->get_cart_contents();
		self::assertGreaterThanOrEqual( 50, $cart->get_subtotal() );
		$product = $this->product( 1 );
		$terms = $this->terms( 'free_item' );
		$terms['product_id'] = $product->get_id();
		$result = $this->provisioner->provision( $terms );
		$order = wc_get_order( (int) $result['external_id'] );
		$gift = new Gift( $this->connection, $this->provisioner );
		$address = array( 'first_name' => 'Ada', 'last_name' => 'Winner', 'address_1' => '1 Main Street', 'address_2' => '', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US' );
		$rates = $gift->shipping_rates( $order, $address );
		self::assertArrayHasKey( 'flat_rate:' . $method, $rates );
		self::assertArrayNotHasKey( 'free_shipping:' . $free_method, $rates );
		self::assertSame( $cart, WC()->cart );
		self::assertSame( $cart_contents, WC()->cart->get_cart_contents() );
		$url = $gift->confirm( $order, $address, 'flat_rate:' . $method );
		$order = wc_get_order( $order->get_id() );
		self::assertSame( '7.00', $order->get_total() );
		self::assertFalse( $order->is_paid() );
		self::assertSame( $order->get_checkout_payment_url(), $url );
		$order->update_meta_data( Gift::META_EXPIRY, time() - 1 );
		$order->save();
		$gift->expire( $order->get_id() );
		self::assertSame( 1, wc_get_held_stock_quantity( $product ) );
		self::assertFalse( $gift->keep_reservation( true, $order ) );
		self::assertSame( array( 'pending' ), $gift->payment_statuses( array( 'pending' ), $order ) );
		self::assertSame( $url, $gift->confirm( $order, array(), '' ) );
		$order->update_status( 'failed', 'Payment declined; customer may retry.' );
		self::assertSame( array(), $gift->payment_statuses( array( 'pending', 'failed' ), $order ) );
		wc_update_product_stock( $product, 0 );
		try {
			$gift->confirm( $order, array(), '' );
			self::fail( 'A failed gift checkout resumed without available stock.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'no longer available', $e->getMessage() );
		}
		self::assertTrue( wc_get_order( $order->get_id() )->has_status( 'failed' ) );
		wc_update_product_stock( $product, 1 );
		self::assertSame( $url, $gift->confirm( $order, array(), '' ) );
		self::assertSame( 1, wc_get_held_stock_quantity( $product ) );
		self::assertSame( '7.00', wc_get_order( $order->get_id() )->get_total() );
		$order->update_status( 'on-hold', 'Awaiting bank transfer.' );
		self::assertSame( $order->get_checkout_order_received_url(), $gift->confirm( $order, array(), '' ) );
		self::assertSame( 0, wc_get_product( $product->get_id() )->get_stock_quantity() );
		$order->payment_complete();
		self::assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		self::assertSame( 0, wc_get_product( $product->get_id() )->get_stock_quantity() );
		self::assertSame( 0, wc_get_held_stock_quantity( $product ) );
	}
	public function test_virtual_gift_completes_without_shipping_and_retries_do_not_reduce_stock_twice(): void {
		$product = $this->product( 1 );
		$product->set_virtual( true );
		$product->save();
		$terms = $this->terms( 'free_item' );
		$terms['product_id'] = $product->get_id();
		$result = $this->provisioner->provision( $terms );
		$order = wc_get_order( (int) $result['external_id'] );
		$gift = new Gift( $this->connection, $this->provisioner );
		$url = $gift->confirm( $order, array( 'country' => 'US' ), '' );
		self::assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		self::assertSame( $url, $gift->confirm( $order, array(), '' ) );
		self::assertSame( 0, wc_get_product( $product->get_id() )->get_stock_quantity() );
		self::assertSame( 0, wc_get_held_stock_quantity( $product ) );
	}
	public function test_uncommitted_cached_grant_cannot_be_mistaken_for_an_issued_reward(): void {
		$terms = $this->terms();
		wp_cache_set( $this->provisioner->key( $terms['reward_id'] ), array( 'terms' => $terms, 'result' => array( 'status' => 'ready', 'external_id' => '999999' ) ), 'options' );
		self::assertNull( $this->provisioner->grant( $terms['reward_id'] ) );
		$result = $this->provisioner->provision( $terms );
		self::assertNotSame( '999999', $result['external_id'] );
		self::assertSame( $result, $this->provisioner->grant( $terms['reward_id'] )['result'] );
	}
	public function test_gift_reservations_use_the_native_database_clock_in_non_utc_stores(): void {
		global $wpdb;
		$timezone = $wpdb->get_var( 'SELECT @@session.time_zone' );
		$wpdb->query( "SET time_zone = '-05:00'" );
		try {
			$product = $this->product( 1 );
			$product->set_virtual( true );
			$product->save();
			$terms = $this->terms( 'free_item' );
			$terms['product_id'] = $product->get_id();
			$terms['expires_at'] = gmdate( DATE_ATOM, time() + HOUR_IN_SECONDS );
			$result = $this->provisioner->provision( $terms );
			self::assertSame( 1, wc_get_held_stock_quantity( $product ) );
			$order = wc_get_order( (int) $result['external_id'] );
			( new Gift( $this->connection, $this->provisioner ) )->confirm( $order, array( 'country' => 'US' ), '' );
			self::assertTrue( wc_get_order( $order->get_id() )->is_paid() );
			self::assertSame( 0, wc_get_product( $product->get_id() )->get_stock_quantity() );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET time_zone = %s', $timezone ) );
		}
	}
	public function test_native_fixed_cart_discount_enforces_amount_minimum_spend_expiry_and_usage_limit(): void {
		$terms = $this->terms( 'fixed_discount' );
		$terms['min_order_amount'] = 30;
		$result = $this->provisioner->provision( $terms );
		$coupon = new WC_Coupon( (int) $result['external_id'] );
		$order = wc_create_order();
		$this->orders[] = $order;
		$order->set_billing_email( $terms['email'] );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		self::assertWPError( ( new \WC_Discounts( $order ) )->is_coupon_valid( $coupon ) );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		self::assertTrue( $order->apply_coupon( $coupon ) );
		$order->calculate_totals();
		self::assertSame( '10', $order->get_discount_total() );
		self::assertSame( '40.00', $order->get_total() );
		$coupon->set_date_expires( time() - 1 );
		$coupon->save();
		self::assertWPError( ( new \WC_Discounts( $order ) )->is_coupon_valid( $coupon ) );
		$coupon->set_date_expires( time() + DAY_IN_SECONDS );
		$coupon->set_usage_count( 1 );
		$coupon->save();
		self::assertWPError( ( new \WC_Discounts( $order ) )->is_coupon_valid( $coupon ) );
	}
	public function test_restricted_fixed_cart_rewards_are_rejected_before_creating_a_coupon(): void {
		$terms = $this->terms( 'fixed_discount' );
		$terms['restrictions']['allowed_product_ids'] = array( $this->product()->get_id() );
		try {
			$this->provisioner->provision( $terms );
			self::fail( 'Unsupported native coupon restrictions were accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertNull( $this->provisioner->grant( $terms['reward_id'] ) );
			self::assertSame( 0, wc_get_coupon_id_by_code( $terms['code'] ) );
		}
	}
	public function test_native_percentage_discount_only_reduces_eligible_non_excluded_order_lines(): void {
		$eligible = $this->product();
		$excluded = $this->product();
		$unselected = $this->product();
		$terms = $this->terms();
		$terms['value'] = 20;
		$terms['restrictions']['allowed_product_ids'] = array( $eligible->get_id(), $excluded->get_id() );
		$terms['restrictions']['excluded_product_ids'] = array( $excluded->get_id() );
		$result = $this->provisioner->provision( $terms );
		$order = wc_create_order();
		$this->orders[] = $order;
		$order->set_billing_email( $terms['email'] );
		$eligible_item = $order->add_product( $eligible, 2 );
		$excluded_item = $order->add_product( $excluded, 1 );
		$unselected_item = $order->add_product( $unselected, 1 );
		$order->calculate_totals();
		self::assertTrue( $order->apply_coupon( new WC_Coupon( (int) $result['external_id'] ) ) );
		$order->calculate_totals();
		self::assertSame( '10', $order->get_discount_total() );
		self::assertSame( '90.00', $order->get_total() );
		self::assertSame( '40', $order->get_item( $eligible_item )->get_total() );
		self::assertSame( '25', $order->get_item( $excluded_item )->get_total() );
		self::assertSame( '25', $order->get_item( $unselected_item )->get_total() );
	}
	public function test_native_percentage_exclusion_without_allow_list_and_all_excluded_cart(): void {
		$eligible = $this->product();
		$excluded = $this->product();
		$terms = $this->terms();
		$terms['value'] = 20;
		$terms['restrictions']['excluded_product_ids'] = array( $excluded->get_id() );
		$result = $this->provisioner->provision( $terms );
		$coupon = new WC_Coupon( (int) $result['external_id'] );
		$order = wc_create_order();
		$this->orders[] = $order;
		$order->set_billing_email( $terms['email'] );
		$order->add_product( $excluded, 1 );
		$order->calculate_totals();
		self::assertWPError( $order->apply_coupon( $coupon ) );
		self::assertSame( '25.00', $order->get_total() );
		$order->add_product( $eligible, 1 );
		$order->calculate_totals();
		self::assertTrue( $order->apply_coupon( $coupon ) );
		$order->calculate_totals();
		self::assertSame( '5', $order->get_discount_total() );
		self::assertSame( '45.00', $order->get_total() );
	}
}
