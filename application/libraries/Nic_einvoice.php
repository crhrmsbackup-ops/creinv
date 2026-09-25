<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Nic_einvoice
{
	private $CI;
	private $config;
	private $token;
	private $sek;
	private $authenticated_user;

	public function __construct()
	{
		$this->CI =& get_instance();
		$this->CI->config->load('einvoice');
		$this->config = array();
		foreach (array('einv_env', 'einv_base_url', 'einv_public_key_path', 'einv_gstin',
			'einv_client_id', 'einv_client_secret', 'einv_app_key', 'einv_username',
			'einv_password', 'einv_paths') as $key) {
			$this->config[$key] = $this->CI->config->item($key);
		}
	}

	public function generate($invoice, $ewaybill)
	{
		$this->authenticate();
		$invoice_response = $this->request('generate_invoice', $invoice);
		$result = $invoice_response;
		if ($ewaybill) {
			$ewaybill['Irn'] = isset($invoice_response['Irn']) ? $invoice_response['Irn'] : '';
			$result['ewaybill'] = $this->request('generate_ewaybill', $ewaybill);
			if (is_array($result['ewaybill'])) {
				$result = array_merge($result, $result['ewaybill']);
			}
		}
		return $result;
	}

	/**
	 * Authenticate with NIC using the configured PEM public key.
	 *
	 * The PEM key encrypts the AppKey; NIC uses that value to return an
	 * encrypted session key (Sek), which is then used for API payloads.
	 */
	public function authenticate()
	{
		$app_key = $this->generate_app_key();
		$credentials = json_encode(array(
			'UserName' => $this->required('einv_username'),
			'Password' => $this->required('einv_password'),
			'AppKey' => $app_key,
			'ForceRefreshAccessToken' => TRUE,
		), JSON_UNESCAPED_SLASHES);
		if ($credentials === FALSE) {
			throw new RuntimeException('Unable to create NIC authentication JSON.');
		}
		// NIC requires Base64(credentials JSON) as the RSA plaintext.
		$encoded_credentials = base64_encode($credentials);
		$encrypted = $this->encrypt_with_pem($encoded_credentials);
		$encoded = base64_encode($encrypted);
		if ($encoded === FALSE || base64_decode($encoded, TRUE) === FALSE) {
			throw new RuntimeException('Unable to create valid Base64 NIC authentication data.');
		}
		$response = $this->http('auth', array(
			'Data' => $encoded,
		), array());
		if ((string) $this->value($response, 'Status') !== '1') {
			$message = isset($response['ErrorDetails']) ? json_encode($response['ErrorDetails']) : 'Unknown NIC error.';
			throw new RuntimeException('NIC authentication failed: ' . $message);
		}
		$data = isset($response['Data']) && is_array($response['Data']) ? $response['Data'] : array();
		if (empty($data['AuthToken']) || empty($data['Sek'])) {
			throw new RuntimeException('NIC authentication failed: missing AuthToken or Sek.');
		}
		$this->token = trim($data['AuthToken']);
		$this->authenticated_user = isset($data['UserName']) && trim($data['UserName'])
			? trim($data['UserName']) : trim($this->required('einv_username'));
		$this->sek = $this->decrypt($data['Sek'], $app_key);
	}

	private function request($path, $payload)
	{
		$json = json_encode($payload);
		return $this->decode($this->http($path, array(
			'Data' => base64_encode($this->encrypt($json, $this->sek)),
		), array(
			'AuthToken' => $this->token,
			'user_name' => $this->authenticated_user,
		)));
	}

	private function http($path, $payload, $extra_headers)
	{
		$url = rtrim($this->required('einv_base_url'), '/') . '/' . trim($this->config['einv_paths'][$path], '/');
		$headers = array('Content-Type: application/json', 'client_id: ' . $this->required('einv_client_id'),
			'client_secret: ' . $this->required('einv_client_secret'), 'Gstin: ' . $this->required('einv_gstin'));
		foreach ($extra_headers as $name => $value) {
			$headers[] = $name . ': ' . $value;
		}
		$handle = curl_init($url);
		curl_setopt($handle, CURLOPT_POST, TRUE);
		curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($handle, CURLOPT_TIMEOUT, 45);
		$body = curl_exec($handle);
		$status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$error = curl_error($handle);
		curl_close($handle);
		if ($body === FALSE || $error || $status < 200 || $status >= 300) {
			throw new RuntimeException('NIC request failed (' . $status . '): ' . ($error ? $error : $body));
		}
		$data = json_decode($body, TRUE);
		if (!is_array($data)) {
			throw new RuntimeException('NIC returned invalid JSON.');
		}
		return $data;
	}

	private function encrypt($plain, $key)
	{
		return openssl_encrypt($plain, 'AES-256-ECB', base64_decode($key), OPENSSL_RAW_DATA);
	}

	private function encrypt_with_pem($value)
	{
		$path = $this->required('einv_public_key_path');
		if (!is_readable($path)) {
			throw new RuntimeException('NIC public PEM key is not readable: ' . $path);
		}
		$key_data = file_get_contents($path);
		$key = openssl_pkey_get_public($key_data);
		if ($key === FALSE) {
			throw new RuntimeException('NIC public PEM key is invalid: ' . $path);
		}
		$key_details = openssl_pkey_get_details($key);
		if (!is_array($key_details) || empty($key_details['bits']) || $key_details['bits'] < 2048) {
			throw new RuntimeException('NIC public PEM key must be an RSA key of at least 2048 bits.');
		}
		$encrypted = '';
		if (!openssl_public_encrypt($value, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
			throw new RuntimeException('Unable to encrypt data with NIC public PEM key.');
		}
		return $encrypted;
	}

	private function decrypt($value, $key)
	{
		$plain = openssl_decrypt(base64_decode($value), 'AES-256-ECB', base64_decode($key), OPENSSL_RAW_DATA);
		if ($plain === FALSE) {
			throw new RuntimeException('Unable to decrypt NIC session key.');
		}
		return $plain;
	}

	private function decode($response)
	{
		if (isset($response['Data']) && $this->sek) {
			$decoded = $this->decrypt($response['Data'], $this->sek);
			$data = json_decode($decoded, TRUE);
			if (is_array($data)) {
				return $data;
			}
		}
		if (isset($response['Data']) && is_array($response['Data'])) {
			return $response['Data'];
		}
		return $response;
	}

	private function required($name)
	{
		$value = isset($this->config[$name]) ? $this->config[$name] : '';
		if (!$value) {
			throw new RuntimeException('Missing e-invoice configuration: ' . $name);
		}
		return $value;
	}

	private function value($data, $key)
	{
		return isset($data[$key]) ? $data[$key] : NULL;
	}

	private function generate_app_key()
	{
		if (function_exists('random_bytes')) {
			$bytes = random_bytes(32);
		} else {
			$bytes = openssl_random_pseudo_bytes(32);
		}
		if ($bytes === FALSE || strlen($bytes) !== 32) {
			throw new RuntimeException('Unable to generate a secure NIC AppKey.');
		}
		return base64_encode($bytes);
	}
}
