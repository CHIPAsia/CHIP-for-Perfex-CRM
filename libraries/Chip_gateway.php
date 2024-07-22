<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Chip_gateway extends App_gateway
{
	public bool $processingFees = true;

	public function __construct()
    {
        /**
        * Call App_gateway __construct function
        */
        parent::__construct();

        /**
        * REQUIRED
        * Gateway unique id
        * The ID must be alpha/alphanumeric
        */
        $this->setId('chip');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('CHIP');

        /**
         * Add gateway settings
        */
        $this->setSettings([
            [
              'name'  => 'secret_key',
              'label' => 'CHIP API Secret Key',
            ],
            [
              'name'  => 'brand_id',
              'label' => 'CHIP Brand ID',
            ],
            [
              'name'  => 'payment_method_whitelist',
              'default_value' => '',
              'label' => 'Payment Method Whitelist (comma separated)',
              'after' => '<p class="mbot15">Possible values: <code>fpx</code>, <code>fpx_b2b1</code>, <code>mastercard</code>, <code>maestro</code>, <code>visa</code>, <code>razer</code>, <code>razer_atome</code>, <code>razer_grabpay</code>, <code>razer_maybankqr</code>, <code>razer_shopeepay</code>, <code>razer_tng</code>, <code>duitnow_qr</code>. Set this to control the available payment method on checkout page. Default value is blank.</p>',
            ],
            [
              'name'  => 'preferred_payment_method',
              'default_value' => 'fpx',
              'label' => 'Preferred payment method',
              'after' => '<p class="mbot15">Possible values: <code>fpx</code>, <code>fpx_b2b1</code>,<code>card</code>,<code>razer</code>,<code>razer_atome</code>. Set this to control the first payment method to be shown in checkout page. Default value: <code>fpx</code></p>',
            ],
            [
              'name'  => 'send_receipt',
              'type'  => 'yes_no',
              'default_value' => 0,
              'label' => 'Send receipt',
              'after' => '<p class="mbot15">Enable this option for customer to receive receipt from CHIP upon successful payment.</p>',
            ],
            [
              'name'  => 'due_strict',
              'type'  => 'yes_no',
              'default_value' => 0,
              'label' => 'Due Strict',
              'after' => '<p class="mbot15">Enable this option to disable payment after purchase passed due.</p>',
            ],
            [
              'name'  => 'due_strict_timing',
              'default_value' => 60,
              'label' => 'Due Strict Timing (minutes)',
              'after' => '<p class="mbot15">Set this value to define the maximum time allowed for customer to make payment. Default value: <code>60</code></p>',
            ],
            [
              'name'  => 'purchase_timezone',
              'label' => 'Purchase Timezone',
              'default_value' => 'Asia/Kuala_Lumpur',
              'after' => '<p class="mbot15">Default value: <code>Asia/Kuala_Lumpur</code>.</p>',
            ],
            [
              'name'  => 'email_fallback',
              'label' => 'Email fallback',
              'after' => '<p class="mbot15">Since CHIP API requires email address, on the event of the customer didn\'t have email address, CHIP will fallback to this email address. Default value is blank.</p>',
            ],
            [
              'name'  => 'public_key',
              'label' => 'CHIP Public Key',
              'type'  => 'textarea',
              'field_attributes' => ['disabled' => true],
            ],
            [
              'name'             => 'currencies',
              'label'            => 'settings_paymentmethod_currencies',
              'default_value'    => 'MYR',
              'field_attributes' => ['disabled' => true],
            ],
        ]);

        hooks()->add_filter('app_payment_gateways', [ $this, 'initMode' ]);
        hooks()->add_action('before_render_payment_gateway_settings', [$this, 'fetchPublicKey']);
        hooks()->add_action('before_render_payment_gateway_settings', [$this, 'webhook_notice']);
        hooks()->add_action('before_render_payment_gateway_settings', [$this, 'validate_option']);
    }

    public function fetchPublicKey($gateway) {
      if ($gateway['id'] !== 'chip') {
        return;
      }

      if (empty($secret_key = $this->getSetting('secret_key'))) {
        return;
      }

      $this->ci->load->library(CHIP_MODULE_NAME . '/chip_api', [$secret_key, '']);

      $chip = $this->ci->chip_api;

      $public_key = $chip->public_key();

      if (is_array($public_key)) {
        echo '<div class="alert alert-info mtop15">';
        echo $this->get_first_error($public_key);
        echo '</div>';

        return;
      }

      update_option('paymentmethod_chip_public_key', $public_key, false);
    }

    public function validate_option($gateway) {
      if ($gateway['id'] !== 'chip') {
        return;
      }

      if (!empty($secret_key = $this->getSetting('secret_key')) AND !empty($brand_id = $this->getSetting('brand_id'))) {
        $chip = $this->ci->chip_api;
        $chip->brand_id = $brand_id;

        $available_payment_method = $chip->payment_methods('MYR');

        if (isset($available_payment_method['available_payment_methods']) AND empty($available_payment_method['available_payment_methods'])) {
          echo '<div class="alert alert-info mtop15">';
          echo 'Brands did not contain any payment method!';
          echo '</div>';
        }
      }
    }

    /**
     * Each time a customer click PAY NOW button on the invoice HTML area, the script will process the payment via this function.
     * You can show forms here, redirect to gateway website, redirect to Codeigniter controller etc..
     * @param  array $data - Contains the total amount to pay and the invoice information
     * @return mixed
     */
    public function process_payment($data)
    {
      $contact = $this->ci->clients_model->get_contacts($data['invoice']->client->userid, ['active' => 1,'is_primary'=> 1])[0];

      $callback_url = site_url('chip/chip/webhook/' . $data['invoice']->id . '/' . $data['invoice']->hash . '/' . $data['payment_attempt']->reference);

      if ( file_exists(APPPATH.'/controllers/gateways/Chip.php')) {
        $callback_url = site_url('gateways/chip/webhook/' . $data['invoice']->id . '/' . $data['invoice']->hash . '/' . $data['payment_attempt']->reference);
      }
      
      $redirect_url = site_url('chip/chip/redirect/' . $data['invoice']->id . '/' . $data['invoice']->hash . '/' . $data['payment_attempt']->reference);

      $payment_method_whitelist = $this->getSetting('payment_method_whitelist');
      $payment_method_whitelist = explode(',', $payment_method_whitelist);
      foreach($payment_method_whitelist as &$pmw) {
        $pmw = trim($pmw);
      }

      $due_strict_timing = preg_replace('/[^0-9]/', '', $this->getSetting('due_strict'));
      if ( empty( $due_strict_timing ) ) {
        $due_strict_timing = 60;
      }
      $due_strict_timing = time() + ( abs( (int) $due_strict_timing ) * 60 );

      $timezone = $this->getSetting('purchase_timezone');
      
      if (!in_array($timezone, DateTimeZone::listIdentifiers( DateTimeZone::ALL ))) {
        $timezone = 'Asia/Kuala_Lumpur';
      }

      $params = [
        'payment_method_whitelist' => $payment_method_whitelist,
        'success_callback' => $callback_url,
        'success_redirect' => $redirect_url,
        'failure_redirect' => $redirect_url,
        'cancel_redirect'  => $redirect_url,
        'creator_agent'    => 'PerfexCRM: 1.0.0',
        'reference'        => $data['invoiceid'],
        'due'              => $due_strict_timing,
        'send_receipt'     => $this->getSetting('send_receipt'),
        'purchase' => [
          'total_override' => round( $data['amount'] * 100 ),
          'timezone'       => $timezone,
          'currency'       => $data['invoice']->currency_name,
          'due_strict'     => $this->getSetting('due_strict'),
          'products'       => [],
        ],
        'brand_id' => $this->getSetting('brand_id'),
        'client' => [
          'email'     => $contact['email'],
          'full_name' => substr( $contact['firstname'] . ' ' . $contact['lastname'], 0, 128 ),
          'phone'     => substr( $data['invoice']->client->phonenumber, 0, 32 ),
          'legal_name'=> substr( $data['invoice']->client->company, 0, 128 ),
          'personal_code' => $data['invoice']->client->userid,
          'street_address'  => substr( $data['invoice']->client->address, 0, 128 ) ,
          'country'         => substr( get_country_short_name($data['invoice']->client->country), 0, 2 ),
          'city'            => substr( $data['invoice']->client->city, 0, 128 ) ,
          'zip_code'        => substr( $data['invoice']->client->zip, 0, 32 ),
          'state'           => substr( $data['invoice']->client->state, 0, 128 ),
        ],
      ];

      foreach ( $data['invoice']->items as $item ) {
        
        $qty   = $item['qty'];
        $description = $item['description'] ?? $item['id'];
  
        $params['purchase']['products'][] = array(
          'name'     => substr( $description, 0, 256 ),
          'price'    => round( $item['rate'] * 100 ),
          'quantity' => $qty
        );
      }

      if (empty($params['client']['email'])) {
        $params['client']['email'] = $this->getSetting('email_fallback');
      }

      foreach ( $params['client'] as $key => $value ) {
        if ( empty( $value ) ) {
          unset( $params['client'][$key] );
        }
      }

      $this->ci->load->library(CHIP_MODULE_NAME . '/chip_api', [$this->getSetting('secret_key'), '']);

      $chip = $this->ci->chip_api;

      $payment = $chip->create_payment($params);

      if ( !array_key_exists( 'id', $payment ) ) {

        if ( is_array( $payment ) ) {
          $error = $this->get_first_error($payment);
          set_alert( 'danger', str_replace('"', '', $error) );
        } else {
          set_alert( 'danger', 'Failed to create purchase.' );
        }
        redirect(site_url('invoice/' . $data['invoice']->id . '/' . $data['invoice']->hash));
        return;
      }

      $this->ci->db->where('id', $data['invoiceid']);
      $this->ci->db->update(db_prefix() . 'invoices', [
          'token' => $payment['id'],
      ]);

      redirect($payment['checkout_url'] . '?active=' . $this->getSetting('preferred_payment_method'));
    }

    public function get_payment($purchase_id) {
      
      $this->ci->load->library(CHIP_MODULE_NAME . '/chip_api', [$this->getSetting('secret_key'), '']);

      $chip = $this->ci->chip_api;
      
      return $chip->get_payment($purchase_id);
    }

    public function webhook_notice($gateway) {
      $file_existence = file_exists(APPPATH.'/controllers/gateways/Chip.php');
      if ($gateway['id'] === 'chip' && !$file_existence) {
        echo '<div class="alert alert-info mtop15">';
        echo 'Controller file for webhooks failed to be created. Please set <code>$config[\'csrf_exclude_uris\'][] = \'chip/chip/webhook\';</code> on your application/config.php file';
        echo '</div>';
      }
    }

    private function get_first_error( array $payment) {
      foreach($payment as $key => $value) {
        if (!is_numeric($key)) {
          return $this->get_first_error($value);
        } else {
          return $value['message'];
        }
      }
    }
}
