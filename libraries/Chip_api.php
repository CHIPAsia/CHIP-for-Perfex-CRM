<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Chip_api
{
  private $require_empty_string_encoding = false;

  public $brand_id;
  private $private_key;
  
  public function __construct($option)
  {
    $this->private_key = $option[0];
    $this->brand_id    = $option[1];
  }

  public function create_payment($params)
  {
    return $this->call('POST', '/purchases/', $params);
  }

  public function charge_payment($payment_id, $params)
  {
    return $this->call('POST', "/purchases/{$payment_id}/charge/", $params);
  }

  public function payment_methods($currency)
  {
    return $this->call(
      'GET',
      "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&amount=200"
    );
  }

  public function payment_recurring_methods($currency)
  {
    return $this->call(
      'GET',
      "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&amount=200&recurring=true"
    );
  }

  public function get_payment($payment_id)
  {
    $result = $this->call('GET', "/purchases/{$payment_id}/");
    return $result;
  }

  public function was_payment_successful($payment_id)
  {
    $result = $this->get_payment($payment_id);
    return $result && $result['status'] == 'paid';
  }

  public function create_client($params)
  {
    return $this->call('POST', "/clients/", $params);
  }

  // this is secret feature
  public function get_client_by_email($email)
  {
    $email_encoded = urlencode($email);
    return $this->call('GET', "/clients/?q={$email_encoded}");
  }

  public function patch_client($client_id, $params) {
    return $this->call('PATCH', "/clients/{$client_id}/", $params);
  }

  public function delete_token($purchase_id) {
    return $this->call('POST', "/purchases/$purchase_id/delete_recurring_token/");
  }

  public function refund_payment($payment_id, $params)
  {
    $result = $this->call('POST', "/purchases/{$payment_id}/refund/", $params);

    return $result;
  }

  public function public_key()
  {
    $result = $this->call('GET', "/public_key/");
    
    return $result;
  }

  public function account_balance()
  {
    $params = array(
      'brand_id' => $this->brand_id
    );

    // get initial state prior
    $initial_state = $this->require_empty_string_encoding;

    // set to true as it requires empty encoding
    $this->require_empty_string_encoding = true;

    $result = $this->call('GET', '/account/json/balance/?' . http_build_query($params));

    // restore initial state
    $this->require_empty_string_encoding = $initial_state;

    return $result;
  }

  private function call($method, $route, $params = [])
  {
    $private_key = $this->private_key;
    if (!empty($params)) {
      $params = json_encode($params);
    }

    $response = $this->request(
      $method,
      sprintf("%s/api/v1%s", 'https://gate.chip-in.asia', $route),
      $params,
      [
        'Content-type: application/json',
        'Authorization: ' . "Bearer " . $private_key,
      ]
    );

    $result = json_decode($response, true);
    if (!$result) {
      return null;
    }

    if (!empty($result['errors'])) {
      return null;
    }

    return $result;
  }

  private function request($method, $url, $params = [], $headers = [])
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    
    if ($method == 'POST') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }

    if ($method == 'PUT') {
      curl_setopt($ch, CURLOPT_PUT, 1);
    }

    if ($method == 'PUT' or $method == 'POST' or $method == 'PATCH') {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }

    if ($method == 'PATCH') {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // this to prevent error when account balance called
    if ($this->require_empty_string_encoding){
      curl_setopt($ch, CURLOPT_ENCODING, '');
    }

    $response = curl_exec($ch);

    curl_close($ch);

    return $response;
  }
}
