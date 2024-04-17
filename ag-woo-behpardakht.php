<?php
/*
* Plugin Name: ag-woo-behpardakht
* Plugin URI: https://agarousi.com
* Description: درگاه پرداخت به پرداخت ملت برای ووکامرس (نسخه فول و کامل)
* Tags: به پرداخت ملت , بانک ملت , بانک ملت برای ووکامرس , درگاه پرداخت بانک ملت برای افزونه ووکامرس , mellat , ‌behpardakht
* Author: Amirhossein Garousi
* Author URI: https://agarousi.com
* Version: 1.0
*/
if ( ! defined( 'ABSPATH' ) ) { die; }  // Cannot access directly.

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;

/*
* This action hook registers our PHP class as a WooCommerce payment gateway
*/
add_filter( 'woocommerce_payment_gateways', 'ga_behpardakht_add_gateway_class' );
function ga_behpardakht_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_AG_Behpardakht'; // your class name is here
	return $gateways;
}

/*
* The class itself, please note that it is inside plugins_loaded action hook
*/
add_action( 'plugins_loaded', 'ag_behpardakht_gateway_class' );
function ag_behpardakht_gateway_class() {

	class WC_AG_Behpardakht extends WC_Payment_Gateway {

		private $paymentConfig;
		private $payment;
		private $payaneh;
		private $username;
		private $password;
		private $failedMassage;
		private $successMassage;

 		/**
		 * Class constructor, more about it in Step 3
		*/
 		public function __construct() {

			$this->id = 'WC_AG_Behpardakht';
			$this->method_title = __('پرداخت امن به پرداخت ملت', 'woocommerce');
			$this->method_description = __('تنظیمات درگاه پرداخت به پرداخت ملت برای افزونه فروشگاه ساز ووکامرس', 'woocommerce');
			$this->icon = plugin_dir_url( __FILE__ ) . 'assets/images/logo.png';
			$this->has_fields = false;

			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];

			$this->payaneh = $this->settings['payaneh'];
			$this->username = $this->settings['username'];
			$this->password = $this->settings['password'];

			$this->successMassage = $this->settings['success_massage'];
			$this->failedMassage = $this->settings['failed_massage'];

			$this->paymentConfig = require(plugin_dir_path(__FILE__) . 'payment.php');
			$this->payment = new Payment($this->paymentConfig);

			// This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// We need custom JavaScript to obtain a token
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			add_action('woocommerce_receipt_' . $this->id . '', array($this, 'Send_to_AG_Behpardakht_Gateway'));
			add_action('woocommerce_api_' . strtolower(get_class($this)) . '', array($this, 'Return_from_AG_Behpardakht_Gateway'));
 		}

		/**
 		 * Plugin options, we deal with it in Step 3 too
 		 */
 		public function init_form_fields(){

			$this->form_fields = apply_filters(
				'WC_AG_Behpardakht_Config',
				array(
					'base_config' => array(
						'title' => __('تنظیمات پایه ای', 'woocommerce'),
						'type' => 'title',
						'description' => '',
					),
					'enabled' => array(
						'title' => __('فعالسازی/غیرفعالسازی', 'woocommerce'),
						'type' => 'checkbox',
						'label' => __('فعالسازی درگاه به پرداخت ملت', 'woocommerce'),
						'description' => __('برای فعالسازی درگاه پرداخت به پرداخت باید چک باکس را تیک بزنید', 'woocommerce'),
						'default' => 'yes',
						'desc_tip' => true,
					),
					'title' => array(
						'title' => __('عنوان درگاه', 'woocommerce'),
						'type' => 'text',
						'description' => __('عنوان درگاه که در طی خرید به مشتری نمایش داده میشود', 'woocommerce'),
						'default' => __('پرداخت امن به پرداخت ملت', 'woocommerce'),
						'desc_tip' => true,
					),
					'description' => array(
						'title' => __('توضیحات درگاه', 'woocommerce'),
						'type' => 'text',
						'desc_tip' => true,
						'description' => __('توضیحاتی که در طی عملیات پرداخت برای درگاه نمایش داده خواهد شد', 'woocommerce'),
						'default' => __('پرداخت امن به وسیله کلیه کارت های عضو شتاب از طریق درگاه به پرداخت ملت', 'woocommerce')
					),
					'currencyselect' => array(
						'title' => __('انتخاب واحد پول', 'woocommerce'),
						'type' => 'select',
						'description' => __('عملکرد پلاگین در این بخش بسیار مهم است. حتما به واحد پول دقت کنید', 'woocommerce'),
						'default'     => 'option2', // Default option value
						'desc_tip'    => true,
						'options'     => array(
							'option1' => __('ریال', 'woocommerce'),
							'option2' => __('تومان', 'woocommerce'),
						),

					),
					'account_config' => array(
						'title' => __('تنظیمات حساب به پرداخت ملت', 'woocommerce'),
						'type' => 'title',
						'description' => '',
					),
					'payaneh' => array(
						'title' => __('شماره پایانه', 'woocommerce'),
						'type' => 'text',
						'description' => __('مرچنت کد درگاه به پرداخت ملت', 'woocommerce'),
						'default' => '',
						'desc_tip' => true
					),
					'username' => array(
						'title' => __('نام کاربری', 'woocommerce'),
						'type' => 'text',
						'description' => __('مرچنت کد درگاه به پرداخت ملت', 'woocommerce'),
						'default' => '',
						'desc_tip' => true
					),
					'password' => array(
						'title' => __('رمز عبور', 'woocommerce'),
						'type' => 'text',
						'description' => __('مرچنت کد درگاه به پرداخت ملت', 'woocommerce'),
						'default' => '',
						'desc_tip' => true
					),
					'payment_config' => array(
						'title' => __('تنظیمات عملیات پرداخت', 'woocommerce'),
						'type' => 'title',
						'description' => '',
					),
					'success_massage' => array(
						'title' => __('پیام پرداخت موفق', 'woocommerce'),
						'type' => 'textarea',
						'description' => __('متن پیامی که میخواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {transaction_id} برای نمایش کد رهگیری (توکن) به پرداخت ملت استفاده نمایید .', 'woocommerce'),
						'default' => __('با تشکر از شما . سفارش شما با موفقیت پرداخت شد .', 'woocommerce'),
					),
					'failed_massage' => array(
						'title' => __('پیام پرداخت ناموفق', 'woocommerce'),
						'type' => 'textarea',
						'description' => __('متن پیامی که میخواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {fault} برای نمایش دلیل خطای رخ داده استفاده نمایید . این دلیل خطا از سایت به پرداخت ملت ارسال میگردد .', 'woocommerce'),
						'default' => __('پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .', 'woocommerce'),
					),
				)
			);
	
	 	}

		/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
	 	public function payment_scripts() {

			// we need JavaScript to process a token only on cart/checkout pages, right?
			if( ! is_cart() && ! is_checkout() && ! isset( $_GET[ 'pay_for_order' ] ) ) {
				return;
			}

			// if our payment gateway is disabled, we do not have to enqueue JS too
			if( 'no' === $this->enabled ) {
				return;
			}

			wp_register_style( 'ag_checkout_css', plugins_url( 'assets/css/ag_checkout_css.css', __FILE__ ) );
			wp_enqueue_style( 'ag_checkout_css' );
			
	
	 	}

		/*
		 * We're processing the payments here, everything about it is in Step 5
		 */
		public function process_payment( $order_id ) {

			$order = new WC_Order($order_id);
			return array(
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url(true)
			);
					
	 	}


		public function Send_to_AG_Behpardakht_Gateway($order_id)
		{

			global $woocommerce;
			$woocommerce->session->order_id_ag_behpardakht = $order_id;
			$order = new WC_Order( $order_id );
			$currency = $order->get_currency();
			$currency = apply_filters( 'WC_AG_Behpardakht_Currency', $currency, $order_id );
			$Amount = intval($order->get_total());
			$callBackUrl = add_query_arg( 'wc_order', $order_id , WC()->api_request_url('WC_AG_Behpardakht') );
			$payment = $this->payment;
	
			echo $payment->via('local')->config(['callbackUrl' => $callBackUrl, 'title' => 'صفحه پرداخت تست', 'description' => 'این صفحه عملکرد درگاه را شبیه سازی میکند'])->purchase(
				(new Invoice)->amount($Amount), 
				function($driver, $transactionId) use ($order_id) {
					update_post_meta($order_id , 'ag_behpardakht_transaction_id', $transactionId);
				}
			)->pay()->render();
			
		}


		public function Return_from_AG_Behpardakht_Gateway()
		{

			global $woocommerce;

			if (isset($_GET['wc_order'])) {
				$order_id = sanitize_text_field($_GET['wc_order']);
			} else {
				$order_id = $woocommerce->session->order_id_ag_behpardakht;
				unset($woocommerce->session->order_id_ag_behpardakht);
			}
			if ($order_id) {
				$order = new WC_Order($order_id);
				if ($order->status !== 'completed') {
					$Amount = intval($order->get_total());
					$transactionId = get_post_meta($order_id, 'ag_behpardakht_transaction_id', true);
					$payment = $this->payment;

					try {
						$receipt = $payment->amount($Amount)->transactionId($transactionId)->verify();
						echo $receipt->getReferenceId();
						wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
						$order->payment_complete($transactionId);
						$Note = sprintf(__('پرداخت موفقیت آمیز بود .<br/> کد رهگیری : %s', 'woocommerce'), $transactionId);
						if ($Note){
							$order->add_order_note($Note, 1);
						}
					} catch (InvalidPaymentException $exception) {
						echo $exception->getMessage();
					}
					
				}
			}
		}
		
 	}
}

