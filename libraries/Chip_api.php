<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Chip_api
{
  private $require_empty_string_encoding = false;

  public $brand_id;
  private $private_key;

  /**
   * Human-readable reason the last call failed, or null when it succeeded.
   * call() returns null for every failure shape so callers have one thing to
   * test; this carries the detail so the merchant still sees why.
   */
  private $last_error = null;

  public function __construct($option)
  {
    $this->private_key = $option[0];
    $this->brand_id = $option[1];
  }

  public function get_last_error()
  {
    return $this->last_error;
  }

  public function create_payment($params)
  {
    return $this->call('POST', '/purchases/', $params);
  }

  public function charge_payment($payment_id, $params)
  {
    return $this->call('POST', "/purchases/{$payment_id}/charge/", $params);
  }

  public function payment_methods($currency, $amount = 1000)
  {
    return $this->call(
      'GET',
      "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&amount={$amount}"
    );
  }

  public function payment_recurring_methods($currency)
  {
    return $this->call(
      'GET',
      "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&amount=1000&recurring=true"
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

  public function patch_client($client_id, $params)
  {
    return $this->call('PATCH', "/clients/{$client_id}/", $params);
  }

  public function delete_token($purchase_id)
  {
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

  /**
   * Flattens an error envelope into one readable line.
   *
   * CHIP answers validation failures with a map of field => messages, e.g.
   * {"__all__":[{"message":"...","code":"authentication_failed"}]}. Reading
   * only the first entry of the first key (the old get_first_error) drops
   * every other field's reason.
   */
  private function describe_error($result)
  {
    if (!is_array($result)) {
      return null;
    }

    $messages = [];

    foreach ($result as $field => $value) {
      if (is_array($value)) {
        foreach ($value as $entry) {
          if (is_array($entry) && !empty($entry['message'])) {
            $messages[] = $entry['message'];
          } elseif (is_string($entry) && $entry !== '') {
            $messages[] = $entry;
          }
        }
      } elseif (is_string($value) && $value !== '') {
        $messages[] = $value;
      }
    }

    if (empty($messages)) {
      return null;
    }

    return implode(' ', array_unique($messages));
  }

  private function call($method, $route, $params = [])
  {
    $this->last_error = null;

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

    // request() stores the transport/status reason and returns null.
    if ($response === null) {
      if ($this->last_error === null) {
        $this->last_error = 'Request to CHIP failed.';
      }

      return null;
    }

    $result = json_decode($response, true);
    if (!$result) {
      $this->last_error = 'Received an unreadable response from CHIP.';

      return null;
    }

    // An error envelope still arrives with a body, so it is decoded above and
    // has to be rejected here. Without this a 401 authentication_failed body
    // parses as an array carrying no 'errors' key and is handed back to the
    // caller as though the call had succeeded.
    if (!empty($result['errors']) || !empty($result['__all__'])) {
      $this->last_error = $this->describe_error($result);

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

    // Verify the gateway certificate. Disabling this would let any host that
    // can intercept the connection impersonate the payment gateway and read
    // the secret key from the Bearer header.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // this to prevent error when account balance called
    if ($this->require_empty_string_encoding) {
      curl_setopt($ch, CURLOPT_ENCODING, '');
    }

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);

    curl_close($ch);

    // The transport failed outright (DNS, TLS, timeout).
    if ($response === false) {
      $this->last_error = $curl_err !== '' ? $curl_err : 'Could not reach CHIP.';

      return null;
    }

    // Only 2xx carries usable data. Every other status is an error envelope,
    // including 401 authentication_failed, and must not be mistaken for a
    // result. Keep the body so call() can report the actual reason.
    if ($status < 200 || $status >= 300) {
      $decoded = json_decode($response, true);
      $detail  = $this->describe_error($decoded);

      $this->last_error = $detail !== null
        ? $detail
        : sprintf('CHIP returned HTTP %d.', $status);

      return null;
    }

    return $response;
  }
}
