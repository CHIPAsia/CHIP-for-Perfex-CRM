<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Chip extends App_Controller
{
  public function redirect($invoice_id, $invoice_hash, $attemptReference = null) {
    $this->db->where('id', $invoice_id);
    $invoice = $this->db->get(db_prefix() . 'invoices')->row();

    if (!$invoice) {
      redirect(site_url('invoice/' . $invoice_id . '/' . $invoice_hash));
      return;
    }

    $payment = $this->chip_gateway->get_payment($invoice->token);

    // get_payment() returns null when the lookup fails (invalid credential,
    // non-2xx, transport error) or when the invoice has no token yet. Reading
    // $payment['status'] on null is a TypeError on PHP 8, which is not an
    // Exception and escapes the request as an uncaught fatal.
    if (!is_array($payment) || !isset($payment['status'])) {
      set_alert('danger', 'Unable to verify the payment with CHIP. Please try again.');
      redirect(site_url('invoice/' . $invoice_id . '/' . $invoice_hash));
      return;
    }

    if ($payment['status'] == 'paid') {
      set_alert( 'success' , _l( 'online_payment_recorded_success'));
      if (total_rows('invoicepaymentrecords', ['invoiceid' => $invoice_id, 'transactionid' => $payment['id']]) == 0) {

        $this->load->model('chip/chip_model');
        
        if ($this->chip_model->insert($payment)) {

          $this->chip_gateway->addPayment([
            'amount'        => $payment['payment']['amount'] / 100,
            'invoiceid'     => $invoice_id,
            'paymentmethod' => strtoupper($payment['transaction_data']['payment_method']),
            'transactionid' => $payment['id'],
            'payment_attempt_reference' => $attemptReference,
          ]);
        }
      }
    } else {
      set_alert( 'danger', _l( 'online_payment_recorded_success_fail_database'));
    }

    redirect(site_url('invoice/' . $invoice_id . '/' . $invoice_hash));
  }

  public function webhook($invoice_id, $invoice_hash, $attemptReference = null) { 
    if ( !isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
      die('No X Signature received from headers');
    }
    
    if ( empty($content = file_get_contents('php://input')) ) {
      die('No input received');
    }

    $payment = json_decode($content, true);

    // A malformed or non-object body decodes to null, and reading
    // $payment['status'] on null is a TypeError on PHP 8 rather than a
    // catchable error. Reject the payload before touching it.
    if ( !is_array($payment) || !isset($payment['status']) ) {
      header('Bad Request', true, 400);
      die('Invalid payload');
    }

    if ( $payment['status'] != 'paid' ) {
      exit;
    }

    $public_key = $this->chip_gateway->getSetting('public_key');
    $public_key = str_replace( '\n', "\n", $public_key );

    if ( openssl_verify( $content,  base64_decode($_SERVER['HTTP_X_SIGNATURE']), $public_key, 'sha256WithRSAEncryption' ) != 1 ) {
      header( 'Forbidden', true, 403 );
      die('Invalid X Signature');
    }

    if ($payment['status'] == 'paid') {
      set_alert( 'success' , _l( 'online_payment_recorded_success'));
      if (total_rows('invoicepaymentrecords', ['invoiceid' => $payment['reference'], 'transactionid' => $payment['id']]) == 0) {

        $this->load->model('chip/chip_model');
        
        if ($this->chip_model->insert($payment)) {
          $this->chip_gateway->addPayment([
              'amount'        => $payment['payment']['amount'] / 100,
              'invoiceid'     => $payment['reference'],
              'paymentmethod' => strtoupper($payment['transaction_data']['payment_method']),
              'transactionid' => $payment['id'],
              'payment_attempt_reference' => $attemptReference,
          ]);
        }
      }
    }
  }
}