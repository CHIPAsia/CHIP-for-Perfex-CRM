<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Chip extends App_Controller
{
  public function redirect($invoice_id, $invoice_hash, $attemptReference = null) {
    $this->db->where('id', $invoice_id);
    $invoice = $this->db->get(db_prefix() . 'invoices')->row();

    $payment = $this->chip_gateway->get_payment($invoice->token);

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