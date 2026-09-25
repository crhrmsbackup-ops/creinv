<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Nic_einvoice
{
	private $CI;
	private $config;
	private $token;
	private $sek;

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

	private function authenticate()
	{
		$app_key = $this->required('einv_app_key');
		$encrypted = '';
		$key = file_get_contents($this->required('einv_public_key_path'));
		if (!openssl_public_encrypt($app_key, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
			throw new RuntimeException('Unable to encrypt NIC application key.');
		}
		$response = $this->http('auth', array(
			'action' => 'ACCESSTOKEN',
			'Data' => base64_encode($encrypted),
			'ForceRefreshAccessToken' => 'true',
		), array('user_name' => $this->required('einv_username'), 'password' => $this->required('einv_password')));
		if (empty($response['AuthToken']) || empty($response['Sek'])) {
			throw new RuntimeException('NIC authentication failed.');
		}
		$this->token = $response['AuthToken'];
		$this->sek = $this->decrypt($response['Sek'], $app_key);
	}

	private function request($path, $payload)
	{
		$json = json_encode($payload);
		return $this->decode($this->http($path, array(
			'Data' => base64_encode($this->encrypt($json, $this->sek)),
			'Sek' => $this->encrypt_rsa($this->sek),
			'Gstin' => $this->required('einv_gstin'),
		), array('auth-token' => $this->token)));
	}

	private function http($path, $payload, $extra_headers)
	{
		$url = rtrim($this->required('einv_base_url'), '/') . '/' . trim($this->config['einv_paths'][$path], '/');
		$headers = array('Content-Type: application/json', 'client-id: ' . $this->required('einv_client_id'),
			'client-secret: ' . $this->required('einv_client_secret'), 'gstin: ' . $this->required('einv_gstin'));
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

	private function encrypt_rsa($value)
	{
		$encrypted = '';
		$key = file_get_contents($this->required('einv_public_key_path'));
		if (!openssl_public_encrypt($value, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
			throw new RuntimeException('Unable to encrypt NIC session key.');
		}
		return base64_encode($encrypted);
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
		return isset($response['Data']) && is_array($response['Data']) ? $response['Data'] : $response;
	}

	private function required($name)
	{
		$value = isset($this->config[$name]) ? $this->config[$name] : '';
		if (!$value) {
			throw new RuntimeException('Missing e-invoice configuration: ' . $name);
		}
		return $value;
	}
}
