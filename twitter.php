<?php

class Twitter {
  
  var $base_url = 'https://api.twitter.com/1.1/';
  var $app_key;
  var $app_secret;
  var $token_key;
  var $token_secret;
  var $curl;
  
  function __construct($app = null, $token = null) {
    if (!empty($app)) {
      $this->set_credentials('app', $app);
    }
    if (!empty($token)) {
      $this->set_credentials('token', $token);
    }
  }
  
  function get($path, $args = null) {
    $url = $this->setup_request('GET', $path, $args);
    $this->authorize_request('GET', $path, $args);
    return $this->request($url);
  }
  
  function post($path, $args = null) {
    $url = $this->setup_request('POST', $path, $args);
    $this->authorize_request('POST', $path, $args);
    return $this->request($url);
  }
  
  function setup_request($method, $path, $args) {
    $this->setup_curl();
    $url = "{$this->base_url}{$path}";
    $method = strtoupper($method);
    if ($method === 'GET') {
      $url = $this->add_args($url, $args);
      curl_setopt($this->curl, CURLOPT_HTTPGET, true);
    } else if ($method === 'POST') {
      curl_setopt($this->curl, CURLOPT_POST, true);
      curl_setopt($this->curl, CURLOPT_POSTFIELDS, $this->get_args($args));
    }
    $last_request = array(
      'url' => $url,
      'path' => $path,
      'args' => $args
    );
    $this->last_request = (object) $last_request;
    return $url;
  }
  
  function setup_curl() {
    if (empty($this->curl)) {
      $this->curl = curl_init();
      curl_setopt_array($this->curl, array(
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
      ));
    }
  }
  
  function request($url) {
    curl_setopt($this->curl, CURLOPT_URL, $url);
    $content = curl_exec($this->curl);
    $last_response = curl_getinfo($this->curl);
    $last_response['content'] = $content;
    $this->last_response = (object) $last_response;
    return json_decode($content);
  }
  
  function add_args($url, $args) {
    if (!empty($args) && is_array($args)) {
      $url_args = $this->get_args($args);
      $separator = (strpos($url, '?') === false) ? '?' : '&';
      $url = "{$url}{$separator}{$url_args}";
    }
    return $url;
  }
  
  function get_args($args) {
    if (!empty($args) && is_array($args)) {
      $arg_list = array();
      foreach ($args as $key => $value) {
        $arg_list[] = urlencode($key) . '=' . urlencode($value);
      }
      return implode('&', $arg_list);
    }
    return '';
  }
  
  function authorize_request($method, $path, $args) {
    $auth_values = array(
      'oauth_consumer_key' => $this->get_credential('app_key'),
      'oauth_nonce' => $this->get_nonce(),
      'oauth_signature' => null, // TK
      'oauth_signature_method' => 'HMAC-SHA1',
      'oauth_timestamp' => time(), 
      'oauth_token' => $this->get_credential('token_key'),
      'oauth_version' => '1.0'
    );
    $auth_list = array();
    foreach ($auth_values as $key => $value) {
      if ($key != 'oauth_signature') {
        $args[$key] = $value;
      }
    }
    $this->last_auth = (object) $auth_values;
    $signature = $this->get_signature($method, $path, $args);
    $auth_values['oauth_signature'] = $signature;
    foreach ($auth_values as $key => $value) {
      $auth_list[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
    }
    $auth_string = implode(', ', $auth_list);
    $auth_header = "Authorization: OAuth $auth_string";
    $this->last_auth->authorization_header = $auth_header;
    curl_setopt($this->curl, CURLOPT_HTTPHEADER, array($auth_header));
  }
  
  function get_nonce() {
    $seed = '';
    for ($i = 0; $i < 32; $i++) {
      $ascii = rand(0, 256);
      $seed .= chr($ascii);
    }
    $seed .= time();
    $base64 = base64_encode($seed);
    return preg_replace('/\W/', '', $base64);
  }
  
  function get_signature($method, $path, $args) {
    $base_string = $this->get_base_string($method, $path, $args);
    $signing_key = $this->get_signing_key();
    $signature_binary = hash_hmac('sha1', $base_string, $signing_key, true);
    $signature_base64 = base64_encode($signature_binary);
    $this->last_auth->base_string = $base_string;
    $this->last_auth->signing_key = $signing_key;
    $this->last_auth->oauth_signature = $signature_base64;
    return $signature_base64;
  }
  
  function get_base_string($method, $path, $args) {
    $keys = array_keys($args);
    $encoded_keys = array_map('rawurlencode', $keys);
    sort($encoded_keys);
    $arg_list = array();
    foreach ($encoded_keys as $encoded_key) {
      $key = rawurldecode($encoded_key);
      $encoded_value = rawurlencode($args[$key]);
      $arg_list[] = "{$encoded_key}={$encoded_value}";
    }
    $arg_string = implode('&', $arg_list);
    $encoded_url = rawurlencode("{$this->base_url}{$path}");
    $encoded_args = rawurlencode($arg_string);
    return "{$method}&{$encoded_url}&{$encoded_args}";
  }
  
  function get_signing_key() {
    $encoded_app_secret = rawurlencode($this->get_credential('app_secret'));
    $encoded_token_secret = rawurlencode($this->get_credential('token_secret'));
    return "{$encoded_app_secret}&{$encoded_token_secret}";
  }
  
  function set_credentials($base, $values) {
    list($key, $secret) = $values;
    $key_var = "{$base}_key";
    $secret_var = "{$base}_secret";
    $this->$key_var = $key;
    $this->$secret_var = $secret;
  }
  
  function get_credential($credential) {
    if (!empty($this->$credential)) {
      return $this->$credential;
    } else {
      trigger_error("Credential $credential not found.");
    }
  }
  
  function __destruct() {
    if (!empty($this->curl)) {
      curl_close($this->curl);
    }
  }
  
}

?>
