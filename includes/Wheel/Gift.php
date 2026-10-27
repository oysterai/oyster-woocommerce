<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;

use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStockException;
use Oyster\Woo\Support\Connection;
use WC_Order;
use WC_Order_Item_Shipping;
defined( 'ABSPATH' ) || exit;

final class Gift {
	public const META_EXPIRY = '_oyster_wheel_expiry';
	public const META_CONFIRMED = '_oyster_wheel_claimed';
	public function __construct( private Connection $connection, private Provisioner $provisioner ) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'claim_page' ), 5 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( $this, 'keep_reservation' ), 10, 2 );
		add_action( 'oyster_wheel_expire_gift', array( $this, 'expire' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'payment_statuses' ), 10, 2 );
	}

	public function reserve( array $terms ): array {
		$product = wc_get_product( (int) ( ( $terms['variant_id'] ?? null ) ?: $terms['product_id'] ) );
		if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || ! $product->is_purchasable()
			|| ! $product->is_in_stock() || ! $product->managing_stock() || $product->backorders_allowed()
			|| ( $product->is_type( 'variation' ) && $product->get_parent_id() !== (int) $terms['product_id'] )
			|| 'yes' !== get_option( 'woocommerce_manage_stock', 'yes' ) ) {
			throw new \InvalidArgumentException( 'reward_unavailable' );
		}
		$order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'oyster-wheel' ) );
		if ( ! $order instanceof WC_Order ) {
			throw new \RuntimeException( 'gift_order_failed' );
		}
		$order->set_currency( get_woocommerce_currency() );
		$order->set_billing_email( strtolower( $terms['email'] ) );
		$order->add_product( $product, 1, array( 'subtotal' => 0, 'total' => 0 ) );
		$order->update_meta_data( Provisioner::META_REWARD, $terms['reward_id'] );
		$order->update_meta_data( Provisioner::META_TERMS, $terms );
		$order->update_meta_data( Provisioner::META_VENDOR, $this->connection->vendor_id() );
		$order->update_meta_data( self::META_EXPIRY, strtotime( $terms['expires_at'] ) );
		$order->update_meta_data( Redemptions::META_NEEDED, 'yes' );
		$order->calculate_totals();
		$order->save();
		try {
			( new ReserveStock() )->reserve_stock_for_order( $order, max( 1, (int) ceil( ( strtotime( $terms['expires_at'] ) - time() ) / 60 ) ) );
		} catch ( ReserveStockException $e ) {
			throw new \InvalidArgumentException( 'reward_unavailable', 0, $e );
		}
		// Verify the API really reserved stock; stores can disable it through configuration.
		global $wpdb;
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT stock_quantity FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d AND product_id = %d AND expires > NOW()", $order->get_id(), $product->get_stock_managed_by_id() ) );
		if ( 1 !== (int) $held ) {
			throw new \InvalidArgumentException( 'reward_unavailable' );
		}
		wp_schedule_single_event( strtotime( $terms['expires_at'] ), 'oyster_wheel_expire_gift', array( $order->get_id() ) );
		return array(
			'status' => 'ready', 'external_id' => (string) $order->get_id(),
			'redemption_url' => add_query_arg( array( 'oyster_wheel_claim' => $terms['reward_id'], 'token' => $terms['code'] ), home_url( '/' ) ),
		);
	}

	public function keep_reservation( bool $cancel, WC_Order $order ): bool {
		return $order->get_meta( Provisioner::META_REWARD ) && ( $order->get_meta( self::META_CONFIRMED ) || (int) $order->get_meta( self::META_EXPIRY ) > time() ) ? false : $cancel;
	}

	public function payment_statuses( array $statuses, WC_Order $order ): array {
		// Failed gifts must return through the claim link to reacquire their stock first.
		if ( $order->get_meta( Provisioner::META_REWARD ) && $order->has_status( 'failed' ) ) {
			return array();
		}
		return $order->get_meta( Provisioner::META_REWARD ) && ! $order->get_meta( self::META_CONFIRMED ) && (int) $order->get_meta( self::META_EXPIRY ) <= time() ? array() : $statuses;
	}

	public function expire( int $order_id ): void {
		Lock::run( 'gift-' . $order_id, function () use ( $order_id ): void {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && $order->get_meta( Provisioner::META_REWARD ) && ! $order->get_meta( self::META_CONFIRMED ) && ! $order->is_paid() && (int) $order->get_meta( self::META_EXPIRY ) <= time() ) {
				( new ReserveStock() )->release_stock_for_order( $order );
				$order->update_status( 'cancelled', __( 'The reserved reward expired before checkout completed.', 'oyster-woocommerce' ) );
			}
		} );
	}

	public function authenticated_order( string $reward_id, string $token ): ?WC_Order {
		$grant = $this->provisioner->grant( $reward_id );
		if ( ! $grant || ! empty( $grant['erased'] ) || 'free_item' !== $grant['terms']['type'] || ! hash_equals( $grant['terms']['code'], $token ) ) {
			return null;
		}
		$order = wc_get_order( (int) $grant['result']['external_id'] );
		return $order instanceof WC_Order && $reward_id === $order->get_meta( Provisioner::META_REWARD ) ? $order : null;
	}

	/** Rates are recomputed from store shipping methods for the submitted destination. */
	public function shipping_rates( WC_Order $order, array $address ): array {
		wc_load_cart();
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}
			$product = clone $product;
			$product->set_price( 0 );
			$items[ $item->get_id() ] = array( 'key' => (string) $item->get_id(), 'data' => $product, 'quantity' => 1, 'line_total' => 0, 'line_subtotal' => 0, 'line_tax' => 0, 'line_subtotal_tax' => 0, 'product_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), 'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0 );
		}
		if ( ! $items ) {
			return array();
		}
		// Native methods such as minimum-spend free shipping also read the cart.
		// Isolate those reads from routine products that are not part of this order.
		$cart = WC()->cart;
		$customer = WC()->customer;
		$shipping = WC()->shipping();
		$shipping_methods = $shipping->shipping_methods;
		WC()->cart = clone $cart;
		WC()->customer = clone $customer;
		try {
			WC()->cart->set_cart_contents( $items );
			WC()->cart->set_applied_coupons( array() );
			WC()->cart->set_totals( array() );
			foreach ( array( 'country', 'state', 'postcode', 'city', 'address_1', 'address_2' ) as $key ) {
				WC()->customer->{'set_shipping_' . $key}( $address[ $key ] ?? '' );
			}
			// A separate cache key preserves the customer's normal cart delivery quote.
			$package = $shipping->calculate_shipping_for_package( array( 'contents' => $items, 'contents_cost' => 0, 'applied_coupons' => array(), 'user' => array( 'ID' => 0 ), 'destination' => $address ), 'oyster_wheel_' . $order->get_id() );
			return $package['rates'] ?? array();
		} finally {
			WC()->cart = $cart;
			WC()->customer = $customer;
			$shipping->shipping_methods = $shipping_methods;
		}
	}

	public function confirm( WC_Order $order, array $address, string $rate_id ): string {
		return Lock::run( 'gift-' . $order->get_id(), function () use ( $order, $address, $rate_id ): string {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order instanceof WC_Order || ( ! $order->has_status( array( 'pending', 'on-hold', 'processing', 'completed' ) )
				&& ! ( $order->has_status( 'failed' ) && $order->get_meta( self::META_CONFIRMED ) ) ) ) {
				throw new \RuntimeException( 'This reward has expired or is unavailable.' );
			}
			if ( $order->is_paid() ) {
				return $order->get_checkout_order_received_url();
			}
			if ( $order->get_meta( self::META_CONFIRMED ) ) {
				if ( $order->has_status( 'failed' ) ) {
					$this->resume_failed_payment( $order );
				}
				return $this->checkout_url( $order );
			}
			if ( (int) $order->get_meta( self::META_EXPIRY ) <= time() ) {
				throw new \RuntimeException( 'This reward has expired or is unavailable.' );
			}
			foreach ( $order->get_items() as $item ) {
				if ( ! $item->get_product() ) {
					throw new \RuntimeException( 'This reward is unavailable. Please contact the store.' );
				}
			}
			$rates = $this->shipping_rates( $order, $address );
			$needs_shipping = false;
			foreach ( $order->get_items() as $item ) {
				$needs_shipping = $needs_shipping || ( $item->get_product() && $item->get_product()->needs_shipping() );
			}
			if ( $needs_shipping && ! isset( $rates[ $rate_id ] ) ) {
				throw new \RuntimeException( 'Choose an available delivery method.' );
			}
			$order->set_address( $address, 'shipping' );
			$order->set_address( $address + array( 'email' => $order->get_billing_email() ), 'billing' );
			$order->remove_order_items( 'shipping' );
			if ( $needs_shipping ) {
				$shipping = new WC_Order_Item_Shipping();
				$shipping->set_shipping_rate( $rates[ $rate_id ] );
				$order->add_item( $shipping );
			}
			$order->calculate_totals();
			// Once delivery is accepted, a late payment must not lose its reserved item.
			// WooCommerce releases this hold on payment or explicit cancellation.
			global $wpdb;
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Unable to confirm this reward. Please try again.' );
			}
			try {
				if ( (int) $order->get_meta( self::META_EXPIRY ) <= time() || 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->wc_reserved_stock} SET expires = '9999-12-31 23:59:59' WHERE order_id = %d AND expires > NOW()", $order->get_id() ) ) ) {
					throw new \RuntimeException( 'This reward reservation is unavailable. Please contact the store.' );
				}
				$order->update_meta_data( self::META_CONFIRMED, time() );
				$order->save();
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'Unable to confirm this reward. Please try again.' );
				}
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			return $this->checkout_url( $order );
		} );
	}

	private function resume_failed_payment( WC_Order $order ): void {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Unable to resume this reward. Please try again.' );
		}
		try {
			// WooCommerce does not count failed orders in held stock; reacquire before charging.
			( new ReserveStock() )->reserve_stock_for_order( $order, 30 );
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->wc_reserved_stock} SET expires = '9999-12-31 23:59:59' WHERE order_id = %d AND expires > NOW()", $order->get_id() ) ) ) {
				throw new \RuntimeException( 'This reward reservation is unavailable. Please contact the store.' );
			}
			$order->update_status( 'pending', __( 'Resuming the original reward payment after a failed attempt.', 'oyster-woocommerce' ) );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Unable to resume this reward. Please try again.' );
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $e instanceof ReserveStockException ) {
				throw new \RuntimeException( 'This reward item is no longer available. Please contact the store.', 0, $e );
			}
			throw $e;
		}
	}

	private function checkout_url( WC_Order $order ): string {
		if ( ! $order->is_paid() && (float) $order->get_total() <= 0 ) {
			$order->payment_complete();
		}
		return $order->needs_payment() ? $order->get_checkout_payment_url() : $order->get_checkout_order_received_url();
	}

	public function claim_page(): void {
		if ( ! isset( $_GET['oyster_wheel_claim'] ) ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$reward_id = sanitize_text_field( wp_unslash( $_GET['oyster_wheel_claim'] ) );
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$order = $this->authenticated_order( $reward_id, $token );
		if ( ! $order || ( ! $order->get_meta( self::META_CONFIRMED ) && ( (int) $order->get_meta( self::META_EXPIRY ) <= time() || $order->has_status( 'failed' ) ) ) || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			wp_die( esc_html__( 'This reward link is unavailable or expired.', 'oyster-woocommerce' ), '', array( 'response' => 404 ) );
		}
		if ( $order->is_paid() || $order->get_meta( self::META_CONFIRMED ) ) {
			wp_safe_redirect( $this->confirm( $order, array(), '' ) );
			exit;
		}
		$address = array();
		$rates = array();
		$error = '';
		$needs_shipping = false;
		foreach ( $order->get_items() as $item ) {
			$needs_shipping = $needs_shipping || ( $item->get_product() && $item->get_product()->needs_shipping() );
		}
		$countries = $needs_shipping ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			try {
				if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'oyster-gift-' . $reward_id ) ) {
					throw new \RuntimeException( 'Reload this page and try again.' );
				}
				foreach ( array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $key ) {
					$address[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
				}
				if ( ! isset( $countries[ $address['country'] ] ) ) {
					throw new \RuntimeException( 'Choose a country this store delivers to.' );
				}
				$states = WC()->countries->get_states( $address['country'] );
				if ( is_array( $states ) && $states && ! isset( $states[ $address['state'] ] ) ) {
					$state = array_search( strtolower( $address['state'] ), array_map( 'strtolower', $states ), true );
					if ( false === $state ) {
						throw new \RuntimeException( 'Enter a valid state or region for the selected country.' );
					}
					$address['state'] = $state;
				}
				foreach ( WC()->countries->get_address_fields( $address['country'], '' ) as $key => $field ) {
					if ( ! empty( $field['required'] ) && empty( $address[ $key ] ) && ! in_array( $key, array( 'email' ), true ) ) {
						throw new \RuntimeException( 'Complete all required address fields.' );
					}
				}
				if ( '' !== $address['postcode'] && ! \WC_Validation::is_postcode( $address['postcode'], $address['country'] ) ) {
					throw new \RuntimeException( 'Enter a valid postal code.' );
				}
				$rates = $this->shipping_rates( $order, $address );
				if ( isset( $_POST['confirm'] ) ) {
					$url = $this->confirm( $order, $address, sanitize_text_field( wp_unslash( $_POST['rate'] ?? '' ) ) );
					wp_safe_redirect( $url );
					exit;
				}
			} catch ( \Throwable $e ) {
				$error = $e instanceof \RuntimeException ? $e->getMessage() : __( 'Unable to prepare delivery. Please try again.', 'oyster-woocommerce' );
			}
		}
		$this->render( $order, $address, $rates, $countries, $error, $reward_id );
		exit;
	}

	private function render( WC_Order $order, array $address, array $rates, array $countries, string $error, string $reward_id ): void {
		$needs_shipping = false;
		foreach ( $order->get_items() as $item ) {
			$needs_shipping = $needs_shipping || ( $item->get_product() && $item->get_product()->needs_shipping() );
		}
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html__( 'Claim your reward', 'oyster-woocommerce' ); ?></title><style>body{font:16px system-ui,sans-serif;background:#f7f7f5;color:#20251f;margin:0;padding:24px}main{max-width:580px;margin:32px auto;background:white;padding:28px;border-radius:16px}label{display:block;margin:16px 0 6px}input,select,button{font:inherit;box-sizing:border-box;max-width:100%}input:not([type=radio]),select{width:100%;padding:10px;border:1px solid #aaa;border-radius:6px}button{padding:12px 18px;margin-top:20px;background:#253c30;color:white;border:0;border-radius:6px}fieldset{margin-top:24px;border:1px solid #ddd}p{line-height:1.5}.error{color:#a52424}</style></head><body><main>
		<h1><?php echo esc_html__( 'Claim your reward', 'oyster-woocommerce' ); ?></h1>
		<p><?php echo esc_html__( 'Your item is free. Normal delivery charges and applicable delivery tax are shown before you continue to checkout.', 'oyster-woocommerce' ); ?></p>
		<?php foreach ( $order->get_items() as $item ) : ?><p><strong><?php echo esc_html( $item->get_name() ); ?></strong> &times; 1</p><?php endforeach; ?>
		<?php if ( $error ) : ?><p role="alert" class="error"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<form method="post"><?php wp_nonce_field( 'oyster-gift-' . $reward_id ); ?>
		<?php foreach ( array( 'first_name' => 'First name', 'last_name' => 'Last name', 'address_1' => 'Street address', 'address_2' => 'Apartment or suite (optional)', 'city' => 'City', 'state' => 'State / region', 'postcode' => 'Postal code', 'phone' => 'Phone (optional)' ) as $key => $label ) : ?>
		<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><input id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $address[ $key ] ?? '' ); ?>" maxlength="150">
		<?php endforeach; ?>
		<label for="country"><?php echo esc_html__( 'Country', 'oyster-woocommerce' ); ?></label><select id="country" name="country" required><option value="">Select country</option><?php foreach ( $countries as $code => $name ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $address['country'] ?? '', $code ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select>
		<button type="submit"><?php echo esc_html__( 'Calculate delivery', 'oyster-woocommerce' ); ?></button>
		<?php if ( $rates ) : ?><fieldset><legend><?php echo esc_html__( 'Delivery', 'oyster-woocommerce' ); ?></legend><?php foreach ( $rates as $id => $rate ) : ?><label><input type="radio" name="rate" value="<?php echo esc_attr( $id ); ?>" required> <?php echo esc_html( $rate->get_label() ); ?>: <?php echo wp_kses_post( wc_price( (float) $rate->get_cost() + array_sum( $rate->get_taxes() ), array( 'currency' => $order->get_currency() ) ) ); ?></label><?php endforeach; ?></fieldset><button type="submit" name="confirm" value="1"><?php echo esc_html__( 'Continue to checkout', 'oyster-woocommerce' ); ?></button>
		<?php elseif ( $address && ! $error && ! $needs_shipping ) : ?><button type="submit" name="confirm" value="1"><?php echo esc_html__( 'Confirm your reward', 'oyster-woocommerce' ); ?></button>
		<?php elseif ( $address && ! $error ) : ?><p><?php echo esc_html__( 'No delivery methods are available for this address. Please contact the store.', 'oyster-woocommerce' ); ?></p><?php endif; ?>
		</form></main></body></html><?php
	}
}
