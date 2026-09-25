<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * NIC e-Invoice/e-Way Bill settings.
 *
 * Put credentials and the NIC public key in application/config/einvoice_local.php
 * (that file is ignored by git). Environment variables are also supported.
 */
$config['einv_env'] = getenv('EINV_ENV') ? getenv('EINV_ENV') : 'sandbox';
$config['einv_base_url'] = getenv('EINV_BASE_URL')
	? getenv('EINV_BASE_URL')
	: 'https://einv1api.gstsandbox.nic.in/';
$config['einv_public_key_path'] = getenv('EINV_PUBLIC_KEY_PATH')
	? getenv('EINV_PUBLIC_KEY_PATH')
	: APPPATH . 'config/keys/einv_public_key.pem';
$config['einv_gstin'] = getenv('EINV_GSTIN') ? getenv('EINV_GSTIN') : '';
$config['einv_client_id'] = getenv('EINV_CLIENT_ID') ? getenv('EINV_CLIENT_ID') : '';
$config['einv_client_secret'] = getenv('EINV_CLIENT_SECRET') ? getenv('EINV_CLIENT_SECRET') : '';
$config['einv_app_key'] = getenv('EINV_APP_KEY') ? getenv('EINV_APP_KEY') : '';
$config['einv_username'] = getenv('EINV_USERNAME') ? getenv('EINV_USERNAME') : '';
$config['einv_password'] = getenv('EINV_PASSWORD') ? getenv('EINV_PASSWORD') : '';
$config['einv_sup_gstin'] = getenv('EINV_SUP_GSTIN') ? getenv('EINV_SUP_GSTIN') : '';
$config['einv_export_country_code'] = getenv('EINV_EXPORT_COUNTRY_CODE')
	? strtoupper(getenv('EINV_EXPORT_COUNTRY_CODE')) : '';
$config['einv_token_refresh_margin_minutes'] = 5;
$config['einv_default_export_currency'] = 'USD';
$config['einv_paths'] = array(
	'auth' => 'eivital/v1.04/auth',
	'generate_invoice' => 'eicore/v1.03/Invoice',
	'generate_ewaybill' => 'eiewb/v1.03/ewaybill',
);

if (is_file(APPPATH . 'config/einvoice_local.php')) {
	include APPPATH . 'config/einvoice_local.php';
}
